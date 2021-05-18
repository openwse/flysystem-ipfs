<?php

namespace FlysystemIpfs;

use Ipfs\Ipfs;
use Ipfs\IpfsException;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Config;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use LogicException;

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class IpfsAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;

    protected Ipfs $client;

    protected array $config;

    public function __construct(Ipfs $client, string $prefix = '', array $config = [])
    {
        $this->client = $client;
        $this->setPathPrefix($prefix);
        $this->config = array_replace_recursive([
            // low-level local pin (without additional config)
            // see: https://docs.ipfs.io/concepts/persistence/#pinning-in-context
            'auto_pin' => true,
            // you can configure a remote service for pinning, make sure you add it before
            // see: https://docs.ipfs.io/how-to/work-with-pinning-services/#adding-a-new-pinning-service
            'pin_options' => [
                'remote' => false,
                'service' => null,
            ],
            // add files/directories to the MFS
            // see: https://docs.ipfs.io/concepts/file-systems/#mutable-file-system-mfs
            'auto_copy' => true,
            // auto_override will delete old file before adding a new one when calling rename or copy
            // Note that there isn't true override for IPFS but you can use IPNS to solve that.
            'auto_override' => true,
            // InterPlanetary Name System
            // see: https://docs.ipfs.io/concepts/ipns/#example-ipns-setup-with-cli
            'auto_publish' => false,
            'publish_options' => [
                'lifetime' => '24h',
                'offline' => false,
                'allow_offline' => true,
            ],
            // resolve urls via configured gateway
            // see: https://docs.ipfs.io/concepts/ipfs-gateway/#gateway-services
            'gateway' => [
                'service' => 'ipfs',
                'style' => 'path',
                'url' => GatewayResolver::DEFAULT_GATEWAY,
                'domain' => null,
                'prefer_domain' => false,
            ],
        ], $config);
    }

    public function getClient(): Ipfs
    {
        return $this->client;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function write($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, $config);
    }

    public function writeStream($path, $resource, Config $config)
    {
        return $this->upload($path, $resource, $config);
    }

    public function update($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, $config);
    }

    public function updateStream($path, $resource, Config $config)
    {
        return $this->upload($path, $resource, $config);
    }

    public function rename($path, $newpath): bool
    {
        $path = $this->applyPathPrefix($path);
        $newPath = $this->applyPathPrefix($newpath);

        try {
            $this->client->files()->mv($path, $newPath);
        } catch (IpfsException $exception) {
            return false;
        }

        return true;
    }

    public function copy($path, $newpath): bool
    {
        $path = $this->applyPathPrefix($path);
        $newPath = $this->applyPathPrefix($newpath);

        try {
            if ($this->has($newPath)) {
                $this->client->files()->rm($newPath);
            }

            $this->client->files()->cp($path, $newPath);
        } catch (IpfsException $exception) {
            return false;
        }

        return true;
    }

    public function delete($path): bool
    {
        $location = $this->applyPathPrefix($path);

        try {
            $this->client->files()->rm($location, true);
        } catch (IpfsException $exception) {
            return false;
        }

        return true;
    }

    public function deleteDir($dirname): bool
    {
        return $this->delete($dirname);
    }

    public function createDir($dirname, Config $config): bool
    {
        $location = $this->applyPathPrefix($dirname);

        try {
            $this->client->files()->mkdir($location, true);
        } catch (IpfsException $exception) {
            return false;
        }

        return true;
    }

    public function has($path)
    {
        return $this->getMetadata($path);
    }

    public function read($path)
    {
        $location = $this->applyPathPrefix($path);

        try {
            $file = $this->client->files()->read($location);
        } catch (IpfsException $exception) {
            return false;
        }

        return [
            /* @phpstan-ignore-next-line */
            'contents' => $file['Content'] ?? '',
        ];
    }

    public function readStream($path)
    {
        $location = $this->applyPathPrefix($path);

        try {
            $stream = $this->client->files()->read($location, true);
        } catch (IpfsException $exception) {
            return false;
        }

        return [
            'stream' => $stream,
        ];
    }

    public function listContents($directory = '', $recursive = false): array
    {
        $location = $this->applyPathPrefix($directory);

        try {
            $result = $this->client->files()->ls($location, true, true);
        } catch (IpfsException $exception) {
            return [];
        }

        $entries = array_map(function ($entry) use ($location) {
            return $this->normalizeResponse($entry, $location);
        }, $result['Entries'] ?? []);

        if ($recursive) {
            foreach ($entries as $entry) {
                if ($entry['type'] === 'dir') {
                    $entries = array_merge($entries, $this->listContents($entry['path'], $recursive));
                }
            }
        }

        return $entries;
    }

    public function getMetadata($path)
    {
        $location = $this->applyPathPrefix($path);

        try {
            $result = $this->client->files()->stat($location);
            $result['Name'] = basename($location);
        } catch (IpfsException $exception) {
            return false;
        }

        return $this->normalizeResponse($result, dirname($location));
    }

    public function getSize($path)
    {
        $result = $this->getMetadata($path);
        if (is_array($result) && $result['type'] === 'dir') {
            return false;
        }

        return $result;
    }

    public function getMimetype($path)
    {
        if (! is_array($this->has($path))) {
            return false;
        }

        $mimetype = (new FinfoMimeTypeDetector())->detectMimeTypeFromPath($path);
        if (is_null($mimetype)) {
            return false;
        }

        return ['mimetype' => $mimetype];
    }

    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * @return false|string
     */
    public function getTemporaryUrl(string $path, ?string $file = null, ?Config $config = null)
    {
        $attributes = $this->getMetadata($path);
        if (! is_array($attributes)) {
            return false;
        }

        $parameters = $this->getMergedConfig($config);

        $publishResult = $this->client->name()->publish(
            '/ipfs/'.$attributes['hash'],
            $parameters['publish_options']['lifetime'],
            $this->getPublishKey($path, $file, $config),
            $parameters['publish_options']['offline'],
            $parameters['publish_options']['allow_offline']
        );

        $resolver = new GatewayResolver('ipns', $parameters['gateway']['style'], [
            'url' => $parameters['gateway']['url'],
            'domain' => $parameters['gateway']['domain'],
            'prefer_domain' => $parameters['gateway']['prefer_domain'],
        ]);

        return $resolver->resolve($path, $file, $attributes['hash'], $publishResult['Name']);
    }

    /**
     * @return false|string
     */
    public function getUrl(string $path, ?string $file = null)
    {
        $attributes = $this->getMetadata($path);
        if (! is_array($attributes)) {
            return false;
        }

        $url = sprintf('ipfs://ipfs/%1$s', $attributes['hash']);

        if (! is_null($file)) {
            $fileLocation = $this->applyPathPrefix($path).'/'.trim($file, '/');
            $fileExists = $this->has($fileLocation);
            if (is_array($fileExists) && $fileExists['type'] === 'file') {
                $url .= '/'.trim($file, '/');
            }
        }

        return $url;
    }

    /**
     * @see https://docs.ipfs.io/concepts/ipfs-gateway/#gateway-services
     *
     * @return false|string
     */
    public function getGatewayUrl(string $path, ?string $file = null, ?Config $config = null)
    {
        $attributes = $this->getMetadata($path);
        if (! is_array($attributes)) {
            return false;
        }

        $parameters = $this->getMergedConfig($config);
        $resolver = new GatewayResolver($parameters['gateway']['service'], $parameters['gateway']['style'], [
            'url' => $parameters['gateway']['url'],
            'domain' => $parameters['gateway']['domain'],
            'prefer_domain' => $parameters['gateway']['prefer_domain'],
        ]);

        $ipns = $parameters['ipns'];
        if ($resolver->getService() === 'ipns' && in_array($resolver->getStyle(), ['path', 'subdomain']) && is_null($ipns)) {
            if ($parameters['auto_publish'] !== true) {
                throw new LogicException(get_class($this).' an IPNS is required to resolve this service/style of gateway. Either auto_publish or provide an IPNS in the Config.');
            }

            $result = $this->client->name()->publish(
                '/ipfs/'.$attributes['hash'],
                $parameters['publish_options']['lifetime'],
                $this->getPublishKey($path, uniqid(), $config),
                $parameters['publish_options']['offline'],
                $parameters['publish_options']['allow_offline']
            );
            $ipns = $result['Name'];
        }

        return $resolver->resolve($path, $file, $attributes['hash'], $ipns);
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @param string|resource $contents
     *
     * @return array|false
     */
    protected function upload(string $path, $contents, Config $config)
    {
        $location = $this->applyPathPrefix($path);

        $parameters = $this->getMergedConfig($config);

        try {
            $remotePinning = isset($parameters['pin_options']['remote'])
                && isset($parameters['pin_options']['service'])
                && $parameters['pin_options']['remote'] === true
                && ! empty($parameters['pin_options']['service'])
            ;
            $result = $this->client->add([[$location, null, $contents]], $parameters['auto_pin'] === true && ! $remotePinning);
            if ($parameters['auto_pin'] === true && $remotePinning) {
                $this->client->pin()->remote($parameters['pin_options']['service'])
                    ->add($result['Hash'])
                ;
            }

            if ($parameters['auto_copy'] === true) {
                if ($this->has($location) && $parameters['auto_override'] === true) {
                    $this->client->files()->rm($location);
                }

                $this->client->files()->mkdir(dirname($location), true);
                $this->client->files()->cp('/ipfs/'.$result['Hash'], $location);
            }

            if ($parameters['auto_publish'] === true) {
                $this->client->name()->publish(
                    '/ipfs/'.$result['hash'],
                    $parameters['publish_options']['lifetime'],
                    $this->getPublishKey($path, uniqid(), $config),
                    $parameters['publish_options']['offline'],
                    $parameters['publish_options']['allow_offline']
                );
            }
        } catch (IpfsException $exception) {
            return false;
        }

        return $this->normalizeResponse($result);
    }

    /**
     * @SuppressWarnings(PHPMD.ElseExpression)
     */
    protected function normalizeResponse(array $response, string $path = ''): array
    {
        $normalizedPath = ltrim($this->removePathPrefix(trim($path, '/').'/'.$response['Name']), '/');

        $normalizedResponse = [
            'path' => $normalizedPath,
            'hash' => $response['Hash'],
            'timestamp' => time(),
        ];

        if (isset($response['Size'])) {
            $normalizedResponse['size'] = $response['Size'];
        }

        if (is_int($response['Type'])) {
            $type = ($response['Type'] === 1) ? 'dir' : 'file';
        } else {
            $type = ($response['Type'] === 'directory') ? 'dir' : 'file';
        }
        $normalizedResponse['type'] = $type;

        return $normalizedResponse;
    }

    private function getPublishKey(string $path, ?string $file = null, ?Config $config = null): string
    {
        $key = is_null($config) ? null : $config->get('key');
        if (is_null($key)) {
            $fullPath = $this->applyPathPrefix($path).'/'.trim($file ?? '', '/');
            /* @phpstan-ignore-next-line */
            $key = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '', $fullPath)));
            $this->client->key()->gen($key);
        }

        return $key;
    }

    private function getMergedConfig(?Config $config = null): array
    {
        $parameters = $this->config;

        if (! is_null($config)) {
            foreach ($parameters as $key => $parameter) {
                if ($config->has($key)) {
                    if (is_array($parameter)) {
                        $subConfig = $config->get($key);
                        foreach ($parameter as $k => $v) {
                            if (isset($subConfig[$k])) {
                                $parameters[$key][$k] = $subConfig[$k];
                            }
                        }

                        continue;
                    }

                    $parameters[$key] = $config->get($key);
                }
            }
        }

        return $parameters;
    }

    public function applyPathPrefix($path): string
    {
        $path = parent::applyPathPrefix($path);

        return '/'.trim($path, '/');
    }
}
