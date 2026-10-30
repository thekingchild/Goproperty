<?php

declare(strict_types=1);

namespace App\Sms\Credits;

use App\Marketing\PlanCatalogue;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\SmsCreditBalance;
use App\Models\SmsCreditEntry;
use App\Notifications\SmsCreditsRunningLow;
use App\Settings\SettingsStore;
use App\Tenancy\OrganizationContext;
use App\Tenancy\OrganizationOwners;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * SMS credits (P05-03; BILLING_PAYMENTS.md § 1.2; docs/plans/P05-subtasks.md
 * "Defaults taken in P05-03"): GoProperty pays GoText and agencies pay
 * through credits, one credit per SMS page. The only writer of
 * `sms_credit_balances` and `sms_credit_entries`, always in the current
 * organization and always under the lock of its balance row, so the
 * balance never goes below zero however many SMS are sent at once, and it
 * always equals the sum of the ledger.
 *
 * - Monthly allowance (refreshAllowance): on the first credit change of a
 *   Lagos month (or the hourly GrantSmsAllowances job, or a plan chosen at
 *   signup), last month's unused allowance expires and this month's is
 *   granted: the plan's, or the custom one finance-admin set. A bigger plan
 *   or allowance mid-month adds the difference; a smaller one waits for the
 *   next month. Once per month (the lock and the entries' keys).
 * - Usage (take): before GoText is called (App\Sms\SendSms), allowance
 *   credits first. At zero nothing is taken and nothing is sent. Once per
 *   delivery, however often its job is retried.
 * - Give back (giveBack): a credit for an SMS GoText refused outright comes
 *   back as an `adjustment`; never for one whose outcome is unknown.
 * - Purchase: credits of a paid bundle (Modules\SaasBilling, once per invoice).
 * - Adjustment: GoProperty staff (finance-admin), with a reason, audited.
 *
 * Owners and administrators are told once a month when the balance falls
 * to 20 % of the month's allowance and once when it reaches zero.
 */
final readonly class SmsCredits
{
    public const string AUDIT_LOG = 'sms_credits';

    public const string TIMEZONE = 'Africa/Lagos';

    public const int LOW_PERCENT = 20;

    public const int NOTE_MAX = 300;

    public function __construct(
        private OrganizationContext $context,
        private PlanCatalogue $plans,
        private SettingsStore $settings,
        private OrganizationOwners $owners,
    ) {}

    /** The Lagos month of a moment (now by default), "2026-12". */
    public static function month(?CarbonInterface $at = null): string
    {
        return CarbonImmutable::instance($at ?? CarbonImmutable::now())->setTimezone(self::TIMEZONE)->format('Y-m');
    }

    /** The first day of the next Lagos month, when this month's allowance expires. */
    public static function nextReset(?CarbonInterface $at = null): CarbonImmutable
    {
        return CarbonImmutable::instance($at ?? CarbonImmutable::now())->setTimezone(self::TIMEZONE)->startOfMonth()->addMonthNoOverflow();
    }

    /** What the current organization gets each month: its custom allowance, else its plan's (Starter's until it chose one). */
    public function monthlyAllowance(): int
    {
        $organization = $this->context->get();
        $custom = $this->settings->get('sms_credits.monthly_allowance', $organization);

        if (is_int($custom)) {
            return max(0, $custom);
        }

        $plans = $this->plans->plans();
        // Read now: the context's copy of the organization may predate a plan just chosen.
        $planKey = Organization::query()->whereKey($organization->id)->value('signup_plan_key');

        foreach ($plans as $plan) {
            if ($plan->key === $planKey) {
                return $plan->smsCredits;
            }
        }

        return $plans === [] ? 0 : $plans[0]->smsCredits;
    }

    /** The current organization's credits now, as the settings page shows them (nothing is written). */
    public function summary(): SmsCreditSummary
    {
        $balance = SmsCreditBalance::query()->first();
        $month = self::month();
        $current = $balance instanceof SmsCreditBalance && $balance->allowance_month === $month;
        $allowanceLeft = $current ? $balance->allowance_credits : 0;
        $purchased = $balance instanceof SmsCreditBalance ? $balance->purchased_credits : 0;

        return new SmsCreditSummary(
            month: $month,
            balance: $allowanceLeft + $purchased,
            monthlyAllowance: $this->monthlyAllowance(),
            allowanceGranted: $current ? $balance->allowance_granted : 0,
            allowanceLeft: $allowanceLeft,
            purchased: $purchased,
        );
    }

    /**
     * Makes sure the current organization's balance row exists. Called
     * before a transaction that will lock it: creating the row inside one
     * that two senders run at once could deadlock.
     */
    public function ensureBalance(): void
    {
        if (! SmsCreditBalance::query()->exists()) {
            SmsCreditBalance::query()->insertOrIgnore(['organization_id' => $this->context->id(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Grants this month's allowance if it is not yet (and expires last month's). Safe to run again. */
    public function refreshAllowance(): void
    {
        $this->ensureBalance();

        DB::transaction(function (): void {
            $this->refreshed($this->locked());
        }, 3);
    }

    /**
     * Takes the credits for an SMS before it is sent: true when taken now or
     * already taken for this delivery, false when there are not enough (the
     * SMS must not be sent). Runs inside the caller's transaction (the
     * delivery's claim) when there is one.
     */
    public function take(NotificationDelivery $delivery, int $credits = 1): bool
    {
        if ($credits < 1) {
            throw new LogicException('An SMS takes at least one credit.');
        }

        $this->ensureBalance();

        return DB::transaction(function () use ($delivery, $credits): bool {
            $balance = $this->locked();

            if ($this->entryExists(self::usageKey($delivery, CreditPool::Allowance)) || $this->entryExists(self::usageKey($delivery, CreditPool::Purchased))) {
                return true;
            }

            $this->refreshed($balance);

            if ($balance->total() < $credits) {
                return false;
            }

            $fromAllowance = min($balance->allowance_credits, $credits);
            $parts = [CreditPool::Allowance->value => $fromAllowance, CreditPool::Purchased->value => $credits - $fromAllowance];

            foreach ($parts as $pool => $taken) {
                if ($taken > 0) {
                    $this->write($balance, CreditReason::Usage, CreditPool::from($pool), -$taken, self::usageKey($delivery, CreditPool::from($pool)), [
                        'notification_delivery_id' => $delivery->id,
                    ]);
                }
            }

            $this->tellIfRunningLow($balance);

            return true;
        }, 3);
    }

    /**
     * Gives back what an SMS took when GoText refused it outright. An
     * allowance credit comes back only within the month it was taken (the
     * month's left-over has expired since). Once per delivery.
     */
    public function giveBack(NotificationDelivery $delivery): void
    {
        $this->ensureBalance();

        DB::transaction(function () use ($delivery): void {
            $balance = $this->locked();
            $this->refreshed($balance);
            $taken = SmsCreditEntry::query()
                ->where('notification_delivery_id', $delivery->id)
                ->where('reason', CreditReason::Usage)
                ->get();

            foreach ($taken as $usage) {
                $key = 'give_back:'.$delivery->id.':'.$usage->pool->value;

                if ($this->entryExists($key) || ($usage->pool === CreditPool::Allowance && $usage->month !== $balance->allowance_month)) {
                    continue;
                }

                $this->write($balance, CreditReason::Adjustment, $usage->pool, -$usage->credits, $key, [
                    'notification_delivery_id' => $delivery->id,
                    'note' => 'Given back: GoText refused the SMS, so it was not sent.',
                ]);
            }
        }, 3);
    }

    /**
     * Adds a paid bundle's credits (never expire). Once per invoice: a
     * second call returns the first entry. `$note` names the invoice in the
     * ledger ("Invoice PR-2026-000123").
     */
    public function purchase(int $credits, int $platformInvoiceId, ?string $note = null): SmsCreditEntry
    {
        if ($credits < 1) {
            throw new LogicException('A bundle adds at least one credit.');
        }

        $this->ensureBalance();

        return DB::transaction(function () use ($credits, $platformInvoiceId, $note): SmsCreditEntry {
            $balance = $this->locked();
            $key = 'purchase:'.$platformInvoiceId;
            $existing = SmsCreditEntry::query()->where('idempotency_key', $key)->first();

            if ($existing instanceof SmsCreditEntry) {
                return $existing;
            }

            $this->refreshed($balance);

            return $this->write($balance, CreditReason::Purchase, CreditPool::Purchased, $credits, $key, [
                'platform_invoice_id' => $platformInvoiceId,
                'note' => $note !== null ? mb_substr($note, 0, self::NOTE_MAX) : null,
            ]);
        }, 3);
    }

    /**
     * GoProperty staff add or remove credits, with a reason (audited). Added
     * credits are purchased-like (they never expire); removed ones come out
     * of the purchased credits first, then the allowance, never below zero.
     *
     * @throws SmsCreditRefused
     */
    public function adjust(PlatformStaff $staff, int $credits, string $reason): SmsCreditEntry
    {
        $reason = trim($reason);

        if ($credits === 0 || abs($credits) > 1_000_000) {
            throw new SmsCreditRefused('Give a number of credits to add (for example 200) or remove (for example -200).');
        }

        if ($reason === '' || mb_strlen($reason) > self::NOTE_MAX) {
            throw new SmsCreditRefused('Give a reason of at most '.self::NOTE_MAX.' characters: it is kept in the ledger and the audit log.');
        }

        $this->ensureBalance();

        return DB::transaction(function () use ($staff, $credits, $reason): SmsCreditEntry {
            $balance = $this->locked();
            $this->refreshed($balance);

            if ($balance->total() + $credits < 0) {
                throw new SmsCreditRefused('The organization has only '.number_format($balance->total()).' credits: an adjustment cannot take it below zero.');
            }

            $key = 'adjustment:'.Str::ulid();
            $extra = ['platform_staff_id' => $staff->id, 'note' => $reason];

            if ($credits > 0) {
                $entry = $this->write($balance, CreditReason::Adjustment, CreditPool::Purchased, $credits, $key, $extra);
            } else {
                $fromPurchased = min($balance->purchased_credits, -$credits);
                $entry = $fromPurchased > 0 ? $this->write($balance, CreditReason::Adjustment, CreditPool::Purchased, -$fromPurchased, $key, $extra) : null;
                $rest = -$credits - $fromPurchased;

                if ($rest > 0) {
                    $entry = $this->write($balance, CreditReason::Adjustment, CreditPool::Allowance, -$rest, $key.':allowance', $extra);
                }
            }

            assert($entry instanceof SmsCreditEntry);

            activity(self::AUDIT_LOG)
                ->event('sms_credits.adjusted')
                ->performedOn($this->context->get())
                ->causedBy($staff)
                ->withProperties(['credits' => $credits, 'reason' => $reason, 'balance_after' => $balance->total()])
                ->log('SMS credits adjusted');

            return $entry;
        }, 3);
    }

    /** The balance row, locked (it exists: ensureBalance()). */
    private function locked(): SmsCreditBalance
    {
        $balance = SmsCreditBalance::query()->lockForUpdate()->first();

        if (! $balance instanceof SmsCreditBalance) {
            SmsCreditBalance::query()->insertOrIgnore(['organization_id' => $this->context->id(), 'created_at' => now(), 'updated_at' => now()]);
            $balance = SmsCreditBalance::query()->lockForUpdate()->firstOrFail();
        }

        return $balance;
    }

    /** Expires last month's unused allowance and grants this month's (or the difference after a bigger one), on the locked row. */
    private function refreshed(SmsCreditBalance $balance): void
    {
        $month = self::month();

        if ($balance->allowance_month !== $month) {
            if ($balance->allowance_month !== null && $balance->allowance_credits > 0) {
                $this->write($balance, CreditReason::Expiry, CreditPool::Allowance, -$balance->allowance_credits, 'expiry:'.$balance->allowance_month, [
                    'month' => $balance->allowance_month,
                ]);
            }

            $balance->allowance_month = $month;
            $balance->allowance_granted = 0;
            $balance->save();
        }

        $allowance = $this->monthlyAllowance();

        if ($balance->allowance_granted < $allowance) {
            $this->write($balance, CreditReason::MonthlyAllowance, CreditPool::Allowance, $allowance - $balance->allowance_granted, 'allowance:'.$month.':'.$allowance);
            $balance->allowance_granted = $allowance;
            $balance->save();
        }
    }

    /**
     * Appends an entry and moves the balance with it.
     *
     * @param  array<string, mixed>  $extra
     */
    private function write(SmsCreditBalance $balance, CreditReason $reason, CreditPool $pool, int $credits, string $key, array $extra = []): SmsCreditEntry
    {
        if ($pool === CreditPool::Allowance) {
            $balance->allowance_credits += $credits;
        } else {
            $balance->purchased_credits += $credits;
        }

        if ($balance->allowance_credits < 0 || $balance->purchased_credits < 0) {
            throw new LogicException('SMS credits never go below zero.');
        }

        $balance->save();

        $entry = new SmsCreditEntry;
        $entry->forceFill([
            'reason' => $reason,
            'pool' => $pool,
            'credits' => $credits,
            'balance_after' => $balance->total(),
            'month' => self::month(),
            'idempotency_key' => $key,
            ...$extra,
        ])->save();

        return $entry;
    }

    private function entryExists(string $key): bool
    {
        return SmsCreditEntry::query()->where('idempotency_key', $key)->exists();
    }

    private static function usageKey(NotificationDelivery $delivery, CreditPool $pool): string
    {
        return 'usage:'.$delivery->id.':'.$pool->value;
    }

    /** Once a month per threshold, after the commit: at 20 % of the month's allowance, and at zero. */
    private function tellIfRunningLow(SmsCreditBalance $balance): void
    {
        $month = (string) $balance->allowance_month;
        $total = $balance->total();
        $empty = $total === 0;
        $low = $balance->allowance_granted > 0 && $total * 100 <= $balance->allowance_granted * self::LOW_PERCENT;

        if ($empty && $balance->empty_notified_month !== $month) {
            $balance->empty_notified_month = $month;
            // Straight to zero: the 20 % notice would only repeat it.
            $balance->low_notified_month = $month;
        } elseif ($low && ! $empty && $balance->low_notified_month !== $month) {
            $balance->low_notified_month = $month;
        } else {
            return;
        }

        $balance->save();
        $organization = $this->context->get();
        $notice = new SmsCreditsRunningLow($organization->id, $empty, $total, $balance->allowance_granted);

        foreach ($this->owners->ownersAndAdministrators($organization) as $user) {
            $user->notify($notice);
        }
    }
}
