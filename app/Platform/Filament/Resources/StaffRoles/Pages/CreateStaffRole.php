<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\StaffRoles\Pages;

use App\Platform\Approvals\ApprovalRefused;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\StaffRoles\StaffRoleResource;
use App\Platform\Staff\StaffChangeRefused;
use App\Platform\Staff\StaffRoles;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/** A custom role, through StaffRoles (audited; protected permissions wait for approval). */
final class CreateStaffRole extends CreateRecord
{
    use ActsAsStaff;

    protected static string $resource = StaffRoleResource::class;

    protected static bool $canCreateAnother = false;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            [$role, $request] = app(StaffRoles::class)->createRole(
                self::actor(),
                (string) ($data['name'] ?? ''),
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

        return $role;
    }
}
