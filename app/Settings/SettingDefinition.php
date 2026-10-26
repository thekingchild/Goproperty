<?php

declare(strict_types=1);

namespace App\Settings;

use InvalidArgumentException;

/**
 * One setting, declared once (PLATFORM_CONSOLE.md § 9.8): type, default,
 * validation, scope, and whether it is a secret, which console permission
 * may change it and whether that needs a fresh password (step-up).
 */
final readonly class SettingDefinition
{
    /**
     * @param  list<mixed>  $rules  Laravel validation rules for the value
     */
    public function __construct(
        public string $key,
        public SettingType $type,
        public mixed $default = null,
        public array $rules = [],
        public SettingScope $scope = SettingScope::Global,
        public bool $secret = false,
        public ?string $permission = null,
        public bool $stepUp = false,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+$/D', $key) !== 1) {
            throw new InvalidArgumentException("Setting keys look like area.name (lowercase), not \"{$key}\".");
        }

        if ($default !== null && ! $type->accepts($default)) {
            throw new InvalidArgumentException("The default for {$key} is not a {$type->value}.");
        }
    }

    /**
     * @param  array<string, mixed>  $definition  as in config/settings.php
     */
    public static function fromArray(string $key, array $definition): self
    {
        $type = SettingType::from(is_string($definition['type'] ?? null) ? $definition['type'] : 'string');
        $scope = SettingScope::from(is_string($definition['scope'] ?? null) ? $definition['scope'] : 'global');
        $rules = is_array($definition['rules'] ?? null) ? array_values($definition['rules']) : [];
        $permission = $definition['permission'] ?? null;

        return new self(
            $key,
            $type,
            $definition['default'] ?? null,
            $rules,
            $scope,
            (bool) ($definition['secret'] ?? false),
            is_string($permission) ? $permission : null,
            (bool) ($definition['step_up'] ?? false),
        );
    }
}
