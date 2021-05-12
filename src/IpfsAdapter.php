<?php

namespace FlysystemIpfs;

use Generator;
use Ipfs\Ipfs;
use Ipfs\IpfsException;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity:)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class IpfsAdapter implements FilesystemAdapter
{
    protected Ipfs $client;

    protected PathPrefixer $prefixer;

    protected MimeTypeDetector $mimeTypeDetector;

    public function __construct(Ipfs $client, string $prefix = '', MimeTypeDetector $mimeTypeDetector = null)
    {
        $this->client = $client;
        $this->prefixer = new PathPrefixer($prefix);
        $this->mimeTypeDetector = $mimeTypeDetector ?: new FinfoMimeTypeDetector();
    }

    public function getClient(): Ipfs
    {
        return $this->client;
    }

    public function fileExists(string $path): bool
    {
        $location = $this->applyPathPrefix($path);

        try {
            $this->client->files()->stat($location);

            return true;
        } catch (IpfsException $exception) {
            return false;
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $location = $this->applyPathPrefix($path);

        try {
            $result = $this->client->add([
                [$location, null, $contents],
            ], $config->get('pin', false));

            if ($this->fileExists($location) && ! $config->get('no_override', false)) {
                $this->client->files()->rm($location);
            }

            if (! $config->get('no_copy', false)) {
                $this->client->files()->mkdir(dirname($location), true);
                $this->client->files()->cp('/ipfs/'.$result['Hash'], $location);
            }
        } catch (IpfsException $exception) {
            throw UnableToWriteFile::atLocation($location, $exception->getMessage(), $exception);
        }
    }

    /**
     * @param resource|string $contents
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $location = $this->applyPathPrefix($path);

        try {
            $result = $this->client->add([
                [$location, null, $contents],
            ], $config->get('pin', false));

            if ($this->fileExists($location) && ! $config->get('no_override', false)) {
                $this->client->files()->rm($location);
            }

            if (! $config->get('no_copy', false)) {
                $this->client->files()->cp('/ipfs/'.$result['Hash'], $location);
            }
        } catch (IpfsException $exception) {
            throw UnableToWriteFile::atLocation($location, $exception->getMessage(), $exception);
        }
    }

    public function read(string $path): string
    {
        $location = $this->applyPathPrefix($path);

        try {
            $file = $this->client->files()->read($location);
        } catch (IpfsException $exception) {
            throw UnableToReadFile::fromLocation($location, $exception->getMessage(), $exception);
        }

        /* @phpstan-ignore-next-line */
        return $file['Content'] ?? '';
    }

    public function readStream(string $path)
    {
        $location = $this->applyPathPrefix($path);

        try {
            $stream = $this->client->files()->read($location, true);
        } catch (IpfsException $exception) {
            throw UnableToReadFile::fromLocation($location, $exception->getMessage(), $exception);
        }

        /* @phpstan-ignore-next-line */
        return $stream;
    }

    public function delete(string $path): void
    {
        $location = $this->applyPathPrefix($path);

        try {
            $this->client->files()->rm($location, true);
        } catch (IpfsException $exception) {
            throw UnableToDeleteFile::atLocation($location, $exception->getMessage(), $exception);
        }
    }

    public function deleteDirectory(string $path): void
    {
        $location = $this->applyPathPrefix($path);

        try {
            $this->client->files()->rm($location, true);
        } catch (IpfsException $exception) {
            throw UnableToDeleteDirectory::atLocation($location, $exception->getMessage(), $exception);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        $location = $this->applyPathPrefix($path);

        try {
            $this->client->files()->mkdir($location, true);
        } catch (IpfsException $exception) {
            throw UnableToCreateDirectory::atLocation($location, $exception->getMessage());
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // throw UnableToSetVisibility::atLocation($path, 'Adapter does not support visibility controls.');
        $location = $this->applyPathPrefix($path);

        if (! $this->fileExists($location)) {
            throw new UnableToSetVisibility('File do not exists');
        }
    }

    public function visibility(string $path): FileAttributes
    {
        $location = $this->applyPathPrefix($path);

        if (! $this->fileExists($location)) {
            throw UnableToRetrieveMetadata::visibility($location, 'File do not exists');
        }

        // Noop
        return new FileAttributes(
            $path,
            null,
            Visibility::PUBLIC,
            time(),
        );
    }

    public function mimeType(string $path): FileAttributes
    {
        $location = $this->applyPathPrefix($path);

        if (! $this->fileExists($location)) {
            throw UnableToRetrieveMetadata::mimeType($location, 'File do not exists.');
        }

        $mime = $this->mimeTypeDetector->detectMimeTypeFromPath($path);
        if (is_null($mime)) {
            throw UnableToRetrieveMetadata::mimeType($location, 'Unknown mimetype.');
        }

        return new FileAttributes(
            $path,
            null,
            Visibility::PUBLIC,
            time(),
            $mime
        );
    }

    public function lastModified(string $path): FileAttributes
    {
        $location = $this->applyPathPrefix($path);

        try {
            $response = $this->client->files()->stat($location);
        } catch (IpfsException $exception) {
            throw UnableToRetrieveMetadata::lastModified($location, $exception->getMessage());
        }

        return new FileAttributes(
            $path,
            $response['Size'],
            Visibility::PUBLIC,
            time()
        );
    }

    public function fileSize(string $path): FileAttributes
    {
        $location = $this->applyPathPrefix($path);

        try {
            $response = $this->client->files()->stat($location);
            if ($response['Type'] === 'directory') {
                throw UnableToRetrieveMetadata::fileSize($location, 'Path is a directory');
            }
        } catch (IpfsException $exception) {
            throw UnableToRetrieveMetadata::fileSize($location, $exception->getMessage());
        }

        return new FileAttributes(
            $path,
            $response['Size'] ?? null,
            Visibility::PUBLIC,
            time()
        );
    }

    public function listContents(string $path, bool $deep = false): iterable
    {
        foreach ($this->iterateFolderContents($path, $deep) as $entry) {
            $storageAttrs = $this->normalizeResponse($path, $entry);

            // Avoid including the base directory itself
            if ($storageAttrs->isDir() && $storageAttrs->path() === $path) {
                continue;
            }

            yield $storageAttrs;
        }
    }

    protected function iterateFolderContents(string $path = '', bool $deep = false): Generator
    {
        $location = $this->applyPathPrefix($path);

        try {
            $result = $this->client->files()->ls($location, true, true);
        } catch (IpfsException $exception) {
            return;
        }

        yield from $result['Entries'] ?? [];

        if ($deep) {
            foreach ($result['Entries'] ?? [] as $entry) {
                if ($entry['Type'] === 1) {
                    yield from $this->iterateFolderContents(
                        trim($this->prefixer->stripDirectoryPrefix($entry['Name']), '/'),
                        $deep
                    );
                }
            }
        }
    }

    /**
     * @return DirectoryAttributes|FileAttributes
     */
    protected function normalizeResponse(string $path, array $response)
    {
        if ($response['Type'] === 1) {
            $normalizedPath = ltrim($path.'/'.$this->prefixer->stripDirectoryPrefix($response['Name']), '/');

            return new DirectoryAttributes(
                $normalizedPath,
                Visibility::PUBLIC,
                time(),
            );
        }

        $normalizedPath = ltrim($path.'/'.$this->prefixer->stripPrefix($response['Name']), '/');

        return new FileAttributes(
            $normalizedPath,
            $response['Size'] ?? null,
            Visibility::PUBLIC,
            time(),
            $this->mimeTypeDetector->detectMimeTypeFromPath($normalizedPath),
            [
                'hash' => $response['Hash'],
            ]
        );
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $path = $this->applyPathPrefix($source);
        $newPath = $this->applyPathPrefix($destination);

        try {
            $this->client->files()->mv($path, $newPath);
        } catch (IpfsException $exception) {
            throw UnableToMoveFile::fromLocationTo($path, $newPath, $exception);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $path = $this->applyPathPrefix($source);
        $newPath = $this->applyPathPrefix($destination);

        try {
            if ($this->fileExists($newPath) && ! $config->get('no_override', false)) {
                $this->client->files()->rm($newPath);
            }

            $this->client->files()->cp($path, $newPath);
        } catch (IpfsException $exception) {
            throw UnableToCopyFile::fromLocationTo($path, $newPath, $exception);
        }
    }

    protected function applyPathPrefix(string $path): string
    {
        return '/'.trim($this->prefixer->prefixPath($path), '/');
    }
}
