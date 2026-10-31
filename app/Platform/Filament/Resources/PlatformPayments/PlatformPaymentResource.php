<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\PlatformPayments;

use App\Platform\Billing\PlatformPaymentRecord;
use App\Platform\Billing\Refunds;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\Customers\CustomerResource;
use App\Platform\Filament\Resources\PlatformInvoices\PlatformInvoiceResource;
use App\Platform\Filament\Resources\PlatformPayments\Pages\ListPlatformPayments;
use App\Platform\Filament\Resources\PlatformPayments\Pages\ViewPlatformPayment;
use App\Platform\Filament\Resources\PlatformPayments\RelationManagers\RefundsRelationManager;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Modules\SaasBilling\Contracts\RefundRefused;
use OverflowException;
use Throwable;
use UnitEnum;

/**
 * Console → Billing → Payments (P06-07b; docs/plans/P06-subtasks.md
 * defaults 56–59): every payment into GoProperty's own account, Paystack
 * and bank transfer, across organizations, newest first; held ones (paid
 * but not as asked, never applied) counted on the menu. Filters for method,
 * status and organization. A payment's page shows its refunds. **Refund**
 * (`refunds.create`) a confirmed or held payment, in whole or in part, with
 * a reason, through App\Platform\Billing\Refunds: at once up to the
 * configured amount, else after a second staff member's approval.
 * Policy: PlatformPaymentRecordPolicy.
 */
final class PlatformPaymentResource extends Resource
{
    use ActsAsStaff;

    protected static ?string $model = PlatformPaymentRecord::class;

    protected static ?string $slug = 'platform-payments';

    protected static ?string $modelLabel = 'payment';

    protected static ?string $pluralModelLabel = 'Payments';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Payments';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function getEloquentQuery(): Builder
    {
        return PlatformPaymentRecord::withDetails(parent::getEloquentQuery()->with('customer'));
    }

    /** Payments held for staff: paid, but not as asked. */
    public static function getNavigationBadge(): ?string
    {
        $held = PlatformPaymentRecord::query()->where('status', 'held')->count();

        return $held > 0 ? (string) $held : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Payments held for staff';
    }

    public static function money(?int $kobo): string
    {
        return $kobo === null ? '—' : Money::kobo($kobo)->format();
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'confirmed' => 'success',
            'pending' => 'warning',
            'held', 'failed' => 'danger',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Started')->dateTime()->sortable(),
                TextColumn::make('customer.name')->label('Organization')->searchable()
                    ->description(fn (PlatformPaymentRecord $record): string => '/o/'.$record->customer->slug),
                TextColumn::make('invoice_number')->label('Invoice')
                    ->url(fn (PlatformPaymentRecord $record): string => PlatformInvoiceResource::getUrl('view', ['record' => $record->platform_invoice_id])),
                TextColumn::make('method')->formatStateUsing(fn (string $state): string => PlatformPaymentRecord::METHODS[$state] ?? $state)
                    ->description(fn (PlatformPaymentRecord $record): ?string => $record->paystack_reference),
                TextColumn::make('amount_kobo')->label('Amount')
                    ->formatStateUsing(fn (PlatformPaymentRecord $record): string => self::money($record->paidKobo()))
                    ->description(fn (PlatformPaymentRecord $record): ?string => (int) $record->refunded_kobo > 0 ? 'Refunded '.self::money($record->refunded_kobo) : null),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => PlatformPaymentRecord::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->description(fn (PlatformPaymentRecord $record): ?string => $record->status === 'held' ? $record->hold_reason : null),
                TextColumn::make('confirmed_at')->label('Confirmed')->dateTime()->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(PlatformPaymentRecord::STATUSES),
                SelectFilter::make('method')->options(PlatformPaymentRecord::METHODS),
                SelectFilter::make('organization_id')->label('Organization')->relationship('customer', 'name')->searchable(),
            ])
            ->recordActions([ViewAction::make(), self::refundAction()])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Payment')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    TextEntry::make('customer.name')->label('Organization')
                        ->url(fn (PlatformPaymentRecord $record): ?string => self::actor()->can('view', $record->customer) ? CustomerResource::getUrl('view', ['record' => $record->organization_id]) : null),
                    TextEntry::make('invoice_number')->label('Invoice')
                        ->url(fn (PlatformPaymentRecord $record): string => PlatformInvoiceResource::getUrl('view', ['record' => $record->platform_invoice_id])),
                    TextEntry::make('method')->formatStateUsing(fn (string $state): string => PlatformPaymentRecord::METHODS[$state] ?? $state),
                    TextEntry::make('paystack_reference')->label('Paystack reference')->placeholder('—')->copyable(),
                    TextEntry::make('paystack_channel')->label('Channel')->placeholder('—'),
                    TextEntry::make('status')->badge()
                        ->formatStateUsing(fn (string $state): string => PlatformPaymentRecord::STATUSES[$state] ?? $state)
                        ->color(fn (string $state): string => self::statusColor($state)),
                    TextEntry::make('amount_kobo')->label('Asked')->formatStateUsing(fn (?int $state): string => self::money($state)),
                    TextEntry::make('paid_amount_kobo')->label('Paid')->formatStateUsing(fn (?int $state): string => self::money($state))->placeholder('—'),
                    TextEntry::make('refunded_kobo')->label('Refunded')->formatStateUsing(fn (?int $state): string => self::money((int) $state)),
                    TextEntry::make('awaiting_kobo')->label('Refund waiting for approval')->formatStateUsing(fn (?int $state): string => self::money((int) $state)),
                    TextEntry::make('confirmed_at')->label('Confirmed')->dateTime()->placeholder('—'),
                    TextEntry::make('created_at')->label('Started')->dateTime(),
                    TextEntry::make('hold_reason')->label('Why it is held / failed')->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * Refund a confirmed or held payment (PlatformPaymentRecordPolicy::refund).
     * The modal says what is left and whether the refund needs approval.
     */
    public static function refundAction(): Action
    {
        return Action::make('refund')
            ->label('Refund')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->authorize(fn (PlatformPaymentRecord $record): bool => self::actor()->can('refund', $record))
            ->modalHeading(fn (PlatformPaymentRecord $record): string => 'Refund '.$record->reference())
            ->modalDescription(function (PlatformPaymentRecord $record): string {
                $refunds = app(Refunds::class);
                $left = $refunds->refundable($record)->remaining();
                $whole = $record->status === 'confirmed' && $record->invoice_kind === 'subscription'
                    ? ' Refunding all of it opens the invoice again, due in 7 days; a part refund leaves it paid.'
                    : ' No invoice or credits change.';

                return 'Up to '.$left->format().' can be refunded. Above '.$refunds->approvalAbove()->format().' for this payment, a second staff member must approve it in Staff → Approvals.'.$whole
                    .($record->method === 'bank_transfer' ? ' Send the money back from GoProperty\'s bank account first, then record it here.' : ' Paystack returns it to the card or account that paid.');
            })
            ->fillForm(fn (PlatformPaymentRecord $record): array => ['amount' => app(Refunds::class)->refundable($record)->remaining()->toDecimalString()])
            ->schema(fn (PlatformPaymentRecord $record): array => array_values(array_filter([
                TextInput::make('amount')->label('Amount (₦)')->required()->maxLength(20)
                    ->regex('/^\s*₦?\s*(\d{1,3}(,\d{3})+|\d+)(\.\d{1,2})?\s*$/u')
                    ->validationMessages(['regex' => 'Give an amount in naira, such as 12,900 or 12900.50.']),
                $record->method === 'bank_transfer'
                    ? TextInput::make('bank_reference')->label('Reference of GoProperty\'s transfer back')->required()->minLength(3)->maxLength(100)
                    : null,
                Textarea::make('reason')->label('Reason')->helperText('Kept in the audit log with your name.')->required()->minLength(3)->maxLength(500)->rows(3),
            ])))
            ->action(function (PlatformPaymentRecord $record, array $data): void {
                try {
                    $amount = Money::naira((string) ($data['amount'] ?? ''));
                } catch (InvalidArgumentException|OverflowException) {
                    Notification::make()->title('Give an amount in naira, such as 12,900 or 12900.50.')->danger()->send();

                    return;
                }

                try {
                    $done = app(Refunds::class)->request(self::actor(), $record, $amount->kobo, (string) ($data['reason'] ?? ''), isset($data['bank_reference']) ? (string) $data['bank_reference'] : null);
                } catch (AuthorizationException $denied) {
                    throw $denied;
                } catch (RefundRefused $refused) {
                    Notification::make()->title($refused->getMessage())->danger()->send();

                    return;
                } catch (Throwable $failure) {
                    report($failure);
                    Notification::make()->title('Something went wrong; nothing was refunded.')->danger()->send();

                    return;
                }

                Notification::make()->title($done)->success()->send();
            });
    }

    public static function getRelations(): array
    {
        return [RefundsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformPayments::route('/'),
            'view' => ViewPlatformPayment::route('/{record}'),
        ];
    }
}
