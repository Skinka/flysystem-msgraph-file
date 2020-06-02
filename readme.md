# Flysystem adapter for OneDrive or SharePoint

This package contains a [Flysystem](https://flysystem.thephpleague.com/) adapter for OneDrive, SharePoint. Under the hood, [MS Graph API](https://docs.microsoft.com/ru-ru/graph/api/resources/onedrive?view=graph-rest-1.0) is used.

## Installation

You can install the package via composer:

``` bash
composer require skinka/flysystem-msgraph-files
```

## Usage

The first thing you need to do is create an application at [Azure](https://docs.microsoft.com/ru-ru/graph/auth-v2-service?view=graph-rest-1.0) and get `client_id`, `client_secret`, `tenant_id`.

``` php
use League\Flysystem\Filesystem;
use Skinka\FlysystemMSGraph\MSGraphAdapter;

$filesystem = new Filesystem(new MSGraphAdapter($clientId, $clientSecret, $tenantId, $prefix));
```

Note: `$prefix` is [file resource](https://docs.microsoft.com/ru-ru/graph/api/resources/onedrive?view=graph-rest-1.0#commonly-accessed-resources) string.

## License

The MIT License (MIT). Please see [License File](license.md) for more information.
