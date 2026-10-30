<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\DeliveryLog\Pages;

use App\Platform\Filament\Resources\DeliveryLog\DeliveryLogResource;
use Filament\Resources\Pages\ListRecords;

final class ListDeliveryLog extends ListRecords
{
    protected static string $resource = DeliveryLogResource::class;

    protected ?string $subheading = 'Every SMS GoProperty sent for an agency, and every WhatsApp message sent through an agency\'s own WhatsApp. A failed SMS is retried three times (after 1, 5 and 30 minutes) before it shows here as failed.';

    protected function getHeaderActions(): array
    {
        return [];
    }
}
