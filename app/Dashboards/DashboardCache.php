<?php

declare(strict_types=1);

namespace App\Dashboards;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use LogicException;
use ReflectionClass;
use ReflectionEnum;
use ReflectionEnumUnitCase;
use ReflectionNamedType;
use Throwable;
use UnitEnum;

/**
 * Cached dashboard figures (P08-04, ARCHITECTURE.md § 13): an
 * organization's figures are worked out once and then read from the
 * default cache store for five minutes, or until the organization's
 * dashboard generation changes (a relevant write, DashboardGenerations),
 * whichever comes first. A miss works them out through ReportConnection
 * (the read replica when there is one). No cache tags (the file and
 * database stores of shared hosting have none).
 *
 * Keys: each entry sits in a slot named by the organization, the figures'
 * name and the caller's slot (for the reports: the buildings covered), and
 * the slot holds the figures for up to KEYS_PER_SLOT keys (everything they
 * depend on: dates, today's date for "as of today" figures), the most
 * recently worked out last, MAX_BYTES at most together. A read uses an
 * entry only when both its key and its generation are the current ones, so
 * it never serves another organization, another set of buildings, other
 * dates or another day. Figures for a new key push out the oldest, and
 * those of an older generation go at once. That keeps the cache bounded
 * per organization (the database store of shared hosting removes expired
 * rows only when they are read again), while two members looking at
 * different dates no longer push each other's figures out on every load
 * (P09-03: one key per slot made each such load work them out again).
 *
 * Values: the cache store unserializes no objects (`cache.serializable_classes`
 * is false), so the figures are kept as a PHP-serialized string (deflated
 * when zlib is there), signed with the application key and read back
 * allowing only the classes the caller names (figures holding any other
 * class are not cached). The key also holds a fingerprint of those
 * classes' properties, so figures cached by an older release are never
 * read into changed classes. Figures larger than MAX_BYTES as stored are
 * not cached (worked out each time).
 */
final readonly class DashboardCache
{
    /** ARCHITECTURE.md § 13: cached aggregates, five minutes. */
    public const int TTL_SECONDS = 300;

    public const int MAX_BYTES = 4_000_000;

    /** Date ranges kept per slot (this month, last month, this year, …). */
    public const int KEYS_PER_SLOT = 4;

    private const string KEY = 'dashboards:';

    public function __construct(
        private Repository $cache,
        private DashboardGenerations $generations,
        private ReportConnection $connection,
        private Config $config,
    ) {}

    /**
     * The organization's figures named $name for $key: from the cache when
     * they were worked out for that key at the current generation, else
     * worked out now (and kept in the slot).
     *
     * @template TValue of object
     *
     * @param  array<mixed>  $slot  where the entry is kept (JSON-encodable; bounded)
     * @param  array<mixed>  $key  everything the figures depend on (JSON-encodable)
     * @param  class-string<TValue>  $expects
     * @param  list<class-string>  $classes  every class the figures hold, $expects included
     * @param  Closure(): TValue  $compute
     * @return TValue
     */
    public function remember(int $organizationId, string $name, array $slot, array $key, string $expects, array $classes, Closure $compute): object
    {
        // The generation first, the records after (see DashboardGenerations).
        try {
            $generation = $this->generations->current($organizationId);
        } catch (Throwable $failure) {
            // No cache to be had: the figures are worked out from the records.
            report($failure);

            return $compute();
        }

        $cacheKey = self::KEY.$organizationId.':'.$name.':'.hash('sha256', (string) json_encode($slot));
        $figuresKey = hash('sha256', (string) json_encode([$name, $slot, $key, self::fingerprint($classes)]));
        $cached = $this->read($cacheKey, $figuresKey, $generation, $expects, $classes);

        if ($cached !== null) {
            return $cached;
        }

        $value = $this->connection->run($generation, $compute);
        $unlisted = array_diff(self::classesIn($value), $classes);

        if ($unlisted !== []) {
            // A programming error (a class missing from the caller's list):
            // the figures would not read back, so they are not cached.
            report(new LogicException('Dashboard figures '.$name.' hold classes not listed for the cache: '.implode(', ', $unlisted).'.'));

            return $value;
        }

        [$encoding, $payload] = self::encode(serialize($value));

        if (strlen($payload) <= self::MAX_BYTES) {
            $this->keep($cacheKey, $figuresKey, [
                'generation' => $generation->token,
                'encoding' => $encoding,
                'payload' => base64_encode($payload),
                'signature' => $this->sign($generation->token, $figuresKey, $encoding, $payload),
            ]);
        }

        return $value;
    }

    /**
     * Adds the entry to its slot as the most recent: entries of other
     * generations go, then the oldest until KEYS_PER_SLOT and MAX_BYTES fit.
     *
     * @param  array<string, string>  $entry
     */
    private function keep(string $cacheKey, string $figuresKey, array $entry): void
    {
        try {
            $kept = [];

            foreach ($this->entries($cacheKey) as $key => $other) {
                if ($key !== $figuresKey && ($other['generation'] ?? null) === $entry['generation']) {
                    $kept[$key] = $other;
                }
            }

            $kept[$figuresKey] = $entry;

            while (count($kept) > 1 && (count($kept) > self::KEYS_PER_SLOT
                || array_sum(array_map(static fn (array $one): int => strlen((string) ($one['payload'] ?? '')), $kept)) > self::MAX_BYTES * 4 / 3)) {
                unset($kept[array_key_first($kept)]);
            }

            $this->cache->put($cacheKey, ['entries' => $kept], self::TTL_SECONDS);
        } catch (Throwable $failure) {
            report($failure);
        }
    }

    /** @return array<string, array<string, mixed>> figures key => entry, oldest first */
    private function entries(string $cacheKey): array
    {
        $slot = $this->cache->get($cacheKey);
        $entries = is_array($slot) && is_array($slot['entries'] ?? null) ? $slot['entries'] : [];

        return array_filter($entries, static fn (mixed $entry, mixed $key): bool => is_string($key) && is_array($entry), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @template TValue of object
     *
     * @param  class-string<TValue>  $expects
     * @param  list<class-string>  $classes
     * @return TValue|null
     */
    private function read(string $cacheKey, string $figuresKey, DashboardGeneration $generation, string $expects, array $classes): ?object
    {
        try {
            $entry = $this->entries($cacheKey)[$figuresKey] ?? null;
        } catch (Throwable $failure) {
            report($failure);

            return null;
        }

        if (! is_array($entry) || ($entry['generation'] ?? null) !== $generation->token) {
            return null;
        }

        $encoding = $entry['encoding'] ?? null;
        $payload = is_string($entry['payload'] ?? null) ? base64_decode($entry['payload'], true) : false;
        $signature = $entry['signature'] ?? null;

        if (! is_string($encoding) || ! is_string($payload) || ! is_string($signature)
            || ! hash_equals($this->sign($generation->token, $figuresKey, $encoding, $payload), $signature)) {
            return null;
        }

        try {
            $serialized = self::decode($encoding, $payload);
            $value = $serialized === null ? null : unserialize($serialized, ['allowed_classes' => $classes]);
        } catch (Throwable) {
            return null;
        }

        return $value instanceof $expects ? $value : null;
    }

    /** @return array{string, string} the encoding and the bytes to keep */
    private static function encode(string $serialized): array
    {
        if (function_exists('gzdeflate')) {
            $deflated = gzdeflate($serialized, 6);

            if (is_string($deflated)) {
                return ['deflate', $deflated];
            }
        }

        return ['none', $serialized];
    }

    private static function decode(string $encoding, string $payload): ?string
    {
        return match ($encoding) {
            'none' => $payload,
            'deflate' => function_exists('gzinflate') && is_string($inflated = gzinflate($payload)) ? $inflated : null,
            default => null,
        };
    }

    private function sign(string $generation, string $figuresKey, string $encoding, string $payload): string
    {
        return hash_hmac('sha256', $generation."\n".$figuresKey."\n".$encoding."\n".$payload, 'dashboards|'.(string) $this->config->get('app.key'));
    }

    /**
     * Every class of the objects in $value, nested ones included.
     *
     * @return list<string>
     */
    private static function classesIn(mixed $value): array
    {
        $classes = [];
        $seen = [];
        $pending = [$value];

        while ($pending !== []) {
            $item = array_pop($pending);

            if (is_array($item)) {
                array_push($pending, ...array_values($item));

                continue;
            }

            if (! is_object($item) || isset($seen[spl_object_id($item)])) {
                continue;
            }

            $seen[spl_object_id($item)] = true;
            $classes[$item::class] = true;

            if (! $item instanceof UnitEnum && ! $item instanceof DateTimeInterface) {
                array_push($pending, ...array_values((array) $item));
            }
        }

        return array_keys($classes);
    }

    /**
     * The classes' properties and their types, so a release that changes
     * them reads none of the figures an earlier one cached.
     *
     * @param  list<class-string>  $classes
     */
    private static function fingerprint(array $classes): string
    {
        static $fingerprints = [];
        $id = implode('|', $classes);

        if (isset($fingerprints[$id])) {
            return $fingerprints[$id];
        }

        $shape = [];

        foreach ($classes as $class) {
            if (enum_exists($class)) {
                $shape[$class] = array_map(static fn (ReflectionEnumUnitCase $case): string => $case->getName(), (new ReflectionEnum($class))->getCases());

                continue;
            }

            $reflection = new ReflectionClass($class);

            $properties = [];

            foreach ($reflection->getProperties() as $property) {
                $type = $property->getType();
                $properties[] = $property->getName().':'.($type instanceof ReflectionNamedType ? $type->getName() : (string) $type);
            }

            $shape[$class] = $properties;
        }

        return $fingerprints[$id] = hash('sha256', (string) json_encode($shape));
    }
}
