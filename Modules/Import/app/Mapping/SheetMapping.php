<?php

declare(strict_types=1);

namespace Modules\Import\Mapping;

use DateTimeInterface;
use Modules\Import\Contracts\ImportColumn;
use Modules\Import\Contracts\ImportRow;
use Modules\Import\Contracts\ImportTarget;

/**
 * One sheet of the uploaded file and how it is mapped: which target it
 * imports (null: not imported) and which of its columns (by position)
 * feeds each target column. A CSV file is one sheet. The headers and the
 * header row are read once, at upload.
 */
final readonly class SheetMapping
{
    /**
     * @param  list<string>  $headers  the file's header cells, by position
     * @param  array<string, int>  $columns  target column key => header position
     */
    public function __construct(
        public int $index,
        public string $name,
        public int $headerRow,
        public array $headers,
        public ?string $target = null,
        public array $columns = [],
    ) {}

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        $headers = is_array($data['headers'] ?? null) ? array_values(array_map(static fn (mixed $h): string => is_scalar($h) ? (string) $h : '', $data['headers'])) : [];
        $columns = [];

        foreach (is_array($data['columns'] ?? null) ? $data['columns'] : [] as $key => $position) {
            if (is_string($key) && is_int($position) && $position >= 0 && $position < count($headers)) {
                $columns[$key] = $position;
            }
        }

        return new self(
            index: is_int($data['index'] ?? null) ? $data['index'] : 0,
            name: is_string($data['name'] ?? null) ? $data['name'] : 'Sheet',
            headerRow: is_int($data['header_row'] ?? null) ? $data['header_row'] : 1,
            headers: $headers,
            target: is_string($data['target'] ?? null) && $data['target'] !== '' ? $data['target'] : null,
            columns: $columns,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'name' => $this->name,
            'header_row' => $this->headerRow,
            'headers' => $this->headers,
            'target' => $this->target,
            'columns' => $this->columns,
        ];
    }

    /** @param  array<string, int>  $columns */
    public function mappedTo(?string $target, array $columns): self
    {
        return new self($this->index, $this->name, $this->headerRow, $this->headers, $target, $target === null ? [] : $columns);
    }

    /**
     * The target's view of one file row: the mapped cells by column key.
     *
     * @param  list<string|int|float|bool|DateTimeInterface|null>  $cells
     */
    public function row(int $number, array $cells): ImportRow
    {
        $values = [];

        foreach ($this->columns as $key => $position) {
            $values[$key] = $cells[$position] ?? null;
        }

        return new ImportRow($this->name, $number, $values);
    }

    /** @return list<ImportColumn> required columns of the target that no file column feeds */
    public function missingRequired(ImportTarget $target): array
    {
        return array_values(array_filter(
            $target->columns(),
            fn (ImportColumn $column): bool => $column->required && ! array_key_exists($column->key, $this->columns),
        ));
    }

    /** The file's header and column letter feeding a target column, when mapped. */
    public function headerFor(string $column): ?string
    {
        $position = $this->columns[$column] ?? null;

        return $position === null ? null : ($this->headers[$position] ?? null);
    }

    public function letterFor(string $column): ?string
    {
        $position = $this->columns[$column] ?? null;

        return $position === null ? null : self::letter($position);
    }

    /** "C" for the third column. */
    public static function letter(int $position): string
    {
        $letter = '';

        for ($n = $position + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letter = chr(65 + (($n - 1) % 26)).$letter;
        }

        return $letter;
    }
}
