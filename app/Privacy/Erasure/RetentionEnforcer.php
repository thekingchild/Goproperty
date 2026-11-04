<?php

declare(strict_types=1);

namespace App\Privacy\Erasure;

use App\Models\DataRequest;
use App\Privacy\DataRequestKind;
use App\Privacy\DataRequests;
use App\Privacy\DataRequestStatus;
use App\Privacy\Jobs\RunDataRequest;

/**
 * `privacy-retention` (P09-02; every 15 minutes, ARCHITECTURE.md § 7):
 * enforces the retention windows. Approved erasures whose retention period
 * has passed are queued (RunDataRequest re-checks and carries them out);
 * erasures and exports left running by a stopped worker are queued again;
 * export files past their 7 days are deleted. Registered with
 * Schedule::call() (no shell), so it runs on shared hosting.
 */
final readonly class RetentionEnforcer
{
    public function __construct(private DataRequests $requests) {}

    /** @return array{queued: int, files_deleted: int} */
    public function run(): array
    {
        $queued = 0;

        DataRequest::query()
            ->where('kind', DataRequestKind::Erasure)
            ->where('status', DataRequestStatus::Waiting)
            ->where('due_at', '<=', now())
            ->orderBy('due_at')
            ->each(function (DataRequest $request) use (&$queued): void {
                $request->forceFill(['status' => DataRequestStatus::Queued])->save();
                RunDataRequest::dispatch($request->id);
                $queued++;
            });

        // A job lost with its worker: the request keeps its place and carries on.
        DataRequest::query()
            ->whereIn('status', [DataRequestStatus::Queued, DataRequestStatus::Running])
            ->where('updated_at', '<=', now()->subMinutes(30))
            ->each(function (DataRequest $request): void {
                $request->touch();
                RunDataRequest::dispatch($request->id);
            });

        return ['queued' => $queued, 'files_deleted' => $this->requests->expireFiles()];
    }
}
