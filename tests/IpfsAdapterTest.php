<?php

namespace FlysystemIpfs\Tests;

use FlysystemIpfs\IpfsAdapter;
use Ipfs\Drivers\HttpClient;
use Ipfs\Ipfs;
use League\Flysystem\AdapterInterface;
use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\Config;

class IpfsAdapterTest extends FilesystemAdapterTestCase
{
    protected static function createFilesystemAdapter(): AdapterInterface
    {
        return new IpfsAdapter(
            new Ipfs(new HttpClient('http://ipfs', 5001)),
            '',
            [
                'auto_publish' => true,
                'publish_options' => [
                    'offline' => true,
                ]
            ]
        );
    }

    public function tearDown(): void
    {
        /** @var IpfsAdapter $adapter */
        $adapter = $this->adapter();

        $this->clearStorage();
        $this->clearCustomAdapter();
        $adapter->getClient()->files()->flush();

        foreach (array_column($adapter->getClient()->key()->list()['Keys'], 'Name') as $key) {
            if ($key !== 'self') {
                $adapter->getClient()->key()->rm($key);
            }
        }
    }

    public function gatewaySettingsProvider(): iterable
    {
        yield ['ipfs', 'path', null, false];
        yield ['ipfs', 'subdomain', null, false];
        yield ['ipfs', 'dnslink', 'my-ipfs-domain.com', true];
        yield ['ipfs', 'dnslink', 'my-ipfs-domain.com', false];
        yield ['ipns', 'path', null, false];
        yield ['ipns', 'subdomain', null, false];
        yield ['ipns', 'dnslink', 'my-ipfs-domain.com', true];
        yield ['ipns', 'dnslink', 'my-ipfs-domain.com', false];
    }

    /**
     * @dataProvider gatewaySettingsProvider
     */
    public function test_it_get_gateway_urls(string $service, string $style, ?string $domain, bool $preferDomain): void
    {
        $this->runScenario(function () use ($service, $style, $domain, $preferDomain) {
            /** @var IpfsAdapter $adapter */
            $adapter = $this->adapter();

            $adapter->createDir('/some/deep/nested/dir', new Config());
            $adapter->write('/some/deep/nested/path.txt', 'contents', new Config());
            $url = $adapter->getGatewayUrl('/some/deep/nested/', 'path.txt', new Config([
                'gateway' => [
                    'service' => $service,
                    'style' => $style,
                    'domain' => $domain,
                    'prefer_domain' => $preferDomain,
                ]
            ]));

            $this->assertIsString($url);
        });
    }

    public function test_it_get_temporary_url(): void
    {
        $this->runScenario(function () {
            /** @var IpfsAdapter $adapter */
            $adapter = $this->adapter();

            $adapter->createDir('/some/deep/nested/dir', new Config());
            $adapter->write('/some/deep/nested/path.txt', 'contents', new Config());

            $adapter->getClient()->key()->gen('myfile');
            $url = $adapter->getTemporaryUrl('/some/deep/nested/', 'path.txt', new Config([
                'key' => 'myfile',
                'publish_options' => [
                    'lifetime' => '60s',
                    'offline' => true,
                ]
            ]));

            $this->assertIsString($url);
        });
    }
}
