<?php

declare(strict_types=1);

namespace Modules\Billing\Bills;

use App\Documents\BrandingSnapshot;
use App\Documents\DocumentNumberer;
use App\Documents\DocumentType;
use App\Documents\Verification\VerificationCodes;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Events\BillIssued;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\BillItem;
use RuntimeException;

/**
 * Issues a bill (ARCHITECTURE.md § 6.4–6.5; DATABASE_SCHEMA.md § 4): the
 * one place a `bills` row and its `bill_items` are created, inside the
 * caller's transaction (run as DB::transaction($callback, 3), AGENT.md
 * § 4), in the current organization.
 *
 * - The invoice number comes from App\Documents\DocumentNumberer (type
 *   `invoice`, shared by every bill type), so a bill that rolls back gives
 *   its number back: numbers stay gap-free.
 * - The verification code from VerificationCodes::issue('invoice'), the
 *   payment reference from Bills\PaymentReferences (unique across
 *   organizations: drawn again on the rare collision), and the branding as
 *   at issue (the caller's BrandingSnapshot, taken once per run).
 * - The parties as at issue (Bills\BillDetails: tenant, building, unit,
 *   lease number and cycle) in `details`, so the invoice never changes
 *   when they do (P03-05c).
 * - The amount is the sum of the lines, in kobo; the status follows from
 *   it and the due date (BillStatus::for(): a bill issued after its due
 *   date, e.g. a catch-up period, is overdue at once).
 * - Audited (log `bills`, `bill.issued`, the bill as subject) and announced
 *   with BillIssued, inside the transaction.
 */
final readonly class BillIssuer
{
    public const string LOG = 'bills';

    /** The verification kind of bills (DocumentVerifiers; Documents\InvoiceVerifier resolves it). */
    public const string VERIFICATION_KIND = 'invoice';

    private const int REFERENCE_ATTEMPTS = 5;

    public function __construct(
        private OrganizationContext $context,
        private DocumentNumberer $numberer,
        private VerificationCodes $codes,
        private PaymentReferences $references,
        private BillDetails $details,
    ) {}

    public function issue(BillDraft $draft, BrandingSnapshot $branding): Bill
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Issue a bill inside a transaction.');
        }

        if ($draft->lines === []) {
            throw new InvalidArgumentException('A bill has at least one line.');
        }

        $amount = Money::sum(array_map(static fn (BillLine $line): Money => $line->amount, $draft->lines));

        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('A bill asks for more than nothing.');
        }

        // Bills are written inside an organization (BelongsToOrganization refuses otherwise).
        $this->context->id();
        $issuedAt = CarbonImmutable::now();

        $bill = new Bill;
        $bill->forceFill([
            'type' => $draft->type,
            'billable_type' => $draft->billableType,
            'billable_id' => $draft->billableId,
            'lease_id' => $draft->leaseId,
            'tenant_profile_id' => $draft->tenantProfileId,
            'property_id' => $draft->propertyId,
            'unit_id' => $draft->unitId,
            'unit_utility_setting_id' => $draft->unitUtilitySettingId,
            'description' => mb_substr($draft->description, 0, 255),
            'amount_kobo' => $amount,
            'paid_kobo' => Money::zero(),
            'due_date' => $draft->dueDate,
            'period_start' => $draft->periodStart,
            'period_end' => $draft->periodEnd,
            'status' => BillStatus::for($amount, Money::zero(), $draft->dueDate->toDateString(), BillDates::today()->toDateString()),
            'issued_at' => $issuedAt,
            'branding_snapshot' => $branding->toArray(),
            'details' => $this->details->take($draft->tenantProfileId, $draft->propertyId, $draft->unitId, $draft->leaseNumber, $draft->cycle)->toArray(),
            'created_by_user_id' => $draft->createdByUserId,
        ]);
        $bill->number = $this->numberer->next(DocumentType::Invoice, $issuedAt, $branding->branding);
        $bill->verification_code = $this->codes->issue(self::VERIFICATION_KIND);
        $this->saveWithReference($bill);

        foreach ($draft->lines as $line) {
            $item = new BillItem;
            $item->forceFill([
                'bill_id' => $bill->id,
                'label' => mb_substr($line->label, 0, 120),
                'note' => $line->note === null ? null : mb_substr($line->note, 0, 255),
                'amount_kobo' => $line->amount,
                'quantity' => $line->quantity,
                'period_start' => $line->periodStart,
                'period_end' => $line->periodEnd,
                'meta' => $line->meta === [] ? null : $line->meta,
            ])->save();
        }

        activity(self::LOG)
            ->event('bill.issued')
            ->performedOn($bill)
            ->withProperties([
                'number' => $bill->number,
                'type' => $bill->type->value,
                'lease_id' => $bill->lease_id,
                'unit_utility_setting_id' => $bill->unit_utility_setting_id,
                'tenant_profile_id' => $bill->tenant_profile_id,
                'amount_kobo' => $amount->kobo,
                'period_start' => $bill->period_start?->toDateString(),
                'due_date' => $bill->due_date->toDateString(),
            ])
            ->log('Bill issued');

        event(new BillIssued(...BillService::eventIds($bill)));

        return $bill;
    }

    /**
     * Saves the new bill with a fresh payment reference, drawing again when
     * another bill (of any organization) already has it. Each try runs in a
     * savepoint, so a collision leaves the caller's transaction as it was.
     */
    private function saveWithReference(Bill $bill): void
    {
        for ($attempt = 1; $attempt <= self::REFERENCE_ATTEMPTS; $attempt++) {
            $bill->payment_reference = $this->references->generate();

            try {
                DB::transaction(static fn (): bool => $bill->save());

                return;
            } catch (UniqueConstraintViolationException $collision) {
                if (! str_contains($collision->getMessage(), 'bills_payment_reference_unique')) {
                    throw $collision;
                }
            }
        }

        throw new RuntimeException('Could not find a free payment reference.');
    }
}
