<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\AccessLog;

use App\Platform\Audit\AccessLogEntry;
use App\Platform\Filament\Resources\AccessLog\Pages\ListAccessLog;
use App\Platform\Filament\Resources\AuditLog\AuditLogResource;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Console → Audit → Access log (PLATFORM_CONSOLE.md § 8, P06-07c, default
 * 61): the authentication log of customers and staff, newest first, paged
 * on the server; filters for account type, event, dates, new devices and
 * IP, and a search by email (accounts with that email, and failed attempts
 * whose typed address hashes to it). Read-only, not exportable. Opening it
 * is audited (`access_log.viewed`). Policy: AccessLogEntryPolicy
 * (`access_log.view`).
 */
final class AccessLogResource extends Resource
{
    protected static ?string $model = AccessLogEntry::class;

    protected static ?string $slug = 'access-log';

    protected static ?string $modelLabel = 'sign-in event';

    protected static ?string $pluralModelLabel = 'access log';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Audit';

    protected static ?string $navigationLabel = 'Access log';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user:id,name,email', 'staff:id,name,email']))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('Y-m-d H:i:s', AuditLogResource::TIMEZONE),
                TextColumn::make('account')
                    ->label('Account')
                    ->state(fn (AccessLogEntry $record): string => $record->accountName())
                    ->description(fn (AccessLogEntry $record): string => $record->accountDetail()),
                TextColumn::make('event')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AccessLogEntry::eventLabel($state))
                    ->color(fn (string $state): string => match ($state) {
                        'login_failed', '2fa_failed', 'turnstile_failed', 'lockout' => 'danger',
                        'login_success', 'passkey_login', '2fa_passed' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('ip')->label('IP')->placeholder('—'),
                TextColumn::make('location')->placeholder('—'),
                TextColumn::make('user_agent')
                    ->label('Browser')
                    ->state(fn (AccessLogEntry $record): ?string => $record->shortUserAgent())
                    ->tooltip(fn (AccessLogEntry $record): ?string => $record->user_agent)
                    ->placeholder('—'),
                IconColumn::make('is_new_device')->label('New device')->boolean()->placeholder('—'),
            ])
            ->searchable()
            ->searchPlaceholder('Search by email')
            ->searchUsing(fn (Builder $query, string $search): Builder => AccessLogEntry::forEmail($query, $search))
            ->filters([
                SelectFilter::make('authenticatable_type')->label('Account type')->options(AccessLogEntry::accountTypes()),
                SelectFilter::make('event')->options(AccessLogEntry::EVENT_LABELS),
                TernaryFilter::make('is_new_device')->label('New device'),
                Filter::make('ip')
                    ->schema([TextInput::make('ip')->label('IP address')->maxLength(45)])
                    ->query(fn (Builder $query, array $data): Builder => is_string($data['ip'] ?? null) && trim($data['ip']) !== ''
                        ? $query->where('ip', trim($data['ip']))
                        : $query)
                    ->indicateUsing(fn (array $data): ?string => is_string($data['ip'] ?? null) && trim($data['ip']) !== '' ? 'IP: '.trim($data['ip']) : null),
                AuditLogResource::datesFilter(),
            ])
            ->defaultSort('id', 'desc')
            ->paginationMode(PaginationMode::Simple)
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccessLog::route('/'),
        ];
    }
}
