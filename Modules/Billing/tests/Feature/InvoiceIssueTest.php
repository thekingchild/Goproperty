<?php

declare(strict_types=1);

use App\Documents\BrandingSnapshots;
use App\Documents\DocumentLayout;
use App\Documents\DocumentRenderer;
use App\Documents\Verification\VerificationCodes;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Media\EvidenceFileProtected;
use App\Media\Media;
use App\Media\MediaUrls;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Bills\BillDraft;
use Modules\Billing\Bills\BillIssuer;
use Modules\Billing\Bills\BillLine;
use Modules\Billing\Bills\BillService;
use Modules\Billing\Contracts\BillLedger;
use Modules\Billing\Documents\Invoices;
use Modules\Billing\Enums\BillableType;
use Modules\Billing\Enums\BillType;
use Modules\Billing\Jobs\RenderInvoice;
use Modules\Billing\Models\Bill;
use Modules\Leasing\Contracts\BillingCycle;
use Modules\Properties\Contracts\BuildingAccess;
use Tests\Support\PdfVisualDiff;

require_once __DIR__.'/invoice-helpers.php';

/*
 * P03-05c: a bill's invoice (BRANDING_DOCUMENTS.md § 2, § 5;
 * ARCHITECTURE.md § 6.5). At issue the bill keeps the branding snapshot
 * and its parties; its PDF is rendered once on the `pdf` queue after the
 * commit and kept as evidence; the bill page shows the invoice as a web
 * page and downloads the kept PDF; /verify shows the invoice's current
 * status. Acceptance: changing branding later does not change issued
 * documents.
 */

beforeEach(function () {
    Storage::fake('media');
    seedOrganizationRoles();
    withoutAccountNameLookups();
    $this->travelTo('2026-12-25 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes Ltd']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->otherOwner = joinAs(User::factory()->create(), $this->other);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court', 'address' => '22 Fola Osibo Road, Lekki Phase 1', 'state' => 'Lagos']);
    $this->flat = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 3B']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222', 'email' => 'kemi.adebayo@example.com']);
    $this->account = addBankAccount($this->acme, $this->owner, '0123456789', 'Adeyemi Realty Ltd');
});

afterEach(fn () => billsFreshRequest());

/** Issues the rent bill of a yearly lease of Flat 3B from 1 Jan 2027 (₦1,500,000), as the job does; returns it. */
function issuedInvoiceBill(object $test): Bill
{
    activeLease($test->acme, $test->owner, $test->flat, $test->kemi);
    runBillGeneration();

    return billsOf($test->acme)->sole();
}

describe('acceptance: changing branding later does not change issued documents', function () {
    it('prints the invoice as issued, web page and kept PDF bytes alike, after the branding, names and layout change', function () {
        invoiceBranding($this->acme, $this->owner, ['tradingName' => 'Adeyemi Realty', 'primaryColor' => '#0E6B5C', 'layout' => 'compact']);
        holdInvoicePdfs();
        $bill = issuedInvoiceBill($this);
        $htmlAtIssue = invoicePage($this->owner, $bill)->assertOk()->getContent();

        // Everything changes before the queue gets to the PDF: name, colour, layout, prefix, the tenant, the building and the unit.
        invoiceBranding($this->acme, $this->owner, ['tradingName' => 'Renamed Homes', 'primaryColor' => '#1D4ED8', 'layout' => 'classic', 'invoicePrefix' => 'INV-REN']);
        inOrganization($this->acme, fn () => $this->kemi->forceFill(['first_name' => 'Changed'])->save());
        inOrganization($this->acme, fn () => $this->palm->forceFill(['name' => 'Renamed Court', 'address' => '1 New Street'])->save());
        inOrganization($this->acme, fn () => $this->flat->forceFill(['unit_label' => 'Flat 9Z'])->save());

        $counter = countingPdfDriver();
        (new RenderInvoice($this->acme->id, $bill->id))->handle(app(OrganizationContext::class), app(Invoices::class));
        $bytes = followInvoice(invoiceDownload($this->owner, $bill));
        $htmlNow = invoicePage($this->owner, $bill)->assertOk()->getContent();

        expect($counter->renders)->toBeGreaterThan(0)
            ->and($htmlNow)->toBe($htmlAtIssue)
            ->and($htmlNow)->toContain('Adeyemi Realty', 'class="layout-compact"', 'background: #0e6b5c', 'Kemi Adebayo', 'Palm Court — Flat 3B', '22 Fola Osibo Road, Lekki Phase 1, Lagos', 'INV-ADR-2026-000001')
            ->and($htmlNow)->not->toContain('Renamed Homes', '#1d4ed8', 'Changed Adebayo', 'Renamed Court', 'Flat 9Z', 'INV-REN');

        requirePdfTools();
        $text = PdfVisualDiff::text($bytes);
        expect($text)->toContain('Adeyemi Realty', 'INV-ADR-2026-000001', 'Kemi Adebayo', 'Palm Court', 'Flat 3B')
            ->and($text)->not->toContain('Renamed', 'Changed Adebayo', 'Flat 9Z');

        // Later still: the kept bytes come back, nothing is rendered again, and the web page is unchanged.
        $renders = $counter->renders;
        invoiceBranding($this->acme, $this->owner, ['tradingName' => 'Third Name', 'layout' => 'modern']);
        expect(followInvoice(invoiceDownload($this->owner, $bill)))->toBe($bytes)
            ->and(invoicePage($this->owner, $bill)->getContent())->toBe($htmlAtIssue)
            ->and($counter->renders)->toBe($renders);
    });

    it('keeps the parties on the bill at issue, and the bills list and page keep showing them once they are gone', function () {
        holdInvoicePdfs();
        $bill = issuedInvoiceBill($this);

        expect(storedBill($this->acme, $bill)->details)->toEqual([
            'tenant' => ['name' => 'Kemi Adebayo', 'phone' => '+2348031112222', 'email' => 'kemi.adebayo@example.com'],
            'building' => ['name' => 'Palm Court', 'address' => '22 Fola Osibo Road, Lekki Phase 1, Lagos'],
            'unit' => 'Flat 3B',
            'lease' => ['number' => 'LS-ADR-2026-000001', 'cycle' => BillingCycle::Annual->value],
        ]);

        // The contracts no longer find the unit or the tenant (as after a handover, P03-14): the kept names show.
        DB::table('units')->where('id', $this->flat->id)->update(['deleted_at' => now()]);
        DB::table('tenant_profiles')->where('id', $this->kemi->id)->update(['organization_id' => $this->other->id]);

        billsPage($this->owner, "/{$bill->id}")->assertOk()->assertSee('Flat 3B')->assertSee('Kemi Adebayo')->assertDontSee('Archived unit')->assertDontSee('Unknown tenant');
    });
});

describe('issue: the PDF is rendered once on the pdf queue after the commit and kept as evidence', function () {
    it('queues the invoice PDF on the pdf queue once the bill is issued, and nothing for an issue that rolls back', function () {
        Queue::fake([RenderInvoice::class]);
        $bill = issuedInvoiceBill($this);

        Queue::assertPushedOn('pdf', RenderInvoice::class, fn (RenderInvoice $job): bool => $job->billId === $bill->id && $job->organizationId === $this->acme->id);
        Queue::assertPushed(RenderInvoice::class, 1);
        expect(keptInvoice($this->acme, $bill))->toBeNull();

        // An issue whose transaction rolls back queues nothing.
        $draft = new BillDraft(BillType::Other, BillableType::Unit, $this->flat->id, $this->kemi->id, 'Service charge', CarbonImmutable::parse('2027-01-31'), [new BillLine('Service charge', Money::naira(50_000))], propertyId: $this->palm->id, unitId: $this->flat->id);
        expect(fn () => inOrganization($this->acme, fn () => DB::transaction(function () use ($draft) {
            app(BillIssuer::class)->issue($draft, app(BrandingSnapshots::class)->take($this->acme));

            throw new RuntimeException('Rolled back');
        })))->toThrow(RuntimeException::class, 'Rolled back');

        Queue::assertPushed(RenderInvoice::class, 1);
        expect(billsOf($this->acme))->toHaveCount(1);
    });

    it('renders the PDF once and serves that same kept file on every download, even when the job runs again', function () {
        $counter = countingPdfDriver();
        $bill = issuedInvoiceBill($this);
        $rendersAtIssue = $counter->renders;
        $media = keptInvoice($this->acme, $bill);
        $bytes = Storage::disk('media')->get($media->getPathRelativeToRoot());

        expect($rendersAtIssue)->toBeGreaterThan(0)
            ->and($media->collection_name)->toBe(DocumentRenderer::COLLECTION)
            ->and($media->model_type)->toBe((new Bill)->getMorphClass())
            ->and($media->model_id)->toBe($bill->id)
            ->and($media->mime_type)->toBe('application/pdf')
            ->and($media->name)->toBe('INV-ADR-2026-000001')
            ->and($media->getCustomProperty('document_key'))->toBe(Invoices::DOCUMENT_KEY)
            ->and(storedBill($this->acme, $bill)->document_media_id)->toBe($media->id)
            ->and($bytes)->toStartWith('%PDF-');

        $first = followInvoice(invoiceDownload($this->owner, $bill));
        $second = followInvoice(invoiceDownload($this->owner, $bill));
        (new RenderInvoice($this->acme->id, $bill->id))->handle(app(OrganizationContext::class), app(Invoices::class));

        expect($first)->toBe($bytes)
            ->and($second)->toBe($bytes)
            ->and($counter->renders)->toBe($rendersAtIssue)
            ->and(inOrganization($this->acme, fn () => Media::query()->where('collection_name', DocumentRenderer::COLLECTION)->count()))->toBe(1);

        requirePdfTools();
        expect(PdfVisualDiff::text($bytes))->toContain('INVOICE', 'INV-ADR-2026-000001', 'Kemi Adebayo', 'Palm Court — Flat 3B', '1,500,000', 'GP-', '0123456789', 'Page 1 of 1');
    });

    it('renders the PDF on the first download when the queue has not got to it yet', function () {
        holdInvoicePdfs();
        $bill = issuedInvoiceBill($this);
        expect(keptInvoice($this->acme, $bill))->toBeNull();

        $bytes = followInvoice(invoiceDownload($this->owner, $bill));

        expect($bytes)->toStartWith('%PDF-')
            ->and(keptInvoice($this->acme, $bill))->not->toBeNull()
            ->and(followInvoice(invoiceDownload($this->owner, $bill)))->toBe($bytes);
    });

    it('keeps the PDF as evidence and never changes what the bill was issued with', function () {
        $bill = issuedInvoiceBill($this);
        $media = keptInvoice($this->acme, $bill);
        $stored = storedBill($this->acme, $bill);

        expect(fn () => inOrganization($this->acme, fn () => $media->delete()))->toThrow(EvidenceFileProtected::class);

        foreach (['number' => 'INV-X-1', 'payment_reference' => 'GP-0000000', 'verification_code' => '0000000000', 'branding_snapshot' => ['x' => 1], 'details' => ['tenant' => ['name' => 'Someone else']], 'issued_at' => now()->addDay()] as $column => $value) {
            expect(fn () => inOrganization($this->acme, fn () => storedBill($this->acme, $bill)->forceFill([$column => $value])->save()))->toThrow(LogicException::class);
        }

        expect(fn () => inOrganization($this->acme, fn () => storedBill($this->acme, $bill)->forceFill(['document_media_id' => $media->id + 1])->save()))->toThrow(LogicException::class)
            ->and(storedBill($this->acme, $bill)->number)->toBe($stored->number)
            ->and(Storage::disk('media')->exists($media->getPathRelativeToRoot()))->toBeTrue();
    });

    it('prints a VOID copy once the bill is voided, and keeps the invoice as issued', function () {
        $bill = issuedInvoiceBill($this);
        $issued = keptInvoice($this->acme, $bill);
        $issuedBytes = Storage::disk('media')->get($issued->getPathRelativeToRoot());

        Queue::fake([RenderInvoice::class]);
        inOrganization($this->acme, fn () => app(BillService::class)->void($this->owner, storedBill($this->acme, $bill), 'Raised against the wrong tenant'));
        Queue::assertPushedOn('pdf', RenderInvoice::class, fn (RenderInvoice $job): bool => $job->billId === $bill->id);
        (new RenderInvoice($this->acme->id, $bill->id))->handle(app(OrganizationContext::class), app(Invoices::class));

        $void = keptInvoice($this->acme, $bill);
        $voidBytes = followInvoice(invoiceDownload($this->owner, $bill));

        expect($void->getCustomProperty('document_key'))->toBe(Invoices::VOID_KEY)
            ->and($void->id)->not->toBe($issued->id)
            ->and(Storage::disk('media')->get($void->getPathRelativeToRoot()))->toBe($voidBytes)
            ->and(Storage::disk('media')->get($issued->getPathRelativeToRoot()))->toBe($issuedBytes)
            ->and(storedBill($this->acme, $bill)->document_media_id)->toBe($issued->id)
            ->and(invoicePage($this->owner, $bill)->getContent())->toContain('Raised against the wrong tenant', 'VOID');

        requirePdfTools();
        // The reason wraps onto a second line in the notes box.
        expect(PdfVisualDiff::text($voidBytes))->toContain('VOID', 'Reason: Raised against the wrong')
            ->and(PdfVisualDiff::text($issuedBytes))->toContain('UNPAID')->not->toContain('Raised against');
    });
});

describe('sub-acceptance: the verify page shows the invoice\'s current status', function () {
    it('shows unpaid, then partly paid, then paid, and nothing about the tenant, the unit or the bank', function () {
        holdInvoicePdfs();
        $bill = issuedInvoiceBill($this);
        $verify = fn () => $this->get('/verify/'.$bill->verification_code)->assertOk();
        $pay = fn (int $naira) => inOrganization($this->acme, fn () => DB::transaction(fn () => app(BillLedger::class)->apply($bill->id, Money::naira($naira))));

        $verify()->assertSee('This invoice is genuine')
            ->assertSee('Issued by <b>Adeyemi Realty Ltd</b>', false)
            ->assertSee('Invoice INV-ADR-2026-000001')
            ->assertSee('25/12/2026')
            ->assertSee('₦1,500,000')
            ->assertSee('data-status="valid"', false)
            ->assertSee('Payment status')
            ->assertSee('Unpaid')
            ->assertSee('01/01/2027 – 31/12/2027')
            ->assertDontSee('Kemi')
            ->assertDontSee('+2348031112222')
            ->assertDontSee('Palm Court')
            ->assertDontSee('Flat 3B')
            ->assertDontSee('Fola Osibo')
            ->assertDontSee('0123456789')
            ->assertDontSee($bill->payment_reference);

        $pay(500_000);
        $verify()->assertSee('data-status="valid"', false)->assertSee('Partly paid')->assertDontSee('>Unpaid<', false);

        $pay(1_000_000);
        $verify()->assertSee('data-status="valid"', false)->assertSee('<dd>Paid</dd>', false);
    });

    it('shows a void bill\'s invoice as voided, without the reason', function () {
        holdInvoicePdfs();
        $bill = issuedInvoiceBill($this);
        inOrganization($this->acme, fn () => app(BillService::class)->void($this->owner, storedBill($this->acme, $bill), 'Kemi moved out before the period'));

        $this->get('/verify/'.$bill->verification_code)->assertOk()
            ->assertSee('This invoice was voided')
            ->assertSee('data-status="voided"', false)
            ->assertSee('Voided on 25/12/2026')
            ->assertDontSee('Payment status')
            ->assertDontSee('Kemi');
    });

    it('answers 404 for a code nobody issued, and shows another organization\'s invoice under its own issuer only', function () {
        holdInvoicePdfs();
        $ours = issuedInvoiceBill($this);
        activeLease($this->other, $this->otherOwner, unitIn($this->other, buildingIn($this->other, ['name' => 'Foreign Court', 'slug' => 'foreign-court'])), tenantIn($this->other, ['first_name' => 'Foreign', 'last_name' => 'Tenant', 'phone' => '+2348035550000', 'email' => 'foreign.tenant@example.com']));
        runBillGeneration();
        $theirs = billsOf($this->other)->sole();

        $this->get('/verify/'.app(VerificationCodes::class)->generate())->assertNotFound();
        $this->get('/verify/'.$theirs->verification_code)->assertOk()->assertSee('Bello Homes Ltd')->assertDontSee('Adeyemi Realty');
        $this->get('/verify/'.$ours->verification_code)->assertOk()->assertSee('Adeyemi Realty Ltd')->assertDontSee('Bello Homes');
    });
});

describe('the bill page: invoice web page and PDF', function () {
    it('shows the invoice as a web page with the bank details and the current status, linked from the bill page', function () {
        holdInvoicePdfs();
        $bill = issuedInvoiceBill($this);
        inOrganization($this->acme, fn () => DB::transaction(fn () => app(BillLedger::class)->apply($bill->id, Money::naira(500_000))));

        billsPage($this->owner, "/{$bill->id}")->assertOk()
            ->assertSee('href="'.route('workspace.bills.invoice', [$this->acme, $bill]).'"', false)
            ->assertSee('href="'.route('workspace.bills.invoice.pdf', [$this->acme, $bill]).'"', false);

        invoicePage($this->owner, $bill)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('INV-ADR-2026-000001')
            ->assertSee('PARTLY PAID')
            ->assertSee('Kemi Adebayo')
            ->assertSee('+2348031112222')
            ->assertSee('Palm Court — Flat 3B')
            ->assertSee('Lease LS-ADR-2026-000001 · Yearly')
            ->assertSee('0123456789')
            ->assertSee('Adeyemi Realty Ltd')
            ->assertSee($bill->payment_reference)
            ->assertSee('₦1,000,000')
            ->assertSee(VerificationCodes::format($bill->verification_code));
    });

    it('lets owners, administrators and agents open the invoice, and nobody else', function (OrganizationRole $role, int $status) {
        holdInvoicePdfs();
        $bill = issuedInvoiceBill($this);
        $user = joinAs(User::factory()->create(), $this->acme, $role);

        invoicePage($user, $bill)->assertStatus($status);
        invoiceDownload($user, $bill)->assertStatus($status === 200 ? 302 : $status);
    })->with([
        'org-owner' => [OrganizationRole::Owner, 200],
        'org-admin' => [OrganizationRole::Admin, 200],
        'agent' => [OrganizationRole::Agent, 200],
        'property-owner' => [OrganizationRole::PropertyOwner, 404],
        'tenant' => [OrganizationRole::Tenant, 404],
    ]);

    it('answers 404 for another organization\'s bill, by URL, by guessed ID and by a signed link of the other organization', function () {
        $ours = issuedInvoiceBill($this);
        activeLease($this->other, $this->otherOwner, unitIn($this->other, buildingIn($this->other, ['name' => 'Foreign Court', 'slug' => 'foreign-court'])), tenantIn($this->other, ['first_name' => 'Foreign', 'last_name' => 'Tenant', 'phone' => '+2348035550000', 'email' => 'foreign.tenant@example.com']));
        runBillGeneration();
        $theirs = billsOf($this->other)->sole();
        $theirMedia = keptInvoice($this->other, $theirs);

        invoicePage($this->owner, $theirs)->assertNotFound();
        invoiceDownload($this->owner, $theirs)->assertNotFound();
        invoicePage($this->owner, $ours, 'other-realty')->assertNotFound();
        invoicePage($this->otherOwner, $ours)->assertNotFound();
        invoiceDownload($this->otherOwner, $ours)->assertNotFound();
        $this->actingAs(User::factory()->create())->get("/o/acme-homes/bills/{$ours->id}/invoice")->assertNotFound();

        $forged = str_replace('/media/'.$this->other->id.'/', '/media/'.$this->acme->id.'/', app(MediaUrls::class)->temporary($theirMedia));
        billsFreshRequest();
        $this->get($forged)->assertNotFound();
        expect(inOrganization($this->acme, fn () => Media::query()->whereKey($theirMedia->id)->exists()))->toBeFalse();
    });

    it('answers 404 to an agent assigned to other buildings', function () {
        holdInvoicePdfs();
        $bill = issuedInvoiceBill($this);
        $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
        $oak = buildingIn($this->acme, ['name' => 'Oak Lodge', 'slug' => 'oak-lodge']);
        assignIn($this->acme, $agent, $oak);
        expect(inOrganization($this->acme, fn () => app(BuildingAccess::class)->limitedTo($agent)))->toBe([$oak->id]);

        invoicePage($agent, $bill)->assertNotFound();
        invoiceDownload($agent, $bill)->assertNotFound();
    });

    it('still shows the invoice while the organization is suspended (a read), but never to impersonating staff', function (ImpersonationMode $mode) {
        holdInvoicePdfs();
        $bill = issuedInvoiceBill($this);
        $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();
        invoicePage($this->owner, $bill)->assertOk();
        invoiceDownload($this->owner, $bill)->assertRedirect();
        $this->acme->forceFill(['status' => OrganizationStatus::Active])->save();

        seedStaffRoles();
        $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
        $handOff = $mode === ImpersonationMode::Write
            ? startSupport($staff, $this->owner, $this->acme, ImpersonationMode::Write)
            : startSupport($staff, $this->owner, $this->acme);
        enterSupport($handOff)->assertRedirect('/o/acme-homes');

        asSupport()->get("/o/acme-homes/bills/{$bill->id}")->assertOk()->assertDontSee('data-test="bill-invoice"', false);
        asSupport()->get("/o/acme-homes/bills/{$bill->id}/invoice")->assertForbidden();
        asSupport()->get("/o/acme-homes/bills/{$bill->id}/invoice.pdf")->assertForbidden();
    })->with([
        'read-only' => [ImpersonationMode::ReadOnly],
        'write' => [ImpersonationMode::Write],
    ]);
});

describe('acceptance: a real issued invoice prints alike with dompdf and Chrome', function () {
    it('prints on one A4 page with dompdf, in the organization\'s layout', function (DocumentLayout $layout) {
        invoiceBranding($this->acme, $this->owner, ['layout' => $layout->value]);
        holdInvoicePdfs();
        $bill = issuedInvoiceBill($this);
        $pdf = inOrganization($this->acme, fn () => app(DocumentRenderer::class)->pdf(app(Invoices::class)->document(storedBill($this->acme, $bill)), 'dompdf'));

        requirePdfTools();
        expect(PdfVisualDiff::rasterise($pdf))->toHaveCount(1)
            ->and(PdfVisualDiff::text($pdf))->toContain('INVOICE', 'INV-ADR-2026-000001', 'Kemi Adebayo', '0123456789', 'Page 1 of 1');
    })->with([DocumentLayout::Modern, DocumentLayout::Classic, DocumentLayout::Compact]);

    it('prints the same with Chrome (Gotenberg) as with dompdf, within the threshold', function (DocumentLayout $layout) {
        requirePdfTools();
        requireGotenberg();
        invoiceBranding($this->acme, $this->owner, ['layout' => $layout->value]);
        holdInvoicePdfs();
        $bill = issuedInvoiceBill($this);
        $document = inOrganization($this->acme, fn () => app(Invoices::class)->document(storedBill($this->acme, $bill)));
        $renderer = app(DocumentRenderer::class);

        $diff = PdfVisualDiff::compare($renderer->pdf($document, 'dompdf'), $chrome = $renderer->pdf($document, 'chrome'));

        expect($diff['pages'])->toBe([1, 1])
            ->and(PdfVisualDiff::text($chrome))->toContain('INV-ADR-2026-000001', VerificationCodes::format($bill->verification_code), 'Page 1 of 1')
            ->and($diff['score'])->toBeLessThan(PdfVisualDiff::THRESHOLD);
    })->with([DocumentLayout::Modern, DocumentLayout::Classic, DocumentLayout::Compact]);
});
