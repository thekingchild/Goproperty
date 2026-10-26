<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Settings\SettingDefinition;
use App\Settings\SettingScope;
use App\Settings\SettingsRegistry;
use App\Settings\SettingsStore;
use App\Settings\SettingType;
use App\Tenancy\MissingOrganizationContext;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
 * P00-07: the settings store (DATABASE_SCHEMA.md § 8, PLATFORM_CONSOLE.md
 * § 9.8): declared keys only, typed and validated, secrets encrypted and
 * never logged, organization values kept apart, every change audited.
 */

beforeEach(function () {
    $registry = app(SettingsRegistry::class);
    $registry->define(new SettingDefinition('billing.reminder_days', SettingType::Integer, 7, ['integer', 'between:1,30'], SettingScope::Organization));
    $registry->define(new SettingDefinition('theme.palette', SettingType::Json, ['primary' => '#1D4ED8'], ['array']));
    $registry->define(new SettingDefinition('auth.always_challenge', SettingType::Boolean, false, ['boolean']));
    $this->settings = app(SettingsStore::class);
});

afterEach(function () {
    app(OrganizationContext::class)->clear();
});

it('returns the default until a value is stored', function () {
    expect($this->settings->get('site.name'))->toBe('GoProperty')
        ->and($this->settings->get('auth.turnstile_site_key'))->toBeNull()
        ->and($this->settings->get('theme.palette'))->toBe(['primary' => '#1D4ED8']);
});

it('stores and reads typed values', function () {
    $this->settings->set('site.name', 'GoProperty NG');
    $this->settings->set('theme.palette', ['primary' => '#0F1B44', 'accent' => '#F59E0B']);
    $this->settings->set('auth.always_challenge', true);

    expect($this->settings->get('site.name'))->toBe('GoProperty NG')
        ->and($this->settings->get('theme.palette'))->toBe(['primary' => '#0F1B44', 'accent' => '#F59E0B'])
        ->and($this->settings->get('auth.always_challenge'))->toBeTrue()
        ->and(DB::table('settings')->where('key', 'site.name')->value('scope_type'))->toBe('global');
});

it('refuses unknown keys', function () {
    $this->settings->set('site.unknown', 'x');
})->throws(InvalidArgumentException::class, 'Unknown setting site.unknown');

it('refuses values of the wrong type', function () {
    $this->settings->set('auth.always_challenge', 'yes');
})->throws(InvalidArgumentException::class, 'auth.always_challenge takes a boolean, not string.');

it('validates values against the definition', function () {
    $this->settings->set('site.name', str_repeat('x', 61));
})->throws(ValidationException::class);

it('keeps secrets encrypted in the database and the cache', function () {
    $this->settings->set('auth.turnstile_secret_key', '0x4AAAAAAA-secret-value');

    $stored = (string) DB::table('settings')->where('key', 'auth.turnstile_secret_key')->value('value');
    $cached = json_encode(Cache::get('settings:global:0'));

    expect($this->settings->get('auth.turnstile_secret_key'))->toBe('0x4AAAAAAA-secret-value')
        ->and($stored)->not->toContain('secret-value')
        ->and($cached)->not->toContain('secret-value');
});

it('never writes a secret to the activity log', function () {
    $this->settings->set('auth.turnstile_secret_key', 'first-secret-value');
    $this->settings->set('auth.turnstile_secret_key', 'second-secret-value');

    $log = Activity::query()->where('log_name', 'settings')->get();

    expect($log)->toHaveCount(2)
        ->and($log->last()->properties->all())->toMatchArray(['key' => 'auth.turnstile_secret_key', 'old' => '[secret]', 'new' => '[secret]'])
        ->and($log->toJson())->not->toContain('secret-value');
});

it('audits who changed what', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->settings->set('site.name', 'GoProperty NG');

    $entry = Activity::query()->where('log_name', 'settings')->sole();

    expect($entry->event)->toBe('updated')
        ->and($entry->causer?->is($user))->toBeTrue()
        ->and($entry->properties->all())->toMatchArray(['key' => 'site.name', 'old' => 'GoProperty', 'new' => 'GoProperty NG']);
});

it('reads a changed value at once, despite the cache', function () {
    expect($this->settings->get('site.name'))->toBe('GoProperty');

    $this->settings->set('site.name', 'Changed');

    expect($this->settings->get('site.name'))->toBe('Changed');
});

it('goes back to the default when forgotten', function () {
    $this->settings->set('site.name', 'Changed');
    $this->settings->forget('site.name');

    expect($this->settings->get('site.name'))->toBe('GoProperty')
        ->and(Activity::query()->where('event', 'reset')->count())->toBe(1);
});

it('keeps each organization\'s values apart', function () {
    $acme = Organization::factory()->create();
    $other = Organization::factory()->create();

    $this->settings->set('billing.reminder_days', 3, $acme);

    expect($this->settings->get('billing.reminder_days', $acme))->toBe(3)
        ->and($this->settings->get('billing.reminder_days', $other))->toBe(7);
});

it('uses the current organization when none is given', function () {
    $acme = Organization::factory()->create();
    app(OrganizationContext::class)->set($acme);

    $this->settings->set('billing.reminder_days', 14);

    expect($this->settings->get('billing.reminder_days', $acme))->toBe(14)
        ->and(DB::table('settings')->where('key', 'billing.reminder_days')->value('scope_id'))->toBe($acme->id);
});

it('needs an organization for organization settings', function () {
    $this->settings->get('billing.reminder_days');
})->throws(MissingOrganizationContext::class);

it('refuses an organization for global settings', function () {
    $this->settings->set('site.name', 'X', Organization::factory()->create());
})->throws(InvalidArgumentException::class, 'site.name is a global setting');

it('refuses duplicate and badly named declarations', function () {
    $registry = app(SettingsRegistry::class);

    expect(fn () => $registry->define(new SettingDefinition('site.name', SettingType::String)))->toThrow(InvalidArgumentException::class, 'declared twice')
        ->and(fn () => new SettingDefinition('SiteName', SettingType::String))->toThrow(InvalidArgumentException::class, 'area.name')
        ->and(fn () => new SettingDefinition('site.count', SettingType::Integer, 'seven'))->toThrow(InvalidArgumentException::class, 'not a integer');
});
