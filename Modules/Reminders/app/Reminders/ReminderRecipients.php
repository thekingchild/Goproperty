<?php

declare(strict_types=1);

namespace Modules\Reminders\Reminders;

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Members\Members;
use App\Models\User;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Tenants\Contracts\TenantDirectory;
use Modules\Tenants\Contracts\TenantReach;

/**
 * Who hears a reminder (docs/plans/P05-subtasks.md defaults 8–10). Runs in
 * the reminder's organization (the current context).
 *
 * - **Tenants** (bills): a tenant who may be contacted (Tenants'
 *   TenantReach: not archived; an imported one only once invited or with
 *   a portal account), by email at their portal account's address, else
 *   the tenant record's, and in the app when they have a portal account.
 *   A phone-only tenant without an account is told nothing until P05-02.
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

        return array_values($chosen->map(static fn (User $user): Recipient => new Recipient(
            key: 'user:'.$user->id,
            name: (string) $user->name,
            userId: $user->id,
            tenantProfileId: null,
            email: self::address($user->email),
        ))->all());
    }

    private static function address(?string $email): ?string
    {
        $email = trim((string) $email);

        return $email === '' ? null : $email;
    }
}
