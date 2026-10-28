<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Statements;

use App\Documents\BrandingSnapshots;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\OwnerStatements\Enums\SettlementStatus;
use Modules\OwnerStatements\Models\OwnerStatementSettlement;
use Modules\Properties\Contracts\OwnerProfileDirectory;

/**
 * The only writer of the agency's Settled / Unsettled mark (P08-05;
 * `owner_statement_settlements`). GoProperty records the settlement only:
 * the agency pays the owner outside it (AGENT.md § 3 rule 8).
 *
 * - settle(): an ended month (Lagos), not settled now, with the date the
 *   owner was paid (not before the month began, not in the future) and a
 *   reference (1–100 characters). The statement is worked out now and kept
 *   as the month's snapshot with the branding of the day and the net due;
 *   from then on the month is shown from the snapshot only, so payments
 *   confirmed or receipts voided later count in the month they happen.
 *   `version` goes up by one: each settled statement keeps its own PDF.
 * - unsettle(): a settled month back to unsettled, with a reason (3–500
 *   characters); who, when and why are kept on the row. The month is
 *   worked out from the records again until it is settled again.
 *
 * Each checks OwnerStatementPolicy (owners and administrators,
 * `owner_statements.manage`: never while GoProperty staff impersonate,
 * nothing in a suspended organization; the owner can never mark a month),
 * locks the month's row (made on first use under the natural key), re-reads
 * it, and is audited under log `owner-statements`
 * (`owner_statement.settled`, `owner_statement.unsettled`).
 */
final readonly class Settlements
{
    public function __construct(
        private OrganizationContext $context,
        private OwnerProfileDirectory $owners,
        private StatementBuilder $builder,
        private BrandingSnapshots $brandings,
    ) {}

    /** The month's row, if it was ever marked. */
    public function of(int $ownerProfileId, StatementMonth $month): ?OwnerStatementSettlement
    {
        return OwnerStatementSettlement::query()
            ->where('owner_profile_id', $ownerProfileId)
            ->whereDate('month', $month->firstDay()->toDateString())
            ->first();
    }

    /**
     * The rows of these months, by month key.
     *
     * @param  list<StatementMonth>  $months
     * @return array<string, OwnerStatementSettlement>
     */
    public function forMonths(int $ownerProfileId, array $months): array
    {
        if ($months === []) {
            return [];
        }

        $rows = [];

        foreach (OwnerStatementSettlement::query()
            ->where('owner_profile_id', $ownerProfileId)
            ->whereIn('month', array_map(static fn (StatementMonth $month): string => $month->firstDay()->toDateString(), $months))
            ->get() as $row) {
            $rows[StatementMonth::ofDate($row->month)->key()] = $row;
        }

        return $rows;
    }

    /**
     * @param  string  $paidOn  Y-m-d
     *
     * @throws ValidationException
     */
    public function settle(User $by, int $ownerProfileId, StatementMonth $month, string $paidOn, string $reference): OwnerStatementSettlement
    {
        Gate::forUser($by)->authorize('settle', [OwnerStatementSettlement::class, $ownerProfileId]);
        abort_if($this->owners->find($ownerProfileId) === null, 404);

        if (! $month->hasEnded()) {
            throw ValidationException::withMessages(['paidOn' => __(':month has not ended yet: settle it once it is over.', ['month' => $month->label()])]);
        }

        $reference = trim((string) preg_replace('/\s+/u', ' ', $reference));
        $date = self::date($paidOn);
        $today = CarbonImmutable::parse(CarbonImmutable::now(StatementMonth::TIMEZONE)->toDateString());

        Validator::make(['paidOn' => $paidOn, 'reference' => $reference], [
            'paidOn' => ['required', static function (string $attribute, mixed $value, \Closure $fail) use ($date, $today, $month): void {
                if ($date === null) {
                    $fail(__('Choose the date the owner was paid.'));
                } elseif ($date->greaterThan($today)) {
                    $fail(__('The date paid cannot be in the future.'));
                } elseif ($date->lessThan($month->firstDay())) {
                    $fail(__('The date paid cannot be before :month.', ['month' => $month->label()]));
                }
            }],
            'reference' => ['required', 'string', 'max:100'],
        ], [
            'paidOn.required' => __('Choose the date the owner was paid.'),
            'reference.required' => __('Type the payment\'s reference: a transfer reference or cheque number.'),
        ])->validate();

        assert($date instanceof CarbonImmutable);

        return DB::transaction(function () use ($by, $ownerProfileId, $month, $date, $reference): OwnerStatementSettlement {
            $row = $this->locked($ownerProfileId, $month);

            if ($row->isSettled()) {
                throw ValidationException::withMessages(['paidOn' => __(':month is already settled.', ['month' => $month->label()])]);
            }

            $statement = $this->builder->build($ownerProfileId, [$month])[$month->key()];

            $row->status = SettlementStatus::Settled;
            $row->paid_on = $date;
            $row->reference = $reference;
            $row->figures = $statement->toArray();
            $row->net_due_kobo = $statement->netDue()->kobo;
            $row->branding_snapshot = $this->brandings->take($this->context->get())->toArray();
            $row->version = $row->version + 1;
            $row->settled_at = CarbonImmutable::now();
            $row->settled_by_user_id = $by->id;
            $row->save();

            activity(StatementTermsBook::LOG)
                ->event('owner_statement.settled')
                ->performedOn($row)
                ->causedBy($by)
                ->withProperties([
                    'owner_profile_id' => $ownerProfileId,
                    'month' => $month->key(),
                    'paid_on' => $date->toDateString(),
                    'reference' => $reference,
                    'net_due_kobo' => $row->net_due_kobo,
                    'rent_kobo' => $statement->totals->rent->kobo,
                    'utilities_kobo' => $statement->totals->utilities->kobo,
                    'commission_kobo' => $statement->totals->commission->kobo,
                    'expenses_kobo' => $statement->totals->expenses->kobo,
                    'commission_bps' => $statement->commissionBps,
                    'utilities_to_owner' => $statement->utilitiesToOwner,
                    'version' => $row->version,
                ])
                ->log('Owner statement month settled');

            return $row;
        }, 3);
    }

    /** @throws ValidationException */
    public function unsettle(User $by, int $ownerProfileId, StatementMonth $month, string $reason): OwnerStatementSettlement
    {
        Gate::forUser($by)->authorize('unsettle', [OwnerStatementSettlement::class, $ownerProfileId]);
        abort_if($this->owners->find($ownerProfileId) === null, 404);

        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:500']], [
            'reason.required' => __('Say why the month is no longer settled.'),
        ])->validate();

        return DB::transaction(function () use ($by, $ownerProfileId, $month, $reason): OwnerStatementSettlement {
            $row = $this->locked($ownerProfileId, $month);

            if (! $row->isSettled()) {
                throw ValidationException::withMessages(['reason' => __(':month is not settled.', ['month' => $month->label()])]);
            }

            $was = ['paid_on' => $row->paid_on?->toDateString(), 'reference' => $row->reference, 'net_due_kobo' => $row->net_due_kobo, 'version' => $row->version];
            $row->status = SettlementStatus::Unsettled;
            $row->unsettled_at = CarbonImmutable::now();
            $row->unsettled_by_user_id = $by->id;
            $row->unsettle_reason = $reason;
            $row->save();

            activity(StatementTermsBook::LOG)
                ->event('owner_statement.unsettled')
                ->performedOn($row)
                ->causedBy($by)
                ->withProperties(['owner_profile_id' => $ownerProfileId, 'month' => $month->key(), 'reason' => $reason, 'was' => $was])
                ->log('Owner statement month marked unsettled');

            return $row;
        }, 3);
    }

    /** The month's row, locked until the transaction ends (made, unsettled, on first use). */
    private function locked(int $ownerProfileId, StatementMonth $month): OwnerStatementSettlement
    {
        $query = static fn () => OwnerStatementSettlement::query()
            ->where('owner_profile_id', $ownerProfileId)
            ->whereDate('month', $month->firstDay()->toDateString())
            ->lockForUpdate();

        $row = $query()->first();

        if ($row instanceof OwnerStatementSettlement) {
            return $row;
        }

        try {
            DB::transaction(function () use ($ownerProfileId, $month): void {
                (new OwnerStatementSettlement)->forceFill([
                    'organization_id' => $this->context->id(),
                    'owner_profile_id' => $ownerProfileId,
                    'month' => $month->firstDay(),
                    'status' => SettlementStatus::Unsettled,
                ])->save();
            });
        } catch (UniqueConstraintViolationException) {
            // Made by another request at the same time: take that one.
        }

        return $query()->firstOrFail();
    }

    private static function date(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }
}
