<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\DataBreaches;

use App\Models\DataBreach;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Concerns\BadgeBeforeMigrations;
use App\Platform\Filament\Resources\DataBreaches\Pages\ListDataBreaches;
use App\Privacy\Breaches\BreachRegister;
use App\Privacy\Breaches\BreachRisk;
use App\Privacy\Breaches\BreachStatus;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Console → Compliance → Breach register (P09-02; NDPA 2023 s. 40;
 * docs/runbooks/breach-response.md): every personal data breach from the
 * moment GoProperty became aware of it, with the 72-hour deadline for the
 * Nigeria Data Protection Commission (red once passed without a notice or
 * a recorded reason for none), containment, each notice and the close.
 * Entries are never deleted. DataBreachPolicy (`privacy.breaches`).
 */
final class DataBreachResource extends Resource
{
    use ActsAsStaff, BadgeBeforeMigrations;

    protected static ?string $model = DataBreach::class;

    protected static ?string $slug = 'breaches';

    protected static ?string $modelLabel = 'breach';

    protected static ?string $pluralModelLabel = 'Breach register';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Breach register';

    protected static ?int $navigationSort = 31;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    public static function getNavigationBadge(): ?string
    {
        return self::countBadge(fn (): int => DataBreach::query()->whereNull('commission_notified_at')->whereNull('not_notified_reason')->where('status', '!=', BreachStatus::Closed)->count());
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    /** @return list<mixed> */
    public static function detailsForm(): array
    {
        return [
            TextInput::make('title')->required()->maxLength(160),
            Textarea::make('description')->label('What happened')->required()->maxLength(5000)->rows(4),
            DateTimePicker::make('aware_at')->label('GoProperty became aware at (Lagos time)')->seconds(false)->timezone('Africa/Lagos')->maxDate(now())
                ->helperText('The 72 hours to tell the Nigeria Data Protection Commission run from here.'),
            DateTimePicker::make('occurred_at')->label('It happened at (if known)')->seconds(false)->timezone('Africa/Lagos'),
            Select::make('risk')->label('Risk to people')->required()->default(BreachRisk::Risk->value)
                ->options(collect(BreachRisk::cases())->mapWithKeys(fn (BreachRisk $risk): array => [$risk->value => $risk->label()])->all()),
            Textarea::make('data_involved')->label('Personal data involved')->maxLength(5000)->rows(2)->helperText('Kinds of data, e.g. tenants\' names and phone numbers. Never the data itself.'),
            TextInput::make('people_affected')->label('People affected (about)')->integer()->minValue(0),
            Textarea::make('organizations_affected')->label('Customers affected')->maxLength(1000)->rows(2)->helperText('By organization number, e.g. #12, #40.'),
            Textarea::make('likely_consequences')->label('Likely consequences')->maxLength(5000)->rows(2),
            Textarea::make('measures_taken')->label('Measures taken or proposed')->maxLength(5000)->rows(2),
        ];
    }

    public static function table(Table $table): Table
    {
        $register = fn (): BreachRegister => app(BreachRegister::class);
        $when = fn (string $name, string $label): DateTimePicker => DateTimePicker::make($name)->label($label)->seconds(false)->timezone('Africa/Lagos')->default(now())->maxDate(now())->required();
        $can = fn (DataBreach $record): bool => self::actor()->can('update', $record);

        return $table
            ->columns([
                TextColumn::make('reference')->label('Ref.')->searchable(),
                TextColumn::make('title')->wrap()->searchable()->description(fn (DataBreach $record): string => $record->risk->label()),
                TextColumn::make('aware_at')->label('Aware since')->dateTime('j M Y H:i', 'Africa/Lagos')->sortable(),
                TextColumn::make('deadline')->label('Commission (72 h)')
                    ->state(fn (DataBreach $record): string => match (true) {
                        $record->commission_notified_at !== null => 'Told '.$record->commission_notified_at->timezone('Africa/Lagos')->format('j M H:i'),
                        $record->not_notified_reason !== null => 'Not told: reason recorded',
                        $record->isOverdue() => 'Overdue since '.$record->noticeDeadline()->timezone('Africa/Lagos')->format('j M H:i'),
                        $record->status === BreachStatus::Closed => '—',
                        default => 'Due by '.$record->noticeDeadline()->timezone('Africa/Lagos')->format('j M H:i').' ('.$record->noticeDeadline()->diffForHumans(['parts' => 2]).')',
                    })
                    ->badge()
                    ->color(fn (DataBreach $record): string => match (true) {
                        $record->commission_notified_at !== null, $record->not_notified_reason !== null => 'success',
                        $record->isOverdue() => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('people_affected')->label('People')->numeric()->placeholder('—'),
                TextColumn::make('notices')->label('Others told')
                    ->state(fn (DataBreach $record): string => implode(' · ', array_filter([
                        $record->customers_notified_at !== null ? 'Customers '.$record->customers_notified_at->timezone('Africa/Lagos')->format('j M') : null,
                        $record->people_notified_at !== null ? 'People '.$record->people_notified_at->timezone('Africa/Lagos')->format('j M') : null,
                    ])) ?: '—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (BreachStatus $state): string => $state->label())->color(fn (BreachStatus $state): string => $state->color()),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(BreachStatus::cases())->mapWithKeys(fn (BreachStatus $status): array => [$status->value => $status->label()])->all()),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('edit')->label('Change details')->icon(Heroicon::OutlinedPencilSquare)->authorize($can)
                        ->fillForm(fn (DataBreach $record): array => $record->only(['title', 'description', 'aware_at', 'occurred_at', 'data_involved', 'people_affected', 'organizations_affected', 'likely_consequences', 'measures_taken']) + ['risk' => $record->risk->value])
                        ->schema(self::detailsForm())
                        ->action(fn (DataBreach $record, array $data): bool => self::attempt(function () use ($record, $data, $register): string {
                            $register()->update($record, self::actor(), $data);

                            return 'Saved.';
                        })),
                    Action::make('contained')->label('Contained')->icon(Heroicon::OutlinedLockClosed)->authorize(fn (DataBreach $record): bool => $can($record) && $record->contained_at === null)
                        ->schema([$when('at', 'Contained at')])
                        ->action(fn (DataBreach $record, array $data): bool => self::attempt(function () use ($record, $data, $register): string {
                            $register()->contained($record, self::actor(), $data['at'] ?? null);

                            return 'Recorded as contained.';
                        })),
                    Action::make('commission')->label('Commission told')->icon(Heroicon::OutlinedBuildingLibrary)->authorize(fn (DataBreach $record): bool => $can($record) && $record->commission_notified_at === null)
                        ->schema([$when('at', 'Told at'), TextInput::make('reference')->label('The Commission\'s reference')->maxLength(100)])
                        ->action(fn (DataBreach $record, array $data): bool => self::attempt(function () use ($record, $data, $register): string {
                            $register()->commissionNotified($record, self::actor(), $data['at'] ?? null, $data['reference'] ?? null);

                            return 'Recorded: the Commission was told.';
                        })),
                    Action::make('customers')->label('Customers told')->icon(Heroicon::OutlinedBuildingOffice2)->authorize(fn (DataBreach $record): bool => $can($record) && $record->customers_notified_at === null)
                        ->schema([$when('at', 'Told at')])
                        ->action(fn (DataBreach $record, array $data): bool => self::attempt(function () use ($record, $data, $register): string {
                            $register()->customersNotified($record, self::actor(), $data['at'] ?? null);

                            return 'Recorded: the customers were told.';
                        })),
                    Action::make('people')->label('People told')->icon(Heroicon::OutlinedUsers)->authorize(fn (DataBreach $record): bool => $can($record) && $record->people_notified_at === null)
                        ->schema([$when('at', 'Told at')])
                        ->action(fn (DataBreach $record, array $data): bool => self::attempt(function () use ($record, $data, $register): string {
                            $register()->peopleNotified($record, self::actor(), $data['at'] ?? null);

                            return 'Recorded: the people affected were told.';
                        })),
                    Action::make('noNotice')->label('No notice needed')->icon(Heroicon::OutlinedNoSymbol)->authorize(fn (DataBreach $record): bool => $can($record) && $record->commission_notified_at === null)
                        ->schema([Textarea::make('reason')->label('Why no notice is needed')->required()->minLength(10)->maxLength(1000)->helperText('E.g. the data was encrypted and the key was not exposed. Kept on the register.')])
                        ->action(fn (DataBreach $record, array $data): bool => self::attempt(function () use ($record, $data, $register): string {
                            $register()->notNotifying($record, self::actor(), (string) $data['reason']);

                            return 'Recorded.';
                        })),
                    Action::make('close')->label('Close')->icon(Heroicon::OutlinedCheckCircle)->color('gray')->authorize($can)
                        ->schema([Textarea::make('note')->label('What was learned and changed')->required()->minLength(10)->maxLength(1000)])
                        ->action(fn (DataBreach $record, array $data): bool => self::attempt(function () use ($record, $data, $register): string {
                            $register()->close($record, self::actor(), (string) $data['note']);

                            return 'Closed.';
                        })),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListDataBreaches::route('/')];
    }
}
