<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\TierApplications;

use App\Enums\TierApplicationStatus;
use App\Enums\TransactionTier;
use App\Platform\Compliance\ReviewApplication;
use App\Platform\Filament\Resources\TierApplications\Pages\ListTierApplications;
use App\Platform\Filament\Resources\TierApplications\Pages\ViewTierApplication;
use App\Platform\Filament\Resources\TierApplications\RelationManagers\DocumentsRelationManager;
use App\Platform\Filament\Resources\TierApplications\RelationManagers\HistoryRelationManager;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Console → Compliance → Tier applications (PLATFORM_CONSOLE.md § 1,
 * ONBOARDING_COMPLIANCE.md § 5.3): the review queue, oldest submission
 * first, and each application's page with its documents (opened through
 * signed, audited links), history and the review actions. Reads across
 * organizations through the ReviewApplication read model; every page and
 * action is checked by TierReviewPolicy / ReviewDocumentPolicy, and
 * TierReview re-checks.
 */
final class TierApplicationResource extends Resource
{
    protected static ?string $model = ReviewApplication::class;

    protected static ?string $slug = 'tier-applications';

    protected static ?string $modelLabel = 'tier application';

    protected static ?string $pluralModelLabel = 'tier applications';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Tier applications';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    public static function statusColor(TierApplicationStatus $status): string
    {
        return match ($status) {
            TierApplicationStatus::Approved => 'success',
            TierApplicationStatus::Rejected => 'danger',
            TierApplicationStatus::MoreInfoNeeded => 'warning',
            TierApplicationStatus::Draft => 'gray',
            TierApplicationStatus::Submitted, TierApplicationStatus::UnderReview => 'info',
        };
    }

    /** Waiting for compliance: the navigation badge. */
    public static function getNavigationBadge(): ?string
    {
        if (! self::canAccess()) {
            return null;
        }

        $waiting = ReviewApplication::query()->whereIn('status', [TierApplicationStatus::Submitted->value, TierApplicationStatus::UnderReview->value])->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'reviewer'])->withCount([
                'documents as current_documents_count' => fn (Builder $documents) => $documents->whereIn('status', ['pending', 'accepted']),
                'documents as expiring_documents_count' => fn (Builder $documents) => $documents
                    ->whereIn('status', ['pending', 'accepted', 'expired'])
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', now()->addDays(self::warningDays())->toDateString()),
            ]))
            ->columns([
                TextColumn::make('customer.name')
                    ->label('Organization')
                    ->description(fn (ReviewApplication $record): string => '/o/'.$record->customer->slug)
                    ->searchable(),
                TextColumn::make('requested_tier')
                    ->label('Tier')
                    ->formatStateUsing(fn (ReviewApplication $record): string => $record->from_tier->label().' → '.$record->requested_tier->label()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (TierApplicationStatus $state): string => $state->label())
                    ->color(fn (TierApplicationStatus $state): string => self::statusColor($state)),
                TextColumn::make('submitted_at')->label('Submitted')->since()->placeholder('Not yet')->sortable(),
                TextColumn::make('reviewer.name')->label('Reviewer')->placeholder('—'),
                TextColumn::make('current_documents_count')->label('Documents')->numeric(),
                TextColumn::make('expiring_documents_count')
                    ->label('Expiring')
                    ->numeric()
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->multiple()
                    ->options(collect(TierApplicationStatus::cases())->mapWithKeys(fn (TierApplicationStatus $status): array => [$status->value => $status->label()])->all())
                    ->default([TierApplicationStatus::Submitted->value, TierApplicationStatus::UnderReview->value, TierApplicationStatus::MoreInfoNeeded->value]),
                SelectFilter::make('requested_tier')
                    ->label('Tier asked for')
                    ->options([TransactionTier::Two->value => TransactionTier::Two->label(), TransactionTier::Three->value => TransactionTier::Three->label()]),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('submitted_at')
            ->paginated([25, 50, 100]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Application')
                ->columns(3)
                ->schema([
                    TextEntry::make('customer.name')->label('Organization'),
                    TextEntry::make('customer.slug')->label('Workspace')->formatStateUsing(fn (string $state): string => '/o/'.$state),
                    TextEntry::make('customer.transaction_tier')->label('Approved tier now')->formatStateUsing(fn (TransactionTier $state): string => $state->label()),
                    TextEntry::make('requested_tier')->label('Asks for')->formatStateUsing(fn (TransactionTier $state): string => $state->label()),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (TierApplicationStatus $state): string => $state->label())
                        ->color(fn (TierApplicationStatus $state): string => self::statusColor($state)),
                    TextEntry::make('declared_annual_volume_kobo')
                        ->label('Expected yearly collections')
                        ->formatStateUsing(fn (?Money $state): string => $state?->format() ?? '—')
                        ->placeholder('—'),
                    TextEntry::make('submitted_at')->label('Submitted')->dateTime()->placeholder('Not yet'),
                    TextEntry::make('reviewer.name')->label('Reviewer')->placeholder('—'),
                    TextEntry::make('decided_at')->label('Decided')->dateTime()->placeholder('—'),
                    TextEntry::make('applicant_note')->label('The organization\'s answer')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('review_note')->label('Note to the organization')->placeholder('—')->columnSpanFull(),
                ]),
            Section::make('Compliance contact')
                ->columns(3)
                ->visible(fn (ReviewApplication $record): bool => $record->requested_tier === TransactionTier::Three)
                ->schema([
                    TextEntry::make('compliance_contact.name')->label('Name')->placeholder('—'),
                    TextEntry::make('compliance_contact.email')->label('Email')->placeholder('—'),
                    TextEntry::make('compliance_contact.phone')->label('Phone')->placeholder('—'),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [DocumentsRelationManager::class, HistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTierApplications::route('/'),
            'view' => ViewTierApplication::route('/{record}'),
        ];
    }

    public static function warningDays(): int
    {
        $days = config('compliance.expiry_warning_days', 30);

        return is_numeric($days) ? (int) $days : 30;
    }
}
