<?php

declare(strict_types=1);

namespace App\Members;

use App\Enums\OrganizationRole;
use App\Exports\ExportColumn;
use App\Exports\ListExport;
use App\Exports\QueryListExport;
use App\Models\Membership;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Settings → Members, the members list (App\Livewire\Workspace\Members)
 * and its export (P02-07): members who work in the workspace (or hold no
 * role yet), by name, with their workspace roles; tenants and property
 * owners have their own lists. Read a chunk of memberships at a time with
 * the roles of that chunk in one query, so the page and the export share
 * the rows and memory stays flat.
 *
 * Names, emails and phones are people's contact details:
 * MembershipPolicy::export refuses the export to GoProperty staff while
 * impersonating (PLATFORM_CONSOLE.md § 6).
 */
final readonly class MemberList implements ListExport
{
    public function __construct(private OrganizationContext $context) {}

    public function key(): string
    {
        return 'members';
    }

    public function title(): string
    {
        return 'Members';
    }

    public function filters(array $input): array
    {
        return [];
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', Membership::class);
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Name', fn (array $row): string => $row['user']->name),
            ExportColumn::make('Email', fn (array $row): ?string => $row['user']->email),
            ExportColumn::make('Phone', fn (array $row): ?string => $row['user']->phone),
            ExportColumn::make('Roles', fn (array $row): string => $row['roles'] === []
                ? 'No role'
                : implode(', ', array_map(static fn (OrganizationRole $role): string => $role->label(), $row['roles']))),
            ExportColumn::make('Member since', fn (array $row) => $row['membership']->created_at),
        ];
    }

    /**
     * @return iterable<array{membership: Membership, user: User, roles: list<OrganizationRole>, others: list<string>}>
     */
    public function rows(User $user, array $filters): iterable
    {
        $memberships = Membership::query()
            ->with('user')
            ->whereHas('user')
            ->orderBy(User::query()->select('name')->whereColumn('users.id', 'organization_user.user_id'))
            ->orderBy('id')
            ->lazy(QueryListExport::CHUNK)
            ->chunk(QueryListExport::CHUNK);

        foreach ($memberships as $chunk) {
            yield from $this->withRoles(array_values($chunk->all()));
        }
    }

    public function count(User $user, array $filters): int
    {
        $count = 0;

        foreach ($this->rows($user, $filters) as $row) {
            $count++;
        }

        return $count;
    }

    /**
     * The chunk's members with their workspace roles, without those who
     * hold only other roles here (tenants, property owners).
     *
     * @param  list<Membership>  $memberships
     * @return list<array{membership: Membership, user: User, roles: list<OrganizationRole>, others: list<string>}>
     */
    private function withRoles(array $memberships): array
    {
        $names = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.organization_id', $this->context->id())
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->whereIn('model_has_roles.model_id', array_map(static fn (Membership $membership): int => $membership->user_id, $memberships))
            ->get(['model_has_roles.model_id', 'roles.name'])
            ->groupBy('model_id');
        $rows = [];

        foreach ($memberships as $membership) {
            $held = $names->get($membership->user_id, collect())->pluck('name')->map(static fn (mixed $name): string => (string) $name)->all();
            $roles = array_values(array_filter(RoleGrants::memberRoles(), static fn (OrganizationRole $role): bool => in_array($role->value, $held, true)));
            $others = array_values(array_diff($held, array_map(static fn (OrganizationRole $role): string => $role->value, $roles)));

            if ($roles !== [] || $others === []) {
                $rows[] = ['membership' => $membership, 'user' => $membership->user, 'roles' => $roles, 'others' => $others];
            }
        }

        return $rows;
    }
}
