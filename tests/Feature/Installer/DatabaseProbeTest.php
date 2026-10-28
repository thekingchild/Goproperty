<?php

declare(strict_types=1);

use App\Installer\Database\DatabaseProbe;
use App\Installer\Database\DatabaseSettings;
use App\Installer\Database\OwnTables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ScratchDatabase;

/*
 * P00-05: "Test connection" (INSTALLER.md § 5–6), against real throwaway
 * databases on the test server.
 */

afterEach(function () {
    ScratchDatabase::dropAll();
});

/**
 * @param  array<string, mixed>  $changes
 */
function settingsFor(string $database, array $changes = []): DatabaseSettings
{
    $base = array_merge(ScratchDatabase::base(), ['database' => $database], $changes);

    return DatabaseSettings::fromConnection($base);
}

it('accepts an empty database', function () {
    $database = ScratchDatabase::create();

    $result = app(DatabaseProbe::class)->test(settingsFor($database));

    expect($result->ok)->toBeTrue()
        ->and($result->message)->toBe('Connected. The database is empty.')
        ->and($result->tables)->toBe([])
        ->and($result->serverVersion)->not->toBeNull();
});

it('lists only the chosen database, never others on the server', function () {
    $other = ScratchDatabase::create();
    Schema::connection($other)->create('wp_posts', fn ($table) => $table->id());
    $chosen = ScratchDatabase::create();

    $result = app(DatabaseProbe::class)->test(settingsFor($chosen));

    expect($result->ok)->toBeTrue()->and($result->tables)->toBe([]);
});

it('refuses a database with tables that are not GoProperty\'s', function () {
    $database = ScratchDatabase::create();
    foreach (['wp_posts', 'wp_users', 'users'] as $table) {
        Schema::connection($database)->create($table, fn ($t) => $t->id());
    }

    $result = app(DatabaseProbe::class)->test(settingsFor($database));

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toBe('This database already has tables that are not GoProperty\'s (wp_posts, wp_users). Choose an empty database.');
});

it('continues a database holding GoProperty tables from an earlier attempt', function () {
    $database = ScratchDatabase::create();
    Schema::connection($database)->create('migrations', fn ($t) => $t->id());
    Schema::connection($database)->create('users', fn ($t) => $t->id());

    $result = app(DatabaseProbe::class)->test(settingsFor($database));

    expect($result->ok)->toBeTrue()
        ->and($result->message)->toContain('from an earlier attempt');
});

it('refuses a database holding a finished installation', function () {
    $database = ScratchDatabase::create();
    Schema::connection($database)->create('installation_state', function ($t) {
        $t->unsignedTinyInteger('id')->primary();
        $t->string('status');
        $t->string('version')->nullable();
        $t->timestamp('finalized_at')->nullable();
        $t->timestamps();
    });
    DB::connection($database)->table('installation_state')->insert(['id' => 1, 'status' => 'finalized']);

    $result = app(DatabaseProbe::class)->test(settingsFor($database));

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toBe('This database holds a finished GoProperty installation. Choose an empty database.');
});

it('explains a wrong password without repeating it', function () {
    $database = ScratchDatabase::create();
    $settings = settingsFor($database, ['password' => 'wrong-Pa55word']);

    $result = app(DatabaseProbe::class)->test($settings);

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toBe('The username or password was refused.');
});

it('explains a database that does not exist', function () {
    $result = app(DatabaseProbe::class)->test(settingsFor('gp_no_such_database'));

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toStartWith('That database does not exist.');
});

it('gives up on an unreachable server within the timeout', function () {
    config(['installer.database_timeout' => 1]);
    $started = microtime(true);

    $result = app(DatabaseProbe::class)->test(settingsFor('gp', ['host' => '127.0.0.1', 'port' => 1]));

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toStartWith('Cannot reach a database server')
        ->and(microtime(true) - $started)->toBeLessThan(5);
});

it('cleans secrets out of messages it does not recognise', function () {
    $settings = new DatabaseSettings('mysql', 'db.example.ng', 3306, 'acct_gp', 'acct_user', 'Sup3r$ecret');
    $exception = new RuntimeException('SQLSTATE[HY000] [9999] Odd failure for acct_user@db.example.ng using Sup3r$ecret (Connection: installer_probe, Host: db.example.ng, SQL: select 1)');

    expect(DatabaseProbe::explain($exception, $settings))
        ->toBe('The database server said: SQLSTATE[HY000] [9999] Odd failure for ***@*** using ***');
});

it('reads server versions', function (string $version, string $numeric) {
    expect(DatabaseProbe::numericVersion($version))->toBe($numeric);
})->with([
    ['8.0.36', '8.0.36'],
    ['8.4.2-0ubuntu0.24.04.1', '8.4.2'],
    ['10.6.18-MariaDB-cll-lve', '10.6.18'],
    ['5.5.5-10.11.6-MariaDB-0+deb12u1', '10.11.6'],
    ['garbage', '0'],
]);

it('refuses settings that are not plain values', function (array $changes, string $message) {
    expect(fn () => settingsFor('gp', $changes))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'driver' => [['driver' => 'pgsql'], 'Choose MySQL or MariaDB.'],
    'host' => [['host' => "localhost\n"], 'The host must be'],
    'host with spaces' => [['host' => 'local host'], 'The host must be'],
    'port' => [['port' => 70000], 'The port must be'],
    'database' => [['database' => 'gp; drop table x'], 'The database name may use'],
    'password' => [['password' => "pa\nss"], 'The password must not contain control characters.'],
]);

it('hides the password from dumps', function () {
    $settings = new DatabaseSettings('mysql', 'localhost', 3306, 'gp', 'gp', 'Sup3r$ecret');

    expect(print_r($settings, true))->not->toContain('Sup3r$ecret');
});

it('knows every table the migrations create', function () {
    $database = DB::connection()->getDatabaseName();
    $tables = DB::connection()->getSchemaBuilder()->getTableListing($database, false);

    expect(array_values(array_diff($tables, app(OwnTables::class)->names())))->toBe([])
        ->and(app(OwnTables::class)->names())->toContain('installation_state', 'migrations', 'users');
});

it('knows which tables a fully migrated database holds: those created and not dropped later', function () {
    $database = DB::connection()->getDatabaseName();
    $tables = DB::connection()->getSchemaBuilder()->getTableListing($database, false);
    sort($tables);

    // P03-14 dropped P02-04's billing_transfer_requests: still one of ours (a part-way install may hold it), no longer expected.
    expect(app(OwnTables::class)->expected())->toBe(array_values($tables))
        ->and(app(OwnTables::class)->names())->toContain('billing_transfer_requests', 'management_handovers')
        ->and(app(OwnTables::class)->expected())->not->toContain('billing_transfer_requests')
        ->and(app(OwnTables::class)->expected())->toContain('management_handovers');
});
