<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\TierSettings\Pages;

use App\Platform\Filament\Resources\TierSettings\TierSettingsResource;
use Filament\Resources\Pages\ListRecords;

/** Every version of the Transaction Tiers' terms (P06-08). */
final class ListTierSettings extends ListRecords
{
    protected static string $resource = TierSettingsResource::class;

    public function getSubheading(): string
    {
        return 'The Transaction Tiers\' limits and documents. A change is proposed on the version in force, approved by a second staff member in Staff → Approvals, and applies from its date.';
    }
}
