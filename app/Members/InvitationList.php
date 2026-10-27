<?php

declare(strict_types=1);

namespace App\Members;

use App\Enums\InvitationKind;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Exports\ExportColumn;
use App\Exports\QueryListExport;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Settings → Members, the invitations list (App\Livewire\Workspace\Members)
 * and its export (P02-07): member invitations waiting to be accepted or
 * expired, newest first. The page shows the latest PAGE_LIMIT; the export
 * holds all of them. Email addresses: InvitationPolicy::export refuses the
 * export to GoProperty staff while impersonating (PLATFORM_CONSOLE.md § 6).
 *
 * @extends QueryListExport<Invitation>
 */
final class InvitationList extends QueryListExport
{
    public const int PAGE_LIMIT = 100;

    public function key(): string
    {
        return 'member_invitations';
    }

    public function title(): string
    {
        return 'Invitations';
    }

    public function filters(array $input): array
    {
        return [];
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', Invitation::class);
    }

    public function query(User $user, array $filters): Builder
    {
        return Invitation::query()
            ->where('kind', InvitationKind::User)
            ->whereIn('status', [InvitationStatus::Pending, InvitationStatus::Expired])
            ->orderByDesc('sent_at')
            ->orderByDesc('id');
    }

    protected function exportQuery(User $user, array $filters): Builder
    {
        return $this->query($user, $filters)->with('invitedBy');
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Email', fn (Invitation $invitation) => $invitation->email),
            ExportColumn::make('Roles', fn (Invitation $invitation): string => implode(', ', array_map(static fn (OrganizationRole $role): string => $role->label(), $invitation->roleEnums()))),
            ExportColumn::make('Status', fn (Invitation $invitation) => $invitation->displayStatus()),
            ExportColumn::make('Invited by', fn (Invitation $invitation) => $invitation->invitedBy?->name),
            ExportColumn::make('Sent on', fn (Invitation $invitation) => $invitation->sent_at),
            ExportColumn::make('Expires on', fn (Invitation $invitation) => $invitation->expires_at),
        ];
    }
}
