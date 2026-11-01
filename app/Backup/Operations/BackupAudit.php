<?php

declare(strict_types=1);

namespace App\Backup\Operations;

use App\Audit\AuditContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the backups console's actions to the hash-chained audit log
 * (PLATFORM_CONSOLE.md § 4, § 7), log name `backups`: what was asked, run,
 * downloaded, restored and changed, with the staff member as causer.
 * Destination credentials never go into an entry.
 */
final readonly class BackupAudit
{
    public const string LOG = 'backups';

    public function __construct(private AuditContext $context) {}

    /** @param  array<string, mixed>  $properties */
    public function record(string $event, Model $subject, ?Model $causer, string $description, array $properties = [], ?string $reason = null): void
    {
        $write = function () use ($event, $subject, $causer, $description, $properties): void {
            $log = activity(self::LOG)->event($event)->performedOn($subject)->withProperties($properties);
            $log = $causer !== null ? $log->causedBy($causer) : $log->causedByAnonymous();
            $log->log($description);
        };

        $reason !== null && $reason !== '' ? $this->context->because($reason, $write) : $write();
    }
}
