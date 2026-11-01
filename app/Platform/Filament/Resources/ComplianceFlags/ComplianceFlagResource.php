<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\ComplianceFlags;

use App\Enums\ComplianceFlagStatus;
use App\Enums\ComplianceFlagType;
use App\Platform\Compliance\FlagQueue;
use App\Platform\Compliance\ReviewFlag;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\ComplianceFlags\Pages\ListComplianceFlags;
use App\Platform\Filament\Resources\ComplianceFlags\Pages\ViewComplianceFlag;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Console → Compliance → Flags (P06-09; ONBOARDING_COMPLIANCE.md § 5.4–5.5,
 * PLATFORM_CONSOLE.md § 1A; docs/plans/P06-subtasks.md defaults 95 and
 * 99): the compliance flag queue across organizations. Opens on the flags
 * § 5.4 sends to compliance that are open or acknowledged, oldest first;
 * filters show every type and status. **Acknowledge**, **Assign to me**,
 * **Resolve** and **Dismiss** with a note (`compliance.flags`), **Extend
 * grace** on a yearly-limit flag (`compliance.restrict`). Changes go
 * through FlagQueue, which re-checks.
 */
final class ComplianceFlagResource extends Resource
{
    use ActsAsStaff;

    protected static ?string $model = ReviewFlag::class;

    protected static ?string $slug = 'compliance-flags';

    protected static ?string $modelLabel = 'compliance flag';

    protected static ?string $pluralModelLabel = 'compliance flags';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Flags';

    protected static ?int $navigationSort = 0;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['customer', 'assignedTo', 'resolvedBy']);
    }

    public static function getNavigationBadge(): ?string
    {
        $open = ReviewFlag::query()
            ->whereIn('type', array_map(static fn (ComplianceFlagType $type): string => $type->value, ComplianceFlagType::queue()))
            ->where('status', ComplianceFlagStatus::Open->value)
            ->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function amount(?int $kobo): string
    {
        return $kobo !== null ? Money::kobo($kobo)->format() : '—';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Raised')->dateTime('j M Y H:i')->sortable(),
                TextColumn::make('customer.name')->label('Organization')->searchable(),
                TextColumn::make('type')->badge()
                    ->formatStateUsing(fn (ComplianceFlagType $state): string => $state->label())
                    ->color(fn (ComplianceFlagType $state): string => $state->color()),
                TextColumn::make('metric_value_kobo')->label('Amount')
                    ->formatStateUsing(fn (?int $state): string => self::amount($state)),
                TextColumn::make('limit_kobo')->label('Limit or line')
                    ->formatStateUsing(fn (?int $state): string => self::amount($state)),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (ComplianceFlagStatus $state): string => $state->label())
                    ->color(fn (ComplianceFlagStatus $state): string => $state->color()),
                TextColumn::make('grace_ends_at')->label('Grace ends')->date('j M Y')->placeholder('—'),
                TextColumn::make('assignedTo.name')->label('Assigned to')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('queue')
                    ->label('Shown to')
                    ->options(['compliance' => 'Compliance queue', 'all' => 'Every flag'])
                    ->default('compliance')
                    ->query(fn (Builder $query, array $data): Builder => ($data['value'] ?? 'compliance') === 'compliance'
                        ? $query->whereIn('type', array_map(static fn (ComplianceFlagType $type): string => $type->value, ComplianceFlagType::queue()))
                        : $query),
                SelectFilter::make('status')
                    ->multiple()
                    ->options(collect(ComplianceFlagStatus::cases())->mapWithKeys(fn (ComplianceFlagStatus $status): array => [$status->value => $status->label()])->all())
                    ->default(ComplianceFlagStatus::openValues()),
                SelectFilter::make('type')
                    ->multiple()
                    ->options(collect(ComplianceFlagType::cases())->mapWithKeys(fn (ComplianceFlagType $type): array => [$type->value => $type->label()])->all()),
            ])
            ->recordActions([ViewAction::make(), self::acknowledgeAction(), self::assignAction()])
            ->defaultSort('created_at')
            ->paginated([25, 50, 100]);
    }

    public static function acknowledgeAction(): Action
    {
        return Action::make('acknowledge')
            ->label('Acknowledge')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->authorize(fn (ReviewFlag $record): bool => self::actor()->can('acknowledge', $record))
            ->action(function (ReviewFlag $record): void {
                self::attempt(function () use ($record): string {
                    app(FlagQueue::class)->acknowledge(self::actor(), $record);

                    return 'Acknowledged.';
                });
            });
    }

    public static function assignAction(): Action
    {
        return Action::make('assign')
            ->label('Assign to me')
            ->icon('heroicon-o-user')
            ->color('gray')
            ->authorize(fn (ReviewFlag $record): bool => self::actor()->can('assign', $record))
            ->action(function (ReviewFlag $record): void {
                self::attempt(function () use ($record): string {
                    app(FlagQueue::class)->assignToMe(self::actor(), $record);

                    return 'Assigned to you.';
                });
            });
    }

    public static function resolveAction(): Action
    {
        return self::closeAction('resolve', 'Resolve', 'heroicon-o-check-circle', 'success', 'What was found and done. Kept with the flag and in the audit log.');
    }

    public static function dismissAction(): Action
    {
        return self::closeAction('dismiss', 'Dismiss', 'heroicon-o-x-circle', 'gray', 'Why there is nothing to act on. Kept with the flag and in the audit log.');
    }

    public static function extendGraceAction(): Action
    {
        return Action::make('extendGrace')
            ->label('Extend grace')
            ->icon('heroicon-o-calendar-days')
            ->color('warning')
            ->authorize(fn (ReviewFlag $record): bool => self::actor()->can('extendGrace', $record))
            ->modalDescription('The organization is restricted only once the grace period ends without an upgrade application. A restriction it already caused is lifted.')
            ->fillForm(fn (ReviewFlag $record): array => ['until' => FlagQueue::firstGraceDay()->max($record->grace_ends_at?->setTimezone('Africa/Lagos')->addDays(30) ?? FlagQueue::firstGraceDay())->min(app(FlagQueue::class)->lastGraceDay())->toDateString()])
            ->schema([
                DatePicker::make('until')->label('Grace ends on')->required()->native(false)
                    ->minDate(fn (): string => FlagQueue::firstGraceDay()->toDateString())
                    ->maxDate(fn (): string => app(FlagQueue::class)->lastGraceDay()->toDateString())
                    ->helperText('The organization is restricted from the start of this day (Lagos) if no application has been sent.'),
                Textarea::make('reason')->required()->minLength(FlagQueue::NOTE_MIN)->maxLength(FlagQueue::NOTE_MAX)->rows(2)
                    ->helperText('Kept in the audit log; not sent to the customer.'),
            ])
            ->action(function (ReviewFlag $record, array $data): void {
                self::attempt(function () use ($record, $data): string {
                    $day = app(FlagQueue::class)->extendGrace(self::actor(), $record, (string) ($data['until'] ?? ''), (string) ($data['reason'] ?? ''));

                    return 'The grace period now ends on '.$day->format('j M Y').'.';
                });
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListComplianceFlags::route('/'),
            'view' => ViewComplianceFlag::route('/{record}'),
        ];
    }

    private static function closeAction(string $name, string $label, string $icon, string $color, string $help): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->authorize(fn (ReviewFlag $record): bool => self::actor()->can('resolve', $record))
            ->schema([Textarea::make('note')->required()->minLength(FlagQueue::NOTE_MIN)->maxLength(FlagQueue::NOTE_MAX)->rows(3)->helperText($help)])
            ->action(function (ReviewFlag $record, array $data) use ($name, $label): void {
                self::attempt(function () use ($record, $data, $name, $label): string {
                    $queue = app(FlagQueue::class);
                    $name === 'resolve'
                        ? $queue->resolve(self::actor(), $record, (string) ($data['note'] ?? ''))
                        : $queue->dismiss(self::actor(), $record, (string) ($data['note'] ?? ''));

                    return $label === 'Resolve' ? 'Resolved.' : 'Dismissed.';
                });
            });
    }
}
