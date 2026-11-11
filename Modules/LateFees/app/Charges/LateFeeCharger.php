<?php

declare(strict_types=1);

namespace Modules\LateFees\Charges;

use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Contracts\BillBalance;
use Modules\Billing\Contracts\BillLedger;
use Modules\Billing\Contracts\LateFeeBills;
use Modules\LateFees\Models\LateFee;

/**
 * Charges the current organization's late fees (PL-02, docs/plans/
 * late-fees-2026-11-11.md defaults 3–6, 9): when the agency has switched
 * them on, every rent bill that still owes money and was due at least the
 * grace days ago (Lagos) gets ONE fee bill (Billing's Contracts\
 * LateFeeBills): the rate of what is unpaid now, rounded to the kobo, at
 * most the cap; nothing when that is zero. Imported bills never.
 *
 * Each rent bill is one transaction (DB::transaction($callback, 3)): lock
 * it (BillLedger::lock), check it still owes and has no fee, issue the fee
 * bill and write the `late_fees` row, together or not at all. The row's
 * unique rent bill id makes a re-run, or two runs at once, charge nothing
 * twice (AGENT.md § 3 rule 6). Audited: `late_fee.charged` (log
 * `late_fees`), ids and amounts only.
 */
final readonly class LateFeeCharger
{
    public const string LOG = 'late_fees';

    /** Rent bills read at a time. */
    public const int PAGE = 100;

    public function __construct(
        private OrganizationContext $context,
        private LateFeeBills $bills,
        private BillLedger $ledger,
    ) {}

    /**
     * Fees charged in the current organization; stops early, returning
     * null, when $shouldStop says so (a later run carries on).
     *
     * @param  (Closure(): bool)|null  $shouldStop
     */
    public function charge(?Closure $shouldStop = null): ?int
    {
        $settings = LateFeeSettings::of($this->context->get());

        if (! $settings->enabled) {
            return 0;
        }

        $dueBy = CarbonImmutable::now('Africa/Lagos')->startOfDay()->subDays($settings->graceDays);
        $charged = 0;
        $afterId = 0;

        do {
            $page = $this->bills->rentBillsDue($dueBy, $afterId, self::PAGE);
            $done = LateFee::query()->whereIn('rent_bill_id', array_map(static fn (BillBalance $bill): int => $bill->id, $page))->pluck('rent_bill_id')->all();

            foreach ($page as $bill) {
                $afterId = $bill->id;

                if (in_array($bill->id, $done, true)) {
                    continue;
                }

                if ($shouldStop !== null && $shouldStop()) {
                    return null;
                }

                $charged += $this->chargeOne($bill->id, $settings, $dueBy) ? 1 : 0;
            }
        } while (count($page) === self::PAGE);

        return $charged;
    }

    private function chargeOne(int $rentBillId, LateFeeSettings $settings, CarbonImmutable $dueBy): bool
    {
        try {
            return DB::transaction(function () use ($rentBillId, $settings, $dueBy): bool {
                $rent = $this->ledger->lock([$rentBillId])[$rentBillId] ?? null;

                if ($rent === null || $rent->type !== 'rent' || $rent->isVoid() || ! $rent->outstanding->isPositive() || $rent->dueDate->toDateString() > $dueBy->toDateString()) {
                    return false;
                }

                if (LateFee::query()->where('rent_bill_id', $rentBillId)->exists()) {
                    return false;
                }

                $fee = $settings->feeOn($rent->outstanding);

                if (! $fee->isPositive()) {
                    return false;
                }

                $note = __(':rate of :unpaid unpaid on :number, due :date', [
                    'rate' => $settings->rateLabel(),
                    'unpaid' => $rent->outstanding->format(),
                    'number' => $rent->number,
                    'date' => $rent->dueDate->format('d/m/Y'),
                ]).($settings->cap !== null && $fee->equals($settings->cap) ? ' '.__('(capped at :cap)', ['cap' => $settings->cap->format()]) : '');

                $issued = $this->bills->issue($rentBillId, $fee, $note);

                $record = new LateFee;
                $record->rent_bill_id = $rentBillId;
                $record->fee_bill_id = $issued->id;
                $record->rate_bps = $settings->rateBps;
                $record->base_kobo = $rent->outstanding;
                $record->amount_kobo = $fee;
                $record->charged_on = CarbonImmutable::now('Africa/Lagos')->startOfDay();
                $record->save();

                activity(self::LOG)
                    ->event('late_fee.charged')
                    ->performedOn($record)
                    ->withProperties([
                        'rent_bill_id' => $rentBillId,
                        'fee_bill_id' => $issued->id,
                        'rate_bps' => $settings->rateBps,
                        'base_kobo' => $rent->outstanding->kobo,
                        'amount_kobo' => $fee->kobo,
                    ])
                    ->log('Late fee charged on '.$rent->number);

                return true;
            }, 3);
        } catch (UniqueConstraintViolationException $duplicate) {
            if (! str_contains($duplicate->getMessage(), 'late_fees_rent_bill_unique')) {
                throw $duplicate;
            }

            // Another run charged it between our check and our insert: nothing was written.
            return false;
        }
    }
}
