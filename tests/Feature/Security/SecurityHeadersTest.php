<?php

declare(strict_types=1);

use App\Http\Middleware\SecurityHeaders;
use App\Models\Organization;
use App\Models\User;

/*
 * Security review 2026-11, finding SR-04 (docs/security/review-2026-11.md;
 * ARCHITECTURE.md § 10 "HTTPS enforced HSTS"): every surface gets HSTS when
 * the site is on https, and is never framed by another site.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    config(['app.url' => 'https://goproperty.ng']);
});

it('sends HSTS, framing, nosniff and referrer headers on every surface', function (string $surface) {
    $response = match ($surface) {
        'workspace' => $this->actingAs($this->owner)->get('/o/acme-homes/buildings'),
        'portal' => $this->actingAs($this->owner)->get('/settings/profile'),
        'console' => $this->actingAs(staffMember(), 'staff')->get('/'.config('app.admin_path')),
        'sign-in' => $this->get('/login'),
        'public' => $this->get('/privacy'),
        'health' => $this->get('/up'),
    };

    $response->assertOk()
        ->assertHeader('Strict-Transport-Security', SecurityHeaders::HSTS)
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($response->headers->get('Referrer-Policy'))->not->toBeNull();
})->with(['workspace', 'portal', 'console', 'sign-in', 'public', 'health']);

it('keeps the stricter headers a route sets itself', function () {
    $response = $this->get('/login')->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")
        ->and($response->headers->get('Referrer-Policy'))->toBe('same-origin');
});

it('sends no HSTS for a site on plain http or a local copy', function () {
    config(['app.url' => 'http://localhost']);
    $this->get('/login')->assertOk()->assertHeaderMissing('Strict-Transport-Security');

    config(['app.url' => 'https://goproperty.test']);
    app()['env'] = 'local';

    try {
        $this->get('/login')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    } finally {
        app()['env'] = 'testing';
    }
});
