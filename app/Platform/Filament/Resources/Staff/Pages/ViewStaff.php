<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Staff\Pages;

use App\Models\PlatformStaff;
use App\Models\StaffRole;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\Staff\StaffResource;
use App\Platform\Staff\StaffLifecycle;
use App\Platform\Staff\StaffRoles;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

/**
 * A staff member's profile (PLATFORM_CONSOLE.md § 1). Each action is
 * offered by PlatformStaffPolicy and carried out by the staff services,
 * which enforce the two-person rules and the super-admin floor.
 *
 * @property-read PlatformStaff $record
 */
final class ViewStaff extends ViewRecord
{
    use ActsAsStaff;

    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->grantRoleAction(),
            $this->removeRoleAction(),
            ActionGroup::make([
                $this->editDetailsAction(),
                $this->resendInvitationAction(),
                $this->deactivateAction(),
                $this->reactivateAction(),
            ]),
        ];
    }

    private function staff(): PlatformStaff
    {
        return $this->record;
    }

    private function allows(string $ability): bool
    {
        return Gate::forUser(self::actor())->allows($ability, $this->staff());
    }

    private function reloadStaff(): void
    {
        $this->record = $this->staff()->fresh() ?? $this->staff();
    }

    public function grantRoleAction(): Action
    {
        return Action::make('grantRole')
            ->label('Grant role')
            ->authorize(fn (): bool => $this->allows('manageRoles'))
            ->schema([
                Select::make('role')
                    ->options(fn (): array => StaffRole::query()
                        ->where('active', true)
                        ->whereNotIn('id', $this->staff()->roles()->pluck('roles.id'))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->required(),
                Textarea::make('reason')->label('Reason (shown to the approver)')->maxLength(500),
            ])
            ->action(function (array $data): void {
                $role = StaffRole::query()->findOrFail((int) $data['role']);

                self::attempt(function () use ($role, $data): string {
                    $request = app(StaffRoles::class)->grant(self::actor(), $this->staff(), $role, isset($data['reason']) ? (string) $data['reason'] : null);

                    return $request !== null
                        ? "Granting {$role->name} needs a second super-admin: the request is in Approvals."
                        : "{$role->name} granted.";
                });
                $this->reloadStaff();
            });
    }

    public function removeRoleAction(): Action
    {
        return Action::make('removeRole')
            ->label('Remove role')
            ->color('gray')
            ->authorize(fn (): bool => $this->allows('manageRoles'))
            ->schema([
                Select::make('role')
                    ->options(fn (): array => StaffRole::query()->whereIn('id', $this->staff()->roles()->pluck('roles.id'))->orderBy('name')->pluck('name', 'id')->all())
                    ->required(),
                Textarea::make('reason')->maxLength(500),
            ])
            ->action(function (array $data): void {
                $role = StaffRole::query()->findOrFail((int) $data['role']);

                self::attempt(function () use ($role, $data): string {
                    app(StaffRoles::class)->revoke(self::actor(), $this->staff(), $role, isset($data['reason']) ? (string) $data['reason'] : null);

                    return "{$role->name} removed.";
                });
                $this->reloadStaff();
            });
    }

    public function editDetailsAction(): Action
    {
        return Action::make('editDetails')
            ->label('Edit details')
            ->icon('heroicon-o-pencil-square')
            ->authorize(fn (): bool => $this->allows('update'))
            ->fillForm(fn (): array => [
                'name' => $this->staff()->name,
                'phone' => $this->staff()->phone,
                'ip_allowlist' => $this->staff()->ip_allowlist ?? [],
            ])
            ->schema([
                TextInput::make('name')->required()->maxLength(120),
                TextInput::make('phone')->tel()->maxLength(32),
                TagsInput::make('ip_allowlist')
                    ->label('IP allowlist')
                    ->helperText('Optional. Addresses or CIDR ranges; from anywhere else this account gets a plain 404.')
                    ->placeholder('203.0.113.7 or 10.0.0.0/8'),
            ])
            ->action(function (array $data): void {
                self::attempt(function () use ($data): string {
                    app(StaffLifecycle::class)->updateDetails(
                        self::actor(),
                        $this->staff(),
                        (string) $data['name'],
                        isset($data['phone']) ? (string) $data['phone'] : null,
                        array_values(array_map('strval', (array) ($data['ip_allowlist'] ?? []))),
                    );

                    return 'Details saved.';
                });
                $this->reloadStaff();
            });
    }

    public function resendInvitationAction(): Action
    {
        return Action::make('resendInvitation')
            ->label('Send invitation again')
            ->icon('heroicon-o-envelope')
            ->authorize(fn (): bool => $this->allows('resendInvitation'))
            ->requiresConfirmation()
            ->modalDescription('A new link is emailed; the old one stops working.')
            ->action(function (): void {
                self::attempt(function (): string {
                    app(StaffLifecycle::class)->resendInvitation(self::actor(), $this->staff());

                    return 'Invitation sent again.';
                });
                $this->reloadStaff();
            });
    }

    public function deactivateAction(): Action
    {
        return Action::make('deactivate')
            ->label('Deactivate')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->authorize(fn (): bool => $this->allows('deactivate'))
            ->requiresConfirmation()
            ->modalDescription('They are signed out everywhere at once and cannot sign in until reactivated. Nothing is deleted.')
            ->schema([Textarea::make('reason')->maxLength(500)])
            ->action(function (array $data): void {
                self::attempt(function () use ($data): string {
                    app(StaffLifecycle::class)->deactivate(self::actor(), $this->staff(), isset($data['reason']) ? (string) $data['reason'] : null);

                    return 'Deactivated. Their sessions have ended.';
                });
                $this->reloadStaff();
            });
    }

    public function reactivateAction(): Action
    {
        return Action::make('reactivate')
            ->label('Reactivate')
            ->icon('heroicon-o-arrow-path')
            ->authorize(fn (): bool => $this->allows('reactivate'))
            ->requiresConfirmation()
            ->schema([Textarea::make('reason')->maxLength(500)])
            ->action(function (array $data): void {
                self::attempt(function () use ($data): string {
                    app(StaffLifecycle::class)->reactivate(self::actor(), $this->staff(), isset($data['reason']) ? (string) $data['reason'] : null);

                    return 'Reactivated.';
                });
                $this->reloadStaff();
            });
    }
}
