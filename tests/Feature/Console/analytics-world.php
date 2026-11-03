<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\TransactionTier;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * The fixture of the System analytics tests (P08-02 acceptance "Metrics
 * match a fixture"): a hand-computed world around the Lagos day 1 March
 * 2027, whose window is 28 Feb 23:00 UTC up to 1 Mar 23:00 UTC. Records sit
 * on both sides of those edges. The clock is frozen at 2 March 2027 05:00
 * Lagos. Expected figures are written out in the tests, worked by hand
 * from this file.
 *
 *   A  Adeyemi Realty   agency, Lagos, tier 2, active monthly Starter, 13 billable units
 *   B  Bello Homes      agency, Oyo, past due yearly Growth, 60 units
 *   C  Chike Properties hybrid, Lagos, trial of Starter, 5 units (created 1 s before the day)
 *   D  Dara Estates     agency, no state, cancelled from its trial on the day, 2 units
 *   E  Ese (landlord)   platform-covered, active Starter, 4 units
 *   F  Femi Lettings    agency, Kano, signed up at the day's first second, no plan yet
 *   G  Gbenga (landlord) platform-covered, signed up on the day
 *   J  Jide Homes       agency, cancelled on the day after paying (churn)
 *   K  Kola Homes       agency, Lagos, active Starter since 25 Feb, 8 units, first payment on the day
 */

const SA_NOW = '2027-03-02 04:00:00';   // UTC: 05:00 in Lagos
const SA_DAY = '2027-03-01';
const SA_FIRST_SECOND = '2027-02-28 23:00:00';
const SA_LAST_SECOND = '2027-03-01 22:59:59';
const SA_BEFORE = '2027-02-28 22:59:59';
const SA_AFTER = '2027-03-01 23:00:00';
const SA_LONG_AGO = '2026-06-01 10:00:00';

/** @param  array<string, mixed>  $attributes */
function saOrganization(string $name, array $attributes = [], string $createdAt = SA_LONG_AGO): int
{
    $organization = Organization::factory()->create(['name' => $name, ...$attributes]);
    DB::table('organizations')->where('id', $organization->id)->update(['created_at' => $createdAt]);

    return $organization->id;
}

function saPlan(string $key): int
{
    return (int) DB::table('plans')->where('key', $key)->value('id');
}

function saSubscription(int $organization, string $plan, string $status, string $cycle = 'monthly', ?string $trialEndsAt = null): void
{
    DB::table('subscriptions')->insert([
        'organization_id' => $organization, 'plan_id' => saPlan($plan), 'billing_cycle' => $cycle, 'status' => $status,
        'trial_ends_at' => $trialEndsAt, 'created_at' => SA_LONG_AGO, 'updated_at' => SA_LONG_AGO,
    ]);
}

function saBuilding(int $organization, string $status = 'active', bool $deleted = false): int
{
    static $n = 0;
    $n++;

    return DB::table('properties')->insertGetId([
        'organization_id' => $organization, 'name' => "Block {$n}", 'slug' => "block-{$n}", 'property_type' => 'apartment_block',
        'address' => "{$n} Allen Avenue", 'state' => 'Lagos', 'status' => $status, 'deleted_at' => $deleted ? SA_LONG_AGO : null,
        'created_at' => SA_LONG_AGO, 'updated_at' => SA_LONG_AGO,
    ]);
}

/** @return list<int> */
function saUnits(int $organization, int $building, int $count, string $createdAt = SA_LONG_AGO, bool $deleted = false): array
{
    static $n = 0;
    $ids = [];

    for ($i = 0; $i < $count; $i++) {
        $n++;
        $ids[] = DB::table('units')->insertGetId([
            'organization_id' => $organization, 'property_id' => $building, 'unit_label' => "Flat {$n}", 'unit_type' => 'flat',
            'status' => 'vacant', 'created_at' => $createdAt, 'updated_at' => $createdAt, 'deleted_at' => $deleted ? $createdAt : null,
        ]);
    }

    return $ids;
}

/** @param  array<string, mixed>  $attributes */
function saInvoice(int $organization, array $attributes): int
{
    static $n = 0;
    $n++;
    $total = $attributes['total_kobo'];

    return DB::table('platform_invoices')->insertGetId([
        'organization_id' => $organization, 'number' => sprintf('PR-2027-%06d', $n), 'kind' => 'subscription',
        'subtotal_kobo' => $attributes['subtotal_kobo'] ?? $total, 'vat_kobo' => $attributes['vat_kobo'] ?? 0, 'status' => 'paid',
        'updated_at' => $attributes['created_at'],
        ...$attributes,
    ]);
}

function saPlatformPayment(int $organization, int $invoice, int $amount, string $status, ?string $confirmedAt): int
{
    return DB::table('platform_payments')->insertGetId([
        'organization_id' => $organization, 'platform_invoice_id' => $invoice, 'method' => 'paystack', 'amount_kobo' => $amount,
        'currency' => 'NGN', 'status' => $status, 'confirmed_at' => $confirmedAt, 'created_at' => $confirmedAt ?? SA_LONG_AGO, 'updated_at' => SA_NOW,
    ]);
}

function saTenant(int $organization, ?int $userId, bool $deleted = false): int
{
    static $n = 0;
    $n++;

    return DB::table('tenant_profiles')->insertGetId([
        'organization_id' => $organization, 'user_id' => $userId, 'first_name' => 'Ada', 'last_name' => 'Obi', 'phone' => sprintf('+23481300000%02d', $n),
        'deleted_at' => $deleted ? SA_LONG_AGO : null, 'created_at' => SA_LONG_AGO, 'updated_at' => SA_LONG_AGO,
    ]);
}

function saCollection(int $organization, int $tenant, string $source, string $method, int $amount, string $status = 'confirmed', ?string $confirmedAt = SA_FIRST_SECOND, bool $deleted = false): void
{
    DB::table('payments')->insert([
        'organization_id' => $organization, 'tenant_profile_id' => $tenant, 'amount_kobo' => $amount, 'method' => $method, 'source' => $source,
        'status' => $status, 'paid_at' => SA_DAY, 'confirmed_at' => $confirmedAt, 'deleted_at' => $deleted ? SA_NOW : null,
        'created_at' => SA_LONG_AGO, 'updated_at' => SA_NOW,
    ]);
}

function saSignIn(string $type, int $id, string $event, string $at): void
{
    DB::table('authentication_logs')->insert(['authenticatable_type' => $type, 'authenticatable_id' => $id, 'event' => $event, 'created_at' => $at]);
}

function saCancelled(int $organization, string $from, string $at): void
{
    activity('subscriptions')->event('subscription.cancelled')->createdAt(CarbonImmutable::parse($at, 'UTC'))
        ->withProperties(['old' => ['status' => $from], 'attributes' => ['status' => 'cancelled'], 'organization_id' => $organization])
        ->log('Cancelled');
}

/**
 * Builds the world. Returns the organizations' ids by letter.
 *
 * @return array<string, int>
 */
function analyticsWorld(): array
{
    $o = [];
    $o['A'] = saOrganization('Adeyemi Realty', ['state' => 'Lagos', 'transaction_tier' => TransactionTier::Two]);
    $o['B'] = saOrganization('Bello Homes', ['state' => 'Oyo']);
    $o['C'] = saOrganization('Chike Properties', ['type' => OrganizationType::Hybrid, 'state' => 'Lagos'], SA_BEFORE);
    $o['D'] = saOrganization('Dara Estates', ['state' => null, 'status' => OrganizationStatus::Cancelled]);
    $o['E'] = saOrganization('Ese Okafor', ['type' => OrganizationType::PropertyOwner, 'platform_covered' => true]);
    $o['F'] = saOrganization('Femi Lettings', ['state' => 'Kano'], SA_FIRST_SECOND);
    $o['G'] = saOrganization('Gbenga Bakare', ['type' => OrganizationType::PropertyOwner, 'platform_covered' => true], SA_LAST_SECOND);
    $o['J'] = saOrganization('Jide Homes', ['status' => OrganizationStatus::Cancelled]);
    $o['K'] = saOrganization('Kola Homes', ['state' => 'Lagos']);

    saSubscription($o['A'], 'starter', 'active', trialEndsAt: '2026-06-15 10:00:00');
    saSubscription($o['B'], 'growth', 'past_due', 'annual');
    saSubscription($o['C'], 'starter', 'trialing', trialEndsAt: '2027-03-10 10:00:00');
    saSubscription($o['D'], 'starter', 'cancelled', trialEndsAt: '2027-03-01 12:00:00');
    saSubscription($o['E'], 'starter', 'active', trialEndsAt: '2027-03-01 12:00:00');
    saSubscription($o['J'], 'starter', 'cancelled');
    saSubscription($o['K'], 'starter', 'active', trialEndsAt: '2027-02-25 10:00:00');

    // Units: A has 11 old ones, one made at the day's first second, one a second before it;
    // a unit in an archived building and a deleted one made on the day never count.
    $a = saBuilding($o['A']);
    saUnits($o['A'], $a, 11);
    saUnits($o['A'], $a, 1, SA_FIRST_SECOND);
    saUnits($o['A'], $a, 1, SA_BEFORE);
    saUnits($o['A'], $a, 1, '2027-03-01 09:00:00', deleted: true);
    saUnits($o['A'], saBuilding($o['A'], 'archived'), 1);
    saUnits($o['A'], saBuilding($o['A'], deleted: true), 1);
    saUnits($o['B'], saBuilding($o['B']), 60);
    $cUnits = saUnits($o['C'], $cBuilding = saBuilding($o['C']), 5);
    saUnits($o['D'], saBuilding($o['D']), 2);
    saUnits($o['E'], saBuilding($o['E']), 4);
    saUnits($o['K'], saBuilding($o['K']), 8);

    // GoProperty's invoices and payments.
    // A: Dec on time, Jan paid 23:30 Lagos on its due day (on time), Feb paid 00:30 Lagos the day after (late), March's on the day.
    foreach ([['2026-12-01 09:00:00', '2026-12-08', '2026-12-05 10:00:00'], ['2027-01-01 09:00:00', '2027-01-08', '2027-01-08 22:30:00'], ['2027-02-01 09:00:00', '2027-02-08', '2027-02-08 23:30:00']] as [$issued, $due, $paid]) {
        $invoice = saInvoice($o['A'], ['total_kobo' => 1_300_750, 'subtotal_kobo' => 1_210_000, 'vat_kobo' => 90_750, 'due_date' => $due, 'paid_at' => $paid, 'created_at' => $issued]);
        saPlatformPayment($o['A'], $invoice, 1_300_750, 'confirmed', $paid);
    }
    $march = saInvoice($o['A'], ['total_kobo' => 1_300_750, 'subtotal_kobo' => 1_210_000, 'vat_kobo' => 90_750, 'due_date' => '2027-03-08', 'paid_at' => '2027-03-01 10:00:00', 'created_at' => '2027-03-01 00:30:00']);
    saPlatformPayment($o['A'], $march, 1_300_750, 'confirmed', '2027-03-01 10:00:00');
    saPlatformPayment($o['A'], $march, 1_300_750, 'pending', null);
    saInvoice($o['A'], ['total_kobo' => 99_000, 'status' => 'void', 'voided_at' => '2027-03-01 11:00:00', 'created_at' => '2027-03-01 10:30:00']);

    // B: January paid early; an invoice of 2026 outside the year; February paid on the day (late, 19 days);
    // an overdue open one; an SMS bundle issued on the day, open.
    $january = saInvoice($o['B'], ['total_kobo' => 4_300_000, 'due_date' => '2027-01-15', 'paid_at' => '2027-01-10 09:00:00', 'created_at' => '2027-01-08 09:00:00']);
    saPlatformPayment($o['B'], $january, 4_300_000, 'confirmed', '2027-01-10 09:00:00');
    saInvoice($o['B'], ['total_kobo' => 4_300_000, 'due_date' => '2026-02-01', 'paid_at' => '2026-01-30 09:00:00', 'created_at' => '2026-01-25 09:00:00']);
    $february = saInvoice($o['B'], ['total_kobo' => 4_300_000, 'due_date' => '2027-02-17', 'paid_at' => SA_LAST_SECOND, 'created_at' => '2027-02-10 09:00:00']);
    saPlatformPayment($o['B'], $february, 4_300_000, 'confirmed', SA_LAST_SECOND);
    $overdue = saInvoice($o['B'], ['total_kobo' => 4_515_000, 'status' => 'open', 'due_date' => '2027-02-20', 'created_at' => '2027-02-13 09:00:00']);
    saPlatformPayment($o['B'], $overdue, 4_515_000, 'held', null);
    saInvoice($o['B'], ['kind' => 'sms_credits', 'total_kobo' => 322_500, 'subtotal_kobo' => 300_000, 'vat_kobo' => 22_500, 'status' => 'open', 'due_date' => '2027-03-08', 'created_at' => '2027-03-01 08:00:00']);

    // K: its first invoice (25 Feb), paid on the day: its first payment ever.
    $first = saInvoice($o['K'], ['total_kobo' => 430_000, 'due_date' => '2027-03-04', 'paid_at' => '2027-03-01 09:00:00', 'created_at' => '2027-02-25 10:00:00']);
    saPlatformPayment($o['K'], $first, 430_000, 'confirmed', '2027-03-01 09:00:00');

    // E (covered): an invoice issued and covered on the day: not revenue.
    saInvoice($o['E'], ['total_kobo' => 1_075_000, 'due_date' => '2027-03-08', 'paid_at' => '2027-03-01 01:00:00', 'created_at' => '2027-03-01 01:00:00']);

    // J paid once last year, then cancelled.
    $jInvoice = saInvoice($o['J'], ['total_kobo' => 1_075_000, 'due_date' => '2026-07-08', 'paid_at' => '2026-07-02 09:00:00', 'created_at' => '2026-07-01 09:00:00']);
    $jPayment = saPlatformPayment($o['J'], $jInvoice, 1_075_000, 'confirmed', '2026-07-02 09:00:00');

    // Refunds: one processed on the day, one still processing.
    $finance = staffNamed('Funke', ['finance-admin'])->id;

    foreach ([['processed', '2027-03-01 12:00:00', 50_000], ['processing', null, 70_000]] as [$status, $processed, $amount]) {
        DB::table('platform_refunds')->insert([
            'organization_id' => $o['J'], 'platform_payment_id' => $jPayment, 'platform_invoice_id' => $jInvoice, 'method' => 'paystack',
            'amount_kobo' => $amount, 'status' => $status, 'reason' => 'Goodwill', 'requested_by_staff_id' => $finance,
            'processed_at' => $processed, 'created_at' => '2027-03-01 11:00:00', 'updated_at' => '2027-03-01 11:00:00',
        ]);
    }

    // Cancellations: J after paying, D from its trial, both on the day; an older one the day before.
    saCancelled($o['J'], 'active', '2027-03-01 15:00:00');
    saCancelled($o['D'], 'trialing', SA_FIRST_SECOND);
    saCancelled($o['J'], 'suspended', SA_BEFORE);

    // Tenants: two people with portal access (one in two organizations), one without, one deleted.
    $users = User::factory()->count(6)->create()->pluck('id')->all();
    $tenant = saTenant($o['A'], $users[0]);
    saTenant($o['B'], $users[0]);
    saTenant($o['K'], $users[1]);
    saTenant($o['A'], null);
    saTenant($o['A'], $users[2], deleted: true);

    // Collections the agencies recorded (A), confirmed on the day unless said.
    saCollection($o['A'], $tenant, 'statement_match', 'bank_transfer', 500_000);
    saCollection($o['A'], $tenant, 'paystack_webhook', 'paystack', 250_000, confirmedAt: SA_LAST_SECOND);
    saCollection($o['A'], $tenant, 'proof_upload', 'bank_transfer', 100_000, confirmedAt: '2027-03-01 12:00:00');
    saCollection($o['A'], $tenant, 'manual', 'cash', 40_000, confirmedAt: '2027-03-01 12:00:00');
    saCollection($o['A'], $tenant, 'manual', 'pos', 10_000, confirmedAt: '2027-03-01 12:00:00');
    saCollection($o['A'], $tenant, 'manual', 'bank_transfer', 70_000, confirmedAt: '2027-03-01 12:00:00');
    saCollection($o['A'], $tenant, 'import', 'bank_transfer', 999_900, confirmedAt: '2027-03-01 12:00:00');
    saCollection($o['A'], $tenant, 'manual', 'cash', 11_100, 'pending_confirmation', null);
    saCollection($o['A'], $tenant, 'manual', 'cash', 22_200, confirmedAt: SA_BEFORE);
    saCollection($o['A'], $tenant, 'manual', 'cash', 33_300, confirmedAt: SA_AFTER);
    saCollection($o['A'], $tenant, 'manual', 'cash', 44_400, deleted: true);

    // Sign-ins: two people on the day (one twice), one three days before, one a week before (out),
    // a failed sign-in and a staff sign-in on the day (not counted).
    saSignIn('user', $users[0], 'login_success', SA_FIRST_SECOND);
    saSignIn('user', $users[0], 'login_success', '2027-03-01 20:00:00');
    saSignIn('user', $users[1], 'passkey_login', SA_LAST_SECOND);
    saSignIn('user', $users[3], 'login_success', '2027-02-26 10:00:00');
    saSignIn('user', $users[4], 'login_success', '2027-02-22 10:00:00');
    saSignIn('user', $users[5], 'login_failed', '2027-03-01 10:00:00');
    saSignIn('platform_staff', 1, 'login_success', '2027-03-01 10:00:00');

    // Tickets (C): two submitted on the day, a draft, one resolved on the day, one resolved the day after.
    foreach ([[SA_FIRST_SECOND, null, 'submitted'], ['2027-03-01 10:00:00', null, 'submitted'], [null, null, 'draft'], ['2027-02-20 10:00:00', '2027-03-01 10:00:00', 'resolved'], ['2027-02-20 10:00:00', SA_AFTER, 'resolved']] as [$submitted, $resolved, $status]) {
        DB::table('tickets')->insert([
            'organization_id' => $o['C'], 'property_id' => $cBuilding, 'unit_id' => $cUnits[0], 'source' => 'tenant', 'category' => 'plumbing',
            'status' => $status, 'title' => 'Leaking tap', 'submitted_at' => $submitted, 'resolved_at' => $resolved, 'created_at' => SA_LONG_AGO, 'updated_at' => SA_NOW,
        ]);
    }

    // Tier applications: one approved and one rejected on the day, two waiting, one approved last month.
    foreach ([['approved', '2027-03-01 10:00:00'], ['rejected', SA_LAST_SECOND], ['submitted', null], ['under_review', null], ['approved', '2027-02-10 10:00:00'], ['draft', null]] as [$status, $decided]) {
        DB::table('tier_applications')->insert([
            'organization_id' => $o['B'], 'from_tier' => 1, 'requested_tier' => 2, 'status' => $status, 'decided_at' => $decided,
            'created_at' => SA_LONG_AGO, 'updated_at' => SA_NOW,
        ]);
    }

    // Compliance flags: open, acknowledged (both open), resolved.
    foreach (['open', 'acknowledged', 'resolved'] as $i => $status) {
        DB::table('compliance_flags')->insert([
            'organization_id' => $o['B'], 'type' => 'spike', 'status' => $status, 'dedupe_key' => "spike:{$i}", 'created_at' => SA_LONG_AGO, 'updated_at' => SA_NOW,
        ]);
    }

    return $o;
}
