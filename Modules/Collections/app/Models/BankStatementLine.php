<?php

declare(strict_types=1);

namespace Modules\Collections\Models;

use App\Casts\MoneyCast;
use App\Models\OrganizationBankAccount;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Collections\Enums\LineDirection;
use Modules\Collections\Enums\MatchConfidence;
use Modules\Collections\Enums\MatchStatus;

/**
 * One transaction of an uploaded bank statement (DATABASE_SCHEMA.md § 4
 * `bank_statement_lines`), kept once however many statements show it
 * (`line_hash`, unique per organization; Statements\LineHash).
 * Organization-scoped; written by Statements\StatementReader (and the
 * matching of P03-07b).
 *
 * Only credits are candidates for matching (offeredForMatching()); a debit
 * is money leaving the account, never a tenant's payment, so it is
 * `ignored` from the start and the model refuses any other match state
 * for it, whoever writes it. Lines are the organization's history: never
 * deleted (the model refuses).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $bank_statement_import_id the statement that first brought the line in
 * @property int $organization_bank_account_id
 * @property int $row_number the row in that statement's file
 * @property CarbonImmutable $transaction_date
 * @property LineDirection $direction
 * @property Money $amount_kobo always positive; `direction` says which way
 * @property string $narration
 * @property string|null $counterparty_name
 * @property string|null $reference the bank's own reference, when the statement has one
 * @property Money|null $balance_kobo the running balance printed on the statement
 * @property string $line_hash
 * @property int $occurrence the line's number among identical lines of its statement
 * @property MatchStatus $match_status
 * @property MatchConfidence|null $match_confidence
 * @property int|null $suggested_bill_id
 * @property int|null $payment_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class BankStatementLine extends Model
{
    use BelongsToOrganization;

    /** @var array<string, mixed> */
    protected $attributes = ['match_status' => 'unmatched', 'occurrence' => 1, 'narration' => ''];

    protected static function booted(): void
    {
        self::saving(static function (BankStatementLine $line): void {
            if ($line->direction === LineDirection::Debit && $line->match_status !== MatchStatus::Ignored) {
                throw new LogicException('A debit line is money leaving the account: it is never offered for matching.');
            }
        });

        self::deleting(static function (): void {
            throw new LogicException('Bank statement lines are the organization\'s history: never deleted.');
        });
    }

    /**
     * The lines matching may work on (P03-07b): credits not matched yet.
     * Debits never are.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfferedForMatching(Builder $query): Builder
    {
        return $query->where('direction', LineDirection::Credit->value)
            ->whereIn('match_status', [MatchStatus::Unmatched->value, MatchStatus::Suggested->value]);
    }

    /** Whether matching may work on this line. */
    public function isOfferedForMatching(): bool
    {
        return $this->direction === LineDirection::Credit && $this->match_status->isOpen();
    }

    /** @return BelongsTo<BankStatementImport, $this> */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'bank_statement_import_id');
    }

    /** @return BelongsTo<OrganizationBankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(OrganizationBankAccount::class, 'organization_bank_account_id')->withTrashed();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'transaction_date' => 'immutable_date',
            'direction' => LineDirection::class,
            'amount_kobo' => MoneyCast::class,
            'balance_kobo' => MoneyCast::class,
            'match_status' => MatchStatus::class,
            'match_confidence' => MatchConfidence::class,
            'row_number' => 'integer',
            'occurrence' => 'integer',
        ];
    }
}
