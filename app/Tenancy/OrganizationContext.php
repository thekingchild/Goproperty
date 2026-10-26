<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Organization;
use Closure;

/**
 * The organization the current request or job works in (ARCHITECTURE.md
 * § 4). Bound as a scoped singleton, so it starts empty for every request
 * and every queued job. SetOrganizationContext sets it for /o/{organization}
 * routes; jobs and commands use run().
 */
final class OrganizationContext
{
    private ?Organization $organization = null;

    public function set(Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function clear(): void
    {
        $this->organization = null;
    }

    public function has(): bool
    {
        return $this->organization !== null;
    }

    /** @throws MissingOrganizationContext */
    public function get(): Organization
    {
        return $this->organization ?? throw new MissingOrganizationContext('No organization context is set.');
    }

    /** @throws MissingOrganizationContext */
    public function id(): int
    {
        return $this->get()->id;
    }

    /**
     * Runs the callback inside an organization's context and restores the
     * previous one afterwards, even on failure.
     *
     * @template T
     *
     * @param  Closure(Organization): T  $callback
     * @return T
     */
    public function run(Organization $organization, Closure $callback): mixed
    {
        $previous = $this->organization;
        $this->organization = $organization;

        try {
            return $callback($organization);
        } finally {
            $this->organization = $previous;
        }
    }

    /**
     * Runs the callback with no organization context (as on the console or
     * the portals) and restores the current one afterwards. For work that
     * must not happen inside an organization, such as a staff member's
     * permission check during impersonation.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function outside(Closure $callback): mixed
    {
        $previous = $this->organization;
        $this->organization = null;

        try {
            return $callback();
        } finally {
            $this->organization = $previous;
        }
    }
}
