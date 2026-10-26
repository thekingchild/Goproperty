<?php

declare(strict_types=1);

namespace Tests\Fixtures\Jobs;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Records the spatie permission team a queued job starts with, and
 * optionally works inside an organization, for the tests that the team
 * never leaks from the dispatching request or from an earlier job.
 */
final class RecordPermissionTeam implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var list<array{label: string, at_start: int|string|null, inside: int|string|null}> */
    public static array $seen = [];

    public function __construct(public string $label, public ?int $organizationId = null) {}

    public function handle(OrganizationContext $context): void
    {
        $atStart = getPermissionsTeamId();
        $inside = null;

        if ($this->organizationId !== null) {
            $organization = Organization::query()->findOrFail($this->organizationId);
            $context->set($organization);
            $inside = getPermissionsTeamId();
        }

        self::$seen[] = ['label' => $this->label, 'at_start' => $atStart, 'inside' => $inside];
    }
}
