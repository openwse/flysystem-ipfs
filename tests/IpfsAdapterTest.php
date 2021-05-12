<?php

namespace FlysystemIpfs\Tests;

use FlysystemIpfs\IpfsAdapter;
use Ipfs\Drivers\HttpClient;
use Ipfs\Ipfs;
use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Visibility;

class IpfsAdapterTest extends FilesystemAdapterTestCase
{
    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        return new IpfsAdapter(
            new Ipfs(new HttpClient('http://ipfs', 5001))
        );
    }

    public function tearDown(): void
    {
        parent::tearDown();

        /** @var IpfsAdapter $adapter */
        $adapter = $this->adapter();

        $this->clearStorage();
        $this->clearCustomAdapter();
        $adapter->getClient()->files()->flush();
    }

    /**
     * @test
     */
    public function overwriting_a_file(): void
    {
        $this->runScenario(function () {
            $this->givenWeHaveAnExistingFile('path.txt', 'contents', ['visibility' => Visibility::PUBLIC]);
            $adapter = $this->adapter();

            $adapter->write('path.txt', 'new contents', new Config(['visibility' => Visibility::PUBLIC]));

            $contents = $adapter->read('path.txt');
            $this->assertEquals('new contents', $contents);
            $visibility = $adapter->visibility('path.txt')->visibility();
            $this->assertEquals(Visibility::PUBLIC, $visibility);
        });
    }

    /**
     * @test
     */
    public function setting_visibility(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();
            $this->givenWeHaveAnExistingFile('path.txt', 'contents', [Config::OPTION_VISIBILITY => Visibility::PUBLIC]);

            $this->assertEquals(Visibility::PUBLIC, $adapter->visibility('path.txt')->visibility());
        });
    }
}
