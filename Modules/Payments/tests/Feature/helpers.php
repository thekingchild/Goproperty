<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Modules\Billing\Jobs\RenderInvoice;
use Modules\Billing\Models\Bill;
use Modules\Payments\Credit\TenantCredit;
use Modules\Payments\Jobs\RenderReceipt;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentAllocation;
use Modules\Payments\Models\TenantCreditEntry;
use Modules\Payments\Payments\PaymentService;
use Modules\Tenants\Models\TenantProfile;
use Tests\Support\LivewireHttp;

/*
 * Helpers for the Payments module's tests (P03-10a). Leases come from the
 * Leasing module's helpers, bills from the scheduled `bills-generate` job
 * (Billing's helpers), payments through the real writer
 * (Payments\PaymentService), pages through HTTP and Livewire updates.
 */

require_once __DIR__.'/../../../Billing/tests/Feature/helpers.php';

/**
 * Holds back the document PDF jobs for the rest of the test: the receipt's
 * (P03-10b, Jobs\RenderReceipt) and the invoice's (P03-05c, Billing's
 * RenderInvoice). Confirming a payment still issues its receipt (number,
 * verification code, snapshot) and bills are issued as usual, but dompdf,
 * which takes a second or more a document and writes to the media disk,
 * does not run. Tests about the PDFs render them themselves. Every other
 * job runs as usual.
 */
function holdReceiptPdfs(): void
{
    Queue::fake([RenderReceipt::class, RenderInvoice::class]);
}

/** The record form's fields for a bank transfer paid today, with changes. */
function paymentInput(TenantProfile|int $tenant, string $amount, array $changes = []): array
{
    return $changes + [
        'tenantId' => (string) ($tenant instanceof TenantProfile ? $tenant->id : $tenant),
        'billId' => '',
        'amount' => $amount,
        'method' => 'bank_transfer',
        'paidAt' => now('Africa/Lagos')->toDateString(),
        'payerName' => '',
        'payerType' => 'individual',
        'reference' => '',
        'note' => '',
    ];
}

/** A payment recorded by the member (waiting for confirmation). */
function recordPayment(Organization $organization, User $by, TenantProfile|int $tenant, string $amount, array $changes = []): Payment
{
    return inOrganization($organization, fn (): Payment => app(PaymentService::class)->record($by, paymentInput($tenant, $amount, $changes)));
}

/** Records and confirms a payment as the member; allocations bill id => naira. */
function confirmedPayment(Organization $organization, User $by, TenantProfile|int $tenant, string $amount, array $changes = [], ?array $allocations = null): Payment
{
    $payment = recordPayment($organization, $by, $tenant, $amount, $changes);

    return confirmPayment($organization, $by, $payment, $allocations);
}

/** Confirms the payment as the member; allocations bill id => naira. */
function confirmPayment(Organization $organization, User $by, Payment $payment, ?array $allocations = null, ?string $note = null): Payment
{
    $split = $allocations === null ? null : array_map(fn (string|int $naira): Money => Money::naira($naira), $allocations);

    return inOrganization($organization, fn (): Payment => app(PaymentService::class)->confirm($by, $payment, $note, $split));
}

/** The payment as stored now. */
function storedPayment(Organization $organization, Payment|int $payment): Payment
{
    return inOrganization($organization, fn (): Payment => Payment::query()->withTrashed()->findOrFail($payment instanceof Payment ? $payment->id : $payment));
}

/** Every payment of the organization, oldest first. */
function paymentsOf(Organization $organization): Collection
{
    return inOrganization($organization, fn () => Payment::query()->withTrashed()->orderBy('id')->get());
}

/**
 * The payment's allocations as bill id => kobo.
 *
 * @return array<int, int>
 */
function allocationsOf(Organization $organization, Payment $payment): array
{
    return inOrganization($organization, fn (): array => PaymentAllocation::query()->where('payment_id', $payment->id)->orderBy('id')->get()
        ->mapWithKeys(fn (PaymentAllocation $allocation): array => [$allocation->bill_id => $allocation->amount_kobo->kobo])->all());
}

/** The tenant's credit now (the balance row). */
function creditOf(Organization $organization, TenantProfile $tenant): Money
{
    return inOrganization($organization, fn (): Money => app(TenantCredit::class)->balance($tenant->id));
}

/**
 * The tenant's credit entries as [reason, kobo, bill id] rows, oldest first.
 *
 * @return list<array{string, int, int|null}>
 */
function creditEntriesOf(Organization $organization, TenantProfile $tenant): array
{
    return inOrganization($organization, fn (): array => TenantCreditEntry::query()->where('tenant_profile_id', $tenant->id)->orderBy('id')->get()
        ->map(fn (TenantCreditEntry $entry): array => [$entry->reason->value, $entry->amount_kobo->kobo, $entry->bill_id])->all());
}

/** The tenant's bills, oldest due first. */
function billsOfTenant(Organization $organization, TenantProfile $tenant): Collection
{
    return inOrganization($organization, fn () => Bill::query()->where('tenant_profile_id', $tenant->id)->orderBy('due_date')->orderBy('id')->get());
}

/** A page of the payments area, as the user ($path after /payments). */
function paymentsPage(User $user, string $path = '', string $slug = 'acme-homes'): TestResponse
{
    billsFreshRequest();

    return test()->actingAs($user)->get("/o/{$slug}/payments{$path}");
}

/** The Livewire snapshot of a payments page the user can open. */
function paymentsSnapshot(User $user, string $path = '', string $slug = 'acme-homes'): string
{
    $snapshot = LivewireHttp::snapshot(paymentsPage($user, $path, $slug)->assertOk());
    billsFreshRequest();
    test()->actingAs($user);

    return $snapshot;
}

/** A Livewire update on a snapshot, as a fresh request by the same user. */
function paymentsUpdate(User $user, string $snapshot, array $updates = [], array $calls = []): TestResponse
{
    billsFreshRequest();
    test()->actingAs($user);

    return LivewireHttp::update(test(), $snapshot, $updates, $calls);
}

/** The payment ids a rendered payments list shows, in order. */
function listedPayments(TestResponse|string $page): array
{
    preg_match_all('/data-payment="(\d+)"/', htmlOf($page), $matches);

    return array_map('intval', $matches[1]);
}
