<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Handovers;

use App\Platform\Filament\Resources\Customers\CustomerResource;
use App\Platform\Filament\Resources\Handovers\Pages\ListHandovers;
use App\Platform\Handovers\HandoverOverview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Console → Customers → Handovers (BILLING_PAYMENTS.md § 7.7 rule 3):
 * management handovers waiting for the agency's answer and those ending
 * after notice, across organizations, for support: who offered what to
 * whom, how many buildings and units, and the dates. `customers.view`
 * (HandoverOverviewPolicy); read-only.
 */
final class HandoverResource extends Resource
{
    protected static ?string $model = HandoverOverview::class;

    protected static ?string $slug = 'handovers';

    protected static ?string $modelLabel = 'handover';

    protected static ?string $pluralModelLabel = 'handovers';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    protected static ?string $navigationLabel = 'Handovers';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    public static function getEloquentQuery(): Builder
    {
        $buildings = DB::table('management_handover_buildings')->whereColumn('management_handover_buildings.management_handover_id', 'management_handovers.id');

        return parent::getEloquentQuery()
            ->with(['landlord', 'agency'])
            ->select('management_handovers.*')
            ->selectSub((clone $buildings)->selectRaw('count(*)'), 'buildings_count')
            ->selectSub((clone $buildings)->selectRaw('coalesce(sum(unit_count), 0)'), 'units_count')
            ->whereIn('management_handovers.status', array_keys(HandoverOverview::OPEN));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // The offering organization is the landlord's at a management, the agency's at a one-way return (BILLING_PAYMENTS.md § 7.1).
                TextColumn::make('landlord.name')->label('Offered by')->searchable(),
                TextColumn::make('agency.name')->label('Offered to')->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (HandoverOverview $record): string => $record->statusLabel())
                    ->color(fn (string $state): string => $state === 'ending' ? 'warning' : 'info'),
                TextColumn::make('buildings_count')->label('Buildings'),
                TextColumn::make('units_count')->label('Units'),
                TextColumn::make('created_at')->label('Offered')->date()->sortable(),
                TextColumn::make('expires_at')->label('Offer expires')->date()->placeholder('—'),
                TextColumn::make('ends_at')->label('Ends')->dateTime()->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(HandoverOverview::OPEN),
            ])
            ->recordActions([
                Action::make('landlord')
                    ->label('Offered by')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (HandoverOverview $record): string => CustomerResource::getUrl('view', ['record' => $record->organization_id])),
                Action::make('agency')
                    ->label('Offered to')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (HandoverOverview $record): string => CustomerResource::getUrl('view', ['record' => $record->to_organization_id])),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ListHandovers::route('/')];
    }
}
