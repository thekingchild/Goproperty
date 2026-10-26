<?php

declare(strict_types=1);

use App\Audit\AuditChain;
use App\Audit\AuditContext;
use App\Audit\AuditEntry;
use App\Audit\ChainVerification;
use App\Platform\Filament\Pages\Settings\CustomCode;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

/*
 * P00-11: the audit log is append-only with a hash chain
 * (PLATFORM_CONSOLE.md § 7). Tampering with a row breaks verification.
 */

function writeEntries(int $count): void
{
    foreach (range(1, $count) as $n) {
        activity('test')->event('created')->withProperties(['n' => $n, 'nested' => ['b' => 2, 'a' => 1]])->log("entry {$n}");
    }
}

function chain(): ChainVerification
{
    return app(AuditChain::class)->verify();
}

it('links every entry to the one before it', function () {
    writeEntries(3);

    $rows = DB::table('activity_log')->orderBy('id')->get();

    expect($rows)->toHaveCount(3)
        ->and($rows[0]->prev_hash)->toBeNull()
        ->and($rows[1]->prev_hash)->toBe($rows[0]->hash)
        ->and($rows[2]->prev_hash)->toBe($rows[1]->hash)
        ->and(DB::table('audit_chain_head')->value('last_hash'))->toBe($rows[2]->hash)
        ->and(chain()->intact)->toBeTrue()
        ->and(chain()->checked)->toBe(3);

    $this->artisan('goproperty:audit-verify')->expectsOutputToContain('The audit log is intact: 3 entries checked.')->assertSuccessful();
});

it('records where an entry came from and why', function () {
    app()->instance('request', Request::create('/x', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Tests/1.0']));

    app(AuditContext::class)->because('Tenant asked by phone', fn () => activity('test')->log('with a reason'));
    activity('test')->log('without a reason');

    $rows = AuditEntry::query()->orderBy('id')->get();
    expect($rows[0]->reason)->toBe('Tenant asked by phone')
        ->and($rows[0]->ip)->toBe('203.0.113.9')
        ->and($rows[0]->user_agent)->toBe('Tests/1.0')
        ->and($rows[1]->reason)->toBeNull()
        ->and(chain()->intact)->toBeTrue();
});

it('detects a changed entry', function (string $column, mixed $value) {
    writeEntries(4);
    $second = DB::table('activity_log')->orderBy('id')->skip(1)->value('id');

    DB::table('activity_log')->where('id', $second)->update([$column => $value]);

    $result = chain();
    expect($result->intact)->toBeFalse()
        ->and($result->brokenAtId)->toBe($second)
        ->and($result->checked)->toBe(1)
        ->and($result->reason)->toContain('content does not match');

    $this->artisan('goproperty:audit-verify')->expectsOutputToContain("broken at entry {$second}")->assertFailed();
})->with([
    'description' => ['description', 'entry 99'],
    'properties' => ['properties', '{"n":2,"nested":{"a":1,"b":3}}'],
    'causer' => ['causer_id', 42],
    'time' => ['created_at', '2020-01-01 00:00:00'],
    'hash itself' => ['hash', str_repeat('a', 64)],
]);

it('detects a removed entry in the middle', function () {
    writeEntries(4);
    $ids = DB::table('activity_log')->orderBy('id')->pluck('id');

    DB::table('activity_log')->where('id', $ids[1])->delete();

    expect(chain())->brokenAtId->toBe($ids[2])
        ->and(chain()->reason)->toContain('link to the entry before it');
});

it('detects removed newest entries', function () {
    writeEntries(3);
    DB::table('activity_log')->where('id', DB::table('activity_log')->max('id'))->delete();

    expect(chain()->intact)->toBeFalse()
        ->and(chain()->brokenAtId)->toBeNull()
        ->and(chain()->reason)->toContain('newest entries are missing');
});

it('detects a forged entry, even with a well-formed hash', function () {
    writeEntries(2);
    $last = DB::table('activity_log')->orderByDesc('id')->first();

    // An attacker without APP_KEY recomputes a plain SHA-256 chain link.
    DB::table('activity_log')->insert([
        'log_name' => 'test', 'description' => 'forged', 'event' => 'created', 'properties' => '[]',
        'prev_hash' => $last->hash, 'hash' => hash('sha256', 'forged'), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('audit_chain_head')->update(['last_hash' => hash('sha256', 'forged')]);

    expect(chain()->intact)->toBeFalse()
        ->and(chain()->reason)->toContain('content does not match');
});

it('survives a database that reorders JSON keys', function () {
    writeEntries(1);

    // MySQL's JSON type stores object keys in its own order.
    DB::table('activity_log')->update(['properties' => '{"nested": {"a": 1, "b": 2}, "n": 1}']);

    expect(chain()->intact)->toBeTrue();
});

it('still verifies after APP_KEY is rotated', function () {
    writeEntries(2);
    $old = config('app.key');

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$old]]);

    expect(chain()->intact)->toBeTrue();
});

it('refuses to change or delete entries through the app', function () {
    writeEntries(1);
    $entry = AuditEntry::query()->sole();

    expect(fn () => $entry->update(['description' => 'changed']))->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $entry->delete())->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $this->artisan('activitylog:clean'))->toThrow(RuntimeException::class, 'append-only');

    expect(chain()->intact)->toBeTrue();
});

it('seals entries written before the chain existed', function () {
    writeEntries(1);
    DB::table('activity_log')->insert(['log_name' => 'legacy', 'description' => 'old entry', 'properties' => '{"x":1}', 'created_at' => now(), 'updated_at' => now()]);

    expect(chain()->intact)->toBeFalse();
    expect(app(AuditChain::class)->sealUnchained())->toBe(1)
        ->and(chain()->intact)->toBeTrue();
});

it('names the staff member as the causer of console changes', function () {
    $staff = staffMember();
    $this->actingAs($staff, 'staff')->withSession(['console.step_up_at' => now()->getTimestamp()]);
    Filament::setCurrentPanel('console');

    Livewire::test(CustomCode::class)->fillForm(['custom_code' => ['css' => 'a{}']])->callAction('save');

    $entry = AuditEntry::query()->where('log_name', 'settings')->sole();
    expect($entry->causer?->is($staff))->toBeTrue()
        ->and($entry->properties['key'])->toBe('custom_code.css');
});

it('checks the chain nightly and logs a break as critical', function () {
    writeEntries(2);
    DB::table('activity_log')->where('id', DB::table('activity_log')->min('id'))->update(['description' => 'changed']);
    Log::spy();

    $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'audit-chain-verify');
    expect($event)->not->toBeNull();
    $event->run(app());

    Log::shouldHaveReceived('critical')->withArgs(fn (string $message) => str_contains($message, 'audit log hash chain is broken'))->once();
});
