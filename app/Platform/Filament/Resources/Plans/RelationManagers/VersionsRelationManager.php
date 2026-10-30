<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Plans\RelationManagers;

use App\Marketing\PlanCatalogue;
use App\Models\PlanChangeNotice;
use App\Models\PlanVersion;
use App\Models\SubscriptionPlan;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Plans\PlanEditor;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A plan's versions, newest first (P06-01a; BILLING_PAYMENTS.md § 1.3:
 * "every change is versioned"): from when, the prices, its state (in
 * effect, scheduled, earlier, cancelled), how many organizations were told,
 * who saved it and why. A scheduled version can be cancelled before its
 * date; nothing else is changed here.
 */
final class VersionsRelationManager extends RelationManager
{
    use ActsAsStaff;

    protected static string $relationship = 'versions';

    protected static ?string $title = 'Versions';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof SubscriptionPlan && self::actor()->can('view', $ownerRecord);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('version'),
                TextColumn::make('effective_from')->label('From')->date('j M Y'),
                TextColumn::make('state')->badge()->state(fn (PlanVersion $record): string => $this->state($record))
                    ->color(fn (string $state): string => match ($state) {
                        'In effect' => 'success',
                        'Scheduled' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('base_price_kobo')->label('Base')->formatStateUsing(fn (int $state): string => Money::kobo($state)->format()),
                TextColumn::make('included_units')->label('Units included')->numeric(),
                TextColumn::make('unit_price_kobo')->label('Extra unit')->placeholder('Custom')->formatStateUsing(fn (?int $state): string => $state === null ? 'Custom' : Money::kobo($state)->format()),
                TextColumn::make('told')->label('Customers told')
                    ->state(fn (PlanVersion $record): string => $record->notice_given_at === null ? '—' : number_format(PlanChangeNotice::query()->withoutGlobalScopes()->where('plan_version_id', $record->id)->count())),
                TextColumn::make('createdBy.name')->label('By')->placeholder('Launch'),
                TextColumn::make('reason')->wrap()->limit(80),
            ])
            ->defaultSort('version', 'desc')
            ->recordActions([
                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (PlanVersion $record): bool => $this->state($record) === 'Scheduled')
                    ->authorize(fn (): bool => self::actor()->can('update', $this->getOwnerRecord()))
                    ->modalDescription('The current terms stay. Customers who were told are not emailed again; their banner goes.')
                    ->schema([Textarea::make('reason')->required()->maxLength(500)->rows(2)])
                    ->action(function (PlanVersion $record, array $data): void {
                        self::attempt(function () use ($record, $data): string {
                            app(PlanEditor::class)->cancel(self::actor(), $record, (string) $data['reason']);

                            return "Version {$record->version} cancelled";
                        });
                    }),
            ])
            ->paginated([10, 25]);
    }

    private function state(PlanVersion $version): string
    {
        if ($version->cancelled_at !== null) {
            return 'Cancelled';
        }

        if ($version->effective_from->toDateString() > PlanCatalogue::day()) {
            return 'Scheduled';
        }

        return app(PlanCatalogue::class)->findById($version->plan_id)?->versionId === $version->id ? 'In effect' : 'Earlier';
    }
}
