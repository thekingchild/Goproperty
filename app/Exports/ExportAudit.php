<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Organization;
use App\Models\User;

/**
 * Writes each export to the hash-chained audit log (PLATFORM_CONSOLE.md
 * § 7), log name `exports`, event `list.exported`, with the organization
 * as subject and the member as causer: which list, which format, the
 * filters it was exported with and how many rows it held. During a
 * support session AuditEntry adds the staff member. Written before the
 * first row is sent.
 */
final class ExportAudit
{
    public const string LOG = 'exports';

    /** @param  array<string, string|int|bool>  $filters */
    public function record(Organization $organization, User $user, ListExport $export, ExportFormat $format, array $filters, int $rows): void
    {
        activity(self::LOG)
            ->event('list.exported')
            ->performedOn($organization)
            ->causedBy($user)
            ->withProperties([
                'list' => $export->key(),
                'format' => $format->value,
                'filters' => $filters,
                'rows' => $rows,
            ])
            ->log("Exported {$export->title()} ({$format->label()}, {$rows} ".($rows === 1 ? 'row' : 'rows').')');
    }
}
