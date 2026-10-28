<?php

declare(strict_types=1);

use App\Installer\Database\OwnTables;
use App\Installer\Migrations\BatchedMigrator;
use App\Installer\Migrations\FreshDatabaseReset;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\ScratchDatabase;

/*
 * P00-05: migrations run a few per request and resume from Laravel's
 * `migrations` table (INSTALLER.md § 5, step 4); a failed fresh install can
 * be emptied and started again (§ 6).
 */

beforeEach(function () {
    $this->database = ScratchDatabase::create();
    $this->migrator = app(BatchedMigrator::class)->on($this->database);
});

afterEach(function () {
    ScratchDatabase::dropAll();
    File::deleteDirectory($this->extraMigrations ?? '');
});

function tablesIn(string $connection): array
{
    return DB::connection($connection)->getSchemaBuilder()->getTableListing(DB::connection($connection)->getDatabaseName(), false);
}

it('reports progress before anything ran', function () {
    $progress = $this->migrator->progress();

    expect($progress->done)->toBe(0)
        ->and($progress->total)->toBeGreaterThan(5)
        ->and($progress->pending)->toHaveCount($progress->total)
        ->and($progress->label())->toBe("0 of {$progress->total}");
});

it('runs a few migrations per batch until done', function () {
    $total = $this->migrator->progress()->total;

    $first = $this->migrator->runBatch(max: 2);

    expect($first->ran)->toHaveCount(2)
        ->and($first->progress->label())->toBe("2 of {$total}")
        ->and($first->failed())->toBeFalse();

    $batches = 1;

    do {
        $result = $this->migrator->runBatch(max: 2);
        $batches++;
    } while (! $result->progress->finished() && $batches <= $total); // a guard that grows with the migrations

    expect($result->progress->done)->toBe($total)
        ->and($batches)->toBe((int) ceil($total / 2))
        ->and(tablesIn($this->database))->toContain('installation_state', 'users', 'migrations');
});

it('resumes where an interrupted run stopped', function () {
    $this->migrator->runBatch(max: 3);

    // A new request (new instance) carries on from the migrations table.
    $resumed = app(BatchedMigrator::class)->on($this->database);

    // Everything left in one batch: this tests resuming, not the time budget,
    // so the budget must not stop it on a slow database server.
    expect($resumed->progress()->done)->toBe(3)
        ->and($resumed->runBatch(max: $resumed->progress()->total, seconds: 600.0)->progress->finished())->toBeTrue();
});

it('stops a batch at the time budget, after at least one migration', function () {
    $result = $this->migrator->runBatch(max: 100, seconds: 0.0);

    expect($result->ran)->toHaveCount(1);
});

it('reports a failing migration by name and stops', function () {
    $this->extraMigrations = sys_get_temp_dir().'/goproperty-migrations-'.Str::random(8);
    File::ensureDirectoryExists($this->extraMigrations);
    file_put_contents($this->extraMigrations.'/2099_01_01_000000_broken.php', <<<'PHP'
        <?php
        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Support\Facades\DB;
        return new class extends Migration {
            public function up(): void { DB::statement('select * from table_that_does_not_exist'); }
        };
        PHP);
    app(Migrator::class)->path($this->extraMigrations);

    do {
        $result = $this->migrator->runBatch(max: 100);
    } while (! $result->failed() && ! $result->progress->finished());

    expect($result->failed())->toBeTrue()
        ->and($result->failedMigration)->toBe('2099_01_01_000000_broken')
        ->and($result->error)->toContain('table_that_does_not_exist')
        ->and($result->error)->not->toContain('Connection:')
        ->and($result->progress->pending)->toBe(['2099_01_01_000000_broken']);
});

it('empties only GoProperty\'s tables for a fresh start', function () {
    $this->markNotInstalled();
    $this->migrator->runBatch(max: 4);
    Schema::connection($this->database)->create('keep_me', fn ($t) => $t->id());

    $dropped = app(FreshDatabaseReset::class)->reset($this->database, $this->database);

    expect($dropped)->not->toBeEmpty()
        ->and(array_diff($dropped, app(OwnTables::class)->names()))->toBe([])
        ->and(tablesIn($this->database))->toBe(['keep_me'])
        ->and($this->migrator->progress()->done)->toBe(0);
});

it('needs the database name typed exactly', function () {
    $this->markNotInstalled();
    $this->migrator->runBatch(max: 2);

    expect(fn () => app(FreshDatabaseReset::class)->reset(strtoupper($this->database), $this->database))
        ->toThrow(InvalidArgumentException::class, 'Type the database name exactly to confirm.')
        ->and(tablesIn($this->database))->not->toBe([]);
});

it('refuses to empty anything once the installer is closed', function () {
    $this->migrator->runBatch(max: 2);

    expect(fn () => app(FreshDatabaseReset::class)->reset($this->database, $this->database))
        ->toThrow(RuntimeException::class, 'The installer is closed.');
});

it('refuses to empty a database where organizations exist', function () {
    $this->markNotInstalled();
    Schema::connection($this->database)->create('organizations', fn ($t) => $t->id());
    DB::connection($this->database)->table('organizations')->insert(['id' => 1]);
    $reset = app(FreshDatabaseReset::class);

    expect(fn () => $reset->reset($this->database, $this->database))
        ->toThrow(RuntimeException::class, 'Organizations exist in this database')
        ->and(tablesIn($this->database))->toBe(['organizations']);
});
