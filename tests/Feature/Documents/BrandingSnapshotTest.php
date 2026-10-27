<?php

declare(strict_types=1);

use App\Branding\BrandingSettings;
use App\Documents\BrandingSnapshot;
use App\Documents\BrandingSnapshots;
use App\Documents\DocumentLayout;
use App\Documents\DocumentRenderer;
use App\Documents\KitSample;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use App\Theme\ThemeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/compliance.php';
require_once __DIR__.'/../../Support/documents.php';

/*
 * P03-05a: the branding snapshot an issued document keeps (BRANDING_DOCUMENTS.md
 * § 1: "Documents already issued keep the branding they were issued with";
 * AGENT.md § 4), with its images embedded.
 */

/** Saves branding through the settings service, as the settings page does. */
function saveBranding(Organization $organization, User $by, array $input, array $uploads = []): void
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
        'receiptFooter' => 'Thank you.',
        'layout' => 'modern',
        'emailSenderName' => '',
        'hidePoweredBy' => false,
    ], $uploads));
}

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('media');
    Notification::fake();
    seedOrganizationRoles();

    $this->acme = Organization::factory()->create(['name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
});

afterEach(fn () => app(OrganizationContext::class)->clear());

describe('acceptance: a snapshot is unaffected by later branding changes', function () {
    it('prints an issued document the same after the branding, its images, the name and the plan change', function () {
        saveBranding($this->acme, $this->owner, [], ['logo' => documentPng('logo.png', 400, 160, [14, 107, 92]), 'signature' => documentPng('signature.png', 300, 120, [30, 30, 30])]);

        $snapshot = app(BrandingSnapshots::class)->take($this->acme->fresh());
        // As the issuing module keeps it: a json column, read back later.
        $stored = json_decode((string) json_encode($snapshot->toArray()), true);
        $issued = app(DocumentRenderer::class)->web(KitSample::document($snapshot->documentBranding()));

        // Everything changes afterwards.
        saveBranding($this->acme->fresh(), $this->owner, [
            'tradingName' => 'Brand New Homes',
            'primaryColor' => '#7C2D12',
            'address' => '1 New Road, Abuja',
            'layout' => 'classic',
            'invoiceNotes' => 'New terms.',
        ], ['logo' => documentPng('new-logo.png', 500, 200, [124, 45, 18])]);
        $this->acme->fresh()->forceFill(['name' => 'Brand New Homes Ltd'])->save();

        $again = BrandingSnapshot::fromArray($stored);
        $reprinted = app(DocumentRenderer::class)->web(KitSample::document($again->documentBranding()));
        $today = app(DocumentRenderer::class)->web(KitSample::document(app(BrandingSnapshots::class)->take($this->acme->fresh())->documentBranding()));

        expect($reprinted)->toBe($issued)
            ->and($reprinted)->toContain('Adeyemi Realty', '#0e6b5c', '14 Admiralty Way')
            ->and($reprinted)->not->toContain('Brand New Homes', '#7c2d12', 'Abuja')
            ->and($again->branding->layout)->toBe(DocumentLayout::Modern)
            ->and($again->image('logo'))->toBe($snapshot->image('logo'))
            ->and($again->image('signature'))->toBe($snapshot->image('signature'))
            ->and($today)->toContain('Brand New Homes', '#7c2d12')
            ->and($today)->not->toBe($issued);
    });

    it('embeds the images themselves, so the snapshot needs no media row or file', function () {
        saveBranding($this->acme, $this->owner, [], ['logo' => documentPng('logo.png'), 'stamp' => documentPng('stamp.png', 200, 200)]);
        $snapshot = app(BrandingSnapshots::class)->take($this->acme->fresh());
        $logo = $snapshot->image('logo');

        expect($logo)->toStartWith('data:image/png;base64,')
            ->and(getimagesizefromstring((string) base64_decode(substr((string) $logo, 22)))[0] ?? null)->toBe(380) // trimmed of its white border
            ->and($snapshot->image('stamp'))->toStartWith('data:image/png;base64,')
            ->and($snapshot->image('signature'))->toBeNull()
            ->and($snapshot->toArray()['branding'])->toHaveKey('logo_media_id');

        // The files and rows are gone (say a disk was lost): the snapshot still prints.
        Storage::fake('media');
        DB::table('media')->update(['collection_name' => 'gone']);
        $html = app(DocumentRenderer::class)->web(KitSample::document(BrandingSnapshot::fromArray($snapshot->toArray())->documentBranding()));

        expect($html)->toContain($logo);
    });

    it('keeps the product name and the "Generated with" choice as they were', function () {
        $snapshot = app(BrandingSnapshots::class)->take($this->acme);

        expect($snapshot->productName)->toBe(app(ThemeContext::class)->current()->name())
            ->and(BrandingSnapshot::fromArray([...$snapshot->toArray(), 'product_name' => 'Old Name'])->documentBranding()->productName)->toBe('Old Name')
            ->and($snapshot->documentBranding()->showPoweredBy)->toBeTrue();
    });
});

it('accepts only PNG data URIs as images, whatever the stored json says', function () {
    $snapshot = app(BrandingSnapshots::class)->take($this->acme);
    $stored = $snapshot->toArray();
    $stored['images'] = ['logo' => 'https://evil.example/logo.png', 'signature' => 'file:///etc/passwd', 'stamp' => 'data:image/svg+xml;base64,PHN2Zz4='];

    $restored = BrandingSnapshot::fromArray($stored);

    expect($restored->image('logo'))->toBeNull()
        ->and($restored->image('signature'))->toBeNull()
        ->and($restored->image('stamp'))->toBeNull()
        ->and(fn () => new BrandingSnapshot($snapshot->branding, 'GoProperty', ['logo' => 'https://evil.example/x.png'], now()->toImmutable()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => BrandingSnapshot::fromArray(['images' => []]))->toThrow(InvalidArgumentException::class);
});
