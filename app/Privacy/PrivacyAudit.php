<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Models\DataRequest;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\User;

/**
 * Writes the NDPA tooling's steps to the hash-chained audit log
 * (PLATFORM_CONSOLE.md § 7), log `privacy`, with the organization as
 * subject so they appear in its history. Entries name requests, tenants
 * and rows by number and count only, never a name, contact or the reason
 * staff typed (the AC "audit kept without the personal data"; default 21).
 */
final class PrivacyAudit
{
    public const string LOG = 'privacy';

    /** @param  array<string, mixed>  $properties */
    public function record(string $event, DataRequest $request, PlatformStaff|User|null $actor, string $description, array $properties = []): void
    {
        $organization = Organization::withTrashed()->find($request->organization_id);
        $log = activity(self::LOG)->event($event)->withProperties([
            'data_request_id' => $request->id,
            'kind' => $request->kind->value,
            'scope' => $request->scope->value,
            'organization_id' => $request->organization_id,
            'tenant_profile_id' => $request->tenant_profile_id,
            ...$properties,
        ]);

        if ($organization !== null) {
            $log = $log->performedOn($organization);
        }

        $log = $actor !== null ? $log->causedBy($actor) : $log->causedByAnonymous();
        $log->log($description);
    }
}
