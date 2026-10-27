{{--
    Settings → Members (/o/{organization}/settings/members; App\Livewire\Workspace\Members).
    Members with their roles, pending and expired invitations, and the invite
    form. Buttons show only for what the user may do (RoleGrants); hiding
    them is not the control: every action checks its policy again.
--}}
@php
    $roleLabel = fn (\App\Enums\OrganizationRole $role): string => __($role->label());
    $roleHelp = [
        'org-owner' => __('Full control, including billing and members'),
        'org-admin' => __('Day-to-day operations and members (not owners)'),
        'agent' => __('Buildings, leases, payments and tickets'),
    ];
@endphp
<x-page :heading="__('Members')" :description="__('The people who work in :organization, and the invitations waiting to be accepted.', ['organization' => $organization->name])">
    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="members-notice" />
    @endif

    @if ($canManage)
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" aria-labelledby="invite-heading">
            <flux:heading size="lg" level="2" id="invite-heading">{{ __('Invite someone') }}</flux:heading>
            <flux:text class="mt-1">{{ __('We email them a link to join. It works once and expires in :days days. No SMS is sent.', ['days' => \App\Members\MemberInvitations::VALID_DAYS]) }}</flux:text>

            <form wire:submit="invite" class="mt-4 flex flex-col gap-4" novalidate>
                <flux:input wire:model="email" type="email" :label="__('Email address')" autocomplete="off" placeholder="name@agency.ng" maxlength="255" required />

                <fieldset class="flex flex-col gap-2">
                    <legend class="mb-1 text-sm font-medium text-zinc-800 dark:text-white">{{ __('Roles') }}</legend>
                    @foreach ($grantable as $role)
                        <label class="flex min-h-11 items-start gap-3 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                            <input type="checkbox" wire:model="roles" value="{{ $role->value }}" class="mt-1 size-4 rounded border-zinc-300 text-primary-600 focus:ring-primary-500" data-test="invite-role-{{ $role->value }}">
                            <span class="flex flex-col">
                                <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $roleLabel($role) }}</span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $roleHelp[$role->value] ?? '' }}</span>
                            </span>
                        </label>
                    @endforeach
                    @error('roles')<flux:text class="text-red-600 dark:text-red-400">{{ $message }}</flux:text>@enderror
                    @error('roles.*')<flux:text class="text-red-600 dark:text-red-400">{{ $message }}</flux:text>@enderror
                </fieldset>

                <div>
                    <flux:button type="submit" variant="primary" data-test="invite-button">{{ __('Send invitation') }}</flux:button>
                </div>
            </form>
        </section>
    @endif

    <section aria-labelledby="members-heading" class="flex flex-col gap-3">
        <flux:heading size="lg" level="2" id="members-heading">{{ __('Members') }} <span class="text-zinc-500">({{ $members->count() }})</span></flux:heading>
        @error('members')<flux:callout variant="danger" icon="x-circle" :heading="$message" />@enderror

        <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="members-list">
            @foreach ($members as $row)
                @php
                    $membership = $row['membership'];
                    $mayTouch = $canManage && collect($row['roles'])->every(fn ($role) => in_array($role, $grantable, true));
                @endphp
                <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="member-{{ $membership->id }}" data-member="{{ $row['user']->email }}">
                    <div class="flex min-w-0 items-center gap-3">
                        <flux:avatar :name="$row['user']->name" :initials="$row['user']->initials()" size="sm" />
                        <div class="flex min-w-0 flex-col">
                            <span class="truncate font-medium text-zinc-900 dark:text-white">
                                {{ $row['user']->name }}
                                @if ($row['user']->id === $me)<span class="text-sm font-normal text-zinc-500">({{ __('you') }})</span>@endif
                            </span>
                            <span class="truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $row['user']->email ?? $row['user']->phone }}</span>
                        </div>
                    </div>

                    @if ($editing === $membership->id)
                        <form wire:submit="saveRoles" class="flex flex-col gap-2 sm:items-end">
                            <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Roles for :name', ['name' => $row['user']->name]) }}">
                                @foreach ($grantable as $role)
                                    <label class="flex min-h-11 items-center gap-2 rounded-lg border border-zinc-200 px-3 dark:border-zinc-700">
                                        <input type="checkbox" wire:model="editRoles" value="{{ $role->value }}" class="size-4 rounded border-zinc-300 text-primary-600 focus:ring-primary-500">
                                        <span class="text-sm">{{ $roleLabel($role) }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('editRoles')<flux:text class="text-red-600 dark:text-red-400">{{ $message }}</flux:text>@enderror
                            <div class="flex gap-2">
                                <flux:button type="submit" size="sm" variant="primary">{{ __('Save roles') }}</flux:button>
                                <flux:button type="button" size="sm" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                            </div>
                        </form>
                    @else
                        <div class="flex flex-wrap items-center gap-2">
                            @forelse ($row['roles'] as $role)
                                <flux:badge size="sm" :color="$role === \App\Enums\OrganizationRole::Owner ? 'amber' : 'zinc'">{{ $roleLabel($role) }}</flux:badge>
                            @empty
                                <flux:badge size="sm" color="red">{{ __('No role') }}</flux:badge>
                            @endforelse

                            @if ($mayTouch)
                                <flux:button size="sm" wire:click="editMember({{ $membership->id }})">{{ __('Change roles') }}</flux:button>
                                <flux:button size="sm" variant="danger" wire:click="removeMember({{ $membership->id }})" wire:confirm="{{ __('Remove :name from :organization? They lose access at once. Their account stays.', ['name' => $row['user']->name, 'organization' => $organization->name]) }}">{{ __('Remove') }}</flux:button>
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    <section aria-labelledby="invitations-heading" class="flex flex-col gap-3">
        <flux:heading size="lg" level="2" id="invitations-heading">{{ __('Invitations') }}</flux:heading>
        @error('invitations')<flux:callout variant="danger" icon="x-circle" :heading="$message" />@enderror

        @if ($invitations->isEmpty())
            <flux:text>{{ __('No invitations are waiting.') }}</flux:text>
        @else
            <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="invitations-list">
                @foreach ($invitations as $invitation)
                    @php
                        $status = $invitation->displayStatus();
                        $mayTouch = $canManage && collect($invitation->roleEnums())->every(fn ($role) => in_array($role, $grantable, true));
                    @endphp
                    <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="invitation-{{ $invitation->id }}" data-invitation="{{ $invitation->email }}">
                        <div class="flex min-w-0 flex-col">
                            <span class="truncate font-medium text-zinc-900 dark:text-white">{{ $invitation->email }}</span>
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ collect($invitation->roleEnums())->map($roleLabel)->join(', ') }}
                                · {{ __('invited by :name', ['name' => $invitation->invitedBy?->name ?? __('a former member')]) }}
                                · @if ($status === \App\Enums\InvitationStatus::Expired)
                                    {{ __('expired :date', ['date' => $invitation->expires_at?->timezone('Africa/Lagos')->format('d/m/Y')]) }}
                                @else
                                    {{ __('expires :date', ['date' => $invitation->expires_at?->timezone('Africa/Lagos')->format('d/m/Y')]) }}
                                @endif
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:badge size="sm" :color="$status === \App\Enums\InvitationStatus::Expired ? 'red' : 'sky'">{{ __($status->label()) }}</flux:badge>
                            @if ($mayTouch)
                                <flux:button size="sm" wire:click="resend({{ $invitation->id }})">{{ __('Send again') }}</flux:button>
                                <flux:button size="sm" variant="danger" wire:click="revoke({{ $invitation->id }})" wire:confirm="{{ __('Revoke the invitation to :email? The link stops working at once.', ['email' => $invitation->email]) }}">{{ __('Revoke') }}</flux:button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-page>
