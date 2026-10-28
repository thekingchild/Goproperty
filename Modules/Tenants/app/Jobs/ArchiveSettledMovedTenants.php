<?php

declare(strict_types=1);

namespace Modules\Tenants\Jobs;

use App\Models\Organization;
use App\Tenancy\Moves\MovedTenants;
use App\Tenancy\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Tenants\Profiles\MovedTenantArchive;

/**
 * The daily backstop of BILLING_PAYMENTS.md § 7.4 (P04-04): every record a
 * tenant left behind when they moved with their building that owes and is
 * owed nothing any more archives itself (Profiles\MovedTenantArchive).
 * The events catch most at once (Listeners\ArchiveSettledMovedTenants);
 * this catches the rest (a bill settled some other way, a missed event).
 * Scheduled daily (Schedule::job on the `default` queue, ARCHITECTURE.md
 * § 7), organization by organization inside each one's context, only
 * those with moved tenants (Core's MovedTenants). Safe to run again.
 */
final class ArchiveSettledMovedTenants implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 50;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(OrganizationContext $context, MovedTenantArchive $archive): void
    {
        if (! app()->bound(MovedTenants::class)) {
            return;
        }

        foreach (Organization::query()->whereKey(app(MovedTenants::class)->organizationIds())->orderBy('id')->get() as $organization) {
            $context->run($organization, static fn (): int => $archive->sweep());
        }
    }
}
