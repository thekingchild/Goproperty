<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A throwaway database on the test server, reached through its own
 * connection. Schema changes there do not commit the test's
 * RefreshDatabase transaction, which runs on the default connection.
 */
final class ScratchDatabase
{
    private const string ADMIN = 'scratch_admin';

    /** @var list<string> */
    private static array $created = [];

    /** Creates an empty database and returns the name of a connection to it. */
    public static function create(): string
    {
        $base = self::base();
        $database = 'gp_scratch_'.Str::lower(Str::random(10));

        config(['database.connections.'.self::ADMIN => $base]);
        DB::connection(self::ADMIN)->statement("create database `{$database}`");

        config(['database.connections.'.$database => array_merge($base, ['database' => $database])]);
        self::$created[] = $database;

        return $database;
    }

    public static function dropAll(): void
    {
        foreach (self::$created as $database) {
            DB::purge($database);
            DB::connection(self::ADMIN)->statement("drop database if exists `{$database}`");
        }

        self::$created = [];
        DB::purge(self::ADMIN);
    }

    /** @return array<string, mixed> */
    public static function base(): array
    {
        $default = config('database.default');
        $base = config('database.connections.'.(is_string($default) ? $default : 'mysql'));

        return is_array($base) ? $base : [];
    }
}
