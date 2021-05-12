## Flysystem v2 adapter for the IPFS API

This package contains a [Flysystem v2](https://flysystem.thephpleague.com/) adapter for [IPFS](https://ipfs.io/). Under the hood, the [IPFS API PHP wrapper](https://github.com/openwse/ipfs-api) is used.

---


## Installation

You can install the package via composer:

``` bash
composer require openwse/flysystem-ipfs
```


## Usage

```php
use League\Flysystem\Filesystem;
use Ipfs\Ipfs;
use Ipfs\Drivers\HttpClient;
use FlysystemIpfs\IpfsAdapter;

$client = new Ipfs(
    new HttpClient('https://ipfs-host', 5001)
);

$adapter = new IpfsAdapter($client);

$filesystem = new Filesystem($adapter);
```
Note: that removing a file on IPFS will only affect your node if the file has been pinned.


## Lint
Run [PHPMD](https://phpmd.org/), [PHPStan](https://phpstan.org/), and [PHP-CS-Fixer](https://github.com/FriendsOfPhp/PHP-CS-Fixer)
``` bash
composer lint
```


## Testing
It uses [tests cases provided by Flysystem](https://flysystem.thephpleague.com/v2/docs/advanced/creating-an-adapter/) but override visibility & last modified settings because it's not supported by IPFS.

``` bash
composer tests
```


## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
