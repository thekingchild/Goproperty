<?php

declare(strict_types=1);

namespace App\Privacy\Data;

use Illuminate\Support\Facades\DB;

/**
 * What an erasure does to the person's rows of one table (P09-02; the
 * erase rules of PersonalDataMap). Only the columns named change: amounts,
 * dates, numbers, statuses and links stay as they are, so history stays
 * whole and no state machine moves (AGENT.md § 3 rules 5 and 7: purge and
 * erasure paths exist only in this tooling).
 */
final class Eraser
{
    private int $changed = 0;

    /** @param  list<int>  $ids */
    public function __construct(
        public readonly string $table,
        public readonly array $ids,
        public readonly Person $person,
        private readonly Scrubber $scrubber,
    ) {}

    /**
     * Sets columns of the person's rows (optionally only those matching $where).
     *
     * @param  array<string, mixed>  $values  a value, or a closure given the row's id
     * @param  array<string, mixed>  $where
     */
    public function set(array $values, array $where = []): self
    {
        if ($this->ids === []) {
            return $this;
        }

        $dynamic = array_filter($values, static fn (mixed $value): bool => $value instanceof \Closure);
        $static = array_diff_key($values, $dynamic);

        if ($dynamic === []) {
            $this->changed += DB::table($this->table)->whereIn('id', $this->ids)->where($where)->update($static);

            return $this;
        }

        foreach (DB::table($this->table)->whereIn('id', $this->ids)->where($where)->pluck('id') as $id) {
            $row = $static;

            foreach ($dynamic as $column => $value) {
                $row[$column] = $value((int) $id);
            }

            $this->changed += DB::table($this->table)->where('id', $id)->update($row);
        }

        return $this;
    }

    /** Takes the person's identifying values out of these text columns. */
    public function scrub(string ...$columns): self
    {
        return $this->scrubWith(array_values($columns), fn (?string $value): ?string => $this->scrubber->text($value));
    }

    /** Takes the person's identifying values out of these JSON columns. */
    public function scrubJson(string ...$columns): self
    {
        return $this->scrubWith(array_values($columns), fn (?string $value): ?string => $this->scrubber->json($value));
    }

    public function delete(): self
    {
        if ($this->ids !== []) {
            $this->changed += DB::table($this->table)->whereIn('id', $this->ids)->delete();
        }

        return $this;
    }

    public function changed(): int
    {
        return $this->changed;
    }

    /**
     * @param  list<string>  $columns
     * @param  callable(?string): ?string  $clean
     */
    private function scrubWith(array $columns, callable $clean): self
    {
        if ($this->ids === [] || $this->person->knownValues === []) {
            return $this;
        }

        foreach (DB::table($this->table)->whereIn('id', $this->ids)->get(['id', ...$columns]) as $row) {
            $changes = [];

            foreach ($columns as $column) {
                $before = $row->{$column} ?? null;
                $after = $clean(is_string($before) ? $before : null);

                if ($after !== $before) {
                    $changes[$column] = $after;
                }
            }

            if ($changes !== []) {
                $this->changed += DB::table($this->table)->where('id', $row->id)->update($changes);
            }
        }

        return $this;
    }
}
