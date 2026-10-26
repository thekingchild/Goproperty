<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Customers\Pages;

use App\Platform\Filament\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\ListRecords;

final class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;
}
