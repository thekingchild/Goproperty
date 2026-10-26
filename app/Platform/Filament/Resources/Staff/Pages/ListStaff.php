<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Staff\Pages;

use App\Models\StaffRole;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\Staff\StaffResource;
use App\Platform\Staff\StaffLifecycle;
use App\Platform\Staff\StaffPermission;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;

final class ListStaff extends ListRecords
{
    use ActsAsStaff;

    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label('Invite staff')
                ->authorize(fn (): bool => StaffResource::can('create'))
                ->modalDescription('They get an email link to set a password, then set up an authenticator app. Granting super-admin, finance-admin or compliance-officer waits for a second super-admin\'s approval.')
                ->schema([
                    TextInput::make('name')->required()->maxLength(120),
                    TextInput::make('email')->label('Work email')->email()->required()->maxLength(254),
                    TextInput::make('phone')->tel()->maxLength(32),
                    CheckboxList::make('roles')
                        ->options(fn (): array => StaffRole::query()->where('active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->descriptions(fn (): array => StaffRole::query()->where('active', true)->pluck('description', 'id')->map(fn (mixed $text): string => (string) $text)->all())
                        ->visible(fn (): bool => self::actor()->holdsStaffPermission(StaffPermission::RolesManage))
                        ->columns(2),
                    Textarea::make('reason')->label('Reason (shown to the approver)')->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $roles = StaffRole::query()->whereKey(array_map('intval', (array) ($data['roles'] ?? [])))->get()->all();

                    self::attempt(function () use ($data, $roles): string {
                        [$staff, $requests] = app(StaffLifecycle::class)->invite(
                            self::actor(),
                            (string) $data['name'],
                            (string) $data['email'],
                            isset($data['phone']) ? (string) $data['phone'] : null,
                            array_values($roles),
                            isset($data['reason']) ? (string) $data['reason'] : null,
                        );

                        return "Invitation sent to {$staff->email}".($requests !== [] ? '; '.count($requests).' role grant(s) wait for approval.' : '.');
                    });
                }),
        ];
    }
}
