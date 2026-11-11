<?php

declare(strict_types=1);

use App\Theme\ThemeContext;
use App\Theme\ThemeManager;

/*
 * The maintenance page (resources/views/errors/503.blade.php): visitors
 * see the platform's own page, in the published theme, while the site is
 * down for an upgrade (docs/runbooks/upgrade.md). Self-contained, since
 * everything else answers 503 then, and still shown when the theme
 * settings cannot be read mid-upgrade.
 */

beforeEach(function () {
    config(['app.maintenance.driver' => 'array']);
});

afterEach(fn () => app()->maintenanceMode()->deactivate());

it('shows visitors the platform\'s own page in the published theme, reloading by itself', function () {
    $themes = app(ThemeManager::class);
    $themes->saveDraft(['theme.name' => 'Ile Rent']);
    $themes->publish();
    app()->maintenanceMode()->activate(['retry' => 90]);

    $response = $this->get('/')->assertStatus(503)->assertHeader('Retry-After', '90');
    $html = (string) $response->getContent();

    expect($html)->toContain('data-test="maintenance"', 'Ile Rent is being updated', 'We are updating Ile Rent', '<meta http-equiv="refresh" content="90">', '--color-primary-700')
        ->not->toContain('Service Unavailable', '/build/', 'livewire', '/brand/');
});

it('reloads every minute when maintenance mode gives no retry time', function () {
    app()->maintenanceMode()->activate([]);

    expect((string) $this->get('/login')->assertStatus(503)->getContent())->toContain('<meta http-equiv="refresh" content="60">');
});

it('still shows the page with the default theme when the settings cannot be read', function () {
    app()->maintenanceMode()->activate([]);
    app()->bind(ThemeContext::class, fn () => throw new RuntimeException('The database is being upgraded.'));

    expect((string) $this->get('/')->assertStatus(503)->getContent())->toContain('data-test="maintenance"', 'We are updating GoProperty');
});

it('answers JSON requests with JSON, as before', function () {
    app()->maintenanceMode()->activate([]);

    $this->getJson('/api/anything')->assertStatus(503)->assertJsonStructure(['message']);
});
