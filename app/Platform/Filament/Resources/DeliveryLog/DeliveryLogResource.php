<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\DeliveryLog;

use App\Platform\Deliveries\DeliveryLogEntry;
use App\Platform\Deliveries\Health\ChannelFigures;
use App\Platform\Deliveries\Health\DeliveryOutcome;
use App\Platform\Deliveries\Health\DeliveryWindow;
use App\Platform\Deliveries\Health\MessagingHealth;
use App\Platform\Filament\Resources\DeliveryLog\Pages\ListDeliveryLog;
use App\Sms\DeliveryStatus;
use App\Support\Money;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Console → Operations → Delivery log (P05-02): every SMS (and, since
 * P05-06, WhatsApp message) across organizations, newest first, with its
 * status, provider id, attempts and error. Failed and undelivered messages
 * are counted on the menu for the last 7 days ("failed sends … surface",
 * the P05-02 acceptance criterion). The System operations dashboard
 * (P05-05) links each of its figures here through the channel, outcome and
 * period filters (filteredUrl()), which count with the same status sets.
 * Masked numbers, no text. `delivery_logs.view` (DeliveryLogEntryPolicy).
 */
final class DeliveryLogResource extends Resource
{
    protected static ?string $model = DeliveryLogEntry::class;

    protected static ?string $slug = 'delivery-log';

    protected static ?string $modelLabel = 'delivery';

    protected static ?string $pluralModelLabel = 'delivery log';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Delivery log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    /** Failed or not delivered in the last 7 days. */
    public static function failedRecently(): int
    {
        return DeliveryLogEntry::query()
            ->whereIn('status', [DeliveryStatus::Failed->value, DeliveryStatus::Undelivered->value])
            ->where('queued_at', '>=', now()->subDays(7))
            ->count();
    }

    /**
     * The log filtered to one dashboard figure, or null when the viewer may
     * not open the log (the figure is then shown without a link).
     */
    public static function filteredUrl(?string $channel = null, ?DeliveryOutcome $outcome = null, ?DeliveryWindow $period = null): ?string
    {
        if (! self::canViewAny()) {
            return null;
        }

        $filters = array_filter([
            'channel' => $channel !== null ? ['value' => $channel] : null,
            'outcome' => $outcome !== null ? ['value' => $outcome->value] : null,
            'period' => $period !== null ? ['value' => $period->value] : null,
        ]);

        return self::getUrl('index', $filters !== [] ? ['filters' => $filters] : []);
    }

    public static function getNavigationBadge(): ?string
    {
        $failed = self::failedRecently();

        return $failed > 0 ? (string) $failed : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Failed or not delivered in the last 7 days';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('organization');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('queued_at')->label('Queued')->dateTime()->sortable(),
                TextColumn::make('organization.name')->label('Organization')->searchable(),
                TextColumn::make('notification_type')->label('Message')->badge()->color('gray'),
                TextColumn::make('channel')->formatStateUsing(fn (string $state): string => strtoupper($state)),
                TextColumn::make('recipient_contact')->label('To')->state(fn (DeliveryLogEntry $record): string => $record->maskedContact()),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (DeliveryStatus $state): string => $state->label())
                    ->color(fn (DeliveryStatus $state): string => $state->color())
                    ->description(fn (DeliveryLogEntry $record): ?string => $record->provider_status),
                TextColumn::make('attempts'),
                TextColumn::make('error')->wrap()->limit(120)->placeholder('—')
                    ->formatStateUsing(fn (?string $state): ?string => MessagingHealth::maskNumbers($state)),
                TextColumn::make('provider_message_id')->label('Provider id')->placeholder('—')->copyable()
                    ->description(fn (DeliveryLogEntry $record): string => $record->provider),
                TextColumn::make('cost_kobo')->label('Cost')->placeholder('—')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? Money::kobo($state)->format() : '—'),
                TextColumn::make('sent_at')->label('Sent')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('delivered_at')->label('Delivered')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('channel')
                    ->options(collect(MessagingHealth::CHANNELS)->mapWithKeys(fn (string $channel): array => [$channel => ChannelFigures::channelLabel($channel)])->all()),
                SelectFilter::make('outcome')
                    ->options(collect(DeliveryOutcome::cases())->mapWithKeys(fn (DeliveryOutcome $outcome): array => [$outcome->value => $outcome->label()])->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $outcome = DeliveryOutcome::tryFrom((string) ($data['value'] ?? ''));

                        return $outcome !== null ? $query->whereIn('status', $outcome->statusValues()) : $query;
                    }),
                SelectFilter::make('period')->label('Queued')
                    ->options(collect(DeliveryWindow::cases())->mapWithKeys(fn (DeliveryWindow $window): array => [$window->value => $window->label()])->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $window = DeliveryWindow::tryFrom((string) ($data['value'] ?? ''));

                        return $window !== null ? $query->where('queued_at', '>=', $window->since(now())) : $query;
                    }),
                SelectFilter::make('status')->options(collect(DeliveryStatus::cases())->mapWithKeys(fn (DeliveryStatus $status): array => [$status->value => $status->label()])->all()),
                SelectFilter::make('problems')->label('Show')
                    ->options(['problems' => 'Failed and not delivered'])
                    ->query(fn (Builder $query, array $data): Builder => ($data['value'] ?? null) === 'problems'
                        ? $query->whereIn('status', [DeliveryStatus::Failed->value, DeliveryStatus::Undelivered->value])
                        : $query),
            ])
            ->defaultSort('queued_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeliveryLog::route('/'),
        ];
    }
}
