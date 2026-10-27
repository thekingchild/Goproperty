<?php

declare(strict_types=1);

namespace Modules\Billing\Jobs;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Modules\Billing\Bills\RentBillGenerator;
use Throwable;

/**
 * `bill:generate` (ARCHITECTURE.md § 6.4, § 7: daily 00:10, `billing`
 * queue): rent bills from leases (Bills\RentBillGenerator; utility bills
 * plug in here with P03-06b), organization by organization, each inside
 * its own context (OrganizationContext::run, no scope removed).
 *
 * Every organization except cancelled ones is billed: a suspended
 * organization's members cannot change anything, but its leases still run
 * and its tenants keep their bills (the tenant portal keeps working while
 * an organization is suspended, ONBOARDING_COMPLIANCE.md § 3); a cancelled
 * one bills nothing more.
 *
 * Safe to run again and at the same time (AGENT.md § 3 rule 6): queued at
 * most once (ShouldBeUniqueUntilProcessing), run one at a time (a cache
 * lock while it works; a second copy that finds it taken leaves the work
 * to the first), and each period billed in a transaction keyed by
 * `(lease_id, type, period_start)`, unique in the database. Stops after
 * BUDGET_SECONDS and queues itself again from the organization it was
 * working on, so a shared host's worker never runs it past ~50 s
 * (AGENT.md § 4); what it did is never done again.
 */
final class GenerateBills implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int BUDGET_SECONDS = 40;

    public const string LOCK = 'billing:generate-bills';

    public int $timeout = 50;

    public int $tries = 3;

    public int $backoff = 60;

    /** How long a queued copy keeps others from being queued. */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $fromOrganizationId = 0)
    {
        $this->onQueue('billing');
    }

    public function handle(OrganizationContext $context, RentBillGenerator $rent): void
    {
        // Longer than a run can last ($timeout), so it never lapses mid-run.
        $lock = Cache::lock(self::LOCK, $this->timeout + 30);

        if (! $lock->get()) {
            return;
        }

        $started = microtime(true);
        $stop = static fn (): bool => microtime(true) - $started > self::BUDGET_SECONDS;
        $resumeFrom = null;

        try {
            $organizations = Organization::query()
                ->where('id', '>=', $this->fromOrganizationId)
                ->where('status', '!=', OrganizationStatus::Cancelled);

            foreach ($organizations->lazyById(100) as $organization) {
                try {
                    $finished = $context->run($organization, static fn (): bool => $rent->generate($stop));
                } catch (Throwable $failure) {
                    // One organization's failure (its branding, say) must not stop the others' bills.
                    report($failure);
                    $finished = true;
                }

                if (! $finished) {
                    $resumeFrom = $organization->id;

                    break;
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
        return 'generate-bills';
    }
}
