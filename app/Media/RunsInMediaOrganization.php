<?php

declare(strict_types=1);

namespace App\Media;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Closure;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

/**
 * For the media library's queued jobs: a job starts with no organization
 * context (ARCHITECTURE.md § 4), but converting a file loads its owner and
 * saves the media row, both organization-scoped. The job therefore runs
 * inside the organization recorded on the media row. If that organization
 * is gone, there is nothing to do.
 */
trait RunsInMediaOrganization
{
    /** @param  Closure(): bool  $work */
    private function inMediaOrganization(SpatieMedia $media, Closure $work): bool
    {
        $organization = Organization::query()->find((int) $media->getAttribute('organization_id'));

        if ($organization === null) {
            return true;
        }

        return app(OrganizationContext::class)->run($organization, static fn (): bool => $work());
    }
}
