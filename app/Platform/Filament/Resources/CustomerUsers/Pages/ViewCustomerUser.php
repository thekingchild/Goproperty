<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\CustomerUsers\Pages;

use App\Platform\Filament\Resources\CustomerUsers\CustomerUserResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewCustomerUser extends ViewRecord
{
    protected static string $resource = CustomerUserResource::class;
}
