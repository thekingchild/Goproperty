<?php

declare(strict_types=1);

namespace App\Platform\Plans;

use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\PlanChangeNotice;
use App\Models\PlanVersion;
use App\Notifications\PlanTermsChanging;
use App\Tenancy\OrganizationContext;
use App\Tenancy\OrganizationOwners;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tells every organization on a plan about its next version (P06-01a;
 * BILLING_PAYMENTS.md § 1.3; docs/plans/P06-subtasks.md default 5): its
 * owners and administrators get PlanTermsChanging in the app and by email.
 * Queued by PlanEditor::schedule() on `notifications`.
 *
 * Safe to run again (AGENT.md § 3 rule 6): each organization's
 * `plan_change_notices` row is written before its notifications are
 * queued, in one transaction, so an organization is told once however
 * often the job runs. Works in chunks of BUDGET_SECONDS and queues itself
 * again from where it stopped. A cancelled version tells nobody more.
 */
final class SendPlanChangeNotices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int BUDGET_SECONDS = 40;

    public int $timeout = 50;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly int $planVersionId, public readonly int $fromOrganizationId = 0)
    {
        $this->onQueue('notifications');
    }

    public function handle(OrganizationContext $context, OrganizationOwners $owners): void
    {
        $version = PlanVersion::query()->find($this->planVersionId);

        if (! $version instanceof PlanVersion || $version->cancelled_at !== null) {
            return;
        }

        $started = microtime(true);
        $resumeFrom = null;

        $organizations = Organization::query()
            ->where('id', '>=', $this->fromOrganizationId)
            ->whereExists(static fn (Builder $query) => $query->selectRaw('1')->from('subscriptions')
                ->whereColumn('subscriptions.organization_id', 'organizations.id')
                ->where('subscriptions.plan_id', $version->plan_id)
                ->where('subscriptions.status', '!=', SubscriptionStatus::Cancelled->value));

        foreach ($organizations->lazyById(100) as $organization) {
            if (microtime(true) - $started > self::BUDGET_SECONDS) {
                $resumeFrom = $organization->id;

                break;
            }

            try {
                $context->run($organization, fn (Organization $organization) => $this->tell($organization, $version, $owners));
            } catch (Throwable $failure) {
                report($failure);
            }
        }

        if ($resumeFrom !== null) {
            self::dispatch($this->planVersionId, $resumeFrom);
        }
    }

    private function tell(Organization $organization, PlanVersion $version, OrganizationOwners $owners): void
    {
        DB::transaction(function () use ($organization, $version, $owners): void {
            $recipients = $owners->ownersAndAdministrators($organization);

            $written = PlanChangeNotice::query()->insertOrIgnore([
                'plan_version_id' => $version->id,
                'organization_id' => $organization->id,
                'recipients' => $recipients->count(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($written === 0) {
                return;
            }

            foreach ($recipients as $recipient) {
                $recipient->notify(new PlanTermsChanging($organization->id, $version->id));
            }
        });
    }
}
