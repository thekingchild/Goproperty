<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Livewire\Settings\DataExportPage;
use App\Models\DataRequest;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use App\Privacy\DataRequests;
use App\Privacy\DataRequestStatus;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\PersonalDataWorld as World;

require_once __DIR__.'/../../Support/impersonation.php';

/*
 * P09-02: Settings → Data export. The organization's owners export the
 * organization's data, or one tenant's (the organization answers its
 * tenant's request); never administrators or agents, never during a
 * GoProperty support session, and still while the organization is
 * suspended (an export).
 */

beforeEach(function () {
    Storage::fake('media');
    Storage::fake('data-exports');
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ngozi Owner']), $this->acme);
    $this->ada = World::tenant($this->acme->id, World::building($this->acme->id), 'Ada', 'Obi', '+2348031110001', 'ada@example.ng', User::factory()->create()->id);
});

function dataExportPage(User $user, Organization $organization): Testable
{
    test()->actingAs($user);
    app(OrganizationContext::class)->set($organization);

    return Livewire::test(DataExportPage::class);
}

it('lets the owner export the organization and one tenant, and download each through a signed link', function () {
    $this->actingAs($this->owner)->get('/o/acme-homes/settings/data-export')->assertOk()->assertSee('Export your organization')->assertSee('Data export');

    dataExportPage($this->owner, $this->acme)->call('exportOrganization')->assertHasNoErrors()->assertSet('notice', fn (?string $notice): bool => $notice !== null);
    dataExportPage($this->owner, $this->acme)
        ->set('tenantSearch', 'Ada')
        ->assertSee('Ada Obi · +2348031110001')
        ->call('chooseTenant', $this->ada['tenant_profiles'][0])
        ->set('reason', 'Asked by email on 3 November')
        ->call('exportTenant')
        ->assertHasNoErrors();

    $requests = DataRequest::query()->orderBy('id')->get();
    expect($requests)->toHaveCount(2)
        ->and($requests->pluck('status')->unique()->all())->toBe([DataRequestStatus::Ready])
        ->and($requests->pluck('requested_by_user_id')->unique()->all())->toBe([$this->owner->id])
        ->and($requests[1]->tenant_profile_id)->toBe($this->ada['tenant_profiles'][0]);

    $link = app(DataRequests::class)->downloadUrl($requests[1], console: false);
    $this->actingAs($this->owner)->get('/o/acme-homes/settings/data-export')->assertSee(e($link), false);
    $this->actingAs($this->owner)->get($link)->assertOk()->assertDownload("data-export-{$requests[1]->id}.zip");
    expect(AuditEntry::query()->where('event', 'data_export.downloaded')->sole()->causer_id)->toBe($this->owner->id);

    // An unsigned link, and another organization's export, are refused.
    $this->actingAs($this->owner)->get('/o/acme-homes/settings/data-export/'.$requests[1]->id.'/download')->assertForbidden();
});

it('is for owners only, never another organization\'s export', function () {
    $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);

    $this->actingAs($admin)->get('/o/acme-homes/settings/data-export')->assertForbidden();
    $this->actingAs($agent)->get('/o/acme-homes/settings/data-export')->assertForbidden();

    $bayo = Organization::factory()->create(['slug' => 'bayo-homes']);
    $bayoOwner = joinAs(User::factory()->create(), $bayo);
    $theirs = app(DataRequests::class)->export($bayo, null, 'Theirs', $bayoOwner)->refresh();
    $link = app(DataRequests::class)->downloadUrl($theirs, console: false);

    // Bayo's link under Acme's address: not found for Acme's owner.
    $this->actingAs($this->owner)->get(str_replace('/o/bayo-homes/', '/o/acme-homes/', $link))->assertForbidden();
    $this->actingAs($this->owner)->get($link)->assertNotFound();
});

it('still exports while the organization is suspended', function () {
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    dataExportPage($this->owner, $this->acme->fresh())->call('exportOrganization')->assertHasNoErrors();

    expect(DataRequest::query()->sole()->status)->toBe(DataRequestStatus::Ready);
});

it('refuses the export to GoProperty staff impersonating the owner', function () {
    enterSupport(startSupport(staffMember(['name' => 'Sola Support', 'email' => 'sola@goproperty.ng']), $this->owner, $this->acme, ImpersonationMode::Write))->assertRedirect('/o/acme-homes');

    asSupport()->get('/o/acme-homes/settings/data-export')->assertForbidden();
    expect(DataRequest::query()->exists())->toBeFalse();
});
