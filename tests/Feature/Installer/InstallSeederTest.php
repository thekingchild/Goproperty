<?php

declare(strict_types=1);

use Database\Seeders\InstallSeeder;
use Illuminate\Support\Facades\DB;

/*
 * P00-05: the installer's seeders must be safe to run again after a timeout
 * (INSTALLER.md § 5, step 4). Running the list twice must leave every table
 * exactly as one run does.
 */

/** @return array<string, list<mixed>> */
function snapshotTables(): array
{
    $connection = DB::connection();
    $snapshot = [];

    foreach ($connection->getSchemaBuilder()->getTableListing($connection->getDatabaseName(), false) as $table) {
        if (in_array($table, ['migrations', 'cache', 'cache_locks', 'sessions'], true)) {
            continue;
        }

        $rows = array_map(function (object $row): array {
            $row = (array) $row;
            unset($row['created_at'], $row['updated_at']);

            return $row;
        }, $connection->table($table)->get()->all());

        sort($rows);
        $snapshot[$table] = $rows;
    }

    return $snapshot;
}

it('leaves the same data when run twice', function () {
    $this->seed(InstallSeeder::class);
    $once = snapshotTables();

    $this->seed(InstallSeeder::class);

    expect(snapshotTables())->toBe($once);
});

it('lists only seeders that exist', function () {
    expect(InstallSeeder::SEEDERS)->toBeArray();

    foreach (InstallSeeder::SEEDERS as $seeder) {
        expect(class_exists($seeder))->toBeTrue();
    }
});

it('does not depend on development packages', function () {
    // The release package has no Faker (composer install --no-dev).
    foreach ([InstallSeeder::class, ...InstallSeeder::SEEDERS] as $seeder) {
        $source = (string) file_get_contents((new ReflectionClass($seeder))->getFileName());

        expect($source)->not->toContain('::factory(')->not->toContain('fake(')->not->toContain('Faker\\');
    }
});
