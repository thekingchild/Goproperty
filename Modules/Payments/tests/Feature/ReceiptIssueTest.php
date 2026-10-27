<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Branding\Branding;
use App\Documents\DocumentLayout;
use App\Documents\DocumentRenderer;
use App\Documents\PdfPages;
use App\Documents\Verification\VerificationCodes;
use App\Media\EvidenceFileProtected;
use App\Media\Media;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Modules\Payments\Contracts\PaymentDraft;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Contracts\PaymentRecorder;
use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Events\PaymentConfirmed;
use Modules\Payments\Jobs\RenderReceipt;
use Modules\Payments\Listeners\IssueReceipt;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\Receipt;
use Modules\Payments\Payments\PaymentService;
use Modules\Payments\Receipts\Receipts;
use Tests\Support\PdfVisualDiff;

require_once __DIR__.'/receipt-helpers.php';

/*
 * P03-10b: a numbered receipt for every confirmed payment
 * (ARCHITECTURE.md § 6.4, BRANDING_DOCUMENTS.md § 3): issued in the
 * confirming transaction (number per organization and year, verification
 * code, branding and parties kept as at issue, "confirmed by"), exactly once
 * per payment however often the confirmation is announced, its PDF rendered
 * once on the `pdf` queue after the commit and kept as evidence; the same
 * receipt prints alike with dompdf and Chrome.
 */

beforeEach(function () {
    // Receipt and invoice PDFs wait on the queue; the tests that need one render it.
    holdReceiptPdfs();
    seedOrganizationRoles();
    $this->travelTo('2026-12-25 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Tunde Bakare']), $this->acme);
    $this->otherOwner = joinAs(User::factory()->create(['name' => 'Chidi Bello']), $this->other);
    receiptBranding($this->acme, $this->owner);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court', 'address' => '22 Fola Osibo Road, Lekki', 'state' => 'Lagos']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348030000001', 'email' => 'kemi@example.test']);
    $this->lease = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']), $this->kemi, ['billingCycle' => 'monthly', 'rent' => '100,000', 'deposit' => '0']);
    $this->bello = tenantIn($this->other, ['first_name' => 'Bisi', 'last_name' => 'Okoro', 'phone' => '+2348030000009', 'email' => 'bisi@example.test']);
    activeLease($this->other, $this->otherOwner, unitIn($this->other, buildingIn($this->other, ['name' => 'Foreign Court'])), $this->bello, ['billingCycle' => 'monthly', 'rent' => '100,000', 'deposit' => '0']);
    runBillGeneration();
    $this->january = billsOfTenant($this->acme, $this->kemi)->sole();
});

afterEach(fn () => billsFreshRequest());

/** A draft for the payment recorder (Collections, OnlinePayments). */
function receiptDraft(int $tenantId, string $key, int $naira, array $changes = []): PaymentDraft
{
    return new PaymentDraft(...$changes + [
        'tenantProfileId' => $tenantId,
        'amount' => Money::naira($naira),
        'method' => PaymentMethod::BankTransfer,
        'source' => PaymentSource::StatementMatch,
        'idempotencyKey' => $key,
        'paidAt' => CarbonImmutable::parse('2026-12-24 15:00:00'),
    ]);
}

it('issues one numbered receipt when a payment is confirmed: number, code, what it prints as at issue, confirmed by', function () {
    $payment = confirmedPayment($this->acme, $this->owner, $this->kemi, '150,000', ['reference' => 'TRF KEMI JAN']);
    $receipt = receiptOf($this->acme, $payment);
    $details = $receipt->details;

    expect($receipt->number)->toBe('RCT-ADR-2026-000001')
        ->and(VerificationCodes::normalize($receipt->verification_code))->toBe($receipt->verification_code)
        ->and(DB::table('document_verifications')->where('code', $receipt->verification_code)->first(['organization_id', 'kind']))
        ->toEqual((object) ['organization_id' => $this->acme->id, 'kind' => 'receipt'])
        ->and($receipt->amount_kobo->kobo)->toBe(15_000_000)
        ->and($receipt->issued_by_user_id)->toBe($this->owner->id)
        ->and(storedPayment($this->acme, $payment)->receipt_id)->toBe($receipt->id)
        ->and($receipt->branding_snapshot['branding']['trading_name'])->toBe('Adeyemi Realty')
        ->and($details['tenant'])->toEqual(['name' => 'Kemi Adebayo', 'phone' => '+2348030000001', 'email' => 'kemi@example.test'])
        ->and($details['property'])->toEqual(['building' => 'Palm Court', 'unit' => 'Flat 1', 'address' => '22 Fola Osibo Road, Lekki, Lagos', 'lease_number' => $this->lease->fresh()->contract_number, 'cycle' => 'Monthly'])
        ->and($details['allocations'])->toEqual([['bill_id' => $this->january->id, 'invoice_number' => $this->january->number, 'description' => 'Rent · 01/01/2027 – 31/01/2027', 'amount_kobo' => 10_000_000, 'settled' => true]])
        ->and($details['outstanding_after_kobo'])->toBe(0)
        ->and($details['credit_balance_kobo'])->toBe(5_000_000)
        ->and($details['confirmed_by'])->toBe('Tunde Bakare')
        ->and($details['payment']['reference'])->toBe('TRF KEMI JAN')
        ->and($details['sent_via'])->toBe(['email'])
        ->and(AuditEntry::query()->where('event', 'receipt.issued')->where('subject_id', $receipt->id)->value('causer_id'))->toBe($this->owner->id);

    // As printed: the agency's name, the tenant, the bill, "confirmed by", the code.
    $html = app(DocumentRenderer::class)->web(inOrganization($this->acme, fn () => app(Receipts::class)->document($receipt)));
    expect($html)->toContain('OFFICIAL RECEIPT', 'RCT-ADR-2026-000001', 'Adeyemi Realty', 'Kemi Adebayo', 'Palm Court', $this->january->number, 'Tunde Bakare', 'One hundred and fifty thousand naira only', VerificationCodes::format($receipt->verification_code), 'A copy was sent to the tenant by email.')
        ->and($html)->not->toContain('VOID');
});

it('says who confirmed it: the member, or how the system did', function () {
    $paystack = inOrganization($this->acme, fn () => app(PaymentRecorder::class)->recordConfirmed(receiptDraft($this->kemi->id, 'paystack:T-1', 100_000, ['method' => PaymentMethod::Paystack, 'source' => PaymentSource::PaystackWebhook, 'paystackReference' => 'T-1'])));
    $statement = inOrganization($this->acme, fn () => app(PaymentRecorder::class)->recordConfirmed(receiptDraft($this->kemi->id, 'statement-line:7', 5_000)));
    $cash = inOrganization($this->acme, fn () => app(PaymentService::class)->recordAndConfirm($this->owner, paymentInput($this->kemi, '2,000', ['method' => 'cash', 'note' => 'Paid at the office'])));

    $printed = fn (int $paymentId) => inOrganization($this->acme, fn () => app(Receipts::class)->document(receiptOf($this->acme, $paymentId)));

    expect($printed($paystack->paymentId)->confirmedBy)->toBe('Automatically — Paystack')
        ->and($printed($paystack->paymentId)->payment->rows())->toMatchArray(['Method' => 'Paystack', 'Paystack reference' => 'T-1', 'Confirmed' => 'Paystack'])
        ->and($printed($statement->paymentId)->confirmedBy)->toBe('Statement match')
        ->and($printed($statement->paymentId)->payment->rows()['Confirmed'])->toBe('Matched from bank statement')
        ->and($printed($cash->id)->confirmedBy)->toBe('Tunde Bakare')
        ->and($printed($cash->id)->payment->rows())->toMatchArray(['Method' => 'Cash', 'Confirmed' => 'Cash received'])
        ->and(receiptOf($this->acme, $paystack->paymentId)->issued_by_user_id)->toBeNull()
        ->and(receiptsOf($this->acme)->pluck('number')->all())->toBe(['RCT-ADR-2026-000001', 'RCT-ADR-2026-000002', 'RCT-ADR-2026-000003']);
});

describe('sub: receipt numbers are sequential per organization', function () {
    it('numbers each organization\'s receipts 1, 2, 3 … whatever the other organization issues in between', function () {
        $otherBill = billsOfTenant($this->other, $this->bello)->sole();
        $numbers = [];

        $numbers[] = receiptOf($this->acme, confirmedPayment($this->acme, $this->owner, $this->kemi, '10,000'))->number;
        $theirs = [receiptOf($this->other, confirmedPayment($this->other, $this->otherOwner, $this->bello, '10,000', ['billId' => (string) $otherBill->id]))->number];
        $numbers[] = receiptOf($this->acme, confirmedPayment($this->acme, $this->owner, $this->kemi, '20,000'))->number;
        $theirs[] = receiptOf($this->other, confirmedPayment($this->other, $this->otherOwner, $this->bello, '20,000'))->number;
        $numbers[] = receiptOf($this->acme, inOrganization($this->acme, fn () => app(PaymentService::class)->recordAndConfirm($this->owner, paymentInput($this->kemi, '30,000'))))->number;

        $theirPrefix = inOrganization($this->other, fn () => Branding::fromOrganization($this->other->fresh())->receiptPrefix);

        expect($numbers)->toBe(['RCT-ADR-2026-000001', 'RCT-ADR-2026-000002', 'RCT-ADR-2026-000003'])
            ->and($theirs)->toBe(["{$theirPrefix}-2026-000001", "{$theirPrefix}-2026-000002"]);
    });

    it('gives a rolled-back confirmation\'s number back, and starts again at 1 in a new year', function () {
        confirmedPayment($this->acme, $this->owner, $this->kemi, '10,000');
        $pending = recordPayment($this->acme, $this->owner, $this->kemi, '20,000');

        // The confirmation is undone after the receipt was issued: its number is not used up.
        expect(fn () => inOrganization($this->acme, fn () => DB::transaction(function () use ($pending): void {
            app(PaymentService::class)->confirm($this->owner, $pending);

            throw new RuntimeException('The request failed after confirming.');
        })))->toThrow(RuntimeException::class);

        expect(receiptsOf($this->acme))->toHaveCount(1)
            ->and(storedPayment($this->acme, $pending)->receipt_id)->toBeNull();

        $second = confirmPayment($this->acme, $this->owner, storedPayment($this->acme, $pending));
        $this->travelTo('2027-01-02 10:00:00');
        $third = confirmedPayment($this->acme, $this->owner, $this->kemi, '30,000');

        expect(receiptOf($this->acme, $second)->number)->toBe('RCT-ADR-2026-000002')
            ->and(receiptOf($this->acme, $third)->number)->toBe('RCT-ADR-2027-000001');
    });
});

it('acceptance: a confirmed payment issues exactly one receipt even if the confirmation is handled twice', function () {
    $payment = confirmedPayment($this->acme, $this->owner, $this->kemi, '100,000');
    $receipt = receiptOf($this->acme, $payment);
    $event = new PaymentConfirmed(
        paymentId: $payment->id,
        organizationId: $this->acme->id,
        tenantProfileId: $this->kemi->id,
        amountKobo: 10_000_000,
        method: 'bank_transfer',
        source: 'manual',
        confirmedByUserId: $this->owner->id,
        allocations: [['billId' => $this->january->id, 'amountKobo' => 10_000_000]],
        creditKobo: 0,
    );

    // Announced again, inside and outside a transaction, and the listener called directly.
    event($event);
    inOrganization($this->acme, fn () => DB::transaction(fn () => event($event)));
    app(IssueReceipt::class)->handle($event);
    inOrganization($this->acme, fn () => app(Receipts::class)->issue($payment->id));

    expect(receiptsOf($this->acme))->toHaveCount(1)
        ->and(receiptOf($this->acme, $payment)->id)->toBe($receipt->id)
        ->and(receiptOf($this->acme, $payment)->number)->toBe('RCT-ADR-2026-000001')
        ->and(DB::table('document_verifications')->where('organization_id', $this->acme->id)->where('kind', 'receipt')->count())->toBe(1)
        ->and(DB::table('document_counters')->where('organization_id', $this->acme->id)->where('doc_type', 'receipt')->value('last_seq'))->toBe(1)
        ->and(AuditEntry::query()->where('event', 'receipt.issued')->count())->toBe(1);
    Queue::assertPushed(RenderReceipt::class, 1);

    // The database refuses a second receipt for the payment too.
    expect(fn () => inOrganization($this->acme, fn () => (new Receipt)->forceFill([
        'payment_id' => $payment->id,
        'tenant_profile_id' => $this->kemi->id,
        'number' => 'RCT-ADR-2026-000099',
        'verification_code' => app(VerificationCodes::class)->generate(),
        'amount_kobo' => Money::naira(100_000),
        'branding_snapshot' => ['version' => 1],
        'details' => [],
    ])->save()))->toThrow(UniqueConstraintViolationException::class);
});

it('issues no receipt for a payment that waits, is rejected, or whose confirmation is refused', function () {
    $waiting = recordPayment($this->acme, $this->owner, $this->kemi, '10,000');
    $rejected = recordPayment($this->acme, $this->owner, $this->kemi, '20,000');
    inOrganization($this->acme, fn () => app(PaymentService::class)->reject($this->owner, $rejected, 'Never arrived'));
    $refused = recordPayment($this->acme, $this->owner, $this->kemi, '30,000');

    expect(fn () => confirmPayment($this->acme, $this->owner, $refused, [$this->january->id => '40,000']))->toThrow(ValidationException::class)
        ->and(fn () => inOrganization($this->acme, fn () => app(Receipts::class)->issue($waiting->id)))->toThrow(LogicException::class, 'confirmed payment only')
        ->and(receiptsOf($this->acme))->toHaveCount(0)
        ->and(DB::table('document_counters')->where('doc_type', 'receipt')->sum('last_seq'))->toEqual(0);
    Queue::assertNotPushed(RenderReceipt::class);
});

it('renders the PDF once on the pdf queue after the commit, and keeps that file as evidence', function () {
    requirePdfTools();
    $payment = confirmedPayment($this->acme, $this->owner, $this->kemi, '100,000');
    $receipt = receiptOf($this->acme, $payment);

    Queue::assertPushedOn('pdf', RenderReceipt::class, fn (RenderReceipt $job): bool => $job->receiptId === $receipt->id && $job->organizationId === $this->acme->id && ! $job->void);
    Queue::assertPushed(RenderReceipt::class, 1);

    $renders = countingPdfDriver();
    renderReceiptPdf($this->acme, $receipt);
    renderReceiptPdf($this->acme, $receipt);
    $media = inOrganization($this->acme, fn () => Media::query()->where('model_type', (new Receipt)->getMorphClass())->where('model_id', $receipt->id)->get());

    expect($renders->renders)->toBe(1)
        ->and($media)->toHaveCount(1)
        ->and($media->sole()->collection_name)->toBe('issued_documents')
        ->and($media->sole()->getCustomProperty('document_key'))->toBe('receipt')
        ->and(storedReceipt($this->acme, $receipt)->document_media_id)->toBe($media->sole()->id);

    $text = PdfVisualDiff::text((string) file_get_contents($media->sole()->getPath()));
    expect($text)->toContain('OFFICIAL RECEIPT', 'RCT-ADR-2026-000001', 'Kemi Adebayo', 'Tunde Bakare', $this->january->number, 'Page 1 of 1')
        ->and(PdfPages::count((string) file_get_contents($media->sole()->getPath())))->toBe(1);

    // The kept file is evidence: never deleted by a customer action.
    expect(fn () => inOrganization($this->acme, fn () => $media->sole()->delete()))->toThrow(EvidenceFileProtected::class);
});

it('prints the receipt as issued after the branding, the tenant and the building change', function () {
    $receipt = receiptOf($this->acme, confirmedPayment($this->acme, $this->owner, $this->kemi, '100,000'));
    $issued = app(DocumentRenderer::class)->web(inOrganization($this->acme, fn () => app(Receipts::class)->document($receipt)));

    receiptBranding($this->acme->fresh(), $this->owner, ['tradingName' => 'Brand New Homes', 'primaryColor' => '#7C2D12', 'receiptPrefix' => 'RCT-BNH', 'layout' => 'classic']);
    inOrganization($this->acme, function (): void {
        $this->kemi->fresh()->forceFill(['first_name' => 'Kemisola'])->save();
        $this->palm->fresh()->forceFill(['name' => 'Palm Towers'])->save();
    });

    $again = app(DocumentRenderer::class)->web(inOrganization($this->acme, fn () => app(Receipts::class)->document(storedReceipt($this->acme, $receipt))));

    expect($again)->toBe($issued)
        ->and($again)->toContain('Adeyemi Realty', 'Kemi Adebayo', 'Palm Court', 'RCT-ADR-2026-000001')
        ->and($again)->not->toContain('Brand New Homes', 'Kemisola', 'Palm Towers');

    // The next receipt takes the new branding and prefix.
    expect(receiptOf($this->acme, confirmedPayment($this->acme, $this->owner, $this->kemi, '5,000'))->number)->toBe('RCT-BNH-2026-000002');
});

it('never changes or deletes an issued receipt', function () {
    $receipt = receiptOf($this->acme, confirmedPayment($this->acme, $this->owner, $this->kemi, '100,000'));

    inOrganization($this->acme, function () use ($receipt): void {
        foreach ([['number' => 'RCT-X'], ['amount_kobo' => Money::naira(1)], ['details' => []], ['branding_snapshot' => []], ['verification_code' => 'AAAAAAAAAA'], ['payment_id' => 1], ['issued_at' => now()->addDay()]] as $change) {
            expect(fn () => Receipt::query()->findOrFail($receipt->id)->forceFill($change)->save())->toThrow(LogicException::class, 'never changed');
        }

        expect(fn () => Receipt::query()->findOrFail($receipt->id)->delete())->toThrow(LogicException::class, 'never deleted')
            ->and(fn () => Receipt::query()->findOrFail($receipt->id)->forceFill(['void_document_media_id' => 5])->save())->toThrow(LogicException::class, 'never changed')
            ->and(Gate::forUser($this->owner)->allows('update', Receipt::query()->findOrFail($receipt->id)))->toBeFalse()
            ->and(Gate::forUser($this->owner)->allows('delete', Receipt::query()->findOrFail($receipt->id)))->toBeFalse();

        // Neither the payment nor the receipt can be dropped from under each other.
        expect(fn () => DB::table('receipts')->where('id', $receipt->id)->delete())->toThrow(QueryException::class);
    });

    expect(storedReceipt($this->acme, $receipt)->number)->toBe('RCT-ADR-2026-000001');
});

describe('acceptance: a real receipt prints alike with dompdf and Chrome', function () {
    it('prints a confirmed payment\'s receipt on one A4 page with dompdf, in the organization\'s layout', function (DocumentLayout $layout) {
        requirePdfTools();
        receiptBranding($this->acme, $this->owner, ['layout' => $layout->value]);
        $receipt = receiptOf($this->acme, confirmedPayment($this->acme, $this->owner, $this->kemi, '150,000', ['reference' => 'TRF KEMI JAN']));
        $document = inOrganization($this->acme, fn () => app(Receipts::class)->document($receipt));
        $pdf = app(DocumentRenderer::class)->pdf($document, 'dompdf');

        expect($document->view())->toBe('documents.receipt.'.$layout->value)
            ->and(PdfPages::count($pdf))->toBe(1)
            ->and(PdfVisualDiff::text($pdf))->toContain('RCT-ADR-2026-000001', 'Tunde Bakare', 'Kemi Adebayo', VerificationCodes::format($receipt->verification_code), 'Page 1 of 1');
    })->with([DocumentLayout::Modern, DocumentLayout::Classic, DocumentLayout::Compact]);

    it('prints the same with Chrome (Gotenberg) as with dompdf, within the threshold, valid and voided', function (DocumentLayout $layout) {
        requirePdfTools();
        requireGotenberg();
        receiptBranding($this->acme, $this->owner, ['layout' => $layout->value]);
        $receipt = receiptOf($this->acme, confirmedPayment($this->acme, $this->owner, $this->kemi, '150,000', ['reference' => 'TRF KEMI JAN']));
        $renderer = app(DocumentRenderer::class);
        voidReceiptAlone($this->acme, $this->owner, $receipt);
        $receipt = storedReceipt($this->acme, $receipt);

        foreach ([false, true] as $void) {
            $document = inOrganization($this->acme, fn () => app(Receipts::class)->document($receipt, $void));
            $dompdf = $renderer->pdf($document, 'dompdf');
            $chrome = $renderer->pdf($document, 'chrome');
            $diff = PdfVisualDiff::compare($dompdf, $chrome);

            expect($diff['pages'])->toBe([1, 1])
                ->and(PdfVisualDiff::text($chrome))->toContain('RCT-ADR-2026-000001', 'Tunde Bakare', 'Page 1 of 1')
                ->and($diff['score'])->toBeLessThan(PdfVisualDiff::THRESHOLD);
        }
    })->with([DocumentLayout::Modern, DocumentLayout::Classic, DocumentLayout::Compact]);
});
