<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A workspace list that exports to CSV and Excel (DATA_IMPORT.md § 6,
 * AGENT.md § 5): the list's own query (filters, search, sort, the
 * organization scope and any narrowing, such as an agent's buildings) and
 * the columns of its export. The page builds its rows from the same query,
 * so an export holds exactly the rows the member can see on the page, on
 * every page of it. Lives in the module that owns the list and is
 * registered into ListExports from the module's provider.
 *
 * Most lists extend QueryListExport (an Eloquent query, read in chunks).
 */
interface ListExport
{
    /** Lower-case key, unique among exports: `owners`, `units`, `billing_transfers_made`. */
    public function key(): string;

    /** What the list is called, for the file name and the audit log: "Owners". */
    public function title(): string;

    /**
     * The filters the list understands, cleaned: unknown keys dropped,
     * values that are not valid for the list removed. The page passes its
     * current filters through here too.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|int|bool>
     */
    public function filters(array $input): array;

    /**
     * Whether the member may export the list (the list's policy, `export…`
     * abilities: reads, so open while suspended; a list holding people's
     * contact details marks its ability #[BlockedWhileImpersonating]).
     *
     * @param  array<string, string|int|bool>  $filters  cleaned
     *
     * @throws AuthorizationException
     */
    public function authorize(User $user, array $filters): void;

    /**
     * The export's columns; a column the member may not see is left out
     * (the page does not show it either).
     *
     * @return list<ExportColumn>
     */
    public function columns(User $user): array;

    /**
     * Every row of the list, in the page's order, read a chunk at a time.
     *
     * @param  array<string, string|int|bool>  $filters  cleaned
     * @return iterable<mixed>
     */
    public function rows(User $user, array $filters): iterable;

    /**
     * How many rows rows() gives.
     *
     * @param  array<string, string|int|bool>  $filters  cleaned
     */
    public function count(User $user, array $filters): int;
}
