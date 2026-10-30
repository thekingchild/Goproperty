<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Plans;

use App\Marketing\Plan;
use App\Marketing\PlanCatalogue;
use App\Models\SubscriptionPlan;
use App\Platform\Filament\Resources\Plans\Pages\EditPlan;
use App\Platform\Filament\Resources\Plans\Pages\ListPlans;
use App\Platform\Filament\Resources\Plans\RelationManagers\VersionsRelationManager;
use App\Platform\Plans\PlanEditor;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Console → Billing → Plans (P06-01a; BILLING_PAYMENTS.md § 1,
 * PLATFORM_CONSOLE.md § 1A; `plans.manage`, SubscriptionPlanPolicy): each
 * plan with today's prices, its customers and any scheduled change. The
 * plan page edits the details at once and schedules new terms (prices,
 * units, features, limits) as a version with at least 30 days' notice when
 * customers are on it (App\Platform\Plans\PlanEditor). Plans are never
 * deleted: stop offering one instead.
 */
final class PlanResource extends Resource
{
    protected static ?string $model = SubscriptionPlan::class;

    protected static ?string $slug = 'plans';

    protected static ?string $modelLabel = 'plan';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Plans & prices';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->description(fn (SubscriptionPlan $record): string => $record->key),
                TextColumn::make('price')->label('Today')->state(fn (SubscriptionPlan $record): string => self::priceLine(self::today($record))),
                TextColumn::make('customers')->label('Customers')->state(fn (SubscriptionPlan $record): int => app(PlanEditor::class)->customers($record->id)),
                TextColumn::make('scheduled')->label('Scheduled change')->placeholder('None')
                    ->state(fn (SubscriptionPlan $record): ?string => app(PlanCatalogue::class)->scheduled($record->id)?->effective_from->format('j M Y')),
                IconColumn::make('is_active')->label('Offered')->boolean(),
            ])
            ->recordActions([EditAction::make()->label('Open')])
            ->defaultSort('sort_order')
            ->paginated(false);
    }

    public static function today(SubscriptionPlan $plan): ?Plan
    {
        return app(PlanCatalogue::class)->findById($plan->id);
    }

    public static function priceLine(?Plan $plan): string
    {
        if ($plan === null) {
            return 'No terms in effect';
        }

        $extra = $plan->extraUnit !== null ? ' + '.$plan->extraUnit->format().' each extra unit' : ' + custom';

        return ($plan->custom ? 'from ' : '').$plan->base->format().' a month, '.number_format($plan->includedUnits).' units'.$extra;
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
            'edit' => EditPlan::route('/{record}/edit'),
        ];
    }
}
