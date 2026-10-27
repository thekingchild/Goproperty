<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\DocumentExpiry;

use App\Enums\ComplianceDocumentStatus;
use App\Enums\ComplianceDocumentType;
use App\Enums\TierApplicationStatus;
use App\Platform\Compliance\ReviewDocument;
use App\Platform\Filament\Resources\DocumentExpiry\Pages\ListDocumentExpiry;
use App\Platform\Filament\Resources\TierApplications\TierApplicationResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Console → Compliance → Document expiry (ONBOARDING_COMPLIANCE.md § 5.3–5.4,
 * `document_expiring` / `document_expired`): Transaction Tier documents of
 * open or approved applications that expire within the warning window or
 * have expired, soonest first, flagged daily by DocumentExpiryCheck.
 * `tiers.review` (ReviewDocumentPolicy); read-only.
 */
final class DocumentExpiryResource extends Resource
{
    protected static ?string $model = ReviewDocument::class;

    protected static ?string $slug = 'document-expiry';

    protected static ?string $modelLabel = 'expiring document';

    protected static ?string $pluralModelLabel = 'expiring documents';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Document expiry';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    public static function getEloquentQuery(): Builder
    {
        $applicationStatuses = [...array_map(static fn (TierApplicationStatus $status): string => $status->value, TierApplicationStatus::open()), TierApplicationStatus::Approved->value];

        return parent::getEloquentQuery()
            ->with(['customer', 'application'])
            ->whereIn('status', [ComplianceDocumentStatus::Pending->value, ComplianceDocumentStatus::Accepted->value, ComplianceDocumentStatus::Expired->value])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(TierApplicationResource::warningDays())->toDateString())
            ->whereHas('application', fn (Builder $query) => $query->whereIn('status', $applicationStatuses));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.name')->label('Organization')->searchable(),
                TextColumn::make('type')->formatStateUsing(fn (ComplianceDocumentType $state): string => $state->label()),
                TextColumn::make('expires_at')->label('Expires')->date()->sortable()
                    ->description(fn (ReviewDocument $record): string => $record->expires_at !== null && $record->expires_at->lessThan(now()->startOfDay()) ? 'Expired' : 'Expires '.$record->expires_at?->diffForHumans()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ComplianceDocumentStatus $state): string => $state->label())
                    ->color(fn (ComplianceDocumentStatus $state): string => $state === ComplianceDocumentStatus::Expired ? 'danger' : 'warning'),
                TextColumn::make('application.status')
                    ->label('Application')
                    ->formatStateUsing(fn (TierApplicationStatus $state): string => $state->label()),
            ])
            ->filters([
                SelectFilter::make('stage')
                    ->options(['expired' => 'Expired', 'expiring' => 'Expiring soon'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'expired' => $query->where('expires_at', '<', now()->toDateString()),
                        'expiring' => $query->where('expires_at', '>=', now()->toDateString()),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('application')
                    ->label('Open application')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (ReviewDocument $record): ?string => $record->tier_application_id !== null ? TierApplicationResource::getUrl('view', ['record' => $record->tier_application_id]) : null),
            ])
            ->defaultSort('expires_at')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDocumentExpiry::route('/')];
    }
}
