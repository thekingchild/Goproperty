<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Installer\Requirements\ServerEnvironment;

/** A simulated host for the installer's requirements checks. */
final class FakeServer
{
    /**
     * @param  array<string, mixed>  $overrides  php, missing, disabled, ini, disk, opcache
     */
    public static function make(array $overrides = []): ServerEnvironment
    {
        return new class($overrides) implements ServerEnvironment
        {
            /** @param  array<string, mixed>  $o */
            public function __construct(private array $o) {}

            public function phpVersion(): string
            {
                return $this->o['php'] ?? '8.4.27';
            }

            public function extensionLoaded(string $extension): bool
            {
                return ! in_array($extension, $this->o['missing'] ?? [], true);
            }

            public function functionAvailable(string $function): bool
            {
                return ! in_array($function, $this->o['disabled'] ?? [], true);
            }

            public function ini(string $setting): string
            {
                return ($this->o['ini'] ?? [])[$setting] ?? ['max_execution_time' => '60', 'memory_limit' => '512M', 'upload_max_filesize' => '64M'][$setting] ?? '';
            }

            public function freeDiskBytes(string $path): ?float
            {
                return array_key_exists('disk', $this->o) ? $this->o['disk'] : 50.0 * 1024 ** 3;
            }

            public function opcacheEnabled(): bool
            {
                return $this->o['opcache'] ?? true;
            }
        };
    }
}
