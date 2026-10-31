<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Models;

use App\Casts\MoneyCast;
use App\Media\HasOrganizationMedia;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\SaasBilling\Contracts\BankTransfers;
use Modules\SaasBilling\Enums\PlatformPaymentStatus;
use Spatie\MediaLibrary\HasMedia;

/**
 * A payment of a platform invoice into GoProperty's own account
 * (DATABASE_SCHEMA.md § 3 `platform_payments`; BILLING_PAYMENTS.md § 4–5):
 * through its Paystack (method `paystack`, one row per reference, unique)
 * or by bank transfer (method `bank_transfer`, P06-04: the owner's proof,
 * its file in the `platform_payment_proofs` collection, waiting for
 * platform staff). Organization-scoped; written only by
 * SmsCredits\SmsCreditPurchases, Payments\SubscriptionCheckout and
 * Payments\TransferProofs (started), Payments\PlatformPaymentSettlement and
 * Payments\TransferReviews (settled); never deleted, and a settled one
 * (confirmed, held, rejected) never changes.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $platform_invoice_id
 * @property string $method
 * @property Money $amount_kobo
 * @property string $currency
 * @property string|null $paystack_reference
 * @property string|null $paystack_channel
 * @property PlatformPaymentStatus $status
 * @property string|null $hold_reason
 * @property Money|null $paid_amount_kobo
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $confirmed_at
 * @property int|null $initiated_by_user_id
 * @property int|null $bank_transfer_proof_media_id
 * @property string|null $proof_sha256
 * @property Money|null $stated_amount_kobo
 * @property CarbonImmutable|null $transferred_on
 * @property string|null $sender_name
 * @property string|null $sender_bank
 * @property string|null $transfer_narration
 * @property int|null $reviewed_by_staff_id
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $rejection_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read PlatformInvoice $invoice
 */
final class PlatformPayment extends Model implements HasMedia
{
    use BelongsToOrganization, HasOrganizationMedia;

    public const string PAYSTACK = 'paystack';

    public const string BANK_TRANSFER = 'bank_transfer';

    /** The proof of a bank transfer: a screenshot, a photo or a PDF; evidence, never deleted. */
    public const string PROOF_COLLECTION = BankTransfers::COLLECTION;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static function (self $payment): void {
            $settled = $payment->getOriginal('status');

            if (($settled instanceof PlatformPaymentStatus && $settled->isSettled()) || $payment->isDirty(['platform_invoice_id', 'amount_kobo', 'currency', 'paystack_reference', 'method', 'proof_sha256', 'stated_amount_kobo', 'transferred_on', 'sender_name', 'sender_bank', 'transfer_narration'])
                || ($payment->getOriginal('bank_transfer_proof_media_id') !== null && $payment->isDirty('bank_transfer_proof_media_id'))) {
                throw new LogicException('A platform payment keeps what was asked, and a settled one never changes.');
            }
        });

        self::deleting(static fn (): never => throw new LogicException('Platform payments are never deleted.'));
    }

    public function isBankTransfer(): bool
    {
        return $this->method === self::BANK_TRANSFER;
    }

    /** @return BelongsTo<PlatformInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PlatformInvoice::class, 'platform_invoice_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PlatformPaymentStatus::class,
            'amount_kobo' => MoneyCast::class,
            'paid_amount_kobo' => MoneyCast::class,
            'stated_amount_kobo' => MoneyCast::class,
            'transferred_on' => 'immutable_date',
            'reviewed_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
