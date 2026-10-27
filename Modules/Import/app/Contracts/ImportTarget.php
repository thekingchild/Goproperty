<?php

declare(strict_types=1);

namespace Modules\Import\Contracts;

/**
 * One kind of record a spreadsheet sheet can import (DATA_IMPORT.md § 1):
 * owners, properties, units, tenants, … Each lives in the module that owns
 * the records and is registered from that module's service provider into
 * ImportTargets, so the Import module never touches another module's
 * tables (ARCHITECTURE.md § 8).
 *
 * The engine calls, for every non-blank row of the sheet mapped to the
 * target:
 * - dry run: validate(), then simulate() when there is no error. Nothing
 *   may be written (the engine also runs the dry run inside a transaction
 *   it rolls back, but a target must not rely on that).
 * - commit: validate() again against the database as it is now (rows with
 *   an error are skipped and reported), then upsert(), inside the engine's
 *   transaction for the chunk of rows; never commit or open your own.
 *
 * Matching (DATA_IMPORT.md § 2 "Re-running and updates"): by the row's
 * `external_ref` when it has one, otherwise by the target's natural key
 * (tenant by phone, property by name, …). Matching includes archived (soft
 * deleted) records: a match restores the record instead of colliding with
 * it (P02 decision 3). A record the batch creates or restores carries the
 * batch's id in `import_batch_id`; a record it only updates keeps its own.
 */
interface ImportTarget
{
    /** Stable key, lower case (e.g. `owners`): the batch kind for a one-sheet file, saved mappings, the template. */
    public function key(): string;

    /** The sheet name in the template and on screen, e.g. "Owners". */
    public function label(): string;

    /** Commit order: lower first (owners → properties → units → tenants → leases → …, DATA_IMPORT.md § 2.4). */
    public function order(): int;

    /** One or two sentences for the template's Instructions sheet. */
    public function instructions(): string;

    /** @return list<ImportColumn> the columns, in template order */
    public function columns(): array;

    /**
     * Errors (blocking) and warnings (not blocking) for one row. Reads only.
     * Remember the keys of rows that would be created (ImportRun::remember)
     * so later rows and later sheets can refer to them during a dry run.
     *
     * @return list<RowIssue>
     */
    public function validate(ImportRow $row, ImportRun $run): array;

    /** What upsert() would do with a valid row, and how it would look. Reads only. */
    public function simulate(ImportRow $row, ImportRun $run): RowPlan;

    /** Creates, updates or restores the record for a valid row; ImportRun::batchId() is set. */
    public function upsert(ImportRow $row, ImportRun $run): UpsertResult;

    /**
     * Soft deletes every record the batch created or restored (import_batch_id
     * = the batch), audited by the caller. Records it only updated keep
     * their changes. Runs inside the caller's transaction.
     *
     * @return int the number of records archived
     */
    public function rollback(int $batchId): int;

    /**
     * Whether any record of the batch has been used since it was imported
     * (a payment recorded, a bill sent, a lease started on it, …): then
     * the batch can no longer be rolled back.
     */
    public function usedSince(int $batchId): bool;
}
