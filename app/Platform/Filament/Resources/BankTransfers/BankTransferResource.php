<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\BankTransfers;

use App\Platform\Billing\BankTransferProof;
use App\Platform\Billing\BankTransferReview;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\BankTransfers\Pages\ListBankTransfers;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Modules\SaasBilling\Contracts\TransferReviewRefused;
use Throwable;
use UnitEnum;

/**
 * Console → Billing → Bank transfers (P06-04; BILLING_PAYMENTS.md § 5
 * steps 3–4): the proofs owners sent for transfers into GoProperty's
 * account, across organizations, waiting ones first: organization,
 * invoice and its total, what the owner says was sent and when, from whom,
 * the file (a signed link valid for minutes), the same file sent before,
 * and the review. **Confirm** (the whole total arrived; the staff member
 * ticks that they checked) and **Reject** (with the reason the owners are
 * shown) go through App\Platform\Billing\BankTransferReview to SaasBilling,
 * audited. Policy: BankTransferProofPolicy.
 */
final class BankTransferResource extends Resource
{
    use ActsAsStaff;

    protected static ?string $model = BankTransferProof::class;

    protected static ?string $slug = 'bank-transfers';

    protected static ?string $modelLabel = 'bank transfer';

    protected static ?string $pluralModelLabel = 'Bank transfers';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Bank transfers';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    public static function getEloquentQuery(): Builder
    {
        return BankTransferProof::withDetails(parent::getEloquentQuery()->with('customer'));
    }

    /** How many proofs wait for review. */
    public static function getNavigationBadge(): ?string
    {
        $waiting = BankTransferProof::query()->where('status', 'pending')->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Proofs waiting for review';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Sent')->dateTime()->sortable(),
                TextColumn::make('customer.name')->label('Organization')->searchable()
                    ->description(fn (BankTransferProof $record): string => '/o/'.$record->customer->slug),
                TextColumn::make('invoice_number')->label('Invoice')->copyable()
                    ->description(fn (BankTransferProof $record): string => self::money($record->invoice_total_kobo).' · '.match ($record->invoice_status) {
                        'open' => 'open',
                        'paid' => 'paid already',
                        default => (string) $record->invoice_status,
                    }),
                TextColumn::make('stated_amount_kobo')->label('Sent, they say')
                    ->formatStateUsing(fn (?int $state): string => self::money($state))
                    ->color(fn (BankTransferProof $record): ?string => $record->stated_amount_kobo !== $record->invoice_total_kobo ? 'danger' : null)
                    ->description(fn (BankTransferProof $record): string => 'on '.($record->transferred_on?->format('j M Y') ?? '—')),
                TextColumn::make('sender_name')->label('From')->wrap()
                    ->description(fn (BankTransferProof $record): ?string => $record->sender_bank),
                TextColumn::make('transfer_narration')->label('Narration')->placeholder('—')->wrap(),
                TextColumn::make('same_file_count')->label('Same file')
                    ->formatStateUsing(fn (?int $state): string => (int) $state > 0 ? 'Sent before ('.$state.')' : 'No')
                    ->badge()
                    ->color(fn (?int $state): string => (int) $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => BankTransferProof::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'confirmed' => 'success',
                        default => 'danger',
                    })
                    ->description(fn (BankTransferProof $record): ?string => $record->reviewer_name !== null ? 'by '.$record->reviewer_name.', '.$record->reviewed_at?->format('j M Y H:i') : null),
                TextColumn::make('rejection_reason')->label('Reason')->wrap()->limit(120)->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(BankTransferProof::STATUSES)->default('pending'),
            ])
            ->recordActions([
                Action::make('file')
                    ->label('Open proof')
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->url(fn (BankTransferProof $record): ?string => app(BankTransferReview::class)->fileUrl(self::actor(), $record), shouldOpenInNewTab: true)
                    ->visible(fn (BankTransferProof $record): bool => $record->bank_transfer_proof_media_id !== null),
                Action::make('confirm')
                    ->label('Confirm')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->authorize(fn (BankTransferProof $record): bool => self::actor()->can('review', $record))
                    ->modalHeading(fn (BankTransferProof $record): string => 'Confirm the transfer for '.$record->invoice_number)
                    ->modalDescription('The invoice is marked paid, a lapsed subscription is active again once nothing else is overdue, and the owners get a receipt. This cannot be undone.')
                    ->schema(fn (BankTransferProof $record): array => [
                        Checkbox::make('checked')
                            ->label('I checked GoProperty\'s bank account: '.self::money($record->invoice_total_kobo).' for '.$record->invoice_number.' arrived in full.')
                            ->accepted()
                            ->validationMessages(['accepted' => 'Confirm only a transfer you found in GoProperty\'s account.']),
                    ])
                    ->action(fn (BankTransferProof $record) => self::decide(fn () => app(BankTransferReview::class)->confirm(self::actor(), $record), 'Confirmed: invoice '.$record->invoice_number.' is paid')),
                Action::make('reject')
                    ->label('Reject')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->authorize(fn (BankTransferProof $record): bool => self::actor()->can('review', $record))
                    ->modalDescription('The invoice stays open. The owners are told by email with this reason, and may send another proof.')
                    ->schema([Textarea::make('reason')->label('Reason, shown to the customer')->required()->minLength(3)->maxLength(500)->rows(3)])
                    ->action(fn (BankTransferProof $record, array $data) => self::decide(fn () => app(BankTransferReview::class)->reject(self::actor(), $record, (string) $data['reason']), 'Proof not accepted; the owners were told')),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankTransfers::route('/'),
        ];
    }

    private static function money(?int $kobo): string
    {
        return $kobo === null ? '—' : Money::kobo($kobo)->format();
    }

    private static function decide(callable $review, string $done): void
    {
        try {
            $review();
        } catch (AuthorizationException $denied) {
            throw $denied;
        } catch (TransferReviewRefused $refused) {
            Notification::make()->title($refused->getMessage())->danger()->send();

            return;
        } catch (Throwable $failure) {
            report($failure);
            Notification::make()->title('Something went wrong; nothing was changed.')->danger()->send();

            return;
        }

        Notification::make()->title($done)->success()->send();
    }
}
