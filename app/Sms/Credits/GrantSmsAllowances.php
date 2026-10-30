<?php

declare(strict_types=1);

namespace App\Sms\Credits;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\SmsCreditBalance;
use App\Tenancy\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * `sms-credits-allowance` (P05-03; hourly at :05 on `billing`): grants each
 * organization this Lagos month's SMS allowance and expires last month's
 * unused one (SmsCredits::refreshAllowance), every organization except
 * cancelled ones. Due on the 1st just after midnight Lagos; running every
 * hour means a missed run (the cron was down) is made up within the hour,
 * and an organization whose balance is already this month's is skipped
 * at once. Sending an SMS refreshes the allowance too, so none is ever sent
 * against last month's.
 *
 * Safe to run again and at the same time (AGENT.md § 3 rule 6): queued at
 * most once, run one at a time (a cache lock), and each grant written once
 * (the balance row's lock and the entry's key). Stops after BUDGET_SECONDS
 * and queues itself again from the organization it was on (AGENT.md § 4).
 * A failure in one organization is reported and does not stop the others.
 */
final class GrantSmsAllowances implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int BUDGET_SECONDS = 40;

    public const string LOCK = 'sms-credits:allowance';

    public int $timeout = 50;

    public int $tries = 3;

    public int $backoff = 60;

    public int $uniqueFor = 3000;

    public function __construct(public readonly int $fromOrganizationId = 0)
    {
        $this->onQueue('billing');
    }

    public function handle(OrganizationContext $context, SmsCredits $credits): void
    {
        $lock = Cache::lock(self::LOCK, $this->timeout + 30);

        if (! $lock->get()) {
            return;
        }

        $started = microtime(true);
        $month = SmsCredits::month();
        $resumeFrom = null;

        try {
            $organizations = Organization::query()
                ->where('id', '>=', $this->fromOrganizationId)
                ->where('status', '!=', OrganizationStatus::Cancelled);

            foreach ($organizations->lazyById(100) as $organization) {
                if (microtime(true) - $started > self::BUDGET_SECONDS) {
                    $resumeFrom = $organization->id;

                    break;
                }

                try {
                    $context->run($organization, static function () use ($credits, $month): void {
                        $balance = SmsCreditBalance::query()->first();

                        if ($balance?->allowance_month !== $month || $balance->allowance_granted < $credits->monthlyAllowance()) {
                            $credits->refreshAllowance();
                        }
                    });
                } catch (Throwable $failure) {
                    report($failure);
                }
            }
        } finally {
            $lock->release();
        }

        if ($resumeFrom !== null) {
            self::dispatch($resumeFrom);
        }
    }

    public function uniqueId(): string
    {
        return 'grant-sms-allowances';
    }
}
