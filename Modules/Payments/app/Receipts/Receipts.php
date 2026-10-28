<?php

declare(strict_types=1);

namespace Modules\Payments\Receipts;

use App\Documents\BrandingSnapshot;
use App\Documents\BrandingSnapshots;
use App\Documents\DocumentNumberer;
use App\Documents\DocumentRenderer;
use App\Documents\DocumentType;
use App\Documents\Parts\DocumentProperty;
use App\Documents\Parts\DocumentTenant;
use App\Documents\Receipt\ReceiptAllocation;
use App\Documents\Receipt\ReceiptDocument;
use App\Documents\Receipt\ReceiptPayment;
use App\Documents\Verification\VerificationCodes;
use App\Media\Media;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Jobs\RenderReceipt;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\Receipt;

/**
 * Receipts (P03-10b; BRANDING_DOCUMENTS.md § 3, § 5; ARCHITECTURE.md
 * § 6.4–6.5): the only writer of issued receipts (voids: ReceiptVoids).
 *
 * - issue(): once a payment is confirmed (Listeners\IssueReceipt, on
 *   PaymentConfirmed inside the confirming transaction, so a confirmed
 *   payment always has its receipt and a rolled-back confirmation gives its
 *   number back): the receipt number (DocumentNumberer, per organization,
 *   type and year: decision 7), a verification code (kind `receipt`), the
 *   agency's branding snapshot (images embedded) and what the receipt
 *   prints (ReceiptDetails), with "confirmed by". Once per payment, under
 *   the payment's row lock (`receipts.payment_id` is unique too), so a
 *   PaymentConfirmed handled twice issues one receipt. Its PDF is queued on
 *   `pdf` after the commit (Jobs\RenderReceipt).
 * - pdf(): the receipt as issued, rendered the first time only
 *   (DocumentRenderer::stored(), key `receipt`) and kept as evidence.
 * - voidPdf(): a voided receipt's VOID copy (watermark and reason), a
 *   second kept file (key `receipt-void`); the original stays as it was.
 *
 * Later changes to the branding, the tenant, the building or the bills
 * never change an issued receipt.
 */
final readonly class Receipts
{
    /** The verification kind registered in DocumentVerifiers. */
    public const string KIND = 'receipt';

    /** The kept PDF's key as issued (DocumentRenderer::stored()). */
    public const string DOCUMENT_KEY = 'receipt';

    /** The VOID copy's key. */
    public const string VOID_KEY = 'receipt-void';

    public const string LOG = 'payments';

    public function __construct(
        private OrganizationContext $context,
        private BrandingSnapshots $snapshots,
        private DocumentNumberer $numberer,
        private VerificationCodes $codes,
        private ReceiptDetails $details,
        private DocumentRenderer $renderer,
    ) {}

    public function forPayment(int $paymentId): ?Receipt
    {
        return Receipt::query()->where('payment_id', $paymentId)->first();
    }

    /**
     * The confirmed payment's receipt, issued now if it was not yet
     * (idempotent). Inside the caller's transaction when there is one.
     */
    public function issue(int $paymentId): Receipt
    {
        return DB::transaction(function () use ($paymentId): Receipt {
            $payment = Payment::query()->withTrashed()->whereKey($paymentId)->lockForUpdate()->first();

            if (! $payment instanceof Payment || $payment->status !== PaymentStatus::Confirmed) {
                throw new LogicException('A receipt is issued for a confirmed payment only.');
            }

            $existing = $this->forPayment($payment->id);

            if ($existing instanceof Receipt) {
                return $existing;
            }

            $organization = Organization::query()->findOrFail($this->context->id());
            $snapshot = $this->snapshots->take($organization);
            $issuedAt = CarbonImmutable::now();

            $receipt = new Receipt;
            $receipt->forceFill([
                'payment_id' => $payment->id,
                'tenant_profile_id' => $payment->tenant_profile_id,
                'number' => $this->numberer->next(DocumentType::Receipt, $issuedAt, $snapshot->branding),
                'verification_code' => $this->codes->issue(self::KIND),
                'amount_kobo' => $payment->amount_kobo,
                'issued_at' => $issuedAt,
                'issued_by_user_id' => $payment->confirmed_by_user_id,
                'branding_snapshot' => $snapshot->toArray(),
                'details' => $this->details->take($payment, $organization),
            ])->save();

            $payment->receipt_id = $receipt->id;
            $payment->save();

            $log = activity(self::LOG)
                ->event('receipt.issued')
                ->performedOn($receipt)
                ->withProperties([
                    'payment_id' => $payment->id,
                    'number' => $receipt->number,
                    'amount_kobo' => $payment->amount_kobo->kobo,
                ]);

            $confirmer = $payment->confirmed_by_user_id !== null ? User::query()->find($payment->confirmed_by_user_id) : null;

            if ($confirmer instanceof User) {
                $log->causedBy($confirmer);
            }

            $log->log('Receipt issued');

            RenderReceipt::dispatch($organization->id, $receipt->id, false);

            return $receipt;
        }, 3);
    }

    /** The receipt as printed: as issued, or (with $void) its VOID copy. */
    public function document(Receipt $receipt, bool $void = false): ReceiptDocument
    {
        if ($void && ! $receipt->isVoided()) {
            throw new LogicException('Only a voided receipt has a VOID copy.');
        }

        $details = $receipt->details;
        $tenant = self::map($details['tenant'] ?? null);
        $property = self::map($details['property'] ?? null);
        $payment = self::map($details['payment'] ?? null);
        $method = PaymentMethod::tryFrom(self::text($payment['method'] ?? null) ?? '') ?? PaymentMethod::BankTransfer;
        $source = PaymentSource::tryFrom(self::text($payment['source'] ?? null) ?? '') ?? PaymentSource::Manual;
        $allocations = [];

        foreach (is_array($details['allocations'] ?? null) ? $details['allocations'] : [] as $row) {
            $row = self::map($row);
            $allocations[] = new ReceiptAllocation(
                invoiceNumber: self::text($row['invoice_number'] ?? null) ?? '',
                description: self::text($row['description'] ?? null) ?? '',
                amount: Money::kobo(self::integer($row['amount_kobo'] ?? null)),
                settled: ($row['settled'] ?? false) === true,
            );
        }

        $sentVia = array_values(array_filter(is_array($details['sent_via'] ?? null) ? $details['sent_via'] : [], 'is_string'));

        return new ReceiptDocument(
            brand: BrandingSnapshot::fromArray($receipt->branding_snapshot)->documentBranding(),
            number: $receipt->number,
            issuedAt: $receipt->issued_at,
            amount: $receipt->amount_kobo,
            tenant: new DocumentTenant(self::text($tenant['name'] ?? null) ?? '', self::text($tenant['phone'] ?? null), self::text($tenant['email'] ?? null)),
            property: new DocumentProperty(
                self::text($property['building'] ?? null) ?? '—',
                self::text($property['unit'] ?? null),
                self::text($property['address'] ?? null),
                self::text($property['lease_number'] ?? null),
                self::text($property['cycle'] ?? null),
            ),
            payment: new ReceiptPayment(
                method: $method->label(),
                paidOn: CarbonImmutable::parse(self::text($payment['paid_on'] ?? null) ?? $receipt->issued_at->toDateString(), 'Africa/Lagos')->startOfDay(),
                confirmation: self::confirmation($method, $source),
                reference: self::text($payment['reference'] ?? null),
                referenceLabel: match ($method) {
                    PaymentMethod::Paystack => 'Paystack reference',
                    PaymentMethod::Pos => 'POS slip',
                    PaymentMethod::Cash => 'Reference',
                    PaymentMethod::BankTransfer => 'Bank narration',
                },
            ),
            allocations: $allocations,
            outstandingAfter: Money::kobo(self::integer($details['outstanding_after_kobo'] ?? null)),
            creditBalance: Money::kobo(self::integer($details['credit_balance_kobo'] ?? null)),
            confirmedBy: self::text($details['confirmed_by'] ?? null) ?? 'Automatically',
            confirmedAt: CarbonImmutable::parse(self::text($details['confirmed_at'] ?? null) ?? $receipt->issued_at->toIso8601String()),
            verificationCode: $receipt->verification_code,
            sentVia: $sentVia,
            voidedAt: $void ? $receipt->voided_at : null,
            voidReason: $void ? $receipt->void_reason : null,
        );
    }

    /**
     * The receipt's PDF as issued: the kept file, or rendered and kept now
     * (once, under a lock on the receipt row). Records its media id.
     */
    public function pdf(Receipt $receipt): Media
    {
        $media = $this->renderer->stored($receipt, self::DOCUMENT_KEY, $this->document($receipt));

        if ($receipt->document_media_id === null) {
            Receipt::query()->whereKey($receipt->id)->whereNull('document_media_id')->update(['document_media_id' => $media->id]);
        }

        return $media;
    }

    /** A voided receipt's VOID copy: kept beside the original, rendered once. */
    public function voidPdf(Receipt $receipt): Media
    {
        $media = $this->renderer->stored($receipt, self::VOID_KEY, $this->document($receipt, void: true));

        if ($receipt->void_document_media_id === null) {
            Receipt::query()->whereKey($receipt->id)->whereNull('void_document_media_id')->whereNotNull('voided_at')->update(['void_document_media_id' => $media->id]);
        }

        return $media;
    }

    /** The kept PDF (as issued, or the VOID copy), if it has been rendered. */
    public function kept(Receipt $receipt, bool $void = false): ?Media
    {
        return $this->renderer->kept($receipt, $void ? self::VOID_KEY : self::DOCUMENT_KEY);
    }

    /** "How it was confirmed" as printed (BRANDING_DOCUMENTS.md § 3 item 4). */
    public static function confirmation(PaymentMethod $method, PaymentSource $source): string
    {
        return match ($source) {
            PaymentSource::PaystackWebhook => 'Paystack',
            PaymentSource::StatementMatch => 'Matched from bank statement',
            PaymentSource::ProofUpload => 'Proof reviewed',
            PaymentSource::Import => 'From the agency\'s earlier records',
            PaymentSource::Manual => match ($method) {
                PaymentMethod::Cash => 'Cash received',
                PaymentMethod::Pos => 'POS slip checked',
                PaymentMethod::BankTransfer, PaymentMethod::Paystack => 'Checked by the agency',
            },
        };
    }

    /** @return array<array-key, mixed> */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function integer(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }
}
