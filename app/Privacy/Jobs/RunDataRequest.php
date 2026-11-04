<?php

declare(strict_types=1);

namespace App\Privacy\Jobs;

use App\Models\ApprovalRequest;
use App\Models\DataRequest;
use App\Models\Organization;
use App\Privacy\Data\Person;
use App\Privacy\Data\Scrubber;
use App\Privacy\DataRequestKind;
use App\Privacy\DataRequests;
use App\Privacy\DataRequestScope;
use App\Privacy\DataRequestStatus;
use App\Privacy\Erasure\OrganizationPurge;
use App\Privacy\Erasure\PersonErasure;
use App\Privacy\Erasure\RetentionRules;
use App\Privacy\Export\DataExportBuilder;
use App\Privacy\PrivacyAudit;
use App\Tenancy\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Carries out one data request (P09-02): an export's archive, a tenant's
 * erasure or an organization's purge, inside the request's organization.
 * Works for BUDGET_SECONDS, keeps its place on the request and queues
 * itself again until done (AGENT.md § 4: jobs under ~50 s on shared
 * hosting). Safe to run twice: one run per request at a time (a cache
 * lock), and a request no longer queued or running is left alone.
 *
 * An erasure is checked again when it starts: an organization reactivated,
 * or a tenant with a new lease or an unpaid bill, since approval is not
 * erased (the request is cancelled with the reason), and nothing runs
 * before `due_at`, the end of the retention period.
 */
final class RunDataRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int BUDGET_SECONDS = 35;

    public int $timeout = 60;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly int $dataRequestId)
    {
        $this->onQueue('default');
    }

    public function handle(OrganizationContext $context, DataExportBuilder $exports, PersonErasure $erasure, OrganizationPurge $purge, RetentionRules $retention, PrivacyAudit $audit): void
    {
        $lock = Cache::lock('data-request:'.$this->dataRequestId, $this->timeout + 30);

        if (! $lock->get()) {
            return;
        }

        try {
            $request = DataRequest::query()->find($this->dataRequestId);

            if ($request === null || ! in_array($request->status, [DataRequestStatus::Queued, DataRequestStatus::Running], true)) {
                return;
            }

            if ($request->kind === DataRequestKind::Erasure && ! $this->stillDue($request, $retention, $audit)) {
                return;
            }

            if ($request->status === DataRequestStatus::Queued) {
                $request->forceFill(['status' => DataRequestStatus::Running, 'started_at' => $request->started_at ?? now()])->save();
            }

            $organization = Organization::withTrashed()->findOrFail($request->organization_id);
            $deadline = microtime(true) + self::BUDGET_SECONDS;

            try {
                $done = $context->run($organization, fn (): bool => match (true) {
                    $request->kind === DataRequestKind::Export => $exports->work($request, $deadline),
                    $request->scope === DataRequestScope::Tenant => $this->eraseTenant($request, $erasure),
                    default => $purge->work($request, $deadline),
                });
            } catch (Throwable $exception) {
                $this->fail($request, $exception, $exports, $audit);

                return;
            }

            if (! $done) {
                self::dispatch($request->id);

                return;
            }

            $this->finish($request, $audit);
        } finally {
            $lock->release();
        }
    }

    private function eraseTenant(DataRequest $request, PersonErasure $erasure): bool
    {
        // The request's own reason may name the person: it loses their details first.
        $scrubber = new Scrubber(Person::load($request->organization_id, (int) $request->tenant_profile_id));
        $request->forceFill(['reason' => (string) $scrubber->text($request->reason)])->save();

        if ($request->approval_request_id !== null) {
            $reason = ApprovalRequest::query()->whereKey($request->approval_request_id)->value('reason');
            DB::table('approval_requests')->where('id', $request->approval_request_id)->update(['reason' => $scrubber->text(is_string($reason) ? $reason : null)]);
        }

        $request->forceFill(['result' => $erasure->erase($request->organization_id, (int) $request->tenant_profile_id)])->save();

        return true;
    }

    private function stillDue(DataRequest $request, RetentionRules $retention, PrivacyAudit $audit): bool
    {
        if ($request->due_at !== null && $request->due_at->isFuture()) {
            $request->forceFill(['status' => DataRequestStatus::Waiting])->save();

            return false;
        }

        if ($request->status === DataRequestStatus::Running) {
            return true;
        }

        $refusal = $request->scope === DataRequestScope::Tenant
            ? $retention->tenantRefusal($request->organization_id, (int) $request->tenant_profile_id)
            : $retention->organizationRefusal($request->organization_id);

        if ($refusal === null) {
            // The window is worked out again from today's records: a lease that
            // started and ended after approval moves the end of retention later.
            $due = $request->scope === DataRequestScope::Tenant
                ? $retention->tenantDueAt($request->organization_id, (int) $request->tenant_profile_id)
                : $retention->organizationDueAt($request->organization_id);

            if (! $due->isFuture()) {
                return true;
            }

            $request->forceFill(['status' => DataRequestStatus::Waiting, 'due_at' => $due])->save();
            $audit->record('data_erasure.postponed', $request, null, 'Erasure postponed to the end of the retention period: '.$request->summary(), ['due_at' => $due->toIso8601String()]);

            return false;
        }

        $request->forceFill(['status' => DataRequestStatus::Cancelled, 'failure' => $refusal, 'completed_at' => now()])->save();
        $audit->record('data_erasure.cancelled', $request, null, 'Erasure cancelled: '.$request->summary(), ['why' => $refusal]);

        return false;
    }

    private function finish(DataRequest $request, PrivacyAudit $audit): void
    {
        if ($request->kind === DataRequestKind::Export) {
            $request->forceFill([
                'status' => DataRequestStatus::Ready,
                'completed_at' => now(),
                'file_expires_at' => now()->addDays(DataRequests::FILE_DAYS),
                'progress' => null,
            ])->save();
            $audit->record('data_export.ready', $request, null, 'Data export ready: '.$request->summary(), ['rows' => $request->result['rows'] ?? [], 'size' => $request->file_size]);

            return;
        }

        $counts = $request->scope === DataRequestScope::Tenant ? ($request->result ?? []) : ($request->progress['counts'] ?? []);
        $request->forceFill(['status' => DataRequestStatus::Completed, 'completed_at' => now(), 'result' => $counts, 'progress' => null])->save();
        $audit->record(
            $request->scope === DataRequestScope::Tenant ? 'data_erasure.tenant_erased' : 'data_erasure.organization_purged',
            $request,
            null,
            'Erasure completed: '.$request->summary(),
            ['counts' => $counts],
        );
    }

    private function fail(DataRequest $request, Throwable $exception, DataExportBuilder $exports, PrivacyAudit $audit): void
    {
        report($exception);

        if ($request->kind === DataRequestKind::Export) {
            $exports->discard($request);
        }

        // The class and the first line only: never a value from the data.
        $why = class_basename($exception).': '.Str::limit(strtok($exception->getMessage(), "\n") ?: '', 300);
        $request->forceFill(['status' => DataRequestStatus::Failed, 'failure' => $why, 'completed_at' => now()])->save();
        $audit->record('data_request.failed', $request, null, 'Data request failed: '.$request->summary(), ['error' => class_basename($exception)]);
    }
}
