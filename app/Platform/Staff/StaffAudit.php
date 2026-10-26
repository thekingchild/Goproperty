<?php

declare(strict_types=1);

namespace App\Platform\Staff;

use App\Models\PlatformStaff;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes staff, role and approval changes to the hash-chained audit log
 * (PLATFORM_CONSOLE.md § 1, § 7), log name `staff`, with the acting staff
 * member as causer. Entries about a staff member have that member as
 * subject, so they make up the profile history.
 */
final class StaffAudit
{
    public const string LOG = 'staff';

    /** @param  array<string, mixed>  $properties */
    public function record(string $event, Model $subject, ?PlatformStaff $actor, string $description, array $properties = []): void
    {
        $log = activity(self::LOG)
            ->event($event)
            ->performedOn($subject)
            ->withProperties($properties);

        $log = $actor !== null ? $log->causedBy($actor) : $log->causedByAnonymous();

        $log->log($description);
    }
}
