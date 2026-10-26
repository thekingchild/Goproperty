<?php

declare(strict_types=1);

namespace App\Settings;

use InvalidArgumentException;

/**
 * Every known setting. Core keys come from config/settings.php; modules add
 * theirs from their service providers with define(). Unknown keys cannot be
 * read or written.
 */
final class SettingsRegistry
{
    /** @var array<string, SettingDefinition> */
    private array $definitions = [];

    public function define(SettingDefinition $definition): void
    {
        if (isset($this->definitions[$definition->key])) {
            throw new InvalidArgumentException("The setting {$definition->key} is declared twice.");
        }

        $this->definitions[$definition->key] = $definition;
    }

    public function get(string $key): SettingDefinition
    {
        return $this->definitions[$key] ?? throw new InvalidArgumentException("Unknown setting {$key}: declare it in config/settings.php or a module's provider.");
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    /** @return array<string, SettingDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }
}
