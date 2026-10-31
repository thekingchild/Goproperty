<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\PlatformInvoices\Pages;

use App\Platform\Filament\Resources\PlatformInvoices\PlatformInvoiceResource;
use Filament\Resources\Pages\ListRecords;

final class ListPlatformInvoices extends ListRecords
{
    protected static string $resource = PlatformInvoiceResource::class;
}
