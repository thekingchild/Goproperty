<?php

declare(strict_types=1);

namespace App\Exports;

/**
 * Cleaning a list's filters (ListExport::filters()): what came from the
 * page's properties (or a stored export ticket) is checked like any other
 * input, and a value the list does not know is dropped, as the page
 * ignores it.
 */
final class ListFilters
{
    /** A search: trimmed, at most `max` characters; empty is no search. */
    public static function search(mixed $value, int $max = 100): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $search = mb_substr(trim($value), 0, $max);

        return $search === '' ? null : $search;
    }

    /** A switch (the Archived tab). */
    public static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /** A record id (a building, an owner), or null. */
    public static function id(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) && strlen($value) <= 18 && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * One of the allowed values, or null.
     *
     * @param  list<string|int>  $allowed
     */
    public static function option(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /** The search as a LIKE pattern matching anywhere, with % and _ taken literally. */
    public static function like(string $search): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
    }

    /**
     * The filters that are set, for the stored ticket and the audit log.
     *
     * @param  array<string, string|int|bool|null>  $filters
     * @return array<string, string|int|bool>
     */
    public static function set(array $filters): array
    {
        return array_filter($filters, static fn (mixed $value): bool => $value !== null && $value !== false);
    }
}
