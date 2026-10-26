<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\StaffRoles\Pages;

use App\Platform\Filament\Resources\StaffRoles\StaffRoleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListStaffRoles extends ListRecords
{
    protected static string $resource = StaffRoleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New role')];
    }
}
