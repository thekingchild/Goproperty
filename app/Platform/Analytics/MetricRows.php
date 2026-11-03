<?php

declare(strict_types=1);

namespace App\Platform\Analytics;

/**
 * The rows one snapshot writes. A metric's total (dimension '') is always
 * written, zero included, so a day taken is a day with figures; a
 * dimension's row only when it is not zero.
 */
final class MetricRows
{
    /** @var array<string, array{metric: string, dimension: string, value: int}> */
    private array $rows = [];

    public function total(PlatformMetric $metric, int $value): void
    {
        $this->put($metric, '', $value);
    }

    public function add(PlatformMetric $metric, int $value, string $dimension): void
    {
        if ($value !== 0) {
            $this->put($metric, $dimension, $value);
        }
    }

    /** @return list<array{metric: string, dimension: string, value: int}> */
    public function all(): array
    {
        return array_values($this->rows);
    }

    private function put(PlatformMetric $metric, string $dimension, int $value): void
    {
        $this->rows[$metric->value."\0".$dimension] = ['metric' => $metric->value, 'dimension' => $dimension, 'value' => $value];
    }
}
