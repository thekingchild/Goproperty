<?php

declare(strict_types=1);

use App\Branding\BrandingSettings;
use App\Media\Media;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Modules\Billing\Documents\Invoices;
use Modules\Billing\Models\Bill;

/*
 * Helpers for the invoice and pay page tests (P03-05c): the organization's
 * branding saved as the settings page saves it, the invoice pages of a
 * bill over HTTP, and the kept PDF behind a download.
 */

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../../tests/Support/documents.php';
require_once __DIR__.'/../../../../tests/Support/organization-settings.php';

/**
 * No Paystack key: bank accounts are saved "name not checked" (usable) and
 * no lookup is sent. HTTP is not faked, so the Chrome halves can reach
 * Gotenberg (a faked client refusing stray requests would hide it, and CI
 * would then fail them as "unreachable").
 */
function withoutAccountNameLookups(): void
{
    config(['services.paystack.secret_key' => null]);
}

/** Saves the organization's branding as Settings → Branding does. */
function invoiceBranding(Organization $organization, User $by, array $changes = []): void
{
    inOrganization($organization, fn () => app(BrandingSettings::class)->save($by, $organization, $changes + [
        'tradingName' => '',
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
        'invoiceNotes' => '',
        'receiptFooter' => '',
        'layout' => 'modern',
        'emailSenderName' => '',
        'hidePoweredBy' => false,
    ]));
}

/** GET the bill's invoice web page as the user. */
function invoicePage(User $user, Bill $bill, string $slug = 'acme-homes'): TestResponse
{
    billsFreshRequest();

    return test()->actingAs($user)->get("/o/{$slug}/bills/{$bill->id}/invoice");
}

/** GET the bill's invoice PDF download as the user. */
function invoiceDownload(User $user, Bill $bill, string $slug = 'acme-homes'): TestResponse
{
    billsFreshRequest();

    return test()->actingAs($user)->get("/o/{$slug}/bills/{$bill->id}/invoice.pdf");
}

/** Follows a download's redirect to the signed media link and returns the PDF bytes. */
function followInvoice(TestResponse $download): string
{
    $location = (string) $download->assertRedirect()->headers->get('Location');
    expect($location)->toContain('/media/')->toContain('signature=');
    billsFreshRequest();

    return test()->get($location)->assertOk()->assertHeader('Content-Type', 'application/pdf')->streamedContent();
}

/** The bill's kept PDF for its current state, if rendered. */
function keptInvoice(Organization $organization, Bill $bill): ?Media
{
    return inOrganization($organization, fn (): ?Media => app(Invoices::class)->kept(storedBill($organization, $bill)));
}

/** GET the public pay page of a reference, signed out (a fresh request). */
function payPage(string $reference): TestResponse
{
    billsFreshRequest();
    app('auth')->forgetGuards();

    return test()->get('/pay/'.$reference);
}
