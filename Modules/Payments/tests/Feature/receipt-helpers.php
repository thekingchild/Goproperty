<?php

declare(strict_types=1);

use App\Branding\BrandingSettings;
use App\Documents\Verification\VerificationCodes;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Modules\Payments\Jobs\RenderReceipt;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentNotice;
use Modules\Payments\Models\Receipt;
use Modules\Payments\Receipts\ReceiptVoids;
use Tests\Support\LivewireHttp;

/*
 * Helpers for the receipt tests (P03-10b): receipts come from confirming
 * payments through the real writer (Payments\PaymentService), voids through
 * Receipts\ReceiptVoids, pages through HTTP and Livewire updates.
 */

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../../tests/Support/documents.php';

/** The payment's receipt as stored now. */
function receiptOf(Organization $organization, Payment|int $payment): Receipt
{
    return inOrganization($organization, fn (): Receipt => Receipt::query()->where('payment_id', $payment instanceof Payment ? $payment->id : $payment)->sole());
}

/** The receipt as stored now. */
function storedReceipt(Organization $organization, Receipt|int $receipt): Receipt
{
    return inOrganization($organization, fn (): Receipt => Receipt::query()->findOrFail($receipt instanceof Receipt ? $receipt->id : $receipt));
}

/** Every receipt of the organization, oldest first. */
function receiptsOf(Organization $organization): Collection
{
    return inOrganization($organization, fn () => Receipt::query()->orderBy('id')->get());
}

/**
 * What the payment's tenant was told, as [event, channels] rows.
 *
 * @return list<array{string, list<string>}>
 */
function noticesOf(Organization $organization, Payment|int $payment): array
{
    return inOrganization($organization, fn (): array => PaymentNotice::query()->where('payment_id', $payment instanceof Payment ? $payment->id : $payment)->orderBy('id')->get()
        ->map(fn (PaymentNotice $notice): array => [$notice->event, $notice->channels])->all());
}

function requestReceiptVoid(Organization $organization, User $by, Receipt $receipt, string $reason = 'Recorded against the wrong tenant'): Receipt
{
    return inOrganization($organization, fn (): Receipt => app(ReceiptVoids::class)->request($by, storedReceipt($organization, $receipt), $reason));
}

function approveReceiptVoid(Organization $organization, User $by, Receipt $receipt): Receipt
{
    return inOrganization($organization, fn (): Receipt => app(ReceiptVoids::class)->approve($by, storedReceipt($organization, $receipt)));
}

function dismissReceiptVoid(Organization $organization, User $by, Receipt $receipt): Receipt
{
    return inOrganization($organization, fn (): Receipt => app(ReceiptVoids::class)->dismiss($by, storedReceipt($organization, $receipt)));
}

function voidReceiptAlone(Organization $organization, User $by, Receipt $receipt, string $reason = 'Issued twice for the same transfer'): Receipt
{
    return inOrganization($organization, fn (): Receipt => app(ReceiptVoids::class)->voidAlone($by, storedReceipt($organization, $receipt), $reason));
}

/** Runs the receipt's PDF job now (as the `pdf` queue would): the original, or the VOID copy. */
function renderReceiptPdf(Organization $organization, Receipt $receipt, bool $void = false): void
{
    app()->call([new RenderReceipt($organization->id, $receipt->id, $void), 'handle']);
}

/** Saves the organization's branding through the settings service, as Settings → Branding does. */
function receiptBranding(Organization $organization, User $by, array $input = []): void
{
    inOrganization($organization, fn () => app(BrandingSettings::class)->save($by, $organization, $input + [
        'tradingName' => 'Adeyemi Realty',
        'primaryColor' => '#0E6B5C',
        'accentColor' => '#F2B53A',
        'address' => '14 Admiralty Way, Lekki Phase 1, Lagos',
        'rcNumber' => 'RC 1234567',
        'phone' => '+234 803 000 0000',
        'email' => 'accounts@adeyemirealty.ng',
        'website' => 'adeyemirealty.ng',
        'invoicePrefix' => 'INV-ADR',
        'receiptPrefix' => 'RCT-ADR',
        'leasePrefix' => 'LS-ADR',
        'invoiceNotes' => 'Rent is payable in advance.',
        'receiptFooter' => 'Thank you for your payment.',
        'layout' => 'modern',
    ]));
}

/** A page of the receipts area, as the user ($path after /receipts). */
function receiptsPage(User $user, string $path = '', string $slug = 'acme-homes'): TestResponse
{
    billsFreshRequest();

    return test()->actingAs($user)->get("/o/{$slug}/receipts{$path}");
}

/** The Livewire snapshot of a receipts page the user can open. */
function receiptsSnapshot(User $user, string $path = '', string $slug = 'acme-homes'): string
{
    $snapshot = LivewireHttp::snapshot(receiptsPage($user, $path, $slug)->assertOk());
    billsFreshRequest();
    test()->actingAs($user);

    return $snapshot;
}

/** A Livewire update on a snapshot, as a fresh request by the same user. */
function receiptsUpdate(User $user, string $snapshot, array $updates = [], array $calls = []): TestResponse
{
    billsFreshRequest();
    test()->actingAs($user);

    return LivewireHttp::update(test(), $snapshot, $updates, $calls);
}

/** The receipt ids a rendered receipts list shows, in order. */
function listedReceipts(TestResponse|string $page): array
{
    preg_match_all('/data-receipt="(\d+)"/', htmlOf($page), $matches);

    return array_map('intval', $matches[1]);
}

/** The public verify page of the receipt's code, as anyone opening the QR code. */
function verifyReceiptPage(Receipt $receipt): TestResponse
{
    billsFreshRequest();

    return test()->get(parse_url(VerificationCodes::url($receipt->verification_code), PHP_URL_PATH));
}
