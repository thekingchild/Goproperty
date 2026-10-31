<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\TierSettings;

use App\Compliance\TransactionTiers;
use App\Enums\ComplianceDocumentType;
use App\Enums\TierVersionStatus;
use App\Enums\TransactionTier;
use App\Models\TransactionTierVersion;
use App\Platform\Compliance\TierDraft;
use App\Platform\Compliance\TierSettings;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\TierSettings\Pages\ListTierSettings;
use App\Platform\Filament\Resources\TierSettings\Pages\ViewTierVersion;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Console → Compliance → Tier settings (P06-08; ONBOARDING_COMPLIANCE.md
 * § 5.2 "Editing the limits", PLATFORM_CONSOLE.md § 1A;
 * docs/plans/P06-subtasks.md defaults 72–81): every version of the
 * Transaction Tiers' terms with its state. **Propose a change** on the
 * version in force (`tiers.configure`) opens the tier's next version behind
 * an approval request that another staff member with
 * `tiers.approve_settings` decides in Staff → Approvals; **Cancel** a
 * scheduled version. Changes go through TierSettings, which re-checks.
 */
final class TierSettingsResource extends Resource
{
    use ActsAsStaff;

    protected static ?string $model = TransactionTierVersion::class;

    protected static ?string $slug = 'tier-settings';

    protected static ?string $modelLabel = 'tier version';

    protected static ?string $pluralModelLabel = 'Tier settings';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Tier settings';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['proposedBy', 'approvedBy', 'cancelledBy', 'approvalRequest']);
    }

    /** "In force", "Scheduled", "Waiting for approval", "Replaced", "Rejected", "Expired unapproved", "Cancelled". */
    public static function state(TransactionTierVersion $version): string
    {
        if ($version->status !== TierVersionStatus::Approved) {
            return $version->status->label();
        }

        if ($version->isScheduled()) {
            return 'Scheduled';
        }

        return self::inForceId($version->tier) === $version->id ? 'In force' : 'Replaced';
    }

    public static function stateColor(string $state): string
    {
        return match ($state) {
            'In force' => 'success',
            'Scheduled', 'Waiting for approval' => 'warning',
            'Rejected' => 'danger',
            default => 'gray',
        };
    }

    public static function inForceId(TransactionTier $tier): ?int
    {
        return app(TransactionTiers::class)->versionInForce($tier)?->id;
    }

    public static function amount(?int $kobo, string $none = 'No cap'): string
    {
        return TierDraft::amount($kobo, $none);
    }

    /**
     * @param  list<ComplianceDocumentType>  $types
     */
    public static function documents(array $types): string
    {
        return $types === [] ? '—' : implode(', ', array_map(static fn (ComplianceDocumentType $type): string => $type->label(), $types));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tier')->formatStateUsing(fn (TransactionTier $state): string => $state->label())->sortable(),
                TextColumn::make('version')->sortable(),
                TextColumn::make('name'),
                TextColumn::make('state')->badge()
                    ->state(fn (TransactionTierVersion $record): string => self::state($record))
                    ->color(fn (string $state): string => self::stateColor($state)),
                TextColumn::make('effective_from')->label('From')->date('j M Y'),
                TextColumn::make('annual_volume_limit_kobo')->label('Yearly limit')
                    ->state(fn (TransactionTierVersion $record): string => self::amount($record->annual_volume_limit_kobo)),
                TextColumn::make('single_payment_limit_kobo')->label('Single payment')
                    ->state(fn (TransactionTierVersion $record): string => self::amount($record->single_payment_limit_kobo)),
                TextColumn::make('review_threshold_kobo')->label('Reviewed above')
                    ->state(fn (TransactionTierVersion $record): string => self::amount($record->review_threshold_kobo, '—')),
                TextColumn::make('required_count')->label('Required documents')
                    ->state(fn (TransactionTierVersion $record): string => (string) count($record->requiredDocuments())),
                TextColumn::make('review_sla_days')->label('Review target')
                    ->state(fn (TransactionTierVersion $record): string => $record->review_sla_days === null ? 'Straight away' : TierDraft::days($record->review_sla_days)),
                TextColumn::make('proposedBy.name')->label('Proposed by')->placeholder('Launch'),
                TextColumn::make('approvedBy.name')->label('Approved by')->placeholder('—'),
                TextColumn::make('reason')->wrap()->limit(80)->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('tier')
                    ->options(collect(TransactionTier::cases())->mapWithKeys(fn (TransactionTier $tier): array => [$tier->value => $tier->label()])->all()),
                SelectFilter::make('status')
                    ->options(collect(TierVersionStatus::cases())->mapWithKeys(fn (TierVersionStatus $status): array => [$status->value => $status->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                self::proposeAction(),
                self::cancelAction(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('tier')->orderByDesc('version'))
            ->paginated([25, 50, 100]);
    }

    /** On the version in force: the tier's next version, filled with its current terms. */
    public static function proposeAction(): Action
    {
        return Action::make('propose')
            ->label('Propose a change')
            ->icon('heroicon-o-pencil-square')
            ->visible(fn (TransactionTierVersion $record): bool => self::inForceId($record->tier) === $record->id)
            ->authorize(fn (): bool => self::actor()->can('propose', TransactionTierVersion::class))
            ->modalHeading(fn (TransactionTierVersion $record): string => 'Propose new terms for '.$record->tier->label())
            ->modalDescription('The change waits for a second staff member who may approve tier settings (a super-admin). It applies from the date you choose once approved, or from the day of the approval if that is later. Lowering a limit restricts nobody at once.')
            ->modalWidth('4xl')
            ->fillForm(fn (TransactionTierVersion $record): array => [
                'name' => $record->name,
                'annual' => self::naira($record->annual_volume_limit_kobo),
                'single' => self::naira($record->single_payment_limit_kobo),
                'review' => self::naira($record->review_threshold_kobo),
                'required' => array_map(static fn (ComplianceDocumentType $type): string => $type->value, $record->requiredDocuments()),
                'optional' => array_map(static fn (ComplianceDocumentType $type): string => $type->value, $record->optionalDocuments()),
                'review_sla_days' => $record->review_sla_days,
                'effective_from' => TierSettings::firstDay()->toDateString(),
            ])
            ->schema(fn (TransactionTierVersion $record): array => self::draftFields($record->tier))
            ->action(function (TransactionTierVersion $record, array $data): void {
                $draft = self::draft($record->tier, $data);
                $warnings = app(TierSettings::class)->warnings($draft);

                $proposed = self::attempt(function () use ($draft, $data): string {
                    $version = app(TierSettings::class)->propose(self::actor(), $draft, (string) ($data['effective_from'] ?? ''), (string) ($data['reason'] ?? ''));

                    return "Version {$version->version} of {$version->tier->label()} is waiting for a second staff member's approval.";
                });

                if ($proposed && $warnings !== []) {
                    Notification::make()->title('Please note')->body(implode(' ', $warnings))->warning()->persistent()->send();
                }
            });
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->visible(fn (TransactionTierVersion $record): bool => $record->isScheduled())
            ->authorize(fn (TransactionTierVersion $record): bool => self::actor()->can('cancel', $record))
            ->requiresConfirmation()
            ->modalDescription('The terms in force stay. Nobody was told about this version, so nobody is told it is cancelled.')
            ->schema([Textarea::make('reason')->required()->minLength(TierSettings::REASON_MIN)->maxLength(TierSettings::REASON_MAX)->rows(2)->helperText('Kept in the audit log.')])
            ->action(function (TransactionTierVersion $record, array $data): void {
                self::attempt(function () use ($record, $data): string {
                    app(TierSettings::class)->cancel(self::actor(), $record, (string) ($data['reason'] ?? ''));

                    return "Version {$record->version} of {$record->tier->label()} is cancelled.";
                });
            });
    }

    /** @return list<Section|Textarea> */
    private static function draftFields(TransactionTier $tier): array
    {
        $documents = collect(ComplianceDocumentType::cases())->mapWithKeys(fn (ComplianceDocumentType $type): array => [$type->value => $type->label()])->all();
        $naira = fn (string $name, string $label, string $help): TextInput => TextInput::make($name)->label($label)->prefix('₦')
            ->integer()->minValue(1)->maxValue(intdiv(TierSettings::MAX_KOBO, 100))->helperText($help);

        $sections = [
            Section::make('Limits')->columns(3)->schema([
                TextInput::make('name')->required()->maxLength(TierSettings::NAME_MAX),
                $naira('annual', 'Yearly collection limit', 'Rolling 12 months. Empty for no cap.'),
                $naira('single', 'Single-payment limit', 'Empty for no cap.'),
                $naira('review', 'Review single payments above', 'Empty for none.'),
            ]),
        ];

        if ($tier !== TransactionTier::One) {
            $sections[] = Section::make('Application')->columns(2)->schema([
                CheckboxList::make('required')->label('Required documents')->options($documents)->required(),
                CheckboxList::make('optional')->label('Optional documents')->options($documents),
                TextInput::make('review_sla_days')->label('Review target (business days)')->integer()->minValue(1)->maxValue(TierSettings::MAX_REVIEW_DAYS)->required(),
            ]);
        }

        $sections[] = Section::make('When and why')->columns(2)->schema([
            DatePicker::make('effective_from')->label('Applies from')->required()->native(false)
                ->minDate(fn (): string => TierSettings::firstDay()->toDateString())
                ->maxDate(fn (): string => TierSettings::lastDay()->toDateString())
                ->helperText('A Lagos day, from today up to a year ahead.'),
            Textarea::make('reason')->required()->minLength(TierSettings::REASON_MIN)->maxLength(TierSettings::REASON_MAX)->rows(3)
                ->helperText('Shown to the approver and kept in the audit log.'),
        ]);

        return $sections;
    }

    /** @param  array<string, mixed>  $data */
    private static function draft(TransactionTier $tier, array $data): TierDraft
    {
        $kobo = static function (mixed $naira): ?int {
            return is_numeric($naira) ? Money::naira((int) $naira)->kobo : null;
        };
        $types = static function (mixed $values): array {
            $types = [];

            foreach (is_array($values) ? $values : [] as $value) {
                $type = is_string($value) ? ComplianceDocumentType::tryFrom($value) : null;

                if ($type !== null) {
                    $types[] = $type;
                }
            }

            return $types;
        };
        $days = $data['review_sla_days'] ?? null;

        return new TierDraft(
            $tier,
            (string) ($data['name'] ?? ''),
            $kobo($data['annual'] ?? null),
            $kobo($data['single'] ?? null),
            $kobo($data['review'] ?? null),
            $tier === TransactionTier::One ? [] : $types($data['required'] ?? []),
            $tier === TransactionTier::One ? [] : $types($data['optional'] ?? []),
            $tier === TransactionTier::One || ! is_numeric($days) ? null : (int) $days,
        );
    }

    private static function naira(?int $kobo): ?int
    {
        return $kobo === null ? null : intdiv($kobo, 100);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTierSettings::route('/'),
            'view' => ViewTierVersion::route('/{record}'),
        ];
    }
}
