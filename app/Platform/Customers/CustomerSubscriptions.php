<?php

declare(strict_types=1);

namespace App\Platform\Customers;

use App\Audit\AuditContext;
use App\Enums\BillingCycle;
use App\Enums\OrganizationStatus;
use App\Enums\SubscriptionStatus;
use App\Marketing\PlanCatalogue;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\Subscription;
use App\Notifications\SubscriptionCancelledNotice;
use App\Notifications\SubscriptionReactivatedNotice;
use App\Platform\Staff\StaffPermission;
use App\Subscriptions\SubscriptionChangeRefused;
use App\Subscriptions\SubscriptionService;
use App\Tenancy\OrganizationContext;
use App\Tenancy\OrganizationOwners;
use Closure;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Staff moves on a customer's GoProperty subscription (P06-07a;
 * PLATFORM_CONSOLE.md § 1B, BILLING_PAYMENTS.md § 2; docs/plans/P06-subtasks.md
 * defaults 50–52). Each re-checks the staff permission, then goes through
 * App\Subscriptions\SubscriptionService (the only writer, AGENT.md § 3
 * rule 5) inside the organization's context, with the staff member as
 * causer and the reason on the audit entry (AuditContext).
 *
 * - changePlan() (`customers.change_plan`): any plan, custom and no longer
 *   offered ones included, either billing cycle, at once at the plan's
 *   current terms; no limit check (default 8). The owners are not emailed.
 * - cancel() / reactivate() (`customers.suspend`): cancel from any status
 *   but cancelled (the organization is closed, data kept); reactivate only
 *   a cancelled subscription (the organization reopens when the
 *   subscription closed it; SaasBilling's metering invoices the period due,
 *   from that day when months were missed). The owners with an email get a
 *   queued notice after the commit, quoting the optional note.
 */
final readonly class CustomerSubscriptions
{
    public const int REASON_MIN = 3;

    public const int REASON_MAX = 500;

    public const int NOTE_MAX = 1000;

    public function __construct(
        private OrganizationContext $context,
        private SubscriptionService $subscriptions,
        private PlanCatalogue $plans,
        private AuditContext $audit,
        private OrganizationOwners $owners,
    ) {}

    public function current(Organization $organization): ?Subscription
    {
        return $this->context->run($organization, static fn (): ?Subscription => Subscription::query()->first());
    }

    /**
     * Every plan staff may move a customer to, with today's terms: offered
     * or not, custom ones included.
     *
     * @return array<int, string> plan id => name
     */
    public function planOptions(): array
    {
        $options = [];

        foreach ($this->plans->all() as $plan) {
            $options[$plan->id] = $plan->name.match (true) {
                $plan->custom && ! $plan->offered => ' (custom, not offered)',
                $plan->custom => ' (custom)',
                ! $plan->offered => ' (not offered)',
                default => '',
            };
        }

        return $options;
    }

    /** @throws CustomerChangeRefused */
    public function changePlan(PlatformStaff $staff, Organization $organization, int $planId, BillingCycle $cycle, string $reason): Subscription
    {
        $this->authorize($staff, StaffPermission::CustomersChangePlan, 'You do not have permission to change a customer\'s plan.');
        $reason = $this->reason($reason);
        $plan = $this->plans->findById($planId) ?? throw new CustomerChangeRefused('That plan does not exist or has no terms in effect today.');

        return $this->inOrganization($organization, $reason, fn (): Subscription => $this->subscriptions->changePlan($staff, $plan, $cycle));
    }

    /** @throws CustomerChangeRefused */
    public function cancel(PlatformStaff $staff, Organization $organization, string $reason, ?string $noteToOwner = null): Subscription
    {
        $this->authorize($staff, StaffPermission::CustomersSuspend, 'You do not have permission to cancel or reactivate subscriptions.');
        $reason = $this->reason($reason);
        $noteToOwner = $this->note($noteToOwner);

        return DB::transaction(function () use ($staff, $organization, $reason, $noteToOwner): Subscription {
            $subscription = $this->inOrganization($organization, $reason, function () use ($staff): Subscription {
                if (Subscription::query()->first()?->status === SubscriptionStatus::Cancelled) {
                    throw new SubscriptionChangeRefused('The subscription is already cancelled.');
                }

                return $this->subscriptions->cancel($staff, 'Subscription cancelled by GoProperty staff');
            });

            $this->notifyOwners($organization, new SubscriptionCancelledNotice($organization->name, $organization->slug, $noteToOwner));

            return $subscription;
        }, 3);
    }

    /** @throws CustomerChangeRefused */
    public function reactivate(PlatformStaff $staff, Organization $organization, string $reason, ?string $noteToOwner = null): Subscription
    {
        $this->authorize($staff, StaffPermission::CustomersSuspend, 'You do not have permission to cancel or reactivate subscriptions.');
        $reason = $this->reason($reason);
        $noteToOwner = $this->note($noteToOwner);

        return DB::transaction(function () use ($staff, $organization, $reason, $noteToOwner): Subscription {
            $closedBefore = $this->status($organization)->blocksWrites();
            $subscription = $this->inOrganization($organization, $reason, function () use ($staff): Subscription {
                $current = Subscription::query()->first();

                if ($current !== null && $current->status !== SubscriptionStatus::Cancelled) {
                    throw new SubscriptionChangeRefused('Only a cancelled subscription is reactivated here: the subscription is '.mb_strtolower($current->status->label()).'.');
                }

                return $this->subscriptions->activate($staff, 'Subscription reactivated by GoProperty staff');
            });
            $reopened = $closedBefore && ! $this->status($organization)->blocksWrites();

            $this->notifyOwners($organization, new SubscriptionReactivatedNotice($organization->name, $organization->slug, $reopened, $noteToOwner));

            return $subscription;
        }, 3);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $change
     * @return T
     *
     * @throws CustomerChangeRefused
     */
    private function inOrganization(Organization $organization, string $reason, Closure $change): mixed
    {
        try {
            return $this->audit->because($reason, fn (): mixed => $this->context->run($organization, $change));
        } catch (SubscriptionChangeRefused $refused) {
            throw new CustomerChangeRefused($refused->getMessage());
        }
    }

    /** @throws CustomerChangeRefused */
    private function authorize(PlatformStaff $staff, StaffPermission $permission, string $message): void
    {
        if (! $staff->holdsStaffPermission(StaffPermission::CustomersView) || ! $staff->holdsStaffPermission($permission)) {
            throw new CustomerChangeRefused($message);
        }
    }

    /** @throws CustomerChangeRefused */
    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::REASON_MIN) {
            throw new CustomerChangeRefused('Give a reason of at least '.self::REASON_MIN.' characters: it is kept in the audit log.');
        }

        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new CustomerChangeRefused('The reason is longer than '.self::REASON_MAX.' characters.');
        }

        return $reason;
    }

    /** @throws CustomerChangeRefused */
    private function note(?string $note): ?string
    {
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX) {
            throw new CustomerChangeRefused('The note is longer than '.self::NOTE_MAX.' characters.');
        }

        return $note;
    }

    private function status(Organization $organization): OrganizationStatus
    {
        return Organization::query()->whereKey($organization->getKey())->firstOrFail()->status;
    }

    private function notifyOwners(Organization $organization, Notification $notice): void
    {
        foreach ($this->owners->withEmail($organization) as $owner) {
            $owner->notify($notice);
        }
    }
}
