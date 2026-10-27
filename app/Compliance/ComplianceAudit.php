<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Audit\AuditContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes Transaction Tier applications, compliance documents, tier changes
 * and staff views of documents to the hash-chained audit log
 * (PLATFORM_CONSOLE.md § 7), log name `compliance`, with the person who
 * acted (the org-owner, or the staff member) as causer and the reason when
 * there is one. Document numbers and names never go into an entry.
 */
final readonly class ComplianceAudit
{
    public const string LOG = 'compliance';

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
