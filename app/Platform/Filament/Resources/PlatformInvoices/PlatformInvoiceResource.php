<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\PlatformInvoices;

use App\Models\Organization;
use App\Platform\Billing\PlatformInvoiceRecord;
use App\Platform\Billing\Refunds;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\Customers\CustomerResource;
use App\Platform\Filament\Resources\PlatformInvoices\Pages\ListPlatformInvoices;
use App\Platform\Filament\Resources\PlatformInvoices\Pages\ViewPlatformInvoice;
use App\Platform\Filament\Resources\PlatformInvoices\RelationManagers\PaymentsRelationManager;
use App\Platform\Filament\Resources\PlatformInvoices\RelationManagers\RefundsRelationManager;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Modules\SaasBilling\Contracts\InvoiceEmailRefused;
use Modules\SaasBilling\Contracts\SubscriptionInvoice;
use Modules\SaasBilling\Contracts\SubscriptionInvoices;
use UnitEnum;

/**
 * Console → Billing → Invoices (P06-07b; docs/plans/P06-subtasks.md
 * default 55): every GoProperty invoice across organizations, subscription
 * periods and SMS bundles, newest first, with filters for status, kind,
 * overdue and organization. An invoice's page shows its lines (from
 * SaasBilling's Contracts\SubscriptionInvoices), its payments and refunds.
 * **Send invoice email again** goes through App\Platform\Billing\Refunds to
 * SaasBilling's Contracts\InvoiceEmails, audited. Invoices are never edited
 * or voided here. Policy: PlatformInvoiceRecordPolicy.
 */
final class PlatformInvoiceResource extends Resource
{
    use ActsAsStaff;

    protected static ?string $model = PlatformInvoiceRecord::class;

    protected static ?string $slug = 'platform-invoices';

    protected static ?string $modelLabel = 'invoice';

    protected static ?string $pluralModelLabel = 'Invoices';

    protected static ?string $recordTitleAttribute = 'number';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Invoices';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('customer');
    }

    public static function money(?int $kobo): string
    {
        return $kobo === null ? '—' : Money::kobo($kobo)->format();
    }

    public static function statusColor(PlatformInvoiceRecord $invoice): string
    {
        return match (true) {
            $invoice->isOverdue() => 'danger',
            $invoice->status === 'open' => 'warning',
            $invoice->status === 'paid' => 'success',
            default => 'gray',
        };
    }

    public static function period(PlatformInvoiceRecord $invoice): string
    {
        if ($invoice->kind === 'sms_credits') {
            return number_format((int) $invoice->sms_credits).' SMS credits';
        }

        return $invoice->period_start !== null && $invoice->period_end !== null
            ? $invoice->period_start->format('j M Y').' – '.$invoice->period_end->format('j M Y')
            : '—';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Issued')->dateTime()->sortable(),
                TextColumn::make('number')->label('Invoice')->searchable()->copyable()
                    ->description(fn (PlatformInvoiceRecord $record): string => PlatformInvoiceRecord::KINDS[$record->kind] ?? $record->kind),
                TextColumn::make('customer.name')->label('Organization')->searchable()
                    ->description(fn (PlatformInvoiceRecord $record): string => '/o/'.$record->customer->slug),
                TextColumn::make('period')->label('For')->state(fn (PlatformInvoiceRecord $record): string => self::period($record))
                    ->description(fn (PlatformInvoiceRecord $record): ?string => $record->plan_name),
                TextColumn::make('total_kobo')->label('Total')->sortable()->formatStateUsing(fn (?int $state): string => self::money($state)),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state, PlatformInvoiceRecord $record): string => $record->statusLabel())
                    ->color(fn (PlatformInvoiceRecord $record): string => self::statusColor($record)),
                TextColumn::make('due_date')->label('Due')->date()->sortable()->placeholder('—'),
                TextColumn::make('paid_at')->label('Paid')->dateTime()->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(PlatformInvoiceRecord::STATUSES),
                SelectFilter::make('kind')->options(PlatformInvoiceRecord::KINDS),
                TernaryFilter::make('overdue')
                    ->label('Overdue')
                    ->queries(
                        true: fn (Builder $query) => PlatformInvoiceRecord::overdue($query),
                        false: fn (Builder $query) => $query->where(fn (Builder $not) => $not->where('platform_invoices.status', '<>', 'open')
                            ->orWhereDate('platform_invoices.due_date', '>=', now('Africa/Lagos')->toDateString())),
                    ),
                SelectFilter::make('organization_id')->label('Organization')->relationship('customer', 'name')->searchable(),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Invoice')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    TextEntry::make('number')->label('Invoice')->copyable(),
                    TextEntry::make('customer.name')->label('Organization')
                        ->url(fn (PlatformInvoiceRecord $record): ?string => self::actor()->can('view', $record->customer) ? CustomerResource::getUrl('view', ['record' => $record->organization_id]) : null),
                    TextEntry::make('kind')->formatStateUsing(fn (string $state): string => PlatformInvoiceRecord::KINDS[$state] ?? $state),
                    TextEntry::make('status')->badge()
                        ->formatStateUsing(fn (string $state, PlatformInvoiceRecord $record): string => $record->statusLabel())
                        ->color(fn (PlatformInvoiceRecord $record): string => self::statusColor($record)),
                    TextEntry::make('period')->label('For')->state(fn (PlatformInvoiceRecord $record): string => self::period($record)),
                    TextEntry::make('plan_name')->label('Plan')->placeholder('—'),
                    TextEntry::make('units_billed')->label('Units counted')->placeholder('—'),
                    TextEntry::make('created_at')->label('Issued')->dateTime(),
                    TextEntry::make('due_date')->label('Due')->date()->placeholder('—'),
                    TextEntry::make('paid_at')->label('Paid')->dateTime()->placeholder('—'),
                    TextEntry::make('notes')->placeholder('—')->columnSpan(2),
                ]),
            Section::make('Lines')
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('lines')->hiddenLabel()->listWithLineBreaks()
                        ->state(fn (PlatformInvoiceRecord $record): array => self::lines($record)),
                ]),
        ]);
    }

    /** @return list<string> */
    public static function lines(PlatformInvoiceRecord $record): array
    {
        $presented = null;
        $organization = Organization::query()->find($record->organization_id);

        if ($record->kind === 'subscription' && $organization instanceof Organization && app()->bound(SubscriptionInvoices::class)) {
            $presented = app(OrganizationContext::class)->run($organization, static fn (): ?SubscriptionInvoice => app(SubscriptionInvoices::class)->find($record->id));
        }

        if (! $presented instanceof SubscriptionInvoice) {
            return [
                (PlatformInvoiceRecord::KINDS[$record->kind] ?? $record->kind).': '.self::money($record->subtotal_kobo),
                'VAT: '.self::money($record->vat_kobo),
                'Total: '.self::money($record->total_kobo),
            ];
        }

        $lines = array_map(static fn ($line): string => $line->label.': '.$line->amount->format(), $presented->lines);
        $lines[] = $presented->subtotalLabel.': '.$presented->subtotal->format();
        $lines[] = $presented->vatLabel.': '.$presented->vat->format();
        $lines[] = 'Total: '.$presented->total->format();

        return $lines;
    }

    public static function resendAction(): Action
    {
        return Action::make('resend')
            ->label('Send invoice email again')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->authorize(fn (PlatformInvoiceRecord $record): bool => self::actor()->can('resend', $record))
            ->requiresConfirmation()
            ->modalDescription('The owners get the invoice email again, as it was sent when it was issued. At most 3 times an hour.')
            ->action(function (PlatformInvoiceRecord $record): void {
                try {
                    $sent = app(Refunds::class)->resendInvoice(self::actor(), $record);
                } catch (AuthorizationException $denied) {
                    throw $denied;
                } catch (InvoiceEmailRefused $refused) {
                    Notification::make()->title($refused->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title("Invoice {$record->number} sent again to {$sent} ".($sent === 1 ? 'owner' : 'owners').'.')->success()->send();
            });
    }

    public static function getRelations(): array
    {
        return [PaymentsRelationManager::class, RefundsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformInvoices::route('/'),
            'view' => ViewPlatformInvoice::route('/{record}'),
        ];
    }
}
