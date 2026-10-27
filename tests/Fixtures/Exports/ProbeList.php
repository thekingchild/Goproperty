<?php

declare(strict_types=1);

namespace Tests\Fixtures\Exports;

use App\Exports\ExportColumn;
use App\Exports\ListExport;
use App\Models\User;
use RuntimeException;

/**
 * A stand-in list for the export tests: it claims `rows` rows (count()),
 * and its rows() gives a few and then fails when `failAfter` is set, to
 * show what happens to a half-written export.
 */
final class ProbeList implements ListExport
{
    public static int $rows = 3;

    public static ?int $failAfter = null;

    public function key(): string
    {
        return 'probe_list';
    }

    public function title(): string
    {
        return 'Probe list';
    }

    public function filters(array $input): array
    {
        return [];
    }

    public function authorize(User $user, array $filters): void {}

    public function columns(User $user): array
    {
        return [ExportColumn::make('Number', fn (int $row): int => $row)];
    }

    public function rows(User $user, array $filters): iterable
    {
        for ($row = 1; $row <= min(self::$rows, 1000); $row++) {
            if (self::$failAfter !== null && $row > self::$failAfter) {
                throw new RuntimeException('The list could not be read.');
            }

            yield $row;
        }
    }

    public function count(User $user, array $filters): int
    {
        return self::$rows;
    }
}
