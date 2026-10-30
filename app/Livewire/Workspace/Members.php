<?php

declare(strict_types=1);

namespace App\Livewire\Workspace;

use App\Enums\InvitationKind;
use App\Enums\OrganizationRole;
use App\Exports\Livewire\WithListExports;
use App\Members\InvitationList;
use App\Members\MemberChangeRefused;
use App\Members\MemberInvitations;
use App\Members\MemberList;
use App\Members\Members as MemberService;
use App\Members\RoleGrants;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use App\Plans\CountsAgainstLimit;
use App\Plans\Feature;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings → Members, /o/{organization}/settings/members (ARCHITECTURE.md
 * § 5, ONBOARDING_COMPLIANCE.md § 2): the organization's members with their
 * roles, and its invitations. Owners and administrators (`members.view`)
 * see the page; with `members.manage` they invite by email with one or
 * more roles, send an invitation again, revoke it, change a member's roles
 * and remove a member, within RoleGrants (only an owner grants or takes
 * away `org-owner`) and never leaving the organization without an owner.
 *
 * Runs inside the `organization` middleware, replayed for Livewire updates:
 * every request has the organization's context and the component refuses
 * any other. Every action checks its policy (InvitationPolicy,
 * MembershipPolicy) and the services check again. Member changes are
 * blocked while GoProperty staff impersonate (`members.manage` and the
 * marks below) and while the organization is suspended (central guard).
 * Both lists export (WithListExports; not while staff impersonate: contact
 * details).
 */
#[Layout('layouts::app')]
#[Title('Members')]
final class Members extends Component
{
    use WithListExports;

    #[Locked]
    public int $organizationId;

    public string $email = '';

    /** @var list<string> */
    public array $roles = [];

    #[Locked]
    public ?int $editing = null;

    /** @var list<string> */
    public array $editRoles = [];

    #[Locked]
    public ?string $notice = null;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context): void
    {
        $this->organizationId = $context->id();
        $this->authorize('viewAny', Membership::class);
        $this->roles = [OrganizationRole::Agent->value];
    }

    #[BlockedWhileImpersonating]
    #[CountsAgainstLimit(Feature::StaffUsers)]
    public function invite(MemberInvitations $invitations): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in($this->memberRoleValues())],
        ], ['roles.required' => 'Choose at least one role.', 'roles.min' => 'Choose at least one role.'], ['email' => 'email address']);

        $roles = $this->toRoles($this->roles);
        $this->authorize('create', [Invitation::class, $roles]);

        if ($this->attempt(fn () => $invitations->invite($this->user(), $this->email, $roles), 'email')) {
            $this->notice = "Invitation sent to {$this->email}. The link works for ".MemberInvitations::VALID_DAYS.' days.';
            $this->reset('email');
            $this->roles = [OrganizationRole::Agent->value];
        }
    }

    #[BlockedWhileImpersonating]
    public function resend(int $invitationId, MemberInvitations $invitations): void
    {
        $invitation = Invitation::query()->where('kind', InvitationKind::User)->findOrFail($invitationId);
        $this->authorize('resend', $invitation);

        if ($this->attempt(fn () => $invitations->resend($this->user(), $invitation), 'invitations')) {
            $this->notice = "Invitation sent to {$invitation->email} again, with a new link.";
        }
    }

    #[BlockedWhileImpersonating]
    public function revoke(int $invitationId, MemberInvitations $invitations): void
    {
        $invitation = Invitation::query()->where('kind', InvitationKind::User)->findOrFail($invitationId);
        $this->authorize('revoke', $invitation);

        if ($this->attempt(fn () => $invitations->revoke($this->user(), $invitation), 'invitations')) {
            $this->notice = "The invitation to {$invitation->email} was revoked: its link no longer works.";
        }
    }

    #[BlockedWhileImpersonating]
    public function editMember(int $membershipId, RoleGrants $grants): void
    {
        $membership = Membership::query()->with('user')->findOrFail($membershipId);
        $current = $grants->memberRolesOf($membership->user);
        $this->authorize('changeRoles', [$membership, $current === [] ? [OrganizationRole::Agent] : $current]);

        $this->editing = $membership->id;
        $this->editRoles = array_map(static fn (OrganizationRole $role): string => $role->value, $current);
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->editing = null;
        $this->editRoles = [];
        $this->resetValidation();
    }

    #[BlockedWhileImpersonating]
    public function saveRoles(MemberService $members): void
    {
        $this->validate([
            'editRoles' => ['required', 'array', 'min:1'],
            'editRoles.*' => ['string', Rule::in($this->memberRoleValues())],
        ], ['editRoles.required' => 'Choose at least one role.', 'editRoles.min' => 'Choose at least one role.'], ['editRoles' => 'roles']);

        $membership = Membership::query()->with('user')->findOrFail($this->editing ?? 0);
        $roles = $this->toRoles($this->editRoles);
        $this->authorize('changeRoles', [$membership, $roles]);

        if ($this->attempt(fn () => $members->changeRoles($this->user(), $membership, $roles), 'editRoles')) {
            $this->notice = "{$membership->user->name}'s roles were changed.";
            $this->cancelEdit();
        }
    }

    #[BlockedWhileImpersonating]
    public function removeMember(int $membershipId, MemberService $members): void
    {
        $membership = Membership::query()->with('user')->findOrFail($membershipId);
        $this->authorize('remove', $membership);

        if ($this->attempt(fn () => $members->remove($this->user(), $membership), 'members')) {
            $this->notice = "{$membership->user->name} was removed from the organization.";

            if ($this->editing === $membership->id) {
                $this->cancelEdit();
            }
        }
    }

    public function render(OrganizationContext $context, RoleGrants $grants): View
    {
        $user = $this->user();
        $grantable = $grants->grantableBy($user);
        $members = $this->members();

        return view('livewire.workspace.members', [
            'organization' => $context->get(),
            'members' => $members,
            'invitations' => Gate::allows('viewAny', Invitation::class) ? $this->invitations() : collect(),
            'grantable' => $grantable,
            'memberRoles' => RoleGrants::memberRoles(),
            'canManage' => $grantable !== [],
            'me' => $user->id,
        ]);
    }

    /**
     * Members who work in the workspace (or hold no role yet), with their
     * workspace roles, by name (MemberList: the export's rows too).
     *
     * @return Collection<int, array{membership: Membership, user: User, roles: list<OrganizationRole>, others: list<string>}>
     */
    private function members(): Collection
    {
        return collect(app(MemberList::class)->rows($this->user(), []))->values();
    }

    /** @return Collection<int, Invitation> */
    private function invitations(): Collection
    {
        return app(InvitationList::class)->query($this->user(), [])->with('invitedBy')->limit(InvitationList::PAGE_LIMIT)->get();
    }

    /** The members and invitations lists export (P02-07); neither has filters. */
    protected function listExports(): array
    {
        return ['members' => [], 'member_invitations' => []];
    }

    /** @return list<string> */
    private function memberRoleValues(): array
    {
        return array_map(static fn (OrganizationRole $role): string => $role->value, RoleGrants::memberRoles());
    }

    /**
     * @param  list<string>  $values
     * @return list<OrganizationRole>
     */
    private function toRoles(array $values): array
    {
        return array_values(array_filter(RoleGrants::memberRoles(), static fn (OrganizationRole $role): bool => in_array($role->value, $values, true)));
    }

    /** Runs a change; a refusal becomes an error on the field. */
    private function attempt(callable $change, string $field): bool
    {
        try {
            $change();
        } catch (MemberChangeRefused $refused) {
            $this->notice = null;
            $this->addError($field, $refused->getMessage());

            return false;
        }

        $this->resetValidation();

        return true;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
