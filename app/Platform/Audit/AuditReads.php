<?php

declare(strict_types=1);

namespace App\Platform\Audit;

use App\Audit\AccessLog;
use App\Models\PlatformStaff;

/**
 * Staff reading the audit log or the access log is itself audited
 * (PLATFORM_CONSOLE.md § 7–8, P06-07c): log `audit`, the reader as causer,
 * once per page load (the pages call this from mount(), never on Livewire
 * re-renders), with the filters or the entry read in the properties.
 */
final class AuditReads
{
    public const string LOG = 'audit';

    /** @param  array<string, mixed>  $filters */
    public function auditLogViewed(PlatformStaff $reader, array $filters = [], ?string $search = null): void
    {
        $this->write($reader, 'audit.viewed', 'Viewed the audit log', ['view' => 'list'] + self::query($filters, $search));
    }

    public function entryViewed(PlatformStaff $reader, AuditLogEntry $entry): void
    {
        $this->write($reader, 'audit.viewed', "Viewed audit log entry #{$entry->id}", ['view' => 'entry', 'entry_id' => $entry->id]);
    }

    public function staffActionsViewed(PlatformStaff $reader, PlatformStaff $member): void
    {
        $this->write($reader, 'audit.viewed', "Viewed {$member->name}'s actions", ['view' => 'staff_actions', 'staff_id' => $member->id]);
    }

    /** @param  array<string, mixed>  $filters */
    public function accessLogViewed(PlatformStaff $reader, array $filters = [], ?string $search = null): void
    {
        // A searched email is kept as the access log keeps typed addresses,
        // its HMAC: "who looked up this person?" is answerable by someone
        // who knows the address, and the address is not copied around.
        $searched = $search !== null && trim($search) !== '' ? ['searched_identifier' => app(AccessLog::class)->hashIdentifier($search)] : [];

        $this->write($reader, 'access_log.viewed', 'Viewed the access log', self::query($filters, null) + $searched);
    }

    /** @param  array<string, mixed>  $properties */
    private function write(PlatformStaff $reader, string $event, string $description, array $properties): void
    {
        activity(self::LOG)
            ->event($event)
            ->causedBy($reader)
            ->withProperties($properties)
            ->log($description);
    }

    /**
     * The filters in use (empty ones dropped) and the search.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private static function query(array $filters, ?string $search): array
    {
        $used = self::filled($filters);

        return array_filter([
            'filters' => $used !== [] ? $used : null,
            'search' => $search !== null && trim($search) !== '' ? trim($search) : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function filled(array $values): array
    {
        $kept = [];

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $value = self::filled($value);
            }

            if ($value !== null && $value !== '' && $value !== [] && $value !== false) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }
}
