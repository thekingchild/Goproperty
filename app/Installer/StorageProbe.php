<?php

declare(strict_types=1);

namespace App\Installer;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Test" for file storage (INSTALLER.md § 5, step 5): writes, reads back and
 * deletes a small file in the bucket. Any failure is explained in plain
 * words with the keys removed.
 */
final class StorageProbe
{
    /** @var Closure(array<string, mixed>): Filesystem */
    private readonly Closure $disk;

    /**
     * @param  (Closure(array<string, mixed>): Filesystem)|null  $disk  builds the disk (tests use a local one)
     */
    public function __construct(FilesystemManager $filesystems, ?Closure $disk = null)
    {
        $this->disk = $disk ?? static fn (array $config): Filesystem => $filesystems->build($config);
    }

    /** @throws InstallationException */
    public function test(StorageSettings $settings): void
    {
        $path = '.goproperty-install-check-'.Str::lower(Str::random(12)).'.txt';
        $contents = 'GoProperty storage check '.Str::random(24);

        try {
            $disk = ($this->disk)($settings->diskConfig());
            $disk->put($path, $contents);

            if ($disk->get($path) !== $contents) {
                throw new InstallationException('The file read back from the bucket was not the one written.');
            }

            $disk->delete($path);

            if ($disk->exists($path)) {
                throw new InstallationException('The test file could not be deleted from the bucket. Give the keys delete permission.');
            }
        } catch (InstallationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InstallationException(self::explain($exception, $settings));
        }
    }

    public static function explain(Throwable $exception, StorageSettings $settings): string
    {
        $message = '';

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $message .= ' '.$current->getMessage();
        }

        return match (true) {
            preg_match('/InvalidAccessKeyId|SignatureDoesNotMatch|InvalidToken|AuthorizationHeaderMalformed/i', $message) === 1 => 'The access key or secret was refused.',
            preg_match('/NoSuchBucket/i', $message) === 1 => 'That bucket does not exist. Create it first, or check the region.',
            preg_match('/AccessDenied|Forbidden|403/i', $message) === 1 => 'These keys may not write to that bucket. Give them read, write and delete access to it.',
            preg_match('/cURL error|Could not resolve|Connection refused|timed out|getaddrinfo/i', $message) === 1 => 'Cannot reach the storage service. Check the endpoint address.',
            default => 'The storage service said: '.mb_strimwidth(trim($settings->scrub($message)), 0, 300, '…'),
        };
    }
}
