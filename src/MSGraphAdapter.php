<?php


namespace Skinka\FlysystemMSGraph;


use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Stream;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Config;
use Microsoft\Graph\Graph;
use Microsoft\Graph\Model\DriveItem;

/**
 * Class MSGraphAdapter
 * @package Skinka\FlysystemMSGraph
 */
class MSGraphAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;

    protected $prefix;

    /** @var Graph */
    protected $graph;

    public function __construct($clientId, $clientSecret, $tenantId, $prefix)
    {
        $guzzle = new \GuzzleHttp\Client();
        $response = $guzzle->post("https://login.microsoftonline.com/$tenantId/oauth2/v2.0/token",
            [
                'headers' => [
                    'Host' => 'login.microsoftonline.com',
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ],
                'form_params' => [
                    'client_id' => $clientId,
                    'scope' => 'https://graph.microsoft.com/.default',
                    'client_secret' => $clientSecret,
                    'grant_type' => 'client_credentials'
                ]
            ]);
        $body = json_decode($response->getBody()->getContents());
        $graph = new Graph();
        $this->graph = $graph->setAccessToken($body->access_token);
        $this->prefix = '/'.trim($prefix, '/').'/';
    }

    public function has($path)
    {
        try {
            $url = $path ? $this->prefix.'root:/'.$path.':/children' : $this->prefix.'root/children';
            $this->graph->createRequest('GET', $url)->execute();
            return true;
        } catch (ClientException $e) {
            if ($e->getCode() == 404) {
                return false;
            }
            throw $e;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function listContents($directory = '', $recursive = false)
    {
        if ($recursive) {
            throw new \Exception('Recursive not supported');
        }
        try {
            $url = $directory ? $this->prefix.'root:/'.$directory.':/children' : $this->prefix.'root/children';

            /** @var DriveItem[] $driveItems */
            $driveItems = $this->graph->createRequest('GET', $url)
                ->setReturnType(DriveItem::class)
                ->execute();

            $children = [];
            foreach ($driveItems as $driveItem) {
                $item = $driveItem->getProperties();
                $item['path'] = $directory.'/'.$driveItem->getName();
                $item['type'] = $driveItem->getFolder() ? 'dir' : 'file';
                $item['dirname'] = $directory;
                $children[] = $item;
            }
            return $children;
        } catch (ClientException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function read($path)
    {
        try {
            $driveItem = $this->getDriveItem($path);
            $contentStream = $this->graph
                ->createRequest('GET', $this->prefix.'items/'.$driveItem->getId().'/content')
                ->setReturnType(Stream::class)
                ->execute();
            $contents = '';
            $bufferSize = 8012;
            while (!$contentStream->eof()) {
                $contents .= $contentStream->read($bufferSize);
            }
            return ['contents' => $contents];
        } catch (ClientException $e) {
            if ($e->getCode() == 404) {
                return false;
            }
            throw $e;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function readStream($path)
    {
        try {
            $content = $this->read($path);
            if ($content) {
                $stream = fopen('php://memory', 'r+');
                fwrite($stream, $content['contents']);
                rewind($stream);
                return ['stream' => $stream];
            }
            return false;
        } catch (ClientException $e) {
            if ($e->getCode() == 404) {
                return false;
            }
            throw $e;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function getUrl($path)
    {
        try {
            $driveItem = $this->getDriveItem($path);
            return $driveItem->getWebUrl();
        } catch (ClientException $e) {
            if ($e->getCode() == 404) {
                return false;
            }
            throw $e;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function getMetadata($path)
    {
        try {
            /** @var DriveItem $driveItem */
            $driveItem = $this->getDriveItem($path);
            return [
                'mimetype' => $driveItem->getFile()->getMimeType(),
                'size' => $driveItem->getSize(),
                'timestamp' => $driveItem->getLastModifiedDateTime(),
            ];
        } catch (ClientException $e) {
            if ($e->getCode() == 404) {
                return false;
            }
            throw $e;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function getSize($path)
    {
        return $this->getMetadata($path)['size'];
    }

    public function getMimetype($path)
    {
        return $this->getMetadata($path)['mimetype'];
    }

    public function getTimestamp($path)
    {
        return $this->getMetadata($path)['timestamp'];
    }

    public function rename($path, $newpath)
    {
        try {
            $driveItem = $this->getDriveItem($path);
            $old = pathinfo($path);
            $new = pathinfo($newpath);
            $body = [
                'name' => $new['basename']
            ];
            if ($old['dirname'] != $new['dirname']) {
                if (!$this->has($new['dirname'])) {
                    $pathItem = $this->makePath($new['dirname']);
                } else {
                    $pathItem = $this->getDriveItem($new['dirname']);
                }
                $body['parentReference'] = ['id' => $pathItem->getId()];
            }

            $this->graph->createRequest('PATCH', $this->prefix.'items/'.$driveItem->getId())
                ->attachBody(json_encode($body))
                ->execute();
            return true;
        } catch (ClientException $e) {
            if ($e->getCode() == 404) {
                return false;
            }
            throw $e;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function copy($path, $newpath)
    {
        $content = $this->read($path);
        return $this->write($newpath, $content, new Config());
    }

    public function delete($path)
    {
        try {
            $driveItem = $this->getDriveItem($path);
            $responce = $this->graph->createRequest('DELETE', $this->prefix.'items/'.$driveItem->getId())
                ->execute();
            return $responce->getStatus() == 204;
        } catch (ClientException $e) {
            if ($e->getCode() == 404) {
                return false;
            }
            throw $e;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function deleteDir($dirname)
    {
        return $this->delete($dirname);
    }

    public function write($path, $contents, Config $config)
    {
        try {
            $response = $this->graph->createRequest('PUT', $this->prefix.'root:/'.$path.':/content')
                ->attachBody($contents)
                ->execute();
            return $response->getStatus() == 200;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function writeStream($path, $resource, Config $config)
    {
        try {
            $this->graph->createRequest('PUT', $this->prefix.'root:/'.$path.':/content')
                ->attachBody(stream_get_contents($resource))
                ->execute();
            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    public function updateStream($path, $resource, Config $config)
    {
        return $this->writeStream($path, $resource, $config);
    }

    public function createDir($dirname, Config $config)
    {
        try {
            return !!$this->makePath($dirname);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param $path
     * @return DriveItem
     * @throws \Microsoft\Graph\Exception\GraphException
     */
    protected function getDriveItem($path)
    {
        return $this->graph->createRequest('GET', $this->prefix.'root:/'.$path)
            ->setReturnType(DriveItem::class)
            ->execute();

    }

    /**
     * @param  string  $path
     * @return DriveItem|null
     * @throws \Microsoft\Graph\Exception\GraphException
     */
    protected function makePath($path)
    {
        $parentId = '';
        $pathItem = null;
        foreach (explode('/', $path) as $dir) {
            $pathItem = $this->graph
                ->createRequest(
                    'POST',
                    $this->prefix.(!$parentId ? 'root/children' : 'items/'.$parentId.'/children')
                )
                ->attachBody(json_encode([
                    'name' => $dir,
                    'folder' => new \stdClass(),
                    '@microsoft.graph.conflictBehavior' => 'replace'
                ], JSON_FORCE_OBJECT))
                ->setReturnType(DriveItem::class)
                ->execute();
            $parentId = $pathItem->getId();
        }
        return $pathItem;
    }
}
