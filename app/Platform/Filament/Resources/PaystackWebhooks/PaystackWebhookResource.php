<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\PaystackWebhooks;

use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\PaystackWebhooks\Pages\ListPaystackWebhooks;
use App\Platform\Webhooks\PaystackWebhookDelivery;
use App\Platform\Webhooks\WebhookReplay;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Console → Operations → Paystack webhooks (P06-03; PLATFORM_CONSOLE.md
 * § 3 "Webhooks … replay button"): the calls to GoProperty's own
 * `/webhooks/paystack`, newest first: event, reference, signature, outcome,
 * error. **Replay** queues a signed call's processing again
 * (App\Platform\Webhooks\WebhookReplay, audited). Policy:
 * PaystackWebhookDeliveryPolicy.
 */
final class PaystackWebhookResource extends Resource
{
    use ActsAsStaff;

    protected static ?string $model = PaystackWebhookDelivery::class;

    protected static ?string $slug = 'paystack-webhooks';

    protected static ?string $modelLabel = 'Paystack webhook';

    protected static ?string $pluralModelLabel = 'Paystack webhooks';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Paystack webhooks';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('received_at')->label('Received')->dateTime()->sortable(),
                TextColumn::make('event')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('reference')->placeholder('—')->copyable()->searchable(),
                IconColumn::make('signature_valid')->label('Signed')->boolean(),
                TextColumn::make('outcome')->badge()->placeholder('Waiting')
                    ->color(fn (?string $state): string => match ($state) {
                        'confirmed' => 'success',
                        'held', 'rejected' => 'danger',
                        'not_paid' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('processing_error')->label('Error')->wrap()->limit(120)->placeholder('—'),
                TextColumn::make('processed_at')->label('Processed')->dateTime()->placeholder('—'),
                TextColumn::make('ip_address')->label('From')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('signature_valid')->label('Signature'),
                SelectFilter::make('outcome')->options([
                    'confirmed' => 'Confirmed', 'held' => 'Held', 'duplicate' => 'Duplicate', 'not_paid' => 'Not paid',
                    'ignored' => 'Ignored', 'rejected' => 'Rejected (bad signature)',
                ]),
                SelectFilter::make('waiting')->label('Show')
                    ->options(['waiting' => 'Not processed yet', 'errors' => 'With an error'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'waiting' => $query->whereNull('processed_at'),
                        'errors' => $query->whereNotNull('processing_error'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('replay')
                    ->label('Replay')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->modalDescription('Processes this call again: GoProperty asks Paystack about the payment and settles it if it is not settled yet. A payment already settled is left as it is.')
                    ->authorize(fn (PaystackWebhookDelivery $record): bool => self::actor()->can('replay', $record))
                    ->action(function (PaystackWebhookDelivery $record): void {
                        $replayed = app(WebhookReplay::class)->replay(self::actor(), $record);

                        $replayed
                            ? Notification::make()->title('Queued to be processed again')->success()->send()
                            : Notification::make()->title('This call cannot be replayed')->danger()->send();
                    }),
            ])
            ->defaultSort('received_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaystackWebhooks::route('/'),
        ];
    }
}
