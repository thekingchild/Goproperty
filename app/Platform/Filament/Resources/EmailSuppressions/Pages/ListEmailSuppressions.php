<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\EmailSuppressions\Pages;

use App\Mail\Suppression\SuppressionReason;
use App\Mail\Suppression\Suppressions;
use App\Models\EmailSuppression;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\EmailSuppressions\EmailSuppressionResource;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

final class ListEmailSuppressions extends ListRecords
{
    use ActsAsStaff;

    protected static string $resource = EmailSuppressionResource::class;

    protected ?string $subheading = 'No email goes to these addresses: the provider reported a permanent bounce or a spam complaint, or staff added them. The people with an account at an address are told in the app to fix it.';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('suppress')
                ->label('Add an address')
                ->authorize(fn (): bool => self::actor()->can('create', EmailSuppression::class))
                ->schema([
                    TextInput::make('email')->label('Email address')->email()->required()->maxLength(191),
                    TextInput::make('detail')->label('Why')->required()->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $added = app(Suppressions::class)->suppress((string) $data['email'], SuppressionReason::Manual, null, (string) $data['detail'], self::actor());

                    $added
                        ? Notification::make()->title('No email goes to '.$data['email'].' from now on')->success()->send()
                        : Notification::make()->title('That address is already suppressed')->warning()->send();
                }),
        ];
    }
}
