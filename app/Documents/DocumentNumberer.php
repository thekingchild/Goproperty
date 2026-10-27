<?php

declare(strict_types=1);

namespace App\Documents;

use App\Branding\Branding;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Document numbers (ARCHITECTURE.md § 6.5, BRANDING_DOCUMENTS.md § 1 and
 * § 5): `{PREFIX}-{YYYY}-{seq}`, e.g. `INV-ADR-2027-000142`, where the
 * prefix is the document type's prefix in the organization's branding (it
 * carries the organization's code), the year is the year of issue in Lagos
 * and the sequence counts from 1 per organization, document type and year,
 * zero-padded to six digits (longer once it passes 999999).
 *
 * Numbers are gap-free: next() takes the number inside the caller's
 * transaction with the counter row locked (`SELECT … FOR UPDATE`) until
 * that transaction ends. A second issue waits for the first; when the first
 * rolls back, its number is given to the next one. Never `max(id) + 1`.
 * Calling it outside a transaction is refused.
 *
 * Works on MySQL 8 and MariaDB 10.6+ (InnoDB, REPEATABLE READ or READ
 * COMMITTED). The locking read only ever looks up a row that exists: a
 * locking read of a missing row would lock the gap where it belongs, and
 * two first numbers holding that gap would deadlock inserting into it. So a
 * year's first number creates its counter row (at 0) on a connection of its
 * own, committed at once whatever the issue does: a rolled-back issue then
 * leaves the row behind for the next one instead of taking it away from
 * under the issues waiting for it. Two first numbers racing to create it
 * meet at the unique key (`INSERT IGNORE`) and both go on to lock the one
 * row. When the organization itself is not committed yet (it was created in
 * the same transaction, as in tests), the row is created in the caller's
 * transaction instead (`INSERT … ON DUPLICATE KEY UPDATE`); an issue that
 * waits there for a creator that rolls back can be chosen as a deadlock
 * victim, which DB::transaction($callback, $attempts) runs again.
 */
final readonly class DocumentNumberer
{
    /** The calendar the year of issue is read in. */
    public const string TIMEZONE = 'Africa/Lagos';

    public const int SEQUENCE_DIGITS = 6;

    public function __construct(private OrganizationContext $context) {}

    /**
     * The next number of this type for the current organization, taken in
     * the caller's transaction. Pass the branding the document is issued
     * with (its BrandingSnapshot's) so the number's prefix matches the
     * printed document; by default the organization's current branding.
     *
     * @throws NumberingRequiresTransaction
     */
    public function next(DocumentType $type, ?DateTimeInterface $issuedAt = null, ?Branding $branding = null): string
    {
        $organization = $this->context->get();

        if ((new DocumentCounter)->getConnection()->transactionLevel() < 1) {
            throw NumberingRequiresTransaction::for($type);
        }

        $year = self::yearOf($issuedAt ?? CarbonImmutable::now());
        $sequence = $this->take($type, $year);

        return self::format($type->prefix($branding ?? Branding::fromOrganization($organization)), $year, $sequence);
    }

    /** "INV-ADR-2027-000142". */
    public static function format(string $prefix, int $year, int $sequence): string
    {
        return $prefix.'-'.$year.'-'.str_pad((string) $sequence, self::SEQUENCE_DIGITS, '0', STR_PAD_LEFT);
    }

    /** The year of issue, as the calendar in Lagos has it. */
    public static function yearOf(DateTimeInterface $issuedAt): int
    {
        return CarbonImmutable::instance($issuedAt)->setTimezone(self::TIMEZONE)->year;
    }

    /** Raises the counter by one under its row lock and returns the new value. */
    private function take(DocumentType $type, int $year): int
    {
        $key = ['doc_type' => $type->value, 'year' => $year];

        // A plain read sees the transaction's snapshot; a row created since
        // is found by createCounter(), which reads committed rows afresh.
        if (DocumentCounter::query()->where($key)->exists() || $this->createCounter($type, $year)) {
            $counter = DocumentCounter::query()->where($key)->lockForUpdate()->firstOrFail();
            $counter->last_seq++;
            $counter->save();

            return $counter->last_seq;
        }

        DocumentCounter::query()->upsert(
            [$key + ['organization_id' => $this->context->id(), 'last_seq' => 1]],
            ['organization_id', 'doc_type', 'year'],
            ['last_seq' => new Expression('last_seq + 1')],
        );

        return DocumentCounter::query()->where($key)->lockForUpdate()->firstOrFail()->last_seq;
    }

    /**
     * Makes sure the counter row exists, committed, from a connection of
     * its own. False when that connection cannot see the organization yet
     * (it is not committed), or waited too long for it.
     */
    private function createCounter(DocumentType $type, int $year): bool
    {
        $main = (new DocumentCounter)->getConnection();
        $name = $main->getName().'_document_counters';
        $config = (array) $main->getConfig();
        unset($config['name']);

        $side = DB::connectUsing($name, $config, force: true);

        try {
            // Bounded, in case the caller's own transaction holds the
            // organization row the foreign key check reads.
            $side->statement('SET SESSION innodb_lock_wait_timeout = 5');

            $organizationId = $this->context->id();

            if (! $side->table('organizations')->where('id', $organizationId)->exists()) {
                return false;
            }

            $row = ['organization_id' => $organizationId, 'doc_type' => $type->value, 'year' => $year];

            if (! $side->table('document_counters')->where($row)->exists()) {
                $now = CarbonImmutable::now();
                $side->table('document_counters')->insertOrIgnore($row + ['last_seq' => 0, 'created_at' => $now, 'updated_at' => $now]);
            }

            return true;
        } catch (QueryException) {
            return false;
        } finally {
            DB::purge($name);
        }
    }
}
