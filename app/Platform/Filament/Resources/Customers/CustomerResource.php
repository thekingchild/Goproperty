<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Customers;

use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\SubscriptionStatus;
use App\Enums\TransactionTier;
use App\Models\SubscriptionPlan;
use App\Platform\Customers\Customer;
use App\Platform\Customers\CustomerSmsCredits;
use App\Platform\Filament\Resources\Customers\Pages\ListCustomers;
use App\Platform\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Platform\Filament\Resources\Customers\RelationManagers\FeaturesRelationManager;
use App\Platform\Filament\Resources\Customers\RelationManagers\HistoryRelationManager;
use App\Platform\Filament\Resources\Customers\RelationManagers\MembersRelationManager;
use App\Sms\Credits\SmsCreditSummary;
use BackedEnum;
use Carbon\CarbonImmutable;
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
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Console → Customers → Organizations (PLATFORM_CONSOLE.md § 1, ARCHITECTURE.md
 * § 6.9): every organization with its type, status, Transaction Tier,
 * members, owner and dates; search, filters and server-side pagination. Its
 * page lists the members and their roles there, the history, and Suspend /
 * Reactivate, and its SMS credits (P05-03: Adjust SMS credits, SMS
 * allowance). P06-07a adds the subscription (plan and status in the list,
 * with filters; on the page its plan, cycle, status, trial, period, units
 * last billed and platform cover, Change plan, Cancel / Reactivate
 * subscription) and the plan features with their overrides. Reads across
 * organizations through the Customer read model; every page and action is
 * checked by CustomerPolicy.
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

    /** The subscription filter's choice for organizations that have none yet. */
    public const string NO_SUBSCRIPTION = 'none';

    public static function subscriptionColor(?SubscriptionStatus $status): string
    {
        return match ($status) {
            SubscriptionStatus::Active => 'success',
            SubscriptionStatus::Trialing => 'info',
            SubscriptionStatus::PastDue, SubscriptionStatus::Grace => 'warning',
            SubscriptionStatus::Suspended => 'danger',
            SubscriptionStatus::Cancelled, null => 'gray',
        };
    }

    /**
     * Organizations whose subscription's column is one of the values (the
     * status filter's "none": no subscription yet).
     *
     * @param  Builder<Customer>  $query
     * @param  array<mixed>  $values
     * @return Builder<Customer>
     */
    private static function whereSubscription(Builder $query, string $column, array $values): Builder
    {
        $values = array_values(array_filter($values, is_scalar(...)));

        if ($values === []) {
            return $query;
        }

        $none = in_array(self::NO_SUBSCRIPTION, $values, true);
        $values = array_values(array_diff($values, [self::NO_SUBSCRIPTION]));

        return $query->where(fn (Builder $inner) => $inner
            ->when($values !== [], fn (Builder $with) => $with->whereIn('organizations.id', DB::table('subscriptions')->select('organization_id')->whereIn($column, $values)))
            ->when($none, fn (Builder $without) => $without->orWhereNotIn('organizations.id', DB::table('subscriptions')->select('organization_id'))));
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
                    // P06-07a: the subscription's plan and status.
                    'subscription_plan' => DB::table('subscriptions')->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
                        ->select('plans.name')->whereColumn('subscriptions.organization_id', 'organizations.id')->limit(1),
                    'subscription_status' => DB::table('subscriptions')->select('status')
                        ->whereColumn('subscriptions.organization_id', 'organizations.id')->limit(1),
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
                TextColumn::make('subscription_plan')->label('Plan')->placeholder('No plan yet'),
                TextColumn::make('subscription_status')
                    ->label('Subscription')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => SubscriptionStatus::tryFrom((string) $state)?->label() ?? '—')
                    ->color(fn (?string $state): string => self::subscriptionColor(SubscriptionStatus::tryFrom((string) $state))),
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
                SelectFilter::make('subscription_status')
                    ->label('Subscription')
                    ->multiple()
                    ->options([...collect(SubscriptionStatus::cases())->mapWithKeys(fn (SubscriptionStatus $status): array => [$status->value => $status->label()])->all(), self::NO_SUBSCRIPTION => 'No subscription yet'])
                    ->query(fn (Builder $query, array $data): Builder => self::whereSubscription($query, 'status', is_array($data['values'] ?? null) ? $data['values'] : [])),
                SelectFilter::make('plan')
                    ->label('Plan')
                    ->options(fn (): array => SubscriptionPlan::query()->orderBy('sort_order')->orderBy('id')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => self::whereSubscription($query, 'plan_id', is_scalar($data['value'] ?? null) && $data['value'] !== '' ? [$data['value']] : [])),
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
                    TextEntry::make('state')->placeholder('—'),
                    TextEntry::make('created_at')->label('Created')->dateTime(),
                    TextEntry::make('trial_ends_at')->label('Trial ends')->dateTime()->placeholder('—'),
                ]),
            // P06-07a: the subscription (Change plan, Cancel / Reactivate subscription are page actions).
            Section::make('Subscription')
                ->columns(4)
                ->schema([
                    TextEntry::make('subscription_plan')->label('Plan')
                        ->state(fn (Customer $record): ?string => self::planLabel($record))
                        ->placeholder('No subscription yet: the owner has not chosen a plan'),
                    TextEntry::make('subscription_cycle')->label('Billing')
                        ->state(fn (Customer $record): ?string => $record->subscription?->billing_cycle->label())
                        ->placeholder('—'),
                    TextEntry::make('subscription_status')->label('Status')
                        ->badge()
                        ->state(fn (Customer $record): ?string => $record->subscription?->status->label())
                        ->color(fn (Customer $record): string => self::subscriptionColor($record->subscription?->status))
                        ->placeholder('—'),
                    TextEntry::make('subscription_status_changed_at')->label('Status since')
                        ->state(fn (Customer $record): ?CarbonImmutable => $record->subscription?->status_changed_at)
                        ->dateTime()->placeholder('—'),
                    TextEntry::make('subscription_trial_ends_at')->label('Trial ends')
                        ->state(fn (Customer $record): ?CarbonImmutable => $record->subscription?->trial_ends_at)
                        ->dateTime()->placeholder('—'),
                    TextEntry::make('subscription_period')->label('Current period')
                        ->state(fn (Customer $record): ?string => $record->subscription?->periodLabel())
                        ->placeholder('None yet'),
                    TextEntry::make('subscription_units')->label('Units last billed')
                        ->state(fn (Customer $record): ?string => $record->subscription?->last_billed_units !== null ? number_format($record->subscription->last_billed_units) : null)
                        ->placeholder('Not billed yet'),
                    IconEntry::make('platform_covered')->label('Platform covered')->boolean()
                        ->helperText(fn (Customer $record): ?string => $record->platform_covered ? 'Invoices are marked paid when issued.' : null),
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

    /** "Growth", "Enterprise (custom)", "Legacy (not offered)". */
    private static function planLabel(Customer $record): ?string
    {
        $plan = $record->subscription?->plan;

        if ($plan === null) {
            return null;
        }

        $tags = array_filter([$plan->custom ? 'custom' : null, $plan->is_active ? null : 'not offered']);

        return $plan->name.($tags !== [] ? ' ('.implode(', ', $tags).')' : '');
    }

    private static function smsCredits(Customer $record): SmsCreditSummary
    {
        return app(CustomerSmsCredits::class)->summary($record->organization());
    }

    public static function getRelations(): array
    {
        return [MembersRelationManager::class, FeaturesRelationManager::class, HistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'view' => ViewCustomer::route('/{record}'),
        ];
    }
}
