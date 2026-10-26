<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\StaffRoles\Pages;

use App\Models\StaffRole;
use App\Platform\Approvals\ApprovalRefused;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\StaffRoles\StaffRoleResource;
use App\Platform\Staff\StaffChangeRefused;
use App\Platform\Staff\StaffRoles;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Rename (custom roles), describe and change permissions, through
 * StaffRoles. The `super-admin` role never opens here (StaffRolePolicy).
 *
 * @property-read StaffRole $record
 */
final class EditStaffRole extends EditRecord
{
    use ActsAsStaff;

    protected static string $resource = StaffRoleResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['permissions_by_area'] = StaffRoleResource::permissionsToForm($this->record->permissionNames());

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof StaffRole) {
            throw new Halt;
        }

        try {
            $request = app(StaffRoles::class)->updateRole(
                self::actor(),
                $record,
                (string) ($data['name'] ?? $record->name),
                isset($data['description']) ? (string) $data['description'] : null,
                StaffRoleResource::permissionsFromForm($data),
            );
        } catch (StaffChangeRefused|ApprovalRefused $refused) {
            Notification::make()->title($refused->getMessage())->danger()->send();

            throw new Halt;
        }

        if ($request !== null) {
            Notification::make()->title('Protected permissions wait for a second super-admin: the request is in Approvals.')->warning()->send();
        }

        return $record->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
