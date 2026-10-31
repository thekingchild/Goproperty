<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\AuditLog;

use App\Models\PlatformStaff;
use App\Platform\Audit\AuditLogEntry;
use App\Platform\Customers\Customer;
use App\Platform\Customers\CustomerUser;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\AuditLog\Pages\ListAuditLog;
use App\Platform\Filament\Resources\AuditLog\Pages\ViewAuditLogEntry;
use App\Platform\Filament\Resources\Customers\CustomerResource;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Console → Audit → Audit log (PLATFORM_CONSOLE.md § 7, P06-07c, default
 * 60): every audit entry across organizations, newest first, paged on the
 * server; filters for organization, log, event, who did it, staff member,
 * support session and dates; a search by description. An entry's page
 * shows what changed, IP, user agent, reason and its link in the hash
 * chain. Read-only and not exportable (personal data). Opening the list or
 * an entry is itself audited (App\Platform\Audit\AuditReads). Policy:
 * AuditLogEntryPolicy (`audit.view`).
 */
final class AuditLogResource extends Resource
{
    use ActsAsStaff;

    public const string TIMEZONE = 'Africa/Lagos';

    protected static ?string $model = AuditLogEntry::class;

    protected static ?string $slug = 'audit-log';

    protected static ?string $modelLabel = 'audit log entry';

    protected static ?string $pluralModelLabel = 'audit log';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Audit';

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    /** @return list<string> */
    public static function relationsForList(): array
    {
        return ['causerUser:id,name,email', 'causerStaff:id,name,email', 'impersonator:id,name'];
    }

    public static function table(Table $table): Table
    {
        return self::entriesTable($table)
            ->filters([
                Filter::make('organization')
                    ->schema([
                        Select::make('organization_id')
                            ->label('Organization')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Customer::query()->withTrashed()
                                ->where(fn (Builder $match) => $match->where('name', 'like', '%'.self::escapeLike($search).'%')->orWhere('slug', 'like', '%'.self::escapeLike($search).'%'))
                                ->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                            ->getOptionLabelUsing(fn (mixed $value): ?string => Customer::query()->withTrashed()->whereKey($value)->value('name')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => is_numeric($data['organization_id'] ?? null)
                        ? AuditLogEntry::forOrganization($query, (int) $data['organization_id'])
                        : $query)
                    ->indicateUsing(fn (array $data): ?string => is_numeric($data['organization_id'] ?? null)
                        ? 'Organization: '.(Customer::query()->withTrashed()->whereKey($data['organization_id'])->value('name') ?? '#'.$data['organization_id'])
                        : null),
                SelectFilter::make('log_name')
                    ->label('Log')
                    ->options(fn (): array => DB::table('activity_log')->whereNotNull('log_name')->distinct()->orderBy('log_name')->pluck('log_name', 'log_name')->all()),
                // Searched, not listed: the events are many and `event` has no index.
                Filter::make('event')
                    ->schema([
                        Select::make('event')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => DB::table('activity_log')
                                ->where('event', 'like', '%'.self::escapeLike($search).'%')
                                ->distinct()->orderBy('event')->limit(50)->pluck('event', 'event')->all())
                            ->getOptionLabelUsing(fn (mixed $value): ?string => is_string($value) ? $value : null),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => is_string($data['event'] ?? null) && $data['event'] !== ''
                        ? $query->where('event', $data['event'])
                        : $query)
                    ->indicateUsing(fn (array $data): ?string => is_string($data['event'] ?? null) && $data['event'] !== '' ? 'Event: '.$data['event'] : null),
                SelectFilter::make('causer_kind')
                    ->label('Who did it')
                    ->options(AuditLogEntry::causerKinds())
                    ->query(fn (Builder $query, array $data): Builder => is_string($data['value'] ?? null) && $data['value'] !== ''
                        ? AuditLogEntry::causedByKind($query, $data['value'])
                        : $query),
                Filter::make('user')
                    ->schema([
                        Select::make('user_id')
                            ->label('Customer user')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => CustomerUser::query()
                                ->where(fn (Builder $match) => $match->where('name', 'like', '%'.self::escapeLike($search).'%')->orWhere('email', 'like', '%'.self::escapeLike($search).'%'))
                                ->orderBy('name')->limit(50)->get(['id', 'name', 'email'])
                                ->mapWithKeys(fn (CustomerUser $user): array => [$user->id => $user->email !== null ? "{$user->name} ({$user->email})" : $user->name])->all())
                            ->getOptionLabelUsing(fn (mixed $value): ?string => CustomerUser::query()->whereKey($value)->value('name')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => is_numeric($data['user_id'] ?? null)
                        ? AuditLogEntry::causedByUser($query, (int) $data['user_id'])
                        : $query)
                    ->indicateUsing(fn (array $data): ?string => is_numeric($data['user_id'] ?? null)
                        ? 'Customer user: '.(CustomerUser::query()->whereKey($data['user_id'])->value('name') ?? '#'.$data['user_id'])
                        : null),
                SelectFilter::make('staff')
                    ->label('Staff member')
                    ->options(fn (): array => PlatformStaff::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => is_numeric($data['value'] ?? null)
                        ? AuditLogEntry::causedByStaff($query, (int) $data['value'])
                        : $query),
                TernaryFilter::make('support_session')
                    ->label('During a support session')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('impersonator_staff_id'),
                        false: fn (Builder $query) => $query->whereNull('impersonator_staff_id'),
                    ),
                self::datesFilter(),
            ]);
    }

    /** The columns, search and paging the list and a staff member's Actions share. */
    public static function entriesTable(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(self::relationsForList()))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('Y-m-d H:i:s', self::TIMEZONE),
                TextColumn::make('log_name')->label('Log')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('event')->placeholder('—'),
                TextColumn::make('description')->searchable()->limit(120)->wrap(),
                TextColumn::make('causer')
                    ->label('Who')
                    ->state(fn (AuditLogEntry $record): string => $record->causerName())
                    ->description(fn (AuditLogEntry $record): ?string => $record->causerDetail()),
                TextColumn::make('impersonator.name')->label('Support session by')->placeholder('—'),
                TextColumn::make('subject')
                    ->label('About')
                    ->state(fn (AuditLogEntry $record): ?string => $record->subjectLabel())
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('ip')->label('IP')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([ViewAction::make()->url(fn (AuditLogEntry $record): string => self::getUrl('view', ['record' => $record]))])
            ->recordUrl(fn (AuditLogEntry $record): string => self::getUrl('view', ['record' => $record]))
            ->defaultSort('id', 'desc')
            ->paginationMode(PaginationMode::Simple)
            ->paginated([25, 50, 100]);
    }

    /** Lagos days, as a range on the indexed `created_at`. */
    public static function datesFilter(): Filter
    {
        return Filter::make('dates')
            ->schema([
                DatePicker::make('from')->label('From'),
                DatePicker::make('until')->label('Until'),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when(is_string($data['from'] ?? null) && $data['from'] !== '', fn (Builder $from) => $from->where('created_at', '>=', CarbonImmutable::parse((string) $data['from'], self::TIMEZONE)->startOfDay()->utc()))
                ->when(is_string($data['until'] ?? null) && $data['until'] !== '', fn (Builder $until) => $until->where('created_at', '<', CarbonImmutable::parse((string) $data['until'], self::TIMEZONE)->startOfDay()->addDay()->utc())));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Entry')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    TextEntry::make('created_at')->label('When')->dateTime('Y-m-d H:i:s', self::TIMEZONE)->helperText('Lagos time'),
                    TextEntry::make('log_name')->label('Log')->badge()->placeholder('—'),
                    TextEntry::make('event')->placeholder('—'),
                    TextEntry::make('description')->columnSpanFull(),
                    TextEntry::make('causer')
                        ->label('Who did it')
                        ->state(fn (AuditLogEntry $record): string => $record->causerName())
                        ->helperText(fn (AuditLogEntry $record): ?string => $record->causerDetail()),
                    TextEntry::make('impersonator.name')
                        ->label('Support session by')
                        ->placeholder('—')
                        ->helperText(fn (AuditLogEntry $record): ?string => $record->supportSessionId() !== null ? 'Session #'.$record->supportSessionId() : null),
                    TextEntry::make('subject')
                        ->label('About')
                        ->state(fn (AuditLogEntry $record): ?string => $record->subjectLabel())
                        ->helperText(fn (AuditLogEntry $record): ?string => $record->subject_type)
                        ->url(fn (AuditLogEntry $record): ?string => $record->isAboutOrganization() ? self::organizationUrl($record) : null)
                        ->placeholder('—'),
                    TextEntry::make('organization')
                        ->label('Organization')
                        ->state(fn (AuditLogEntry $record): ?string => $record->organizationId() !== null
                            ? (Customer::query()->withTrashed()->whereKey($record->organizationId())->value('name') ?? 'Organization #'.$record->organizationId())
                            : null)
                        ->url(fn (AuditLogEntry $record): ?string => self::organizationUrl($record))
                        ->placeholder('—'),
                    TextEntry::make('why')->label('Reason')->state(fn (AuditLogEntry $record): ?string => $record->why())->placeholder('—')->columnSpan(2),
                    TextEntry::make('ip')->label('IP')->placeholder('—'),
                    TextEntry::make('user_agent')->label('Browser')->placeholder('—')->columnSpan(2),
                ]),
            Section::make('What changed')
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('changes')
                        ->hiddenLabel()
                        ->state(fn (AuditLogEntry $record): array => $record->changes())
                        ->table([TableColumn::make('Field'), TableColumn::make('Before'), TableColumn::make('After')])
                        ->schema([
                            TextEntry::make('field'),
                            TextEntry::make('old')->placeholder('—'),
                            TextEntry::make('new')->placeholder('—'),
                        ])
                        ->placeholder('No field changes recorded.'),
                    KeyValueEntry::make('details')
                        ->label('Details')
                        ->keyLabel('Property')
                        ->valueLabel('Value')
                        ->state(fn (AuditLogEntry $record): array => $record->propertyLines())
                        ->placeholder('No details recorded.'),
                ]),
            Section::make('Hash chain')
                ->columnSpanFull()
                ->columns(2)
                ->collapsible()
                ->schema([
                    TextEntry::make('prev_hash')->label('Previous entry\'s hash')->fontFamily('mono')->placeholder('— (first entry)')->copyable(),
                    TextEntry::make('hash')->label('This entry\'s hash')->fontFamily('mono')->placeholder('—')->copyable(),
                ]),
        ]);
    }

    private static function organizationUrl(AuditLogEntry $record): ?string
    {
        $id = $record->organizationId();
        $customer = $id !== null ? Customer::query()->find($id) : null;

        return $customer !== null && self::actor()->can('view', $customer) ? CustomerResource::getUrl('view', ['record' => $customer]) : null;
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLog::route('/'),
            'view' => ViewAuditLogEntry::route('/{record}'),
        ];
    }
}
