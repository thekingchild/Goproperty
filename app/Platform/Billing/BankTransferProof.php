<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Platform\Customers\Customer;
use App\Policies\BankTransferProofPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The console's read model of a bank-transfer proof sent for a GoProperty
 * subscription invoice (P06-04; BILLING_PAYMENTS.md § 5): the
 * `platform_payments` rows with method `bank_transfer`, across
 * organizations, with the invoice's number, total and status and how many
 * other proofs of the organization had the same file. Read-only: proofs
 * change only through SaasBilling's Contracts\BankTransferReviews
 * (App\Platform\Billing\BankTransferReview), never here.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $platform_invoice_id
 * @property int $amount_kobo the invoice's total, asked
 * @property string $status pending, confirmed, rejected
 * @property int|null $bank_transfer_proof_media_id
 * @property int|null $stated_amount_kobo
 * @property CarbonImmutable|null $transferred_on
 * @property string|null $sender_name
 * @property string|null $sender_bank
 * @property string|null $transfer_narration
 * @property int|null $reviewed_by_staff_id
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $rejection_reason
 * @property CarbonImmutable|null $created_at
 * @property string|null $invoice_number
 * @property int|null $invoice_total_kobo
 * @property string|null $invoice_status
 * @property int|null $same_file_count
 * @property string|null $reviewer_name
 * @property-read Customer $customer
 */
#[UsePolicy(BankTransferProofPolicy::class)]
final class BankTransferProof extends Model
{
    public const array STATUSES = ['pending' => 'Waiting for review', 'confirmed' => 'Confirmed', 'rejected' => 'Not accepted'];

    protected $table = 'platform_payments';

    protected static function booted(): void
    {
        self::addGlobalScope('bank_transfers', static fn (Builder $query) => $query->where('platform_payments.method', 'bank_transfer'));
        self::saving(fn (): never => throw new LogicException('Bank transfers are confirmed or rejected only through SaasBilling\'s BankTransferReviews.'));
        self::deleting(fn (): never => throw new LogicException('Platform payments are never deleted.'));
    }

    /**
     * With the invoice's number, total and status, the reviewer's name and
     * the count of the organization's other proofs with the same file.
     *
     * @template TQuery of Builder<covariant Model>
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function withDetails(Builder $query): Builder
    {
        return $query->select('platform_payments.*')->addSelect([
            'invoice_number' => DB::table('platform_invoices')->select('number')->whereColumn('platform_invoices.id', 'platform_payments.platform_invoice_id'),
            'invoice_total_kobo' => DB::table('platform_invoices')->select('total_kobo')->whereColumn('platform_invoices.id', 'platform_payments.platform_invoice_id'),
            'invoice_status' => DB::table('platform_invoices')->select('status')->whereColumn('platform_invoices.id', 'platform_payments.platform_invoice_id'),
            'reviewer_name' => DB::table('platform_staff')->select('name')->whereColumn('platform_staff.id', 'platform_payments.reviewed_by_staff_id'),
            'same_file_count' => DB::table('platform_payments as others')->selectRaw('count(*)')
                ->whereColumn('others.organization_id', 'platform_payments.organization_id')
                ->whereColumn('others.proof_sha256', 'platform_payments.proof_sha256')
                ->whereColumn('others.id', '<>', 'platform_payments.id'),
        ]);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'organization_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
            'stated_amount_kobo' => 'integer',
            'invoice_total_kobo' => 'integer',
            'same_file_count' => 'integer',
            'transferred_on' => 'immutable_date',
            'reviewed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
