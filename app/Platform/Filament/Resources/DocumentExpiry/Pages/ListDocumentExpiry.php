<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\DocumentExpiry\Pages;

use App\Platform\Filament\Resources\DocumentExpiry\DocumentExpiryResource;
use Filament\Resources\Pages\ListRecords;

final class ListDocumentExpiry extends ListRecords
{
    protected static string $resource = DocumentExpiryResource::class;
}
