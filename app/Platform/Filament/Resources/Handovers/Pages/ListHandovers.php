<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Handovers\Pages;

use App\Platform\Filament\Resources\Handovers\HandoverResource;
use Filament\Resources\Pages\ListRecords;

final class ListHandovers extends ListRecords
{
    protected static string $resource = HandoverResource::class;
}
