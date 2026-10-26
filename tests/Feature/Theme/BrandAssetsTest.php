<?php

declare(strict_types=1);

use App\Models\PlatformStaff;
use App\Models\StaffStatus;
use App\Models\User;
use App\Theme\BrandAssets;
use App\Theme\ThemeManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * P00-09c: brand images at /brand/{role} (PLATFORM_CONSOLE.md § 9.2).
 */

beforeEach(function () {
    Storage::fake('brand');
    $this->assets = app(BrandAssets::class);
});

it('serves the generated mark in theme colours when nothing is uploaded', function () {
    $themes = app(ThemeManager::class);
    $themes->saveDraft(['theme.color_primary' => '#0F766E']);
    $themes->publish();

    $this->get('/brand/logo-light')->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeader('Cache-Control', 'max-age=300, public')
        ->assertSee('fill="#0f766e"', false)
        ->assertCookieMissing(config('session.cookie'));

    $this->get('/brand/favicon')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    $this->get('/favicon.ico')->assertRedirect('/brand/favicon');

    $png = $this->get('/brand/apple-touch-icon')->assertOk()->assertHeader('Content-Type', 'image/png')->getContent();
    expect(getimagesizefromstring((string) $png))->toMatchArray([0 => 180, 1 => 180]);
});

it('has no fallback for photos', function (string $role) {
    $this->get('/brand/'.$role)->assertNotFound();
})->with(['hero', 'auth', 'share-image']);

it('refuses unknown roles and odd versions', function () {
    $this->get('/brand/secrets')->assertNotFound();
    $this->get('/brand/hero?v=../../.env')->assertNotFound();
    $this->get('/brand/hero?v=0123456789abcdef')->assertNotFound();
});

it('makes a touch icon from an uploaded raster icon', function () {
    $path = $this->assets->store('icon', UploadedFile::fake()->image('icon.png', 512, 512));
    $themes = app(ThemeManager::class);
    $themes->saveDraft(['theme.icon' => $path]);
    $themes->publish();

    $png = $this->get('/brand/apple-touch-icon')->assertOk()->getContent();
    expect(getimagesizefromstring((string) $png))->toMatchArray([0 => 180, 1 => 180]);
});

it('turns the share image into a JPEG without photo details', function () {
    $path = $this->assets->store('share-image', UploadedFile::fake()->image('card.png', 2400, 1260));

    expect($path)->toMatch('#^share/[a-f0-9]{32}\.jpg$#')
        ->and(getimagesizefromstring((string) Storage::disk('brand')->get($path)))->toMatchArray([0 => 1200, 1 => 630, 'mime' => 'image/jpeg']);
});

it('checks image rules for each role', function (string $role, UploadedFile $file, ?string $problem) {
    expect($this->assets->problem($role, $file))->toBe($problem);
})->with([
    'hero ok' => ['hero', UploadedFile::fake()->image('hero.jpg', 2400, 1350), null],
    'hero too narrow' => ['hero', UploadedFile::fake()->image('hero.jpg', 400, 300), 'The photo must be at least 600 px wide.'],
    'favicon too small' => ['favicon', UploadedFile::fake()->image('fav.png', 16, 16), 'The favicon must be at least 48 × 48 px.'],
    'share image too big' => ['share-image', UploadedFile::fake()->image('card.png', 5000, 1000), 'The share image must be 200 to 4096 px on each side.'],
    'SVG as hero' => ['hero', UploadedFile::fake()->createWithContent('hero.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'), 'Upload a JPG, PNG or WEBP file.'],
    'JPEG named .png' => ['logo-light', fn () => UploadedFile::fake()->createWithContent('logo.png', (string) UploadedFile::fake()->image('x.jpg', 600, 150)->getContent()), 'The file name ends in .png but the file is JPG.'],
    'SVG with entity' => ['icon', UploadedFile::fake()->createWithContent('icon.svg', '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg"/>'), 'This SVG contains scripts, links or embedded content, which are not allowed. Export it again as a plain SVG.'],
    'SVG with data link' => ['logo-dark', UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><image href="data:image/png;base64,AAAA"/></svg>'), 'This SVG contains scripts, links or embedded content, which are not allowed. Export it again as a plain SVG.'],
    'ICO favicon' => ['favicon', UploadedFile::fake()->createWithContent('favicon.ico', "\x00\x00\x01\x00".str_repeat("\x00", 40)), null],
]);

it('measures white text over a photo and its overlay', function () {
    // Fake images are black.
    $photo = $this->assets->store('hero', UploadedFile::fake()->image('dark.jpg', 1200, 800));

    expect($this->assets->overlayContrast($photo, '#0f1b44', 60))->toBeGreaterThan(15)
        ->and($this->assets->overlayContrast($photo, '#ffffff', 90))->toBeLessThan(1.5)
        ->and($this->assets->overlayContrast($photo, '#ffffff', 55))->toBeBetween(3.0, 4.5);
});

it('puts uploaded logos, icon and favicon on the public site, the app and the console', function () {
    $svg = fn (string $fill) => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 30"><rect width="120" height="30" fill="'.$fill.'"/></svg>');
    $dark = $this->assets->store('logo-dark', $svg('#ffffff'));
    $light = $this->assets->store('logo-light', $svg('#0f766e'));
    $icon = $this->assets->store('icon', UploadedFile::fake()->image('icon.png', 512, 512));

    $themes = app(ThemeManager::class);
    $themes->saveDraft(['theme.logo_dark' => $dark, 'theme.logo_light' => $light, 'theme.icon' => $icon, 'theme.logo_mode' => 'logo_only']);
    $themes->publish();

    $version = fn (string $path): string => pathinfo($path, PATHINFO_FILENAME);

    $this->get('/')
        ->assertSee('<img class="brand__logo" src="'.url('/brand/logo-dark?v='.$version($dark)).'"', false)
        ->assertSee('<img class="brand__logo" src="'.url('/brand/logo-light?v='.$version($light)).'"', false)
        ->assertSee('<link rel="icon" href="'.url('/brand/icon?v='.$version($icon)).'">', false)
        ->assertDontSee('<span>GoProperty</span>', false);

    $this->actingAs(User::factory()->create())->get('/portal')
        ->assertSee(url('/brand/icon?v='.$version($icon)), false);

    $staff = PlatformStaff::query()->create(['name' => 'Ada', 'email' => 'ada@goproperty.ng', 'password' => 'x-long-password-1', 'status' => StaffStatus::Active]);
    $this->actingAs($staff, 'staff')->get('/'.config('app.admin_path'))
        ->assertSee(url('/brand/logo-light?v='.$version($light)), false);
});
