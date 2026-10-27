<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\TierApplications\Pages;

use App\Platform\Filament\Resources\TierApplications\TierApplicationResource;
use Filament\Resources\Pages\ListRecords;

final class ListTierApplications extends ListRecords
{
    protected static string $resource = TierApplicationResource::class;
}
