<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Customers\RelationManagers;

use App\Models\PlatformStaff;
use App\Plans\Feature;
use App\Platform\Customers\Customer;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Plans\FeatureOverrides;
use App\Platform\Plans\FeatureStanding;
use App\Platform\Staff\StaffPermission;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The organization's plan features (P06-07a; docs/plans/P06-subtasks.md
 * default 53): every feature but `sms_credits` (the page's SMS allowance
 * sets it) with the plan's value, the override in force (value, reason,
 * who, until) and what applies. Seen with `customers.view`; **Set
 * override** and **End override** need `customers.override_features`
 * (CustomerPolicy::overrideFeatures) and go through FeatureOverrides,
 * which re-checks and audits. The rows are worked out, not a query
 * (Filament custom data), keyed by the feature.
 */
final class FeaturesRelationManager extends RelationManager
{
    use ActsAsStaff;

    protected static string $relationship = 'featureOverrides';

    protected static ?string $title = 'Plan features';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $staff = Filament::auth()->user();

        return $staff instanceof PlatformStaff && $staff->holdsStaffPermission(StaffPermission::CustomersView);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    private function customer(): Customer
    {
        $customer = $this->getOwnerRecord();

        if (! $customer instanceof Customer) {
            throw new AuthorizationException;
        }

        return $customer;
    }

    private function mayOverride(): bool
    {
        return Gate::forUser(self::actor())->allows('overrideFeatures', $this->customer());
    }

    /** @param  array<string, mixed>  $record */
    private static function feature(array $record): Feature
    {
        return Feature::from(is_string($record['key'] ?? null) ? $record['key'] : '');
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => collect(app(FeatureOverrides::class)->standings($this->customer()->organization()))
                ->mapWithKeys(fn (FeatureStanding $standing): array => [$standing->feature->value => $standing->toRow()])
                ->all())
            ->columns([
                TextColumn::make('feature')->label('Feature')->wrap(),
                TextColumn::make('plan')->label('Plan'),
                TextColumn::make('override')
                    ->label('Override in force')
                    ->placeholder('None')
                    ->color('warning')
                    ->description(fn (array $record): ?string => self::overrideDetails($record))
                    ->wrap(),
                TextColumn::make('applies')->label('Applies')->weight('bold'),
            ])
            // Rows are arrays: no click-through to a view or edit page.
            ->recordAction(null)
            ->recordUrl(null)
            ->recordActions([$this->setOverrideAction(), $this->endOverrideAction()])
            ->paginated(false);
    }

    /** @param  array<string, mixed>  $record */
    private static function overrideDetails(array $record): ?string
    {
        if (! is_string($record['override'] ?? null)) {
            return null;
        }

        $until = $record['until'] ?? null;
        $by = is_string($record['granted_by'] ?? null) ? $record['granted_by'] : 'a staff member';

        return (is_string($record['reason'] ?? null) ? $record['reason'] : '')
            .' — by '.$by
            .($until instanceof CarbonImmutable ? ', until '.$until->setTimezone('Africa/Lagos')->format('j M Y') : ', no end');
    }

    public function setOverrideAction(): Action
    {
        return Action::make('setOverride')
            ->label('Set override')
            ->icon('heroicon-o-adjustments-horizontal')
            ->authorize(fn (): bool => $this->mayOverride())
            ->modalHeading(fn (array $record): string => 'Override '.self::feature($record)->label().' for '.$this->customer()->name)
            ->modalDescription('Wins over the plan until it ends, and replaces any override of this feature. The customer is not told.')
            ->schema(fn (array $record): array => [
                ...(self::feature($record)->isLimit()
                    ? [TextInput::make('limit')->label('Limit')->integer()->minValue(0)->maxValue(FeatureOverrides::LIMIT_MAX)
                        ->helperText('Leave empty for unlimited.')]
                    : [Radio::make('enabled')->label('Feature')->options(['1' => 'Switched on', '0' => 'Switched off'])->required()]),
                Textarea::make('reason')->label('Reason')->required()
                    ->minLength(FeatureOverrides::REASON_MIN)->maxLength(FeatureOverrides::REASON_MAX)
                    ->helperText('Shown here with the override and kept in the audit log with your name.'),
                DatePicker::make('until')->label('Ends on (optional)')
                    ->minDate(CarbonImmutable::tomorrow('Africa/Lagos'))
                    ->helperText('The plan applies again from this day (Lagos time). Leave empty for no end.'),
            ])
            ->action(function (array $record, array $data): void {
                $feature = self::feature($record);
                $limit = $data['limit'] ?? null;
                $until = $data['until'] ?? null;

                self::attempt(function () use ($feature, $data, $limit, $until): string {
                    app(FeatureOverrides::class)->set(
                        self::actor(),
                        $this->customer()->organization(),
                        $feature,
                        $feature->isLimit() ? null : in_array($data['enabled'] ?? null, ['1', 1, true], true),
                        is_numeric($limit) ? (int) $limit : null,
                        (string) ($data['reason'] ?? ''),
                        is_string($until) && $until !== '' ? CarbonImmutable::parse($until, 'Africa/Lagos')->startOfDay() : null,
                    );

                    return $feature->label().' is overridden for '.$this->customer()->name.'.';
                });
                $this->flushCachedTableRecords();
            });
    }

    public function endOverrideAction(): Action
    {
        return Action::make('endOverride')
            ->label('End override')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->authorize(fn (): bool => $this->mayOverride())
            ->visible(fn (array $record): bool => is_string($record['override'] ?? null))
            ->requiresConfirmation()
            ->modalHeading(fn (array $record): string => 'End the override of '.self::feature($record)->label().'?')
            ->modalDescription('The plan\'s value applies again at once. The override is kept in the history.')
            ->schema([
                Textarea::make('reason')->label('Reason (optional)')->maxLength(FeatureOverrides::REASON_MAX),
            ])
            ->action(function (array $record, array $data): void {
                $feature = self::feature($record);

                self::attempt(function () use ($feature, $data): string {
                    app(FeatureOverrides::class)->end(self::actor(), $this->customer()->organization(), $feature, isset($data['reason']) ? (string) $data['reason'] : null);

                    return 'The override of '.$feature->label().' has ended: the plan applies again.';
                });
                $this->flushCachedTableRecords();
            });
    }
}
