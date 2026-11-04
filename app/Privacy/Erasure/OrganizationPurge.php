<?php

declare(strict_types=1);

namespace App\Privacy\Erasure;

use App\Media\Media;
use App\Models\DataRequest;
use App\Privacy\Data\PersonalDataMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Purges a cancelled or deleted organization's data (P09-02; DATABASE_SCHEMA.md
 * rule 6, PLATFORM_CONSOLE.md § 1 two-person rule `data.purge`; defaults
 * 22–26), once its 90 days have passed (RetentionRules). Every row with the
 * organization's `organization_id` in a table PersonalDataMap lists is
 * deleted, and every file of the organization (evidence too, inside
 * Media::purging()), except what the map keeps: GoProperty's own billing
 * records with the organization (and the files they point at, such as a
 * bank-transfer proof of a subscription payment), its support-session
 * trail, consent records (without IP address and browser) and the data
 * request register. The audit log is never touched (append-only, hash
 * chained). The organization's row stays as a tombstone with its name (it
 * is on GoProperty's kept invoices), everything else about it blanked, and
 * the sign-in accounts nothing else uses are anonymised (AccountAnonymiser).
 *
 * The delete order comes from the schema's foreign keys, so a new table
 * needs no code here: nullable links inside the organization's rows are
 * cleared first (which also breaks cycles such as payment ↔ receipt), then
 * the tables are emptied children first. Another organization's row that
 * points at a purged one (a handover's receiving tenant) loses the link if
 * it may be empty; otherwise the purge stops with the reason.
 *
 * Resumable within the 50-second limit of shared hosting: work() stops at
 * the deadline and keeps its phase in `data_requests.progress`; every phase
 * is safe to run again.
 */
final class OrganizationPurge
{
    private const int CHUNK = 1000;

    /** @var array<string, list<array{column: string, table: string, nullable: bool, cascades: bool}>>|null child table → its foreign keys */
    private ?array $foreignKeys = null;

    public function __construct(
        private readonly PersonalDataMap $map,
        private readonly AccountAnonymiser $accounts,
    ) {}

    /** Works until the purge is done (true) or the deadline (microtime) passes (false). */
    public function work(DataRequest $request, float $deadline): bool
    {
        $organizationId = $request->organization_id;
        $progress = ($request->progress ?? []) + ['phase' => 'collect', 'table' => 0, 'counts' => [], 'users' => [], 'emails' => []];

        try {
            while (microtime(true) < $deadline) {
                switch ($progress['phase']) {
                    case 'collect':
                        $this->collect($organizationId, $progress);
                        $progress['phase'] = 'files';

                        break;
                    case 'files':
                        if ($this->files($organizationId, $deadline, $progress)) {
                            $progress['phase'] = 'unlink';
                        }

                        break;
                    case 'unlink':
                        $this->unlink($organizationId);
                        $progress['phase'] = 'rows';

                        break;
                    case 'rows':
                        if ($this->rows($organizationId, $deadline, $progress)) {
                            $progress['phase'] = 'accounts';
                        }

                        break;
                    case 'accounts':
                        $this->finishAccounts($organizationId, $progress);
                        $progress['phase'] = 'done';

                        return true;
                    default:
                        return true;
                }
            }
        } finally {
            $request->forceFill(['progress' => $progress])->save();
        }

        return false;
    }

    /**
     * The tables purged, children first.
     *
     * @return list<string>
     */
    public function deleteOrder(): array
    {
        $purged = $this->purgedTables();
        $edges = [];

        foreach ($purged as $table) {
            foreach ($this->foreignKeys()[$table] ?? [] as $key) {
                // Nullable links are cleared before deleting; cascades go with their parent.
                if (! $key['nullable'] && ! $key['cascades'] && $key['table'] !== $table && in_array($key['table'], $purged, true)) {
                    $edges[$table][] = $key['table'];
                }
            }
        }

        $order = [];
        $state = [];
        $visit = function (string $table) use (&$visit, &$order, &$state, $edges): void {
            if (($state[$table] ?? null) === 'done') {
                return;
            }

            if (($state[$table] ?? null) === 'visiting') {
                throw new RuntimeException("The tables' required foreign keys form a cycle at {$table}.");
            }

            $state[$table] = 'visiting';

            foreach ($edges[$table] ?? [] as $parent) {
                $visit($parent);
            }

            $state[$table] = 'done';
            $order[] = $table;
        };

        foreach ($purged as $table) {
            $visit($table);
        }

        // Parents come first in $order: delete the other way round.
        return array_reverse($order);
    }

    /** @return list<string> */
    public function purgedTables(): array
    {
        return array_values(array_filter(
            $this->map->tables(),
            fn (string $table): bool => $this->map->rule($table)?->keptOnPurge === null && Schema::hasTable($table),
        ));
    }

    /**
     * Accounts and addresses to look at once the rows are gone (ids and
     * SHA-256 of addresses only: the progress keeps no personal data).
     *
     * @param  array<string, mixed>  $progress
     */
    private function collect(int $organizationId, array &$progress): void
    {
        $users = [];
        $emails = [];

        foreach (['organization_user' => 'user_id', 'tenant_profiles' => 'user_id', 'owner_profiles' => 'user_id'] as $table => $column) {
            foreach (DB::table($table)->where('organization_id', $organizationId)->whereNotNull($column)->pluck($column) as $id) {
                $users[] = (int) $id;
            }
        }

        foreach (['tenant_profiles', 'owner_profiles', 'invitations'] as $table) {
            foreach (DB::table($table)->where('organization_id', $organizationId)->whereNotNull('email')->pluck('email') as $email) {
                $emails[] = hash('sha256', mb_strtolower((string) $email));
            }
        }

        foreach (DB::table('users')->whereIn('id', $users)->whereNotNull('email')->pluck('email') as $email) {
            $emails[] = hash('sha256', mb_strtolower((string) $email));
        }

        $progress['users'] = array_values(array_unique($users));
        $progress['emails'] = array_values(array_unique($emails));
    }

    /**
     * Every file of the organization but those kept, evidence included.
     *
     * @param  array<string, mixed>  $progress
     */
    private function files(int $organizationId, float $deadline, array &$progress): bool
    {
        $kept = $this->keptIds($organizationId)['media'] ?? [];

        // At least one batch a run, however little time is left.
        do {
            $batch = Media::query()->whereNotIn('id', $kept)->orderBy('id')->limit(50)->get();

            Media::purging(function () use ($batch, &$progress): void {
                foreach ($batch as $media) {
                    $media->delete();
                    $progress['counts']['files'] = (int) ($progress['counts']['files'] ?? 0) + 1;
                }
            });
        } while ($batch->isNotEmpty() && microtime(true) < $deadline);

        if (Media::query()->whereNotIn('id', $kept)->exists()) {
            return false;
        }

        // Transaction Tier documents live on their own encrypted disk (ARCHITECTURE.md § 9).
        foreach (DB::table('compliance_documents')->where('organization_id', $organizationId)->get(['disk', 'path']) as $document) {
            if (is_string($document->disk) && is_string($document->path) && $document->path !== '') {
                Storage::disk($document->disk)->delete($document->path);
            }
        }

        // P01-05's branding files, left where they were when they moved to the media library.
        $disk = config('branding.disk');
        $folder = config('branding.folder');

        if (is_string($disk) && is_string($folder)) {
            Storage::disk($disk)->deleteDirectory($folder.'/'.$organizationId);
        }

        return true;
    }

    /** Clears every link that would stop a purged row from going. */
    private function unlink(int $organizationId): void
    {
        $purged = $this->purgedTables();

        foreach ($this->foreignKeys() as $child => $keys) {
            foreach ($keys as $key) {
                $parent = $key['table'];

                if (! in_array($parent, $purged, true) || $key['cascades']) {
                    continue;
                }

                $childPurged = in_array($child, $purged, true);
                $childHasOrganization = Schema::hasColumn($child, 'organization_id');
                $parentIds = DB::table($parent)->select('id')->where('organization_id', $organizationId);

                // The organization's own rows: clear nullable links (self-links and cycles included).
                if ($childPurged && $key['nullable']) {
                    DB::table($child)->where('organization_id', $organizationId)->whereNotNull($key['column'])->update([$key['column'] => null]);
                }

                // Rows of other organizations (or of no organization) pointing at the purged rows.
                $outside = DB::table($child)->whereIn($key['column'], $parentIds);

                // This organization's own rows are handled above (purged tables) or
                // keep what they point at (kept tables, keptIds()).
                if ($childHasOrganization) {
                    $outside->where('organization_id', '!=', $organizationId);
                }

                if (! $outside->exists()) {
                    continue;
                }

                if (! $key['nullable']) {
                    throw new RuntimeException("Rows of {$child} outside the organization need its {$parent}: the purge cannot remove them.");
                }

                // By id: MySQL refuses an UPDATE whose subquery reads the same table (a self-link).
                foreach (array_chunk(array_map(intval(...), $outside->pluck('id')->all()), 1000) as $ids) {
                    DB::table($child)->whereIn('id', $ids)->update([$key['column'] => null]);
                }
            }
        }
    }

    /** @param  array<string, mixed>  $progress */
    private function rows(int $organizationId, float $deadline, array &$progress): bool
    {
        $order = $this->deleteOrder();
        $kept = $this->keptIds($organizationId);

        while (isset($order[(int) $progress['table']])) {
            $table = $order[(int) $progress['table']];

            do {
                $deleted = DB::table($table)->where('organization_id', $organizationId)->whereNotIn('id', $kept[$table] ?? [])->limit(self::CHUNK)->delete();
                $progress['counts'][$table] = (int) ($progress['counts'][$table] ?? 0) + $deleted;

                // At least one chunk a run; a table cut short carries on next run.
                if ($deleted === self::CHUNK && microtime(true) >= $deadline) {
                    return false;
                }
            } while ($deleted === self::CHUNK);

            $progress['table'] = (int) $progress['table'] + 1;

            if (microtime(true) >= $deadline) {
                return false;
            }
        }

        return true;
    }

    /** @param  array<string, mixed>  $progress */
    private function finishAccounts(int $organizationId, array &$progress): void
    {
        DB::transaction(function () use ($organizationId, &$progress): void {
            $anonymised = 0;

            foreach ($progress['users'] as $userId) {
                $anonymised += $this->accounts->anonymiseIfUnused((int) $userId) ? 1 : 0;
            }

            $progress['counts']['accounts'] = $anonymised;

            $lifted = 0;

            foreach (DB::table('email_suppressions')->pluck('email') as $email) {
                if (in_array(hash('sha256', mb_strtolower((string) $email)), $progress['emails'], true)) {
                    $lifted += $this->accounts->liftSuppressions([(string) $email]);
                }
            }

            $progress['counts']['email_suppressions'] = $lifted;

            DB::table('consent_records')->where('organization_id', $organizationId)->update(['ip_address' => null, 'user_agent' => null]);

            // The tombstone: its name stays on GoProperty's kept invoices (default 23).
            DB::table('organizations')->where('id', $organizationId)->update([
                'slug' => 'purged-'.$organizationId,
                'previous_slugs' => null,
                'cac_number' => null,
                'address' => null,
                'state' => null,
                'lga' => null,
                'logo_media_id' => null,
                'branding' => null,
                'online_payment_cap_kobo' => null,
                'updated_at' => now(),
            ]);
            DB::table('organizations')->where('id', $organizationId)->whereNull('deleted_at')->update(['deleted_at' => now()]);

            $progress['users'] = [];
            $progress['emails'] = [];
        });
    }

    /**
     * Rows of purged tables that GoProperty's kept records point at (e.g.
     * the bank-transfer proof of a subscription payment).
     *
     * @return array<string, list<int>>
     */
    private function keptIds(int $organizationId): array
    {
        $kept = [];

        foreach ($this->foreignKeys() as $child => $keys) {
            if ($this->map->rule($child)?->keptOnPurge === null) {
                continue;
            }

            foreach ($keys as $key) {
                foreach (DB::table($child)->where('organization_id', $organizationId)->whereNotNull($key['column'])->pluck($key['column']) as $id) {
                    $kept[$key['table']][] = (int) $id;
                }
            }
        }

        return array_map(static fn (array $ids): array => array_values(array_unique($ids)), $kept);
    }

    /** @return array<string, list<array{column: string, table: string, nullable: bool, cascades: bool}>> */
    private function foreignKeys(): array
    {
        if ($this->foreignKeys !== null) {
            return $this->foreignKeys;
        }

        $keys = [];

        foreach (Schema::getTableListing(Schema::getConnection()->getDatabaseName(), schemaQualified: false) as $table) {
            $nullable = [];

            foreach (Schema::getColumns($table) as $column) {
                $nullable[$column['name']] = (bool) $column['nullable'];
            }

            foreach (Schema::getForeignKeys($table) as $key) {
                if (count($key['columns']) !== 1) {
                    continue;
                }

                $column = $key['columns'][0];
                $keys[$table][] = [
                    'column' => $column,
                    'table' => (string) $key['foreign_table'],
                    'nullable' => $nullable[$column] ?? false,
                    'cascades' => in_array(strtolower((string) $key['on_delete']), ['cascade'], true),
                ];
            }
        }

        return $this->foreignKeys = $keys;
    }
}
