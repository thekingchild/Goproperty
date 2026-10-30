<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EmailTemplate;
use App\Models\PlatformStaff;
use App\Platform\Staff\StaffPermission as P;

/**
 * Console → Content → Email templates (PLATFORM_CONSOLE.md § 5): GoProperty's
 * own wording, edited with `templates.edit` (the `content-manager` role,
 * and super-admins). Templates come from code (the catalog and its
 * seeder): none is created or deleted here.
 */
final class EmailTemplatePolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::TemplatesEdit);
    }

    public function view(PlatformStaff $actor, EmailTemplate $template): bool
    {
        return $this->viewAny($actor);
    }

    public function create(PlatformStaff $actor): bool
    {
        return false;
    }

    /** Save new wording, roll back, switch on or off, send a test. */
    public function update(PlatformStaff $actor, EmailTemplate $template): bool
    {
        return $actor->holdsStaffPermission(P::TemplatesEdit);
    }

    public function rollback(PlatformStaff $actor, EmailTemplate $template): bool
    {
        return $this->update($actor, $template);
    }

    public function sendTest(PlatformStaff $actor, EmailTemplate $template): bool
    {
        return $this->update($actor, $template);
    }

    public function delete(PlatformStaff $actor, EmailTemplate $template): bool
    {
        return false;
    }
}
