<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\EmailSuppressions;

use App\Mail\Suppression\SuppressionReason;
use App\Mail\Suppression\Suppressions;
use App\Models\EmailSuppression;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\EmailSuppressions\Pages\ListEmailSuppressions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Console → Content → Suppressed addresses (P05-04; PLATFORM_CONSOLE.md
 * § 5 "Delivery"): addresses no email goes to, from the provider's bounce
 * and complaint webhook or added by staff. Lifting one lets email go there
 * again (after its owner fixed the mailbox); nothing is deleted.
 * EmailSuppressionPolicy: see with `delivery_logs.view`, add and lift with
 * `settings.email_provider`.
 */
final class EmailSuppressionResource extends Resource
{
    use ActsAsStaff;

    protected static ?string $model = EmailSuppression::class;

    protected static ?string $slug = 'email-suppressions';

    protected static ?string $modelLabel = 'suppressed address';

    protected static ?string $pluralModelLabel = 'suppressed addresses';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Suppressed addresses';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('liftedBy');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')->searchable()->copyable(),
                TextColumn::make('reason')->badge()->formatStateUsing(fn (SuppressionReason $state): string => $state->label())
                    ->color(fn (SuppressionReason $state): string => $state === SuppressionReason::Complaint ? 'danger' : 'warning'),
                TextColumn::make('provider')->placeholder('—'),
                TextColumn::make('detail')->limit(80)->wrap()->placeholder('—'),
                TextColumn::make('suppressed_at')->label('Since')->dateTime()->sortable(),
                TextColumn::make('lifted_at')->label('Allowed again')->dateTime()->placeholder('—')
                    ->description(fn (EmailSuppression $record): ?string => $record->liftedBy?->name),
            ])
            ->filters([
                TernaryFilter::make('active')->label('Suppressed now')->default(true)
                    ->queries(true: fn (Builder $query) => $query->whereNull('lifted_at'), false: fn (Builder $query) => $query->whereNotNull('lifted_at')),
                SelectFilter::make('reason')->options(collect(SuppressionReason::cases())->mapWithKeys(fn (SuppressionReason $reason): array => [$reason->value => $reason->label()])->all()),
            ])
            ->recordActions([
                Action::make('lift')
                    ->label('Allow again')
                    ->requiresConfirmation()
                    ->modalDescription('Email goes to this address again. Do this only once its owner has fixed the mailbox: another bounce or complaint suppresses it again.')
                    ->authorize(fn (EmailSuppression $record): bool => self::actor()->can('lift', $record))
                    ->action(function (EmailSuppression $record): void {
                        app(Suppressions::class)->lift($record, self::actor());
                        Notification::make()->title("Email goes to {$record->email} again")->success()->send();
                    }),
            ])
            ->defaultSort('suppressed_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailSuppressions::route('/'),
        ];
    }
}
