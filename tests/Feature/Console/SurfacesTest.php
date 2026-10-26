<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

/*
 * P00-08 acceptance: a seeded demo user can sign in and see the empty shells
 * of all three surfaces (ONBOARDING_COMPLIANCE.md § 1).
 */

it('lets the seeded demo user sign in and open every surface', function () {
    $this->seed(DatabaseSeeder::class);

    $this->post('/login', ['email' => 'test@example.com', 'password' => 'password'])
        ->assertRedirect('/dashboard');

    $this->get('/o/demo-realty')->assertOk()->assertSee('Demo Realty')->assertSee('Your properties, leases, bills and payments');
    $this->get('/portal')->assertOk()->assertSee('My rentals');
    $this->get('/owner')->assertOk()->assertSee('My properties');
});

it('links every surface from the sidebar', function () {
    $user = User::factory()->create();
    $user->organizations()->attach(Organization::factory()->create(['name' => 'Acme Homes', 'slug' => 'acme-homes']));

    $this->actingAs($user)->get('/portal')
        ->assertSee('Acme Homes')
        ->assertSee('/o/acme-homes', false)
        ->assertSee(route('portal.home'), false)
        ->assertSee(route('owner.home'), false)
        ->assertDontSee('livewire-starter-kit');
});

it('sends guests to sign in', function (string $path) {
    $this->get($path)->assertRedirect('/login');
})->with(['/o/demo-realty', '/portal', '/owner']);

it('keeps another organization\'s workspace hidden', function () {
    Organization::factory()->create(['slug' => 'other-realty']);

    $this->actingAs(User::factory()->create())->get('/o/other-realty')->assertNotFound();
});

it('reserves the pay and verify pages without sign-in', function (string $path) {
    $this->get($path)->assertNotFound();
})->with(['/pay/PAY-2026-0001', '/verify/RCPT-7Q4M-K2XD', '/pay/x', '/verify/<script>']);

it('builds pay and verify links', function () {
    expect(route('pay.show', 'PAY-2026-0001', false))->toBe('/pay/PAY-2026-0001')
        ->and(route('documents.verify', 'RCPT-7Q4M', false))->toBe('/verify/RCPT-7Q4M');
});
