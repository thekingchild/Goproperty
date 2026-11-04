<?php

declare(strict_types=1);

use App\Audit\AuditChain;
use App\Audit\AuditEntry;
use App\Models\ApprovalRequest;
use App\Models\DataRequest;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Approvals\ApprovalRefused;
use App\Platform\Approvals\ApprovalService;
use App\Privacy\Data\PersonalDataMap;
use App\Privacy\Data\Scrubber;
use App\Privacy\DataRequestRefused;
use App\Privacy\DataRequests;
use App\Privacy\DataRequestStatus;
use App\Privacy\Erasure\OrganizationPurge;
use App\Privacy\Erasure\RetentionEnforcer;
use App\Privacy\Erasure\RetentionRules;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OrganizationScopedModels;
use Tests\Support\PersonalDataWorld as World;

/*
 * P09-02 acceptance: "erasure completes within retention rules". A
 * tenant's erasure and an organization's purge are approved by a second
 * staff member, wait for the 90-day retention window, are carried out by
 * the scheduled retention run on the first run after it — never before —
 * leave everyone else's data exactly as it was, keep the financial records
 * (amounts, dates, numbers) and the audit log (intact, without the
 * person's details).
 */

beforeEach(function () {
    Storage::fake('media');
    Storage::fake('data-exports');
    Storage::fake('compliance');
    $this->freezeTime();
    $this->requester = staffMember(roles: ['compliance-officer']);
    $this->approver = staffNamed('Bola', ['super-admin']);
    $this->acme = Organization::factory()->create(['name' => 'Acme Realty']);
    $this->bayo = Organization::factory()->create(['name' => 'Bayo Homes']);

    $this->ada = User::factory()->create(['name' => 'Ada Obi', 'email' => 'ada@example.ng', 'phone' => '+2348031110001']);
    $this->otherAda = User::factory()->create(['name' => 'Ada Obi', 'email' => 'ada.obi@example.ng', 'phone' => '+2348031110002']);
    $this->bayoOnly = User::factory()->create(['name' => 'Tunde Bayo', 'email' => 'tunde@example.ng', 'phone' => '+2348031110004']);

    $acmeBuilding = World::building($this->acme->id);
    $bayoBuilding = World::building($this->bayo->id);
    $this->mine = World::tenant($this->acme->id, $acmeBuilding, 'Ada', 'Obi', '+2348031110001', 'ada@example.ng', $this->ada->id);
    $this->lookAlike = World::tenant($this->acme->id, $acmeBuilding, 'Ada', 'Obi', '+2348031110002', 'ada.obi@example.ng', $this->otherAda->id, '99999999999');
    $this->elsewhere = World::tenant($this->bayo->id, $bayoBuilding, 'Ada', 'Obi', '+2348031110001', 'ada@example.ng', $this->ada->id);
    $this->bayoTenant = World::tenant($this->bayo->id, $bayoBuilding, 'Tunde', 'Bayo', '+2348031110004', 'tunde@example.ng', $this->bayoOnly->id, '77777777777');

    foreach ([$this->acme, $this->bayo] as $organization) {
        app(OrganizationContext::class)->run($organization, function (): void {
            foreach (array_keys(OrganizationScopedModels::factories()) as $class) {
                OrganizationScopedModels::make($class);
            }
        });
    }

    // Ada's lease at Acme ended ten days ago.
    $this->tenantId = $this->mine['tenant_profiles'][0];
    DB::table('leases')->whereIn('id', $this->mine['leases'])->update(['expired_at' => now()->subDays(10), 'status' => 'expired']);
});

/**
 * Every row of the tables, as stored, to compare before and after.
 *
 * @param  array<string, list<int>>|null  $only  rows per table, or every row of every organization but $except
 * @return array<string, array<int, array<string, mixed>>>
 */
function erasureSnapshot(?array $only = null, ?int $exceptOrganization = null): array
{
    $tables = $only ?? array_fill_keys(app(PersonalDataMap::class)->tables(), null);
    $rows = [];

    foreach ($tables as $table => $ids) {
        $query = DB::table($table)->orderBy('id');
        $ids !== null ? $query->whereIn('id', $ids) : $query->where('organization_id', '!=', $exceptOrganization);
        $rows[$table] = $query->get()->keyBy('id')->map(fn (object $row): array => (array) $row)->all();
    }

    return $rows;
}

function erasureRetentionRun(): void
{
    app(RetentionEnforcer::class)->run();
}

it('acceptance: erases a tenant only after the 90 days since their lease ended, and leaves everyone else untouched', function () {
    $others = erasureSnapshot(array_merge_recursive($this->lookAlike, $this->elsewhere, $this->bayoTenant));
    $before = erasureSnapshot($this->mine);
    $proofFile = DB::table('media')->whereIn('id', $this->mine['media'])->get();

    $request = app(DataRequests::class)->erasure($this->acme, $this->tenantId, 'Ticket 5120: Ada Obi asked to be forgotten; identity checked.', $this->requester);

    expect($request->status)->toBe(DataRequestStatus::AwaitingApproval);

    // A second staff member with data.purge approves; nobody may approve their own.
    $approval = ApprovalRequest::query()->findOrFail($request->approval_request_id);
    expect(fn () => app(ApprovalService::class)->approve($approval, $this->requester))->toThrow(ApprovalRefused::class);
    app(ApprovalService::class)->approve($approval, $this->approver);

    $request->refresh();
    expect($request->status)->toBe(DataRequestStatus::Waiting)
        ->and($request->due_at?->toDateTimeString())->toBe(now()->subDays(10)->addDays(RetentionRules::DAYS)->toDateTimeString());

    // Not before the 90 days: every run until then changes nothing.
    erasureRetentionRun();
    $this->travelTo($request->due_at->subMinute());
    erasureRetentionRun();
    expect(erasureSnapshot($this->mine))->toEqual($before)
        ->and($request->refresh()->status)->toBe(DataRequestStatus::Waiting);

    // The first run after them erases.
    $this->travelTo($request->due_at->addMinutes(15));
    erasureRetentionRun();
    $request->refresh();

    expect($request->status)->toBe(DataRequestStatus::Completed)
        ->and($request->result['files'])->toBe(3);

    $tenant = DB::table('tenant_profiles')->find($this->tenantId);
    expect($tenant->first_name)->toBe('Erased')
        ->and($tenant->email)->toBeNull()
        ->and($tenant->phone)->toBe('erased-'.$this->tenantId)
        ->and($tenant->address)->toBeNull()
        ->and($tenant->id_value)->toBeNull()
        ->and($tenant->emergency_contact)->toBeNull()
        ->and($tenant->user_id)->toBeNull()
        ->and($tenant->deleted_at)->not->toBeNull();

    // Financial records stay: amounts, dates, numbers, statuses; the person goes.
    $payment = DB::table('payments')->find($this->mine['payments'][0]);
    $receipt = DB::table('receipts')->find($this->mine['receipts'][0]);
    $bill = DB::table('bills')->find($this->mine['bills'][0]);
    expect($payment->amount_kobo)->toBe($before['payments'][$payment->id]['amount_kobo'])
        ->and($payment->paid_at)->toBe($before['payments'][$payment->id]['paid_at'])
        ->and($payment->reference)->toBe($before['payments'][$payment->id]['reference'])
        ->and($payment->status)->toBe('confirmed')
        ->and($payment->payer_name)->toBeNull()
        ->and($receipt->number)->toBe($before['receipts'][$receipt->id]['number'])
        ->and($receipt->amount_kobo)->toBe(150_000_000)
        ->and($receipt->details)->not->toContain('Ada Obi')
        ->and($bill->number)->toBe($before['bills'][$bill->id]['number'])
        ->and($bill->amount_kobo)->toBe(150_000_000)
        ->and($bill->status)->toBe('paid')
        ->and($bill->details)->not->toContain('Ada Obi', '+2348031110001', 'ada@example.ng')
        ->and(DB::table('bank_statement_lines')->find($this->mine['bank_statement_lines'][0])->narration)->toBe('TRF FROM '.Scrubber::ERASED.' RENT')
        ->and(DB::table('ticket_messages')->find($this->mine['ticket_messages'][0])->body)->toBe(PersonalDataMap::REMOVED)
        ->and(DB::table('group_posts')->find($this->mine['group_posts'][0])->body)->toBe(PersonalDataMap::REMOVED)
        ->and(DB::table('group_reactions')->whereIn('id', $this->mine['group_reactions'])->exists())->toBeFalse()
        ->and(DB::table('notification_deliveries')->find($this->mine['notification_deliveries'][0])->recipient_contact)->toBe('erased');

    // The agency's internal note on her ticket stays, without her name.
    $note = DB::table('ticket_messages')->where('ticket_id', $this->mine['tickets'][0])->where('is_internal', true)->sole();
    expect($note->body)->toBe('Internal: '.Scrubber::ERASED.' is often late with rent.');

    // Her files are purged, evidence included; nobody else's.
    expect(DB::table('media')->whereIn('id', $this->mine['media'])->exists())->toBeFalse();
    foreach ($proofFile as $file) {
        expect(Storage::disk('media')->exists("{$file->organization_id}/{$file->uuid}/{$file->file_name}"))->toBeFalse();
    }

    // Everyone else exactly as before: the look-alike, her own tenancy at Bayo, Bayo's other tenant.
    expect(erasureSnapshot(array_merge_recursive($this->lookAlike, $this->elsewhere, $this->bayoTenant)))->toEqual($others);
    foreach (DB::table('media')->whereIn('id', array_merge($this->lookAlike['media'], $this->elsewhere['media']))->get() as $file) {
        expect(Storage::disk('media')->exists("{$file->organization_id}/{$file->uuid}/{$file->file_name}"))->toBeTrue();
    }

    // Her account still serves her tenancy at Bayo: untouched.
    expect($this->ada->fresh()->email)->toBe('ada@example.ng');

    // The audit log keeps every step, intact, and never her details; the request's reason loses her name.
    $entries = AuditEntry::query()->where('log_name', 'privacy')->orderBy('id')->get();
    expect($entries->pluck('event')->all())->toBe(['data_erasure.requested', 'data_erasure.approved', 'data_erasure.tenant_erased'])
        ->and(json_encode([$entries->pluck('properties'), $entries->pluck('description')]))->not->toContain('Ada', 'Obi', '+234803', 'ada@example')
        ->and(app(AuditChain::class)->verify()->intact)->toBeTrue()
        ->and($request->reason)->toBe('Ticket 5120: '.Scrubber::ERASED.' asked to be forgotten; identity checked.');
});

it('anonymises the portal account when nothing else uses it', function () {
    $tunde = $this->bayoTenant['tenant_profiles'][0];
    DB::table('leases')->whereIn('id', $this->bayoTenant['leases'])->update(['expired_at' => now()->subDays(200)]);
    DB::table('email_suppressions')->insert(['email' => 'tunde@example.ng', 'reason' => 'bounce', 'suppressed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('consent_records')->insert(['user_id' => $this->bayoOnly->id, 'document' => 'terms', 'granted' => true, 'version' => 'v-1', 'source' => 'signup', 'ip_address' => '102.89.1.2', 'user_agent' => 'Browser', 'recorded_at' => now()]);

    $request = app(DataRequests::class)->erasure($this->bayo, $tunde, 'Ticket 5121', $this->requester);
    app(ApprovalService::class)->approve(ApprovalRequest::query()->findOrFail($request->approval_request_id), $this->approver);
    erasureRetentionRun();

    $account = DB::table('users')->find($this->bayoOnly->id);
    expect($request->refresh()->status)->toBe(DataRequestStatus::Completed)
        ->and($account->name)->toBe('Erased user')
        ->and($account->email)->toBeNull()
        ->and($account->phone)->toBeNull()
        ->and(DB::table('email_suppressions')->where('email', 'tunde@example.ng')->exists())->toBeFalse()
        ->and(DB::table('consent_records')->where('user_id', $this->bayoOnly->id)->whereNotNull('ip_address')->exists())->toBeFalse()
        ->and(DB::table('consent_records')->where('user_id', $this->bayoOnly->id)->count())->toBe(2);
});

it('refuses to erase a tenant with an active lease or unpaid bills, and cancels an approved erasure that no longer qualifies', function () {
    $lookAlike = $this->lookAlike['tenant_profiles'][0];
    DB::table('leases')->whereIn('id', $this->lookAlike['leases'])->update(['status' => 'active']);

    expect(fn () => app(DataRequests::class)->erasure($this->acme, $lookAlike, 'Ticket', $this->requester))->toThrow(DataRequestRefused::class, 'active');

    DB::table('leases')->whereIn('id', $this->lookAlike['leases'])->update(['status' => 'expired', 'expired_at' => now()->subYear()]);
    DB::table('bills')->whereIn('id', $this->lookAlike['bills'])->update(['status' => 'overdue']);
    expect(fn () => app(DataRequests::class)->erasure($this->acme, $lookAlike, 'Ticket', $this->requester))->toThrow(DataRequestRefused::class, 'unpaid');

    // Approved while she qualified; a new lease before the 90 days are up stops it.
    $request = app(DataRequests::class)->erasure($this->acme, $this->tenantId, 'Ticket 5122', $this->requester);
    app(ApprovalService::class)->approve(ApprovalRequest::query()->findOrFail($request->approval_request_id), $this->approver);
    DB::table('leases')->whereIn('id', $this->mine['leases'])->update(['status' => 'active']);
    $this->travelTo($request->refresh()->due_at->addMinute());
    erasureRetentionRun();

    expect($request->refresh()->status)->toBe(DataRequestStatus::Cancelled)
        ->and($request->failure)->toContain('active')
        ->and(DB::table('tenant_profiles')->find($this->tenantId)->first_name)->toBe('Ada');
});

it('waits again when a lease ended after approval, and erases only 90 days after that end', function () {
    $request = app(DataRequests::class)->erasure($this->acme, $this->tenantId, 'Ticket 5123', $this->requester);
    app(ApprovalService::class)->approve(ApprovalRequest::query()->findOrFail($request->approval_request_id), $this->approver);
    $firstDue = $request->refresh()->due_at;

    // A lease of hers that ended 30 days after approval (started and ended inside the window).
    DB::table('leases')->whereIn('id', $this->mine['leases'])->update(['expired_at' => now()->addDays(30)]);
    $laterDue = now()->addDays(30 + RetentionRules::DAYS);

    $this->travelTo($firstDue->addMinute());
    erasureRetentionRun();

    expect($request->refresh()->status)->toBe(DataRequestStatus::Waiting)
        ->and($request->due_at->toIso8601String())->toBe($laterDue->toIso8601String())
        ->and(DB::table('tenant_profiles')->find($this->tenantId)->first_name)->toBe('Ada')
        ->and(AuditEntry::query()->where('event', 'data_erasure.postponed')->count())->toBe(1);

    $this->travelTo($laterDue->copy()->subMinute());
    erasureRetentionRun();
    expect($request->refresh()->status)->toBe(DataRequestStatus::Waiting);

    $this->travelTo($laterDue->copy()->addMinute());
    erasureRetentionRun();

    expect($request->refresh()->status)->toBe(DataRequestStatus::Completed)
        ->and(DB::table('tenant_profiles')->find($this->tenantId)->first_name)->not->toBe('Ada');
});

it('closes the erasure when the approval is rejected, and needs privacy.requests to ask', function () {
    $request = app(DataRequests::class)->erasure($this->acme, $this->tenantId, 'Ticket 5123', $this->requester);
    app(ApprovalService::class)->reject(ApprovalRequest::query()->findOrFail($request->approval_request_id), $this->approver, 'Not verified');

    expect($request->refresh()->status)->toBe(DataRequestStatus::Rejected)
        ->and(fn () => app(DataRequests::class)->erasure($this->acme, $this->tenantId, 'Ticket', staffNamed('Sade', ['support-agent'])))->toThrow(DataRequestRefused::class, 'may not');
});

it('acceptance: purges a cancelled organization only after the 90 days, keeps GoProperty\'s own records, and leaves the other organization untouched', function () {
    // Bayo's subscription ended 30 days ago; the organization is cancelled.
    DB::table('organizations')->where('id', $this->bayo->id)->update(['status' => 'cancelled']);
    DB::table('subscriptions')->where('organization_id', $this->bayo->id)->update(['status' => 'cancelled', 'ended_at' => now()->subDays(30)]);
    $acmeBefore = erasureSnapshot(exceptOrganization: $this->bayo->id);
    $bayoFiles = DB::table('media')->where('organization_id', $this->bayo->id)->get();
    $kept = ['platform_invoices', 'platform_payments', 'platform_refunds', 'subscriptions', 'subscription_unit_events', 'impersonation_sessions'];
    $keptBefore = collect($kept)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->where('organization_id', $this->bayo->id)->count()])->all();

    expect(fn () => app(DataRequests::class)->erasure($this->acme, null, 'Not cancelled', $this->requester))->toThrow(DataRequestRefused::class, 'cancelled');

    $request = app(DataRequests::class)->erasure($this->bayo, null, 'Contract ended; purge after the retention period', $this->requester);
    app(ApprovalService::class)->approve(ApprovalRequest::query()->findOrFail($request->approval_request_id), $this->approver);
    $request->refresh();

    expect($request->status)->toBe(DataRequestStatus::Waiting)
        ->and($request->due_at?->toDateTimeString())->toBe(now()->subDays(30)->addDays(90)->toDateTimeString());

    // Day 89 after cancellation: nothing.
    $this->travelTo($request->due_at->subDay());
    erasureRetentionRun();
    expect(DB::table('tenant_profiles')->where('organization_id', $this->bayo->id)->count())->toBeGreaterThan(0)
        ->and($request->refresh()->status)->toBe(DataRequestStatus::Waiting);

    // Day 90: purged.
    $this->travelTo($request->due_at->addMinutes(15));
    erasureRetentionRun();
    $request->refresh();

    expect($request->status)->toBe(DataRequestStatus::Completed, (string) $request->failure);

    foreach (app(PersonalDataMap::class)->rules() as $table => $rule) {
        $left = DB::table($table)->where('organization_id', $this->bayo->id)->count();

        if ($rule->keptOnPurge === null) {
            // Only the files GoProperty's kept records point at (a subscription payment's proof) stay.
            $table === 'media'
                ? expect(DB::table('media')->where('organization_id', $this->bayo->id)->pluck('id')->diff(DB::table('platform_payments')->pluck('bank_transfer_proof_media_id')->filter())->all())->toBe([])
                : expect($left)->toBe(0, "{$table} still has Bayo's rows");
        }
    }

    foreach ($kept as $table) {
        expect(DB::table($table)->where('organization_id', $this->bayo->id)->count())->toBe($keptBefore[$table], $table);
    }

    // Bayo's files are gone from the disk with their rows.
    foreach ($bayoFiles as $file) {
        if (! DB::table('media')->where('id', $file->id)->exists()) {
            expect(Storage::disk('media')->exists("{$file->organization_id}/{$file->uuid}/{$file->file_name}"))->toBeFalse();
        }
    }

    // Acme exactly as it was, Ada's account (still Acme's tenant) untouched; Bayo's own tenant's account anonymised.
    expect(erasureSnapshot(exceptOrganization: $this->bayo->id))->toEqual($acmeBefore)
        ->and($this->ada->fresh()->email)->toBe('ada@example.ng')
        ->and($this->bayoOnly->fresh()->email)->toBeNull();

    // The tombstone keeps the name GoProperty's invoices carry, nothing else.
    $tombstone = DB::table('organizations')->find($this->bayo->id);
    expect($tombstone->name)->toBe('Bayo Homes')
        ->and($tombstone->slug)->toBe('purged-'.$this->bayo->id)
        ->and($tombstone->address)->toBeNull()
        ->and($tombstone->deleted_at)->not->toBeNull()
        ->and(app(RetentionRules::class)->organizationPurged($this->bayo->id))->toBeTrue();

    $entry = AuditEntry::query()->where('event', 'data_erasure.organization_purged')->sole();
    expect(json_encode($entry->properties))->not->toContain('Tunde', 'Ada', '+234', '@example.ng')
        ->and(app(AuditChain::class)->verify()->intact)->toBeTrue();
});

it('clears another organization\'s optional link to a purged row, and changes nothing else of it', function () {
    DB::table('organizations')->where('id', $this->bayo->id)->update(['status' => 'cancelled']);
    DB::table('subscriptions')->where('organization_id', $this->bayo->id)->update(['status' => 'cancelled', 'ended_at' => now()->subDays(100)]);
    // Acme handed a tenant to Bayo once: its line points at Bayo's copy of the tenant.
    $line = $this->mine['management_handover_tenants'][0];
    DB::table('management_handover_tenants')->where('id', $line)->update(['receiving_tenant_profile_id' => $this->bayoTenant['tenant_profiles'][0]]);
    $before = (array) DB::table('management_handover_tenants')->find($line);

    $request = app(DataRequests::class)->erasure($this->bayo, null, 'Purge after retention', $this->requester);
    app(ApprovalService::class)->approve(ApprovalRequest::query()->findOrFail($request->approval_request_id), $this->approver);
    erasureRetentionRun();

    expect($request->refresh()->status)->toBe(DataRequestStatus::Completed, (string) $request->failure)
        ->and((array) DB::table('management_handover_tenants')->find($line))->toEqual(['receiving_tenant_profile_id' => null] + $before);
});

it('does not purge an organization reactivated before its 90 days are up', function () {
    DB::table('organizations')->where('id', $this->bayo->id)->update(['status' => 'cancelled']);
    DB::table('subscriptions')->where('organization_id', $this->bayo->id)->update(['status' => 'cancelled', 'ended_at' => now()]);
    $request = app(DataRequests::class)->erasure($this->bayo, null, 'Purge after retention', $this->requester);
    app(ApprovalService::class)->approve(ApprovalRequest::query()->findOrFail($request->approval_request_id), $this->approver);

    DB::table('organizations')->where('id', $this->bayo->id)->update(['status' => 'active']);
    $this->travel(91)->days();
    erasureRetentionRun();

    expect($request->refresh()->status)->toBe(DataRequestStatus::Cancelled)
        ->and(DB::table('tenant_profiles')->where('organization_id', $this->bayo->id)->count())->toBeGreaterThan(0);
});

it('purges over several runs when the organization is big, keeping its place', function () {
    DB::table('organizations')->where('id', $this->bayo->id)->update(['status' => 'cancelled']);
    DB::table('subscriptions')->where('organization_id', $this->bayo->id)->update(['status' => 'cancelled', 'ended_at' => now()->subDays(100)]);
    $request = DataRequest::query()->forceCreate([
        'kind' => 'erasure', 'scope' => 'organization', 'organization_id' => $this->bayo->id,
        'status' => DataRequestStatus::Running, 'reason' => 'Test', 'requested_by_staff_id' => $this->requester->id,
    ]);
    $purge = app(OrganizationPurge::class);
    $runs = 0;

    do {
        $done = app(OrganizationContext::class)->run($this->bayo, fn (): bool => $purge->work($request->refresh(), microtime(true) + 0.005));
        $runs++;
    } while (! $done && $runs < 2000);

    expect($done)->toBeTrue()
        ->and($runs)->toBeGreaterThan(2)
        ->and(DB::table('tenant_profiles')->where('organization_id', $this->bayo->id)->count())->toBe(0);
});
