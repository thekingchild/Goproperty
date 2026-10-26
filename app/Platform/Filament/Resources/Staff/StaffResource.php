<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Staff;

use App\Models\PlatformStaff;
use App\Models\StaffRole;
use App\Models\StaffStatus;
use App\Platform\Filament\Resources\Staff\Pages\ListStaff;
use App\Platform\Filament\Resources\Staff\Pages\ViewStaff;
use App\Platform\Filament\Resources\Staff\RelationManagers\HistoryRelationManager;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Console → Staff (PLATFORM_CONSOLE.md § 1): the staff list and each staff
 * member's profile (roles, status, last login, 2FA, IP allowlist, history).
 * Staff are added only by invitation and never deleted. Every page and
 * action is checked by PlatformStaffPolicy; the services re-check.
 */
final class StaffResource extends Resource
{
    protected static ?string $model = PlatformStaff::class;

    protected static ?string $slug = 'staff';

    protected static ?string $modelLabel = 'staff member';

    protected static ?string $pluralModelLabel = 'staff';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Staff';

    protected static ?string $navigationLabel = 'Staff';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function statusColor(StaffStatus $status): string
    {
        return match ($status) {
            StaffStatus::Active => 'success',
            StaffStatus::Invited => 'warning',
            StaffStatus::Deactivated => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('roles'))
            ->columns([
                TextColumn::make('name')
                    ->searchable(['name', 'email'])
                    ->sortable()
                    ->description(fn (PlatformStaff $record): string => $record->email),
                TextColumn::make('roles.name')->label('Roles')->badge()->placeholder('No roles'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (StaffStatus $state): string => ucfirst($state->value))
                    ->color(fn (StaffStatus $state): string => self::statusColor($state)),
                IconColumn::make('two_factor')
                    ->label('2FA')
                    ->state(fn (PlatformStaff $record): bool => $record->hasTwoFactorEnabled())
                    ->boolean(),
                TextColumn::make('last_login_at')->label('Last login')->since()->placeholder('Never')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(StaffStatus::cases())->mapWithKeys(fn (StaffStatus $status): array => [$status->value => ucfirst($status->value)])->all()),
                SelectFilter::make('role')
                    ->options(fn (): array => StaffRole::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('roles', fn (Builder $roles) => $roles->whereKey($data['value']))
                        : $query),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('name');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Profile')
                ->columns(2)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('email')->label('Work email'),
                    TextEntry::make('phone')->placeholder('—'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (StaffStatus $state): string => ucfirst($state->value))
                        ->color(fn (StaffStatus $state): string => self::statusColor($state)),
                    TextEntry::make('roles.name')->label('Roles')->badge()->placeholder('No roles'),
                    TextEntry::make('last_login_at')->label('Last login')->dateTime()->placeholder('Never'),
                    IconEntry::make('two_factor')
                        ->label('2FA enrolled')
                        ->state(fn (PlatformStaff $record): bool => $record->hasTwoFactorEnabled())
                        ->boolean(),
                    TextEntry::make('ip_allowlist')->label('IP allowlist')->badge()->placeholder('Any address'),
                    TextEntry::make('invitedBy.name')->label('Invited by')->placeholder('—'),
                    TextEntry::make('invitation')
                        ->label('Invitation')
                        ->state(fn (PlatformStaff $record): string => match (true) {
                            $record->invited_by_staff_id === null => 'Created by the installer',
                            $record->invitation_accepted_at !== null => 'Accepted '.$record->invitation_accepted_at->toDayDateTimeString(),
                            $record->invitation_expires_at !== null && $record->invitation_expires_at->isPast() => 'Expired: send it again',
                            $record->invitation_expires_at !== null => 'Waiting, expires '.$record->invitation_expires_at->toDayDateTimeString(),
                            default => '—',
                        }),
                    TextEntry::make('deactivated_at')->label('Deactivated')->dateTime()->placeholder('—'),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [HistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaff::route('/'),
            'view' => ViewStaff::route('/{record}'),
        ];
    }
}
