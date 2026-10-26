<?php

declare(strict_types=1);

namespace App\Settings;

use App\Models\Organization;
use App\Support\SafeHtml;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Typed settings in the `settings` table (DATABASE_SCHEMA.md § 8). Values are
 * validated against their definition, secrets are encrypted before they
 * reach the database or the cache, reads are cached per scope, and every
 * change is written to the activity log with secret values left out.
 * Organization settings use the current organization unless one is given.
 */
final class SettingsStore
{
    public const string TABLE = 'settings';

    private const string SECRET_PLACEHOLDER = '[secret]';

    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly DatabaseManager $db,
        private readonly Cache $cache,
        private readonly StringEncrypter $encrypter,
        private readonly OrganizationContext $context,
    ) {}

    public function get(string $key, ?Organization $organization = null): mixed
    {
        $definition = $this->registry->get($key);
        [$scopeType, $scopeId] = $this->scope($definition, $organization);
        $stored = $this->stored($scopeType, $scopeId);

        if (! array_key_exists($key, $stored)) {
            return $definition->default;
        }

        $value = json_decode($stored[$key], true);

        if ($definition->secret && is_string($value)) {
            $value = json_decode($this->encrypter->decryptString($value), true);
        }

        return $value;
    }

    /**
     * The value as it would be stored: HTML fields cleaned, then checked
     * against the definition's type and rules.
     *
     * @throws InvalidArgumentException when the value has the wrong type
     * @throws ValidationException
     */
    public function validated(string $key, mixed $value): mixed
    {
        $definition = $this->registry->get($key);

        if ($value !== null && ! $definition->type->accepts($value)) {
            throw new InvalidArgumentException("{$key} takes a {$definition->type->value}, not ".get_debug_type($value).'.');
        }

        $value = $this->clean($definition, $value);
        $rules = ['value' => $definition->rules];

        foreach ($definition->itemRules as $path => $itemRules) {
            $rules['value.'.$path] = $itemRules;
        }

        Validator::make(['value' => $value], $rules, [], ['value' => $key])->validate();

        return $value;
    }

    /** @throws ValidationException */
    public function set(string $key, mixed $value, ?Organization $organization = null): void
    {
        $definition = $this->registry->get($key);
        [$scopeType, $scopeId] = $this->scope($definition, $organization);
        $value = $this->validated($key, $value);

        $old = $this->get($key, $organization);
        $encoded = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if ($definition->secret) {
            $encoded = (string) json_encode($this->encrypter->encryptString($encoded), JSON_THROW_ON_ERROR);
        }

        $now = now();
        $this->db->connection()->table(self::TABLE)->upsert(
            [['scope_type' => $scopeType, 'scope_id' => $scopeId, 'key' => $key, 'value' => $encoded, 'created_at' => $now, 'updated_at' => $now]],
            ['scope_type', 'scope_id', 'key'],
            ['value', 'updated_at'],
        );

        $this->forgetCache($scopeType, $scopeId);
        $this->audit('updated', $definition, $scopeType, $scopeId, $old, $value);
    }

    /** Back to the default. */
    public function forget(string $key, ?Organization $organization = null): void
    {
        $definition = $this->registry->get($key);
        [$scopeType, $scopeId] = $this->scope($definition, $organization);
        $old = $this->get($key, $organization);

        $deleted = $this->db->connection()->table(self::TABLE)
            ->where(['scope_type' => $scopeType, 'scope_id' => $scopeId, 'key' => $key])
            ->delete();

        $this->forgetCache($scopeType, $scopeId);

        if ($deleted > 0) {
            $this->audit('reset', $definition, $scopeType, $scopeId, $old, $definition->default);
        }
    }

    private function clean(SettingDefinition $definition, mixed $value): mixed
    {
        if ($definition->html === []) {
            return $value;
        }

        if (is_string($value) && in_array('', $definition->html, true)) {
            return SafeHtml::clean($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $index => $item) {
            if (is_array($item)) {
                foreach ($definition->html as $field) {
                    if ($field !== '' && is_string($item[$field] ?? null)) {
                        $item[$field] = SafeHtml::clean($item[$field]);
                    }
                }

                $value[$index] = $item;
            }
        }

        return $value;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function scope(SettingDefinition $definition, ?Organization $organization): array
    {
        if ($definition->scope === SettingScope::Global) {
            if ($organization !== null) {
                throw new InvalidArgumentException("{$definition->key} is a global setting, not an organization's.");
            }

            return [SettingScope::Global->value, 0];
        }

        return [SettingScope::Organization->value, ($organization ?? $this->context->get())->id];
    }

    /** @return array<string, string> key => stored JSON (secrets still encrypted) */
    private function stored(string $scopeType, int $scopeId): array
    {
        $rows = $this->cache->rememberForever(self::cacheKey($scopeType, $scopeId), fn (): array => $this->db->connection()
            ->table(self::TABLE)
            ->where(['scope_type' => $scopeType, 'scope_id' => $scopeId])
            ->pluck('value', 'key')
            ->all());

        return array_filter($rows, 'is_string');
    }

    private function forgetCache(string $scopeType, int $scopeId): void
    {
        $this->cache->forget(self::cacheKey($scopeType, $scopeId));
    }

    private static function cacheKey(string $scopeType, int $scopeId): string
    {
        return "settings:{$scopeType}:{$scopeId}";
    }

    private function audit(string $event, SettingDefinition $definition, string $scopeType, int $scopeId, mixed $old, mixed $new): void
    {
        activity('settings')
            ->event($event)
            ->withProperties([
                'key' => $definition->key,
                'scope' => $scopeType,
                'scope_id' => $scopeId,
                'old' => $definition->secret ? ($old === null ? null : self::SECRET_PLACEHOLDER) : $old,
                'new' => $definition->secret ? ($new === null ? null : self::SECRET_PLACEHOLDER) : $new,
            ])
            ->log("setting {$definition->key} {$event}");
    }
}
