<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\DataBreaches\Pages;

use App\Models\DataBreach;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\DataBreaches\DataBreachResource;
use App\Privacy\Breaches\BreachRegister;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

final class ListDataBreaches extends ListRecords
{
    use ActsAsStaff;

    protected static string $resource = DataBreachResource::class;

    protected ?string $subheading = 'Record a breach as soon as you know of it: the Nigeria Data Protection Commission must be told within 72 hours, and the people affected without delay when the risk to them is high. Follow docs/runbooks/breach-response.md.';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('record')
                ->label('Record a breach')
                ->color('danger')
                ->icon('heroicon-o-shield-exclamation')
                ->authorize(fn (): bool => self::actor()->can('create', DataBreach::class))
                ->schema(DataBreachResource::detailsForm())
                ->action(fn (array $data): bool => self::attempt(function () use ($data): string {
                    $breach = app(BreachRegister::class)->record(self::actor(), $data);

                    return "Recorded as {$breach->reference}: tell the Commission by ".$breach->noticeDeadline()->timezone('Africa/Lagos')->format('j M Y H:i').' (Lagos).';
                })),
        ];
    }
}
