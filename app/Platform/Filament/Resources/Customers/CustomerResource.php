<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Customers;

use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\TransactionTier;
use App\Platform\Customers\Customer;
use App\Platform\Customers\CustomerSmsCredits;
use App\Platform\Filament\Resources\Customers\Pages\ListCustomers;
use App\Platform\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Platform\Filament\Resources\Customers\RelationManagers\HistoryRelationManager;
use App\Platform\Filament\Resources\Customers\RelationManagers\MembersRelationManager;
use App\Sms\Credits\SmsCreditSummary;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
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
 * Console → Customers → Organizations (PLATFORM_CONSOLE.md § 1, ARCHITECTURE.md
 * § 6.9): every organization with its type, status, Transaction Tier,
 * members, owner and dates; search, filters and server-side pagination. Its
 * page lists the members and their roles there, the history, and Suspend /
 * Reactivate, and its SMS credits (P05-03: Adjust SMS credits, SMS
 * allowance). Reads across organizations through the Customer read model;
 * every page and action is checked by CustomerPolicy.
 */
final class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $slug = 'customers';

    protected static ?string $modelLabel = 'organization';

    protected static ?string $pluralModelLabel = 'organizations';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    protected static ?string $navigationLabel = 'Organizations';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    public static function statusColor(OrganizationStatus $status): string
    {
        return match ($status) {
            OrganizationStatus::Active => 'success',
            OrganizationStatus::Onboarding => 'info',
            OrganizationStatus::Restricted => 'warning',
            OrganizationStatus::Suspended => 'danger',
            OrganizationStatus::Cancelled => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select('organizations.*')
                ->withCount('members')
                ->addSelect([
                    'owner_name' => Customer::ownerColumn('name'),
                    'owner_email' => Customer::ownerColumn('email'),
                ]))
            ->columns([
                TextColumn::make('name')
                    ->label('Organization')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $inner) => $inner
                        ->where('organizations.name', 'like', '%'.addcslashes($search, '%_\\').'%')
                        ->orWhere('organizations.slug', 'like', '%'.addcslashes($search, '%_\\').'%')
                        ->orWhereIn('organizations.id', Customer::ownedBy($search))))
                    ->sortable()
                    ->description(fn (Customer $record): string => '/o/'.$record->slug),
                TextColumn::make('type')
                    ->formatStateUsing(fn (OrganizationType $state): string => $state->label()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (OrganizationStatus $state): string => $state->label())
                    ->color(fn (OrganizationStatus $state): string => self::statusColor($state)),
                TextColumn::make('transaction_tier')
                    ->label('Tier')
                    ->formatStateUsing(fn (TransactionTier $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('members_count')->label('Members')->numeric()->sortable(),
                TextColumn::make('owner_name')
                    ->label('Owner')
                    ->description(fn (Customer $record): ?string => is_string($record->getAttribute('owner_email')) ? $record->getAttribute('owner_email') : null)
                    ->placeholder('No owner'),
                TextColumn::make('created_at')->label('Created')->date()->sortable(),
                TextColumn::make('trial_ends_at')->label('Trial ends')->date()->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->multiple()
                    ->options(collect(OrganizationStatus::cases())->mapWithKeys(fn (OrganizationStatus $status): array => [$status->value => $status->label()])->all()),
                SelectFilter::make('type')
                    ->options(collect(OrganizationType::cases())->mapWithKeys(fn (OrganizationType $type): array => [$type->value => $type->label()])->all()),
                SelectFilter::make('transaction_tier')
                    ->label('Tier')
                    ->options(collect(TransactionTier::cases())->mapWithKeys(fn (TransactionTier $tier): array => [$tier->value => $tier->label()])->all()),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Organization')
                ->columns(3)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('slug')->label('Workspace')->formatStateUsing(fn (string $state): string => '/o/'.$state),
                    TextEntry::make('type')->formatStateUsing(fn (OrganizationType $state): string => $state->label()),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (OrganizationStatus $state): string => $state->label())
                        ->color(fn (OrganizationStatus $state): string => self::statusColor($state)),
                    TextEntry::make('suspended_at')->label('Suspended since')->dateTime()->visible(fn (Customer $record): bool => $record->suspended_at !== null),
                    TextEntry::make('status_before_suspension')
                        ->label('Reactivation returns to')
                        ->formatStateUsing(fn (OrganizationStatus $state): string => $state->label())
                        ->visible(fn (Customer $record): bool => $record->status_before_suspension !== null),
                    TextEntry::make('transaction_tier')->label('Transaction Tier')->formatStateUsing(fn (TransactionTier $state): string => $state->label()),
                    IconEntry::make('platform_covered')->label('Platform covered')->boolean(),
                    TextEntry::make('state')->placeholder('—'),
                    TextEntry::make('created_at')->label('Created')->dateTime(),
                    TextEntry::make('trial_ends_at')->label('Trial ends')->dateTime()->placeholder('—'),
                ]),
            // P05-03: the customer's SMS credits; finance-admin adjusts them and sets a custom allowance (page actions).
            Section::make('SMS credits')
                ->columns(4)
                ->schema([
                    TextEntry::make('sms_balance')->label('Balance')
                        ->state(fn (Customer $record): string => number_format(self::smsCredits($record)->balance)),
                    TextEntry::make('sms_allowance_left')->label('This month\'s allowance left')
                        ->state(fn (Customer $record): string => number_format(self::smsCredits($record)->allowanceLeft).' of '.number_format(self::smsCredits($record)->allowanceGranted)),
                    TextEntry::make('sms_purchased')->label('Purchased (never expire)')
                        ->state(fn (Customer $record): string => number_format(self::smsCredits($record)->purchased)),
                    TextEntry::make('sms_monthly_allowance')->label('Monthly allowance')
                        ->state(fn (Customer $record): string => number_format(self::smsCredits($record)->monthlyAllowance)
                            .(app(CustomerSmsCredits::class)->customAllowance($record->organization()) !== null ? ' (custom)' : ' (plan)')),
                ]),
        ]);
    }

    private static function smsCredits(Customer $record): SmsCreditSummary
    {
        return app(CustomerSmsCredits::class)->summary($record->organization());
    }

    public static function getRelations(): array
    {
        return [MembersRelationManager::class, HistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'view' => ViewCustomer::route('/{record}'),
        ];
    }
}
