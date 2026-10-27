<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * MariaDB 10.6 runs with explicit_defaults_for_timestamp off: the first
 * NOT NULL TIMESTAMP column of a table with no explicit default silently
 * becomes DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, so any
 * update rewrites it (it once rewrote bills.issued_at when a bill was
 * voided). Give such columns ->useCurrent() or ->nullable().
 */
it('has no timestamp column that rewrites itself on update', function () {
    $columns = DB::select(
        'select table_name as t, column_name as c from information_schema.columns '
        .'where table_schema = database() and lower(extra) like ?',
        ['%on update%'],
    );

    expect(array_map(fn (object $column): string => $column->t.'.'.$column->c, $columns))->toBe([]);
});
