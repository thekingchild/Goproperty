<?php

declare(strict_types=1);

namespace App\Members;

use App\Models\Organization;
use App\Models\User;

/**
 * Writes member and invitation changes to the hash-chained audit log
 * (PLATFORM_CONSOLE.md § 7), log name `members`, with the organization as
 * subject (so they make up its history) and the acting user as causer.
 * During a support session AuditEntry adds the staff member.
 */
final class MemberAudit
{
    public const string LOG = 'members';

    /** @param  array<string, mixed>  $properties */
    public function record(string $event, Organization $organization, User $actor, string $description, array $properties = []): void
    {
        activity(self::LOG)
            ->event($event)
            ->performedOn($organization)
            ->causedBy($actor)
            ->withProperties($properties)
            ->log($description);
    }
}
