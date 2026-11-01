<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\ComplianceFlags\Pages;

use App\Platform\Filament\Resources\ComplianceFlags\ComplianceFlagResource;
use Filament\Resources\Pages\ListRecords;

final class ListComplianceFlags extends ListRecords
{
    protected static string $resource = ComplianceFlagResource::class;

    public function getSubheading(): string
    {
        return 'What the Transaction Tier monitoring noticed: yearly limits, large and cash payments, possible structuring, spikes and expiring documents. Yearly-limit flags close themselves when the collections fall back or the organization moves up.';
    }
}
