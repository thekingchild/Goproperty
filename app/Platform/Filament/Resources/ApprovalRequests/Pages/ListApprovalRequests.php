<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\ApprovalRequests\Pages;

use App\Platform\Filament\Resources\ApprovalRequests\ApprovalRequestResource;
use Filament\Resources\Pages\ListRecords;

final class ListApprovalRequests extends ListRecords
{
    protected static string $resource = ApprovalRequestResource::class;
}
