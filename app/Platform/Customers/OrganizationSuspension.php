<?php

declare(strict_types=1);

namespace App\Platform\Customers;

use App\Audit\AuditContext;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Notifications\OrganizationReactivatedNotice;
use App\Notifications\OrganizationSuspendedNotice;
use App\Platform\Staff\StaffPermission;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Staff suspend and reactivate organizations (PLATFORM_CONSOLE.md § 1:
 * `customers.suspend`, held by `operations-manager` and `super-admin`;
 * ONBOARDING_COMPLIANCE.md § 5.5: reason required, audited, org-owner
 * notified). While suspended the organization's members can read and
 * export but not write (App\Tenancy\OrganizationWriteGuard), from their
 * next request. Reactivation returns to the status the organization was
 * suspended from. Nothing is deleted.
 *
 * Each change is one audit entry (log `customers`) with the staff member as
 * causer, the reason, and the status before and after. The org-owners are
 * emailed through the `notifications` queue once the change is committed.
 */
final class OrganizationSuspension
{
    public const string LOG = 'customers';

    public const int REASON_MAX = 500;

    public const int NOTE_MAX = 1000;

    public function __construct(private readonly AuditContext $audit) {}

    /** @throws CustomerChangeRefused */
    public function suspend(PlatformStaff $actor, Organization $organization, string $reason, ?string $noteToOwner = null): Organization
    {
        [$reason, $noteToOwner] = $this->checked($actor, $reason, $noteToOwner);

        return DB::transaction(function () use ($actor, $organization, $reason, $noteToOwner): Organization {
            $locked = $this->lock($organization);
            $from = $locked->status;

            if (! $from->canBeSuspended()) {
                throw new CustomerChangeRefused("{$locked->name} is {$from->value}, so it cannot be suspended.");
            }

            $locked->forceFill([
                'status' => OrganizationStatus::Suspended,
                'suspended_at' => now(),
                'status_before_suspension' => $from,
            ]);
            $this->saveQuietly($locked);

            $this->record('organization.suspended', $locked, $actor, $reason, $from, $noteToOwner, "Suspended {$locked->name}");
            $this->notifyOwners($locked, new OrganizationSuspendedNotice($locked->name, $locked->slug, $noteToOwner));

            return $locked;
        });
    }

    /** @throws CustomerChangeRefused */
    public function reactivate(PlatformStaff $actor, Organization $organization, string $reason, ?string $noteToOwner = null): Organization
    {
        [$reason, $noteToOwner] = $this->checked($actor, $reason, $noteToOwner);

        return DB::transaction(function () use ($actor, $organization, $reason, $noteToOwner): Organization {
            $locked = $this->lock($organization);

            if ($locked->status !== OrganizationStatus::Suspended) {
                throw new CustomerChangeRefused("{$locked->name} is not suspended.");
            }

            $to = $locked->status_before_suspension;
            $to = $to !== null && $to->canBeSuspended() ? $to : OrganizationStatus::Active;

            $locked->forceFill([
                'status' => $to,
                'suspended_at' => null,
                'status_before_suspension' => null,
            ]);
            $this->saveQuietly($locked);

            $this->record('organization.reactivated', $locked, $actor, $reason, OrganizationStatus::Suspended, $noteToOwner, "Reactivated {$locked->name}");
            $this->notifyOwners($locked, new OrganizationReactivatedNotice($locked->name, $locked->slug, $noteToOwner));

            return $locked;
        });
    }

    /**
     * The organization's owners with an email address (phone-only accounts
     * cannot be emailed).
     *
     * @return Collection<int, User>
     */
    public function owners(Organization $organization): Collection
    {
        $owners = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', OrganizationRole::Owner->value)
            ->whereNull('roles.organization_id')
            ->where('model_has_roles.organization_id', $organization->getKey())
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->select('model_has_roles.model_id');

        $members = DB::table('organization_user')
            ->where('organization_id', $organization->getKey())
            ->select('user_id');

        return User::query()
            ->whereIn('id', $owners)
            ->whereIn('id', $members)
            ->whereNotNull('email')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{string, string|null}
     *
     * @throws CustomerChangeRefused
     */
    private function checked(PlatformStaff $actor, string $reason, ?string $noteToOwner): array
    {
        if (! $actor->holdsStaffPermission(StaffPermission::CustomersSuspend)) {
            throw new CustomerChangeRefused('You do not have permission to suspend or reactivate organizations.');
        }

        $reason = trim($reason);
        $noteToOwner = $noteToOwner !== null && trim($noteToOwner) !== '' ? trim($noteToOwner) : null;

        if ($reason === '') {
            throw new CustomerChangeRefused('Give a reason: it is kept in the audit log.');
        }

        if (mb_strlen($reason) > self::REASON_MAX || ($noteToOwner !== null && mb_strlen($noteToOwner) > self::NOTE_MAX)) {
            throw new CustomerChangeRefused('The reason or the note is too long.');
        }

        return [$reason, $noteToOwner];
    }

    private function lock(Organization $organization): Organization
    {
        return Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
    }

    /** The audit entry below replaces the model's own change log entry. */
    private function saveQuietly(Organization $organization): void
    {
        $organization->disableLogging();

        try {
            $organization->save();
        } finally {
            $organization->enableLogging();
        }
    }

    private function record(string $event, Organization $organization, PlatformStaff $actor, string $reason, OrganizationStatus $from, ?string $noteToOwner, string $description): void
    {
        $this->audit->because($reason, function () use ($event, $organization, $actor, $from, $noteToOwner, $description): void {
            activity(self::LOG)
                ->event($event)
                ->performedOn($organization)
                ->causedBy($actor)
                ->withProperties(array_filter([
                    'old' => ['status' => $from->value],
                    'attributes' => ['status' => $organization->status->value],
                    'note_to_owner' => $noteToOwner,
                ], static fn (mixed $value): bool => $value !== null))
                ->log($description);
        });
    }

    private function notifyOwners(Organization $organization, Notification $notice): void
    {
        foreach ($this->owners($organization) as $owner) {
            $owner->notify($notice);
        }
    }
}
