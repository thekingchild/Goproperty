<?php

declare(strict_types=1);

use App\Hosting\Heartbeats;

/*
 * P01-09: the scheduler heartbeat the installer's finish screen polls for
 * its live cron check (INSTALLER.md § 5, PLATFORM_CONSOLE.md § 2).
 */

function heartbeatUrl(): string
{
    return '/'.config('app.admin_path').'/system/heartbeat';
}

it('tells signed-in staff when the scheduler and the queue tick last ran', function () {
    $staff = staffMember();

    $this->actingAs($staff, 'staff')->getJson(heartbeatUrl())
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['scheduler' => ['at' => null, 'fresh' => false], 'queue_tick' => ['at' => null, 'fresh' => false]]);

    app(Heartbeats::class)->beat(Heartbeats::SCHEDULER);

    $this->actingAs($staff, 'staff')->getJson(heartbeatUrl())->assertJsonPath('scheduler.fresh', true);

    $this->travel(6)->minutes();

    $this->actingAs($staff, 'staff')->getJson(heartbeatUrl())->assertJsonPath('scheduler.fresh', false);
});

it('sends guests to the console sign-in', function () {
    $this->get(heartbeatUrl())->assertRedirect('/'.config('app.admin_path').'/login');
});

it('sends staff without 2FA to set it up', function () {
    $this->actingAs(staffMember([], withTwoFactor: false), 'staff')->get(heartbeatUrl())
        ->assertRedirect('/'.config('app.admin_path').'/multi-factor-authentication/set-up');
});

it('refuses staff without the system.status permission', function () {
    // P01-07: the permission comes from staff roles (technical-admin, super-admin).
    $this->actingAs(staffMember(roles: ['analyst']), 'staff')->getJson(heartbeatUrl())->assertForbidden();
});

it('answers technical admins', function () {
    $this->actingAs(staffMember(roles: ['technical-admin']), 'staff')->getJson(heartbeatUrl())->assertOk();
});
