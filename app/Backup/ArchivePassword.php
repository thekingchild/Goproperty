<?php

declare(strict_types=1);

namespace App\Backup;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The password of every backup zip (AES-256). BACKUP_ARCHIVE_PASSWORD when
 * set; otherwise derived from APP_KEY, which the owner must keep anyway
 * (encrypted columns need it). `goproperty:backup-password` prints it for a
 * restore by hand (docs/runbooks/restore.md).
 */
final readonly class ArchivePassword
{
    public function __construct(private Config $config) {}

    public function get(): string
    {
        $explicit = $this->config->get('backup.archive_password');

        if (is_string($explicit) && strlen($explicit) >= 20) {
            return $explicit;
        }

        $key = $this->config->get('app.key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('APP_KEY is not set; backups cannot be encrypted.');
        }

        $raw = Str::startsWith($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;

        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'goproperty-backup-archive', $raw, true)), '+/', '-_'), '=');
    }
}
