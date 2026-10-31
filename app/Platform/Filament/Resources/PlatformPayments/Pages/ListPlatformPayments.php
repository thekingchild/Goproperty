<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\PlatformPayments\Pages;

use App\Platform\Filament\Resources\PlatformPayments\PlatformPaymentResource;
use Filament\Resources\Pages\ListRecords;

final class ListPlatformPayments extends ListRecords
{
    protected static string $resource = PlatformPaymentResource::class;
}
