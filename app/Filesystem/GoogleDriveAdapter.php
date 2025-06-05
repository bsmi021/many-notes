<?php

declare(strict_types=1);

namespace App\Filesystem;

use Google_Service_Drive;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToRetrieveMetadata;

class GoogleDriveAdapter implements FilesystemAdapter
{
    protected Google_Service_Drive $service;

    public function __construct(Google_Service_Drive $service)
    {
        $this->service = $service;
    }

    public function fileExists(string $path): bool
    {
        // TODO: Implement fileExists() method.
        return false;
    }

    public function directoryExists(string $path): bool
    {
        // TODO: Implement directoryExists() method.
        return false;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        // TODO: Implement write() method.
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        // TODO: Implement writeStream() method.
    }

    public function read(string $path): string
    {
        // TODO: Implement read() method.
        return '';
    }

    public function readStream(string $path)
    {
        // TODO: Implement readStream() method.
        return false;
    }

    public function delete(string $path): void
    {
        // TODO: Implement delete() method.
    }

    public function deleteDirectory(string $path): void
    {
        // TODO: Implement deleteDirectory() method.
    }

    public function createDirectory(string $path, Config $config): void
    {
        // TODO: Implement createDirectory() method.
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // TODO: Implement setVisibility() method.
    }

    public function visibility(string $path): FileAttributes
    {
        // TODO: Implement visibility() method.
        throw UnableToRetrieveMetadata::visibility($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        // TODO: Implement mimeType() method.
        throw UnableToRetrieveMetadata::mimeType($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        // TODO: Implement lastModified() method.
        throw UnableToRetrieveMetadata::lastModified($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        // TODO: Implement fileSize() method.
        throw UnableToRetrieveMetadata::fileSize($path);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        // TODO: Implement listContents() method.
        return [];
    }

    public function move(string $source, string $destination, Config $config): void
    {
        // TODO: Implement move() method.
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        // TODO: Implement copy() method.
    }
}
