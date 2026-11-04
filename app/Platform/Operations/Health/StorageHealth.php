<?php

declare(strict_types=1);

namespace App\Platform\Operations\Health;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Storage for the operations dashboard (PLATFORM_CONSOLE.md § 2 "Storage"):
 * the database's size and its largest tables (from `information_schema`,
 * the same on MySQL 8 and MariaDB 10.6+; the server's own estimates, which
 * MySQL refreshes about daily), their growth against a sample kept in the
 * cache for each Lagos day (up to 8 days back), and the uploaded files'
 * size per disk from the media library's records (the bucket itself is not
 * listed: that would be slow and costs requests).
 */
final readonly class StorageHealth
{
    private const string SAMPLE = 'operations:storage:';

    private const int KEEP_DAYS = 8;

    public function __construct(private Cache $cache) {}

    /**
     * @return array{total: int, tables: list<array{name: string, bytes: int, rows: int, growth: int|null}>, since: string|null}|null
     *                                                                                                                                null when the server would not say
     */
    public function database(CarbonInterface $now, int $largest = 10): ?array
    {
        try {
            $rows = DB::select('SELECT TABLE_NAME AS name, COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0) AS bytes, COALESCE(TABLE_ROWS, 0) AS row_estimate FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        } catch (Throwable) {
            return null;
        }

        $sizes = [];
        $counts = [];

        foreach ($rows as $row) {
            $sizes[(string) $row->name] = (int) $row->bytes;
            $counts[(string) $row->name] = (int) $row->row_estimate;
        }

        arsort($sizes);
        [$since, $earlier] = $this->sample(CarbonImmutable::instance($now), $sizes);
        $tables = [];

        foreach (array_slice($sizes, 0, $largest, true) as $name => $bytes) {
            $tables[] = ['name' => $name, 'bytes' => $bytes, 'rows' => $counts[$name], 'growth' => $earlier !== null ? $bytes - ($earlier[$name] ?? 0) : null];
        }

        return ['total' => array_sum($sizes), 'tables' => $tables, 'since' => $since];
    }

    /** @return list<array{disk: string, files: int, bytes: int}> */
    public function media(): array
    {
        return array_values(DB::table('media')->groupBy('disk')->orderBy('disk')
            ->select('disk')->selectRaw('COUNT(*) AS files')->selectRaw('SUM(size) AS bytes')
            ->get()
            ->map(static fn (object $row): array => ['disk' => (string) $row->disk, 'files' => (int) $row->files, 'bytes' => (int) $row->bytes])
            ->all());
    }

    public static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) abs($bytes);
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return ($bytes < 0 ? '−' : '').($unit === 0 ? (string) (int) $size : number_format($size, 1)).' '.$units[$unit];
    }

    /**
     * Keeps today's sizes (once a day) and returns the oldest kept day with its sizes.
     *
     * @param  array<string, int>  $sizes
     * @return array{0: string|null, 1: array<string, int>|null}
     */
    private function sample(CarbonImmutable $now, array $sizes): array
    {
        $today = $now->setTimezone('Africa/Lagos')->startOfDay();
        $this->cache->add(self::SAMPLE.$today->toDateString(), $sizes, $now->addDays(self::KEEP_DAYS));

        for ($days = self::KEEP_DAYS - 1; $days >= 1; $days--) {
            $day = $today->subDays($days)->toDateString();
            $earlier = $this->cache->get(self::SAMPLE.$day);

            if (is_array($earlier)) {
                /** @var array<string, int> $earlier */
                return [$day, $earlier];
            }
        }

        return [null, null];
    }
}
