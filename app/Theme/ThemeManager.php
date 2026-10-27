<?php

declare(strict_types=1);

namespace App\Theme;

use App\Models\PlatformStaff;
use App\Settings\SettingsRegistry;
use App\Settings\SettingsStore;
use App\Support\JsonValues;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Draft, preview and publish for the theme, website and SEO settings
 * (PLATFORM_CONSOLE.md § 9.8). Edits go into one draft; the public site
 * keeps reading the published values until a staff member publishes. Each
 * publish writes the changed keys through the settings store (validated
 * and audited) and keeps a numbered snapshot that can be restored.
 */
final class ThemeManager
{
    /** Keys that are drafted and published together. */
    public const array PREFIXES = ['theme.', 'site.', 'seo.'];

    public const string TABLE = 'setting_versions';

    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly SettingsStore $settings,
        private readonly DatabaseManager $db,
    ) {}

    /** @return list<string> */
    public function keys(): array
    {
        return array_values(array_filter(
            array_keys($this->registry->all()),
            fn (string $key): bool => $this->isVersioned($key),
        ));
    }

    public function isVersioned(string $key): bool
    {
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function published(): Theme
    {
        return new Theme($this->publishedValues());
    }

    /** @return array<string, mixed> */
    public function publishedValues(): array
    {
        $values = [];

        foreach ($this->keys() as $key) {
            $values[$key] = $this->settings->get($key);
        }

        return $values;
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        $values = [];

        foreach ($this->keys() as $key) {
            $values[$key] = $this->registry->get($key)->default;
        }

        return $values;
    }

    public function hasDraft(): bool
    {
        return $this->draftRow() !== null;
    }

    /** @return array<string, mixed>|null the draft's full set of values */
    public function draftValues(): ?array
    {
        $row = $this->draftRow();

        return $row === null ? null : $this->decode($row->keys ?? null) + $this->publishedValues();
    }

    /** The draft, or the published theme when there is no draft. */
    public function draftTheme(): Theme
    {
        return new Theme($this->draftValues() ?? $this->publishedValues());
    }

    /**
     * Merges changes into the draft (created from the published values when
     * there is none). Every value is validated now, so publish cannot fail on
     * a bad value later.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed> the draft's values
     *
     * @throws ValidationException
     */
    public function saveDraft(array $changes, ?PlatformStaff $by = null): array
    {
        $values = $this->draftValues() ?? $this->publishedValues();
        $audit = [];

        foreach ($changes as $key => $value) {
            if (! $this->isVersioned($key)) {
                throw new InvalidArgumentException("{$key} is not drafted with the theme; it applies on save.");
            }

            $value = $this->settings->validated($key, $value);

            if (! JsonValues::same($values[$key] ?? null, $value)) {
                $audit[$key] = ['old' => $values[$key] ?? null, 'new' => $value];
            }

            $values[$key] = $value;
        }

        $this->writeDraft($values, $by);

        // Every save is audited with before and after (PLATFORM_CONSOLE.md § 9.1).
        if ($audit !== []) {
            $log = activity('theme')->event('draft_saved')->withProperties(['changes' => $audit]);

            if ($by !== null) {
                $log->causedBy($by);
            }

            $log->log('theme draft saved: '.implode(', ', array_keys($audit)));
        }

        return $values;
    }

    /**
     * Every set of values still in use: the published theme, the draft and
     * each stored version (so brand images they name are kept).
     *
     * @return list<array<string, mixed>>
     */
    public function valueSets(): array
    {
        $sets = [$this->publishedValues()];

        foreach ($this->connection()->table(self::TABLE)->get(['keys']) as $row) {
            $sets[] = $this->decode($row->keys ?? null);
        }

        return $sets;
    }

    /** Starts a draft from an earlier published version. */
    public function restore(int $version, ?PlatformStaff $by = null): void
    {
        $row = $this->connection()->table(self::TABLE)->where('version', $version)->first();

        if ($row === null) {
            throw new InvalidArgumentException("There is no published version {$version}.");
        }

        $this->writeDraft($this->decode($row->keys ?? null) + $this->defaults(), $by);
    }

    /** Starts a draft with the shipped defaults (PLATFORM_CONSOLE.md § 9.9). */
    public function resetDraftToDefaults(?PlatformStaff $by = null): void
    {
        $this->writeDraft($this->defaults(), $by);
    }

    public function discardDraft(): void
    {
        $this->connection()->table(self::TABLE)->where('status', 'draft')->delete();
    }

    /**
     * Makes the draft live and returns the new version number.
     *
     * @throws ValidationException
     */
    public function publish(?PlatformStaff $by = null): int
    {
        $draft = $this->draftValues() ?? throw new RuntimeException('There is no draft to publish.');

        return $this->connection()->transaction(function () use ($draft, $by): int {
            $published = $this->publishedValues();

            foreach ($draft as $key => $value) {
                if ($this->isVersioned($key) && $this->registry->has($key) && ! JsonValues::same($published[$key], $value)) {
                    $this->settings->set($key, $value);
                }
            }

            $connection = $this->connection();
            $version = ((int) $connection->table(self::TABLE)->max('version')) + 1;
            $now = now();

            $connection->table(self::TABLE)->where('status', 'published')->update(['status' => 'archived', 'updated_at' => $now]);
            $connection->table(self::TABLE)->where('status', 'draft')->delete();
            $connection->table(self::TABLE)->insert([
                'version' => $version,
                'status' => 'published',
                'keys' => json_encode($this->publishedValues(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_by_staff_id' => $by?->id,
                'published_by_staff_id' => $by?->id,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $log = activity('theme')->event('published')->withProperties(['version' => $version]);

            if ($by !== null) {
                $log->causedBy($by);
            }

            $log->log("theme version {$version} published");

            return $version;
        });
    }

    /** @return list<array{version: int, status: string, published_at: string|null, published_by_staff_id: int|null}> */
    public function versions(): array
    {
        return array_values($this->connection()->table(self::TABLE)
            ->whereNotNull('version')
            ->orderByDesc('version')
            ->get(['version', 'status', 'published_at', 'published_by_staff_id'])
            ->map(fn (object $row): array => [
                'version' => (int) $row->version,
                'status' => (string) $row->status,
                'published_at' => is_string($row->published_at) ? $row->published_at : null,
                'published_by_staff_id' => is_numeric($row->published_by_staff_id) ? (int) $row->published_by_staff_id : null,
            ])
            ->all());
    }

    /** @param  array<string, mixed>  $values */
    private function writeDraft(array $values, ?PlatformStaff $by): void
    {
        $values = array_intersect_key($values, array_flip($this->keys()));
        $encoded = json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $now = now();
        $connection = $this->connection();

        if ($connection->table(self::TABLE)->where('status', 'draft')->exists()) {
            $connection->table(self::TABLE)->where('status', 'draft')->update(['keys' => $encoded, 'created_by_staff_id' => $by?->id, 'updated_at' => $now]);

            return;
        }

        $connection->table(self::TABLE)->insert([
            'version' => null,
            'status' => 'draft',
            'keys' => $encoded,
            'created_by_staff_id' => $by?->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function draftRow(): ?object
    {
        return $this->connection()->table(self::TABLE)->where('status', 'draft')->first();
    }

    /** @return array<string, mixed> */
    private function decode(mixed $json): array
    {
        $values = is_string($json) ? json_decode($json, true) : null;

        return is_array($values) ? array_filter($values, fn ($key): bool => is_string($key) && $this->registry->has($key), ARRAY_FILTER_USE_KEY) : [];
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection();
    }
}
