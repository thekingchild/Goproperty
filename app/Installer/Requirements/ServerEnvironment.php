<?php

declare(strict_types=1);

namespace App\Installer\Requirements;

/** What the requirements checker asks of PHP and the host; faked in tests. */
interface ServerEnvironment
{
    public function phpVersion(): string;

    public function extensionLoaded(string $extension): bool;

    public function functionAvailable(string $function): bool;

    public function ini(string $setting): string;

    /** Null when the host does not say. */
    public function freeDiskBytes(string $path): ?float;

    public function opcacheEnabled(): bool;
}
