<?php

declare(strict_types=1);

namespace Modules\Properties\Assignments;

use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Properties\Models\AgentAssignment;
use Modules\Properties\Models\Property;

/**
 * The only writer of agent assignments (DATABASE_SCHEMA.md § 3
 * `agent_building`, ARCHITECTURE.md § 5): owners and administrators assign
 * an agent to a building and take them off it, checked against
 * PropertyPolicy::assignAgents for the acting user (so the suspension and
 * impersonation guards apply), and every change is in the audit log (log
 * `properties`, the building as subject).
 *
 * Only agents can be assigned: members of the current organization holding
 * `agent` and neither `org-owner` nor `org-admin` (owners and
 * administrators always see every building, so assigning them would change
 * nothing). Assigning twice changes nothing. When a member leaves the
 * organization (App\Members\MemberRemoved) their assignments there go too
 * (dropMember()), so a returning member starts unassigned.
 *
 * BILLING_PAYMENTS.md § 1.2 limits agent assignment to the Scale plan; it
 * is ungated until P06-01 (P02 decision 4).
 */
final readonly class AgentAssignments
{
    public const string LOG = 'properties';

    public function __construct(private OrganizationContext $context) {}

    /**
     * Agents of the current organization who can be assigned, by name.
     *
     * @return Collection<int, User>
     */
    public function assignable(): Collection
    {
        return $this->assignableQuery()->orderBy('name')->orderBy('id')->get();
    }

    /**
     * The building's assigned members (current members only), by name, with
     * whether the assignment limits them (false once they hold `org-owner`
     * or `org-admin`, who see every building).
     *
     * @return list<array{user: User, limited: bool}>
     */
    public function agentsOf(Property $property): array
    {
        $users = User::query()
            ->whereIn('id', AgentAssignment::query()->where('property_id', $property->id)->select('user_id'))
            ->whereIn('id', Membership::query()->select('user_id'))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
        $notLimited = $this->holdingRoles([OrganizationRole::Owner, OrganizationRole::Admin])
            ->whereIn('model_has_roles.model_id', $users->modelKeys())
            ->pluck('model_has_roles.model_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return array_values(array_map(static fn (User $user): array => ['user' => $user, 'limited' => ! in_array($user->id, $notLimited, true)], $users->all()));
    }

    /**
     * Assign the agent to the building (nothing changes when already assigned).
     *
     * @throws ValidationException (key `agent`) when the user is not an agent here, or the building was archived meanwhile
     */
    public function assign(User $by, Property $property, int $agentId): AgentAssignment
    {
        Gate::forUser($by)->authorize('assignAgents', $property);

        $agent = $this->assignableQuery()->whereKey($agentId)->first()
            ?? throw ValidationException::withMessages(['agent' => __('Choose an agent from the list.')]);

        try {
            return DB::transaction(function () use ($by, $property, $agent): AgentAssignment {
                $building = Property::query()->whereKey($property->getKey())->lockForUpdate()->firstOrFail();

                if ($building->isArchived()) {
                    throw ValidationException::withMessages(['agent' => __(':name is archived. Restore it before assigning agents.', ['name' => $building->name])]);
                }

                $existing = AgentAssignment::query()->where('property_id', $building->id)->where('user_id', $agent->id)->first();

                if ($existing instanceof AgentAssignment) {
                    return $existing;
                }

                $assignment = new AgentAssignment;
                $assignment->forceFill(['user_id' => $agent->id, 'property_id' => $building->id])->save();
                $this->audit('property.agent_assigned', $building, $by, "Assigned {$agent->name} to the building", ['user_id' => $agent->id]);

                return $assignment;
            });
        } catch (UniqueConstraintViolationException) {
            // Assigned by another request a moment ago.
            return AgentAssignment::query()->where('property_id', $property->id)->where('user_id', $agent->id)->firstOrFail();
        }
    }

    /** Take the agent off the building (404 when they are not on it). */
    public function unassign(User $by, Property $property, int $agentId): void
    {
        Gate::forUser($by)->authorize('assignAgents', $property);

        DB::transaction(function () use ($by, $property, $agentId): void {
            $assignment = AgentAssignment::query()->with('user')->where('property_id', $property->id)->where('user_id', $agentId)->lockForUpdate()->firstOrFail();
            $assignment->delete();

            $this->audit('property.agent_unassigned', $property, $by, 'Took '.($assignment->user->name ?? 'a member').' off the building', ['user_id' => $agentId]);
        });
    }

    /**
     * Delete every assignment the user has in the current organization,
     * audited per building: they left it (App\Members\Members::remove,
     * already authorized there). Returns how many went.
     */
    public function dropMember(int $userId, ?User $by): int
    {
        $assignments = AgentAssignment::query()->with('property')->where('user_id', $userId)->orderBy('property_id')->get();

        foreach ($assignments as $assignment) {
            $assignment->delete();

            if ($assignment->property instanceof Property) {
                $this->audit('property.agent_unassigned', $assignment->property, $by, 'Took a member who left the organization off the building', ['user_id' => $userId, 'reason' => 'member_removed']);
            }
        }

        return $assignments->count();
    }

    /** @return Builder<User> */
    private function assignableQuery(): Builder
    {
        return User::query()
            ->whereIn('id', Membership::query()->select('user_id'))
            ->whereIn('id', $this->holdingRoles([OrganizationRole::Agent])->select('model_has_roles.model_id'))
            ->whereNotIn('id', $this->holdingRoles([OrganizationRole::Owner, OrganizationRole::Admin])->select('model_has_roles.model_id'));
    }

    /**
     * Users holding one of the roles in the current organization.
     *
     * @param  list<OrganizationRole>  $roles
     */
    private function holdingRoles(array $roles): QueryBuilder
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.organization_id', $this->context->id())
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->whereIn('roles.name', array_map(static fn (OrganizationRole $role): string => $role->value, $roles));
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(string $event, Property $property, ?User $by, string $description, array $properties): void
    {
        $entry = activity(self::LOG)
            ->event($event)
            ->performedOn($property)
            ->withProperties(['property' => $property->name, ...$properties]);

        if ($by !== null) {
            $entry->causedBy($by);
        }

        $entry->log($description);
    }
}
