<?php

declare(strict_types=1);

namespace Modules\Import\Mapping;

use Modules\Import\Contracts\ImportColumn;
use Modules\Import\Contracts\ImportTarget;
use Modules\Import\Contracts\ImportTargets;
use Modules\Import\Models\ImportColumnMapping;

/**
 * Suggests how an uploaded sheet maps onto a target (DATA_IMPORT.md
 * § 2.2): which target a sheet holds (its name, else the headers it shares
 * with a target's columns), and which file column feeds each target column
 * (a mapping the organization saved for that target first, then the
 * column's label, key or aliases). The agency confirms or changes it.
 * Runs inside the organization (saved mappings are scoped).
 */
final readonly class MappingSuggester
{
    public function __construct(private ImportTargets $targets) {}

    /** "Phone No." and "phone_no" both read "phoneno". */
    public static function normalise(string $header): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower($header));
    }

    public function suggest(SheetMapping $sheet, ?string $target = null): SheetMapping
    {
        $target = $target !== null ? $this->targets->find($target) : $this->targetFor($sheet);

        return $target === null ? $sheet->mappedTo(null, []) : $sheet->mappedTo($target->key(), $this->columnsFor($target, $sheet->headers));
    }

    public function targetFor(SheetMapping $sheet): ?ImportTarget
    {
        $name = self::normalise($sheet->name);

        foreach ($this->targets->all() as $target) {
            if ($name !== '' && in_array($name, [self::normalise($target->label()), self::normalise($target->key())], true)) {
                return $target;
            }
        }

        // Otherwise the target sharing the most headers, if at least two.
        $best = null;
        $bestScore = 1;

        foreach ($this->targets->all() as $target) {
            $score = count(array_filter(
                array_keys($this->columnsFor($target, $sheet->headers, withSaved: false)),
                static fn (string $key): bool => $key !== ImportColumn::EXTERNAL_REF,
            ));

            if ($score > $bestScore) {
                [$best, $bestScore] = [$target, $score];
            }
        }

        return $best;
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, int> column key => header position
     */
    public function columnsFor(ImportTarget $target, array $headers, bool $withSaved = true): array
    {
        $positions = [];

        foreach ($headers as $position => $header) {
            $normalised = self::normalise($header);

            if ($normalised !== '' && ! isset($positions[$normalised])) {
                $positions[$normalised] = $position;
            }
        }

        $columns = $withSaved ? $this->fromSaved($target, $positions) : [];
        $taken = array_flip($columns);

        foreach ($target->columns() as $column) {
            if (isset($columns[$column->key])) {
                continue;
            }

            foreach ([$column->label, $column->key, ...$column->aliases] as $name) {
                $position = $positions[self::normalise($name)] ?? null;

                if ($position !== null && ! isset($taken[$position])) {
                    $columns[$column->key] = $position;
                    $taken[$position] = $column->key;

                    break;
                }
            }
        }

        return $columns;
    }

    /**
     * The saved mapping for this target that matches most of the file's
     * headers (newest first on a tie).
     *
     * @param  array<string, int>  $positions  normalised header => position
     * @return array<string, int>
     */
    private function fromSaved(ImportTarget $target, array $positions): array
    {
        $known = array_flip(array_map(static fn (ImportColumn $column): string => $column->key, $target->columns()));
        $best = [];

        $saved = ImportColumnMapping::query()->where('kind', $target->key())->latest('updated_at')->latest('id')->limit(20)->get();

        foreach ($saved as $mapping) {
            $columns = [];

            foreach ($mapping->mapping as $header => $key) {
                $position = $positions[(string) $header] ?? null;

                if ($position !== null && isset($known[$key]) && ! isset($columns[$key]) && ! in_array($position, $columns, true)) {
                    $columns[$key] = $position;
                }
            }

            if (count($columns) > count($best)) {
                $best = $columns;
            }
        }

        return $best;
    }
}
