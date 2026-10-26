<?php

declare(strict_types=1);

namespace App\Installer\Requirements;

final class PhpServerEnvironment implements ServerEnvironment
{
    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function extensionLoaded(string $extension): bool
    {
        return extension_loaded($extension);
    }

    public function functionAvailable(string $function): bool
    {
        // function_exists() is false for anything in disable_functions.
        return function_exists($function);
    }

    public function ini(string $setting): string
    {
        $value = ini_get($setting);

        return is_string($value) ? $value : '';
    }

    public function freeDiskBytes(string $path): ?float
    {
        if (! function_exists('disk_free_space')) {
            return null;
        }

        $free = @disk_free_space($path);

        return is_float($free) ? $free : null;
    }

    public function opcacheEnabled(): bool
    {
        return in_array(strtolower($this->ini('opcache.enable')), ['1', 'on', 'true'], true);
    }
}
