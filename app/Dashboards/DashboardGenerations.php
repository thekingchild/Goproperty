<?php

declare(strict_types=1);

namespace App\Dashboards;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Each organization's dashboard generation (P08-04, ARCHITECTURE.md § 13),
 * kept in the default cache store (database or file on shared hosting,
 * Redis on a VPS; no cache tags needed). A record the dashboards are
 * worked out from (models using ChangesDashboardFigures, building moves)
 * calls changed() when it is written; the organization then gets a new
 * token once the write is committed, and every figure cached under the old
 * token is worked out again on its next read.
 *
 * Changing the token after the commit (not before) is what keeps a
 * concurrent reader from caching the old figures under the new token: a
 * reader takes the token first and the records after, so whatever it
 * caches carries a token that is already old once the write is visible.
 *
 * Scoped: fresh for every request and queued job.
 */
final class DashboardGenerations
{
    private const string KEY = 'dashboards:generation:';

    /** @var array<int, true> organizations whose new token waits for a commit */
    private array $pending = [];

    public function __construct(private readonly Repository $cache) {}

    public function current(int $organizationId): DashboardGeneration
    {
        $generation = DashboardGeneration::fromStored($this->cache->get(self::KEY.$organizationId));

        if ($generation === null) {
            $generation = DashboardGeneration::fresh();
            $this->cache->forever(self::KEY.$organizationId, $generation->toStored());
        }

        return $generation;
    }

    /**
     * The organizations' figures change with a write: a new token for each
     * once the open transaction commits (at once outside one; Laravel keeps
     * one transaction record for every connection). Many writes in one
     * transaction give one new token.
     */
    public function changed(int ...$organizationIds): void
    {
        foreach (array_unique($organizationIds) as $organizationId) {
            // Every write registers its callback (a rolled-back transaction
            // drops its own); the first to run after a commit renews the
            // token and the others of that commit find nothing to do.
            $this->pending[$organizationId] = true;

            DB::afterCommit(function () use ($organizationId): void {
                if (! isset($this->pending[$organizationId])) {
                    return;
                }

                unset($this->pending[$organizationId]);

                try {
                    $this->renew($organizationId);
                } catch (Throwable $failure) {
                    // The write is committed: a cache that cannot be reached
                    // must not fail it. Cached figures then live out their
                    // five minutes (DashboardCache::TTL_SECONDS).
                    report($failure);
                }
            });
        }
    }

    /** A new token now: every cached figure of the organization is worked out again. */
    public function renew(int $organizationId): DashboardGeneration
    {
        $generation = DashboardGeneration::fresh();
        $this->cache->forever(self::KEY.$organizationId, $generation->toStored());

        return $generation;
    }
}
