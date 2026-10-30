<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\DeliveryLog;

use App\Platform\Deliveries\DeliveryLogEntry;
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
 * Console → Operations → Delivery log (P05-02): every SMS across
 * organizations, newest first, with its status, provider id, attempts and
 * error. Failed and undelivered messages are counted on the menu for the
 * last 7 days ("failed sends … surface", the P05-02 acceptance criterion;
 * P05-05 builds the health dashboard on these rows). Masked numbers, no
 * text. `delivery_logs.view` (DeliveryLogEntryPolicy).
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
                TextColumn::make('error')->wrap()->limit(120)->placeholder('—'),
                TextColumn::make('provider_message_id')->label('Provider id')->placeholder('—')->copyable()
                    ->description(fn (DeliveryLogEntry $record): string => $record->provider),
                TextColumn::make('cost_kobo')->label('Cost')->placeholder('—')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? Money::kobo($state)->format() : '—'),
                TextColumn::make('sent_at')->label('Sent')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('delivered_at')->label('Delivered')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
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
