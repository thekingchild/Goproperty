<?php

declare(strict_types=1);

namespace App\Platform\Operations;

use Illuminate\Database\Eloquent\Model;

/**
 * The operations dashboard's entries in the hash-chained audit log
 * (PLATFORM_CONSOLE.md § 2, § 7), log name `operations`: alerts raised and
 * cleared (no causer: the checks raise them), test alerts and failed jobs
 * retried or forgotten (the staff member as causer). Never a secret, a job's
 * payload or personal data.
 */
final class OperationsAudit
{
    public const string LOG = 'operations';

    /** @param  array<string, mixed>  $properties */
    public function record(string $event, string $description, ?Model $subject = null, ?Model $causer = null, array $properties = []): void
    {
        $log = activity(self::LOG)->event($event)->withProperties($properties);
        $log = $subject !== null ? $log->performedOn($subject) : $log;
        $log = $causer !== null ? $log->causedBy($causer) : $log->causedByAnonymous();
        $log->log($description);
    }
}
