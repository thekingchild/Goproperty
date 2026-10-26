<?php

declare(strict_types=1);

namespace App\Backup\GoogleDrive;

use DateTimeImmutable;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use Throwable;

/**
 * A Flysystem adapter over Google Drive for backups (PLATFORM_CONSOLE.md
 * § 4): paths such as "goproperty-db/2026-10-26-01-30-00.zip" map onto
 * folders and files under one folder of a Shared Drive. Enough for
 * spatie/laravel-backup: write, read, list, size, date, delete.
 */
final class GoogleDriveAdapter implements FilesystemAdapter
{
    /** @var array<string, string> path => folder id */
    private array $folders = [];

    public function __construct(
        private readonly DriveClient $client,
        private readonly string $rootFolderId,
    ) {}

    public function fileExists(string $path): bool
    {
        return $this->file($path) !== null;
    }

    public function directoryExists(string $path): bool
    {
        return $this->folderId($path, create: false) !== null;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw UnableToWriteFile::atLocation($path, 'no temporary stream');
        }

        fwrite($stream, $contents);
        rewind($stream);
        $this->writeStream($path, $stream, $config);
        fclose($stream);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            [$directory, $name] = $this->split($path);
            $parent = (string) $this->folderId($directory, create: true);
            $existing = $this->file($path);
            $this->client->upload($parent, $name, $contents);

            // Drive allows two files with one name: keep only the new one.
            if ($existing !== null) {
                $this->client->delete($existing['id']);
            }
        } catch (Throwable $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function read(string $path): string
    {
        $stream = $this->readStream($path);
        $contents = (string) stream_get_contents($stream);
        fclose($stream);

        return $contents;
    }

    public function readStream(string $path)
    {
        $file = $this->file($path) ?? throw UnableToReadFile::fromLocation($path, 'not found');

        try {
            return $this->client->download($file['id']);
        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function delete(string $path): void
    {
        $file = $this->file($path);

        if ($file === null) {
            return;
        }

        try {
            $this->client->delete($file['id']);
        } catch (Throwable $exception) {
            throw UnableToDeleteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function deleteDirectory(string $path): void
    {
        $id = $this->folderId($path, create: false);

        if ($id !== null) {
            $this->client->delete($id);
            unset($this->folders[trim($path, '/')]);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->folderId($path, create: true);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'Backups in Google Drive are private to the Shared Drive.');
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, 'private');
    }

    public function mimeType(string $path): FileAttributes
    {
        $file = $this->file($path) ?? throw UnableToRetrieveMetadata::mimeType($path, 'not found');

        return new FileAttributes($path, null, null, null, $file['mimeType'] !== '' ? $file['mimeType'] : 'application/octet-stream');
    }

    public function lastModified(string $path): FileAttributes
    {
        $file = $this->file($path) ?? throw UnableToRetrieveMetadata::lastModified($path, 'not found');

        return new FileAttributes($path, null, null, $this->timestamp($file['modifiedTime']));
    }

    public function fileSize(string $path): FileAttributes
    {
        $file = $this->file($path) ?? throw UnableToRetrieveMetadata::fileSize($path, 'not found');

        return new FileAttributes($path, isset($file['size']) ? (int) $file['size'] : null);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $path = trim($path, '/');
        $id = $this->folderId($path, create: false);

        if ($id === null) {
            return;
        }

        foreach ($this->client->children($id) as $item) {
            $itemPath = ltrim($path.'/'.$item['name'], '/');

            if ($item['mimeType'] === DriveClient::FOLDER) {
                $this->folders[$itemPath] = $item['id'];

                yield new DirectoryAttributes($itemPath, null, $this->timestamp($item['modifiedTime']));

                if ($deep) {
                    yield from $this->listContents($itemPath, true);
                }

                continue;
            }

            yield new FileAttributes($itemPath, isset($item['size']) ? (int) $item['size'] : null, 'private', $this->timestamp($item['modifiedTime']));
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        throw UnableToMoveFile::fromLocationTo($source, $destination);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        throw UnableToCopyFile::fromLocationTo($source, $destination);
    }

    /** @return array{id: string, name: string, mimeType: string, size?: string, modifiedTime: string}|null */
    private function file(string $path): ?array
    {
        [$directory, $name] = $this->split($path);
        $parent = $this->folderId($directory, create: false);

        if ($parent === null) {
            return null;
        }

        foreach ($this->client->children($parent, $name) as $item) {
            if ($item['mimeType'] !== DriveClient::FOLDER) {
                return $item;
            }
        }

        return null;
    }

    private function folderId(string $path, bool $create): ?string
    {
        $path = trim($path, '/');

        if ($path === '') {
            return $this->rootFolderId;
        }

        if (isset($this->folders[$path])) {
            return $this->folders[$path];
        }

        $parent = $this->rootFolderId;
        $walked = '';

        foreach (explode('/', $path) as $segment) {
            $walked = ltrim($walked.'/'.$segment, '/');

            if (isset($this->folders[$walked])) {
                $parent = $this->folders[$walked];

                continue;
            }

            $found = $this->client->children($parent, $segment, DriveClient::FOLDER)[0]['id'] ?? null;

            if ($found === null) {
                if (! $create) {
                    return null;
                }

                $found = $this->client->createFolder($parent, $segment);
            }

            $parent = $this->folders[$walked] = $found;
        }

        return $parent;
    }

    /** @return array{string, string} */
    private function split(string $path): array
    {
        $path = trim($path, '/');
        $slash = strrpos($path, '/');

        return $slash === false ? ['', $path] : [substr($path, 0, $slash), substr($path, $slash + 1)];
    }

    private function timestamp(string $iso): ?int
    {
        if ($iso === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(DATE_RFC3339_EXTENDED, $iso) ?: DateTimeImmutable::createFromFormat(DATE_RFC3339, $iso);

        return $date !== false ? $date->getTimestamp() : null;
    }
}
