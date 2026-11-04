<?php

declare(strict_types=1);

namespace App\Platform\Operations\Queues;

use App\Models\PlatformStaff;
use App\Platform\Operations\OperationsAudit;
use App\Platform\Staff\StaffPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Retry or forget a failed job from the operations dashboard
 * (PLATFORM_CONSOLE.md § 2 "Queues"), for `queues.manage` (technical-admin,
 * super-admin), checked here and not only on the screen. Retrying puts the
 * job back on its queue as Laravel's `queue:retry` does (in-process, no
 * shell); forgetting removes the failed job's record. Each is one audit
 * entry. A retried job runs again from the start: jobs are written to be
 * safe to re-run (AGENT.md § 3 rule 6).
 */
final readonly class FailedJobs
{
    public function __construct(private FailedJobProviderInterface $failer, private OperationsAudit $audit) {}

    public function retry(PlatformStaff $staff, string $uuid): void
    {
        $job = $this->find($staff, $uuid);

        Artisan::call('queue:retry', ['id' => [$uuid]]);

        $this->audit->record('failed_job.retried', "Failed job {$job['job']} on {$job['queue']} put back on its queue", causer: $staff, properties: $job);
    }

    public function forget(PlatformStaff $staff, string $uuid): void
    {
        $job = $this->find($staff, $uuid);

        $this->failer->forget($uuid);

        $this->audit->record('failed_job.forgotten', "Failed job {$job['job']} on {$job['queue']} forgotten", causer: $staff, properties: $job);
    }

    /** @return array{uuid: string, queue: string, job: string} */
    private function find(PlatformStaff $staff, string $uuid): array
    {
        if (! Gate::forUser($staff)->allows(StaffPermission::QueuesManage->value)) {
            throw new AuthorizationException('Retrying and forgetting failed jobs needs "Queues and failed jobs".');
        }

        $row = $this->failer->find($uuid);

        if ($row === null) {
            throw new InvalidArgumentException('That failed job is no longer listed.');
        }

        $payload = json_decode(is_string($row->payload ?? null) ? $row->payload : '', true);

        return [
            'uuid' => $uuid,
            'queue' => is_string($row->queue ?? null) ? $row->queue : '',
            'job' => class_basename(is_array($payload) && is_string($payload['displayName'] ?? null) ? $payload['displayName'] : 'Unknown job'),
        ];
    }
}
