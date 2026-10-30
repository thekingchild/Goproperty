<?php

declare(strict_types=1);

namespace Modules\Reminders\Reminders;

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Members\Members;
use App\Models\User;
use App\Notifications\Preferences\NotificationPreferences;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Reminders\Enums\ReminderChannel;
use Modules\Tenants\Contracts\TenantDirectory;
use Modules\Tenants\Contracts\TenantReach;

/**
 * Who hears a reminder (docs/plans/P05-subtasks.md defaults 8–10). Runs in
 * the reminder's organization (the current context).
 *
 * - **Tenants** (bills): a tenant who may be contacted (Tenants'
 *   TenantReach: not archived; an imported one only once invited or with
 *   a portal account), by email at their portal account's address, else
 *   the tenant record's, in the app when they have a portal account, and
 *   by SMS (P05-02) when that account's phone number is verified (opened
 *   a link sent to it by WhatsApp, or confirmed by the agency in person;
 *   ARCHITECTURE.md § 6.7: unverified numbers are never texted). A tenant
 *   with no account is never texted. Channels the account turned off for
 *   reminders (App\Notifications\Preferences) are left out.
 * - **Staff** (renewal alerts): the agents assigned to the lease's building
 *   who hold `leases.manage`; when it has none, the organization's owners
 *   and administrators who hold it. In the app and by email.
 */
final readonly class ReminderRecipients
{
    public function __construct(
        private TenantDirectory $tenants,
        private TenantReach $reach,
        private Members $members,
        private BuildingAccess $access,
        private NotificationPreferences $preferences,
    ) {}

    /**
     * The tenants among these who can hear a reminder, by id.
     *
     * @param  list<int>  $tenantIds
     * @return array<int, Recipient>
     */
    public function tenants(array $tenantIds): array
    {
        $contactable = $this->reach->contactable($tenantIds);

        if ($contactable === []) {
            return [];
        }

        $summaries = $this->tenants->findMany($contactable);
        $userIds = array_values(array_filter(array_map(static fn ($tenant): ?int => $tenant->userId, $summaries)));
        $users = $userIds === [] ? collect() : User::query()->whereKey($userIds)->get()->keyBy('id');
        $recipients = [];

        foreach ($summaries as $id => $tenant) {
            if ($tenant->archived) {
                continue;
            }

            $user = $tenant->userId !== null ? $users->get($tenant->userId) : null;
            $user = $user instanceof User ? $user : null;
            $recipient = new Recipient(
                key: $user !== null ? 'user:'.$user->id : 'tenant:'.$tenant->id,
                name: $tenant->name,
                userId: $user?->id,
                tenantProfileId: $tenant->id,
                email: self::address($user?->email) ?? self::address($tenant->email),
                phone: $user !== null && $user->hasVerifiedPhone() ? $user->phone : null,
                off: $this->off($user),
            );

            if ($recipient->channels() !== []) {
                $recipients[$id] = $recipient;
            }
        }

        return $recipients;
    }

    /**
     * The members told that a lease of this building ends soon.
     *
     * @return list<Recipient>
     */
    public function staffFor(int $propertyId): array
    {
        $holders = $this->members->holdersOf(OrganizationPermission::ManageLeases);

        if ($holders === []) {
            return [];
        }

        $users = User::query()->whereKey($holders)->orderBy('id')->get();
        $assigned = $users->filter(fn (User $user): bool => in_array($propertyId, $this->access->limitedTo($user) ?? [], true));
        $chosen = $assigned->isNotEmpty()
            ? $assigned
            : $users->filter(static fn (User $user): bool => $user->hasAnyRole(OrganizationRole::Owner->value, OrganizationRole::Admin->value));

        return array_values($chosen->map(fn (User $user): Recipient => new Recipient(
            key: 'user:'.$user->id,
            name: (string) $user->name,
            userId: $user->id,
            tenantProfileId: null,
            email: self::address($user->email),
            off: $this->off($user),
        ))->all());
    }

    /**
     * The reminder channels the account turned off.
     *
     * @return list<ReminderChannel>
     */
    public function off(?User $user): array
    {
        return array_values(array_filter([
            $this->preferences->allows($user, NotificationPreferences::REMINDERS, 'email') ? null : ReminderChannel::Email,
            $this->preferences->allows($user, NotificationPreferences::REMINDERS, 'sms') ? null : ReminderChannel::Sms,
        ]));
    }

    private static function address(?string $email): ?string
    {
        $email = trim((string) $email);

        return $email === '' ? null : $email;
    }
}
