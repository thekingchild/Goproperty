<?php

declare(strict_types=1);

namespace Modules\Billing\Bills;

use App\Models\User;
use App\Settings\SettingsStore;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Events\BillOverdue;
use Modules\Billing\Events\BillVoided;
use Modules\Billing\Models\Bill;
use Modules\Leasing\Contracts\CycleCalculator;

/**
 * Changes to issued bills (AGENT.md § 3 rule 5): voiding one with a reason
 * and flagging bills overdue. Bills are created by Bills\BillIssuer and
 * paid through Bills\Ledger (Contracts\BillLedger); nothing else writes
 * them. Every step locks the bill's row inside DB::transaction($callback,
 * 3), so it cannot cross a payment being applied.
 *
 * - void(): the member must pass BillPolicy::void (bills.manage, not while
 *   suspended, never while staff impersonate); the reason is required; a
 *   void bill, or one with payments applied, is refused (BillRefused) and
 *   nothing changes. Audited (log `bills`, `bill.voided` with the reason)
 *   and announced (BillVoided). A void bill accepts nothing afterwards.
 * - flagOverdue(): bills still unpaid or partly paid after their due date
 *   (Lagos) become overdue (bills:flag-overdue, daily), each announced once
 *   (BillOverdue: the reminder ladder, P05).
 *
 * Also holds the organization setting `billing.lead_days`: how many days
 * before a period starts its bill is generated (ARCHITECTURE.md § 6.4).
 */
final readonly class BillService
{
    public const string LEAD_DAYS_SETTING = 'billing.lead_days';

    public const int MAX_LEAD_DAYS = 90;

    public function __construct(private SettingsStore $settings) {}

    /**
     * Void the bill, with the reason it is wrong (a wrong amount, a lease
     * entered by mistake).
     *
     * @throws ValidationException
     */
    public function void(User $by, Bill $bill, string $reason): Bill
    {
        Gate::forUser($by)->authorize('void', $bill);

        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:500']], [
            'reason.required' => __('Say why the bill is void.'),
        ])->validate();

        return $this->transaction(function () use ($by, $bill, $reason): Bill {
            $bill = Bill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();

            if ($bill->status === BillStatus::Void) {
                throw BillRefused::because(__('This bill is already void.'));
            }

            if ($bill->paid_kobo->isPositive()) {
                throw BillRefused::because(__('Payments of :amount are applied to this bill. Reverse them before voiding it.', ['amount' => $bill->paid_kobo->format()]));
            }

            $previous = $bill->status;
            $bill->status = BillStatus::Void;
            $bill->voided_at = CarbonImmutable::now();
            $bill->voided_by_user_id = $by->id;
            $bill->void_reason = $reason;
            $bill->save();

            activity(BillIssuer::LOG)
                ->event('bill.voided')
                ->performedOn($bill)
                ->causedBy($by)
                ->withProperties([
                    'number' => $bill->number,
                    'amount_kobo' => $bill->amount_kobo->kobo,
                    'previous_status' => $previous->value,
                    'reason' => $reason,
                ])
                ->log('Bill voided');

            event(new BillVoided(...self::eventIds($bill)));

            return $bill;
        });
    }

    /**
     * Flag the current organization's bills whose due date has passed and
     * that are still unpaid or partly paid; stops early (false) when
     * $shouldStop says so. Each bill is locked and checked again, so one
     * paid meanwhile is left alone, and a re-run flags nothing twice.
     *
     * @param  (Closure(): bool)|null  $shouldStop
     * @return int|false how many bills were flagged, false when stopped before the end
     */
    public function flagOverdue(?Closure $shouldStop = null): int|false
    {
        $today = BillDates::today()->toDateString();
        $flagged = 0;
        $afterId = 0;

        do {
            $ids = Bill::query()
                ->whereIn('status', BillStatus::flaggable())
                ->where('due_date', '<', $today)
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit(200)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            foreach ($ids as $id) {
                if ($shouldStop !== null && $shouldStop()) {
                    return false;
                }

                $afterId = $id;
                $flagged += $this->transaction(function () use ($id, $today): int {
                    $bill = Bill::query()->whereKey($id)->lockForUpdate()->first();

                    if (! $bill instanceof Bill || ! in_array($bill->status, BillStatus::flaggable(), true) || $bill->due_date->toDateString() >= $today) {
                        return 0;
                    }

                    $bill->status = BillStatus::Overdue;
                    $bill->save();
                    event(new BillOverdue(...self::eventIds($bill)));

                    return 1;
                });
            }
        } while (count($ids) === 200);

        return $flagged;
    }

    /** How many days before a period starts its bill is generated, in the current organization. */
    public function leadDays(): int
    {
        $days = $this->settings->get(self::LEAD_DAYS_SETTING);

        return is_int($days) && $days >= 0 ? min($days, self::MAX_LEAD_DAYS) : CycleCalculator::DEFAULT_LEAD_DAYS;
    }

    /**
     * The values every bill event carries.
     *
     * @return array{billId: int, organizationId: int, tenantProfileId: int, leaseId: int|null, type: string, number: string, amountKobo: int}
     */
    public static function eventIds(Bill $bill): array
    {
        return [
            'billId' => $bill->id,
            'organizationId' => $bill->organization_id,
            'tenantProfileId' => $bill->tenant_profile_id,
            'leaseId' => $bill->lease_id,
            'type' => $bill->type->value,
            'number' => $bill->number,
            'amountKobo' => $bill->amount_kobo->kobo,
        ];
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function transaction(Closure $callback): mixed
    {
        return DB::transaction($callback, 3);
    }
}
