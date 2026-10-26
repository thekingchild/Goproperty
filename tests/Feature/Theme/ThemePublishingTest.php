<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\StaffStatus;
use App\Models\User;
use App\Theme\ThemeManager;
use Filament\Support\Colors\Color as FilamentColor;

/*
 * P00-09a acceptance: publishing a theme colour updates both the customer app
 * and the console; a draft never reaches the public site (PLATFORM_CONSOLE.md
 * § 9.8). Only the staff-only console preview shows it.
 */

beforeEach(function () {
    $this->staff = PlatformStaff::query()->create(['name' => 'Ada', 'email' => 'ada@goproperty.ng', 'password' => 'x-long-password-1', 'status' => StaffStatus::Active]);
    $this->user = User::factory()->create();
    $this->user->organizations()->attach(Organization::factory()->create(['slug' => 'acme-homes']));
    $this->console = '/'.config('app.admin_path');
});

it('updates the customer app and the console when a colour is published', function () {
    $themes = app(ThemeManager::class);
    $themes->saveDraft(['theme.color_primary' => '#0F766E'], $this->staff);
    $themes->publish($this->staff);

    foreach (['/dashboard', '/o/acme-homes', '/portal', '/owner'] as $path) {
        $this->actingAs($this->user)->get($path)
            ->assertOk()
            ->assertSee('--color-primary-700:#0f766e', false)
            ->assertDontSee('--color-primary-700:#1d4ed8', false);
    }

    $this->actingAs($this->staff, 'staff')->get($this->console)
        ->assertOk()
        ->assertSee('--primary-700:'.FilamentColor::convertToOklch('#0f766e'), false)
        ->assertSee('--color-primary-700:#0f766e', false);
});

it('keeps a draft off every public and customer page', function () {
    app(ThemeManager::class)->saveDraft(['theme.color_primary' => '#B91C1C', 'theme.name' => 'Draft Name Ltd'], $this->staff);

    $this->get('/login')->assertOk()->assertDontSee('#b91c1c', false)->assertDontSee('Draft Name Ltd');

    foreach (['/dashboard', '/o/acme-homes', '/portal', '/owner'] as $path) {
        $this->actingAs($this->user)->get($path)
            ->assertOk()
            ->assertSee('--color-primary-700:#1d4ed8', false)
            ->assertDontSee('#b91c1c', false)
            ->assertDontSee('Draft Name Ltd');
    }

    $this->actingAs($this->staff, 'staff')->get($this->console)
        ->assertOk()
        ->assertDontSee('#b91c1c', false);
});

it('shows the draft in the console preview, to staff only', function () {
    app(ThemeManager::class)->saveDraft(['theme.color_primary' => '#B91C1C'], $this->staff);
    $preview = $this->console.'/theme-preview/app';

    $this->actingAs($this->staff, 'staff')->get($preview)
        ->assertOk()
        ->assertSee('--color-primary-700:#b91c1c', false)
        ->assertSee('Preview of the draft theme')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('hides the preview from guests and customers', function () {
    $preview = $this->console.'/theme-preview/app';

    $this->get($preview)->assertRedirect($this->console.'/login');
    $this->actingAs($this->user)->get($preview)->assertRedirect($this->console.'/login');
});

it('loads only the chosen font', function () {
    $themes = app(ThemeManager::class);
    $themes->saveDraft(['theme.font' => 'manrope']);
    $themes->publish();

    $this->actingAs($this->user)->get('/portal')
        ->assertSee('font-family: "Manrope"', false)
        ->assertSee('--font-ui:"Manrope"', false)
        ->assertDontSee('font-family: "Inter"', false);
});

it('names the app and console after the theme', function () {
    $themes = app(ThemeManager::class);
    $themes->saveDraft(['theme.name' => 'Ile Rent']);
    $themes->publish();

    $this->actingAs($this->user)->get('/portal')->assertSee('My rentals - Ile Rent');
    $this->actingAs($this->staff, 'staff')->get($this->console)->assertSee('Ile Rent console');
});
