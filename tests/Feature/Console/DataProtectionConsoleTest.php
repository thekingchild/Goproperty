<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Models\ApprovalRequest;
use App\Models\DataBreach;
use App\Models\DataRequest;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Filament\Pages\Settings\DataProtectionSettings;
use App\Platform\Filament\Resources\DataBreaches\Pages\ListDataBreaches;
use App\Platform\Filament\Resources\DataRequests\Pages\ListDataRequests;
use App\Privacy\Breaches\BreachRegister;
use App\Privacy\Breaches\BreachStatus;
use App\Privacy\DataRequestRefused;
use App\Privacy\DataRequests;
use App\Privacy\DataRequestStatus;
use App\Settings\SettingsStore;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\PersonalDataWorld as World;

/*
 * P09-02: the console's data protection pages (PLATFORM_CONSOLE.md § 11):
 * Compliance → Data requests (exports with their signed, audited download;
 * erasures through the two-person approval), Compliance → Breach register
 * (the 72-hour deadline) and Settings → Data protection (the officer).
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    Storage::fake('media');
    Storage::fake('data-exports');
    seedStaffRoles();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00', 'Africa/Lagos'));
    $this->console = '/'.config('app.admin_path');
    $this->ngozi = staffNamed('Ngozi', ['compliance-officer']);
    $this->acme = Organization::factory()->create(['name' => 'Acme Realty']);
    $this->ada = World::tenant($this->acme->id, World::building($this->acme->id), 'Ada', 'Obi', '+2348031110001', 'ada@example.ng', User::factory()->create()->id);
});

it('exports a tenant\'s data from the console and downloads it through a signed, audited link', function () {
    $this->actingAs($this->ngozi, 'staff');
    $this->get($this->console.'/data-requests')->assertOk()->assertSee('Data requests');

    Livewire::test(ListDataRequests::class)
        ->callAction('export', data: ['organization_id' => $this->acme->id, 'scope' => 'tenant', 'tenant_profile_id' => $this->ada['tenant_profiles'][0], 'reason' => 'Ticket 4411: tenant asked by email; identity checked'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $request = DataRequest::query()->sole();
    expect($request->status)->toBe(DataRequestStatus::Ready)
        ->and($request->requested_by_staff_id)->toBe($this->ngozi->id);

    Livewire::test(ListDataRequests::class)->callTableAction('download', $request)->assertRedirect();

    $link = app(DataRequests::class)->downloadUrl($request, console: true);
    $response = $this->get($link)->assertOk()->assertDownload("data-export-{$request->id}.zip");
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($request->refresh()->downloads)->toBe(1)
        ->and(AuditEntry::query()->where('event', 'data_export.downloaded')->sole()->causer_id)->toBe($this->ngozi->id);

    // Unsigned, tampered or expired links, and staff without privacy.requests, are refused.
    $this->get($this->console.'/data-requests/'.$request->id.'/download')->assertForbidden();
    $this->get($link.'x')->assertForbidden();
    $this->flushSession();
    $this->actingAs(staffNamed('Sade', ['support-agent']), 'staff')->get($link)->assertForbidden();
    $this->flushSession();
    $this->travel(16)->minutes();
    $this->actingAs($this->ngozi, 'staff')->get($link)->assertForbidden();
});

it('asks for an erasure through the two-person approval, and shows the refusal for an organization that is not cancelled', function () {
    $this->actingAs($this->ngozi, 'staff');
    $tenant = $this->ada['tenant_profiles'][0];

    Livewire::test(ListDataRequests::class)
        ->callAction('erase', data: ['organization_id' => $this->acme->id, 'scope' => 'organization', 'reason' => 'Owner asked to close and delete', 'understood' => true])
        ->assertNotified('Only a cancelled or deleted organization is purged: this one is active.');

    Livewire::test(ListDataRequests::class)
        ->callAction('erase', data: ['organization_id' => $this->acme->id, 'scope' => 'tenant', 'tenant_profile_id' => $tenant, 'reason' => 'Ticket 5120: tenant asked to be forgotten', 'understood' => true])
        ->assertHasNoActionErrors();

    $request = DataRequest::query()->sole();
    expect($request->status)->toBe(DataRequestStatus::AwaitingApproval)
        ->and(ApprovalRequest::query()->findOrFail($request->approval_request_id)->action->value)->toBe('data_purge');

    // It can be withdrawn before it starts.
    Livewire::test(ListDataRequests::class)->callTableAction('cancel', $request)->assertNotified('Withdrawn.');
    expect($request->refresh()->status)->toBe(DataRequestStatus::Cancelled);
});

it('records a breach with its 72-hour deadline, each notice, and its close', function () {
    $tunde = staffNamed('Tunde', ['technical-admin']);
    $this->actingAs($tunde, 'staff');
    $this->get($this->console.'/breaches')->assertOk();

    Livewire::test(ListDataBreaches::class)->callAction('record', data: [
        'title' => 'Export file emailed to the wrong address',
        'description' => 'A tenant export was sent to a mistyped address.',
        'aware_at' => '2026-11-04 08:30:00',
        'risk' => 'risk',
        'data_involved' => 'One tenant\'s name, phone and lease',
        'people_affected' => 1,
        'organizations_affected' => '#'.$this->acme->id,
    ])->assertHasNoActionErrors();

    $breach = DataBreach::query()->sole();
    expect($breach->reference)->toBe('BR-2026-'.str_pad((string) $breach->id, 4, '0', STR_PAD_LEFT))
        // Typed in Lagos time, kept in UTC: 72 hours from then.
        ->and($breach->noticeDeadline()->toDateTimeString())->toBe(CarbonImmutable::parse('2026-11-04 08:30:00', 'Africa/Lagos')->utc()->addHours(72)->toDateTimeString())
        ->and($breach->isOverdue())->toBeFalse()
        ->and($breach->recorded_by_staff_id)->toBe($tunde->id);

    $this->get($this->console.'/breaches')->assertSee('Due by');

    $this->travel(4)->days();
    expect($breach->refresh()->isOverdue())->toBeTrue();
    // A new sign-in: the old session idled out.
    $this->flushSession();
    $this->actingAs($tunde, 'staff')->get($this->console.'/breaches')->assertSee('Overdue since');

    Livewire::test(ListDataBreaches::class)
        ->callTableAction('commission', $breach, ['at' => now()->subHour()->toDateTimeString(), 'reference' => 'NDPC/2026/118'])
        ->callTableAction('contained', $breach, ['at' => now()->subHours(2)->toDateTimeString()])
        ->callTableAction('close', $breach, ['note' => 'Exports now go only through the signed download link.']);

    $breach->refresh();
    expect($breach->commission_reference)->toBe('NDPC/2026/118')
        ->and($breach->isOverdue())->toBeFalse()
        ->and($breach->status)->toBe(BreachStatus::Closed)
        ->and(AuditEntry::query()->where('log_name', 'privacy')->pluck('event')->all())->toBe(['breach.recorded', 'breach.commission_notified', 'breach.contained', 'breach.closed']);

    // Closed: no more changes; support staff never see the register.
    expect(fn () => app(BreachRegister::class)->peopleNotified($breach, $tunde, null))->toThrow(DataRequestRefused::class, 'closed');
    $this->flushSession();
    $this->actingAs(staffNamed('Sade', ['support-agent']), 'staff')->get($this->console.'/breaches')->assertForbidden();
});

it('names the data protection officer for the privacy page, both name and email or neither', function () {
    $this->actingAs($this->ngozi, 'staff');

    Livewire::test(DataProtectionSettings::class)
        ->set('data.name', 'Funmi Adebayo')->set('data.email', '')
        ->callAction('save')->assertHasErrors(['data.email']);

    Livewire::test(DataProtectionSettings::class)
        ->set('data.name', 'Funmi Adebayo')->set('data.email', 'dpo@goproperty.ng')->set('data.phone', '+234 803 123 4567')
        ->callAction('save')->assertNotified('Saved: the privacy page shows these details');

    expect(app(SettingsStore::class)->get('privacy.dpo_email'))->toBe('dpo@goproperty.ng')
        ->and(AuditEntry::query()->where('log_name', 'settings')->whereJsonContains('properties->key', 'privacy.dpo_email')->exists())->toBeTrue();

    $this->get('/privacy')->assertSee('Funmi Adebayo');
    $this->flushSession();
    $this->actingAs(staffNamed('Tunde', ['technical-admin']), 'staff')->get($this->console.'/settings/data-protection')->assertForbidden();
});

it('shows the requests to an approver with data.purge', function () {
    app(DataRequests::class)->export($this->acme, null, 'Owner asked for a copy', $this->ngozi);

    $this->actingAs(staffNamed('Bola', ['super-admin']), 'staff')->get($this->console.'/data-requests')->assertOk()->assertSee('Owner asked for a copy');
    $this->flushSession();
    $this->actingAs(staffNamed('Funke', ['finance-admin']), 'staff')->get($this->console.'/data-requests')->assertForbidden();
});
