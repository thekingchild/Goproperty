<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Models\DataRequest;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Platform\Approvals\ApprovalAction;
use App\Platform\Approvals\ApprovalService;
use App\Platform\Staff\StaffPermission;
use App\Privacy\Erasure\RetentionRules;
use App\Privacy\Jobs\RunDataRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Data protection requests (P09-02; NDPA 2023 ss. 34–36; PLATFORM_CONSOLE.md
 * § 11): the only writer of `data_requests`, besides the job that carries
 * them out (RunDataRequest).
 *
 * - **Export** (one tenant of an organization, or the whole organization):
 *   asked by GoProperty staff in the console on a verified request, or by an
 *   organization's owner for their own organization or one of its tenants;
 *   queued at once; the archive is downloaded through a signed link that
 *   lives 15 minutes, every download audited, and the file deleted after
 *   7 days (defaults 4–8, 11).
 * - **Erasure** (a tenant anonymised, or a cancelled or deleted organization
 *   purged): asked by staff only, approved by a second staff member with
 *   `data.purge` (PLATFORM_CONSOLE.md § 1), then carried out by the
 *   scheduler once the retention period has passed (RetentionRules;
 *   RetentionEnforcer).
 *
 * The reason is the request's reference and why it is lawful ("Ticket 4411:
 * tenant asked by email, identity checked"); it stays on the request and is
 * never written to the audit log.
 */
final readonly class DataRequests
{
    /** Minutes a download link works. */
    public const int LINK_MINUTES = 15;

    /** Days an export's file is kept. */
    public const int FILE_DAYS = 7;

    public function __construct(
        private RetentionRules $retention,
        private ApprovalService $approvals,
        private PrivacyAudit $audit,
    ) {}

    public function export(Organization $organization, ?int $tenantProfileId, string $reason, PlatformStaff|User $by): DataRequest
    {
        $this->authorize($organization, $by);

        $scope = $tenantProfileId === null ? DataRequestScope::Organization : DataRequestScope::Tenant;

        if ($tenantProfileId !== null && ! DB::table('tenant_profiles')->where('organization_id', $organization->id)->where('id', $tenantProfileId)->exists()) {
            throw new DataRequestRefused('That organization has no such tenant.');
        }

        if ($tenantProfileId !== null && $this->retention->tenantErased($organization->id, $tenantProfileId)) {
            throw new DataRequestRefused('This tenant\'s data has been erased: there is nothing left to export.');
        }

        if ($this->retention->organizationPurged($organization->id)) {
            throw new DataRequestRefused('This organization\'s data has been purged.');
        }

        $request = DB::transaction(function () use ($organization, $tenantProfileId, $scope, $reason, $by): DataRequest {
            $this->refuseDuplicate(DataRequestKind::Export, $organization, $tenantProfileId);

            $request = $this->open(DataRequestKind::Export, $scope, $organization, $tenantProfileId, $reason, $by, DataRequestStatus::Queued);
            $this->audit->record('data_export.requested', $request, $by, 'Data export requested: '.$request->summary());

            return $request;
        });

        RunDataRequest::dispatch($request->id)->afterCommit();

        return $request;
    }

    /** Opens an erasure and its two-person approval request (staff only). */
    public function erasure(Organization $organization, ?int $tenantProfileId, string $reason, PlatformStaff $by): DataRequest
    {
        $this->authorize($organization, $by);

        $refusal = $tenantProfileId !== null
            ? $this->retention->tenantRefusal($organization->id, $tenantProfileId)
            : $this->retention->organizationRefusal($organization->id);

        if ($refusal !== null) {
            throw new DataRequestRefused($refusal);
        }

        return DB::transaction(function () use ($organization, $tenantProfileId, $reason, $by): DataRequest {
            $this->refuseDuplicate(DataRequestKind::Erasure, $organization, $tenantProfileId);

            $scope = $tenantProfileId === null ? DataRequestScope::Organization : DataRequestScope::Tenant;
            $request = $this->open(DataRequestKind::Erasure, $scope, $organization, $tenantProfileId, $reason, $by, DataRequestStatus::AwaitingApproval);
            $what = $tenantProfileId === null ? "Purge organization #{$organization->id}'s data" : "Erase tenant #{$tenantProfileId} of organization #{$organization->id}";
            $approval = $this->approvals->request(ApprovalAction::DataPurge, $by, $request, "{$what} (NDPA)", ['data_request_id' => $request->id], $reason);

            $request->forceFill(['approval_request_id' => $approval->id])->save();
            $this->audit->record('data_erasure.requested', $request, $by, 'Erasure requested: '.$request->summary(), ['approval_request_id' => $approval->id]);

            return $request;
        });
    }

    /** Withdraws an erasure that has not started (the approval request, if pending, is left to expire or be rejected). */
    public function cancel(DataRequest $request, PlatformStaff $by): void
    {
        if (! $by->holdsStaffPermission(StaffPermission::PrivacyRequests)) {
            throw new DataRequestRefused('You may not withdraw data protection requests.');
        }

        DB::transaction(function () use ($request, $by): void {
            $locked = DataRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->kind !== DataRequestKind::Erasure || ! in_array($locked->status, [DataRequestStatus::AwaitingApproval, DataRequestStatus::Waiting], true)) {
                throw new DataRequestRefused('Only an erasure that has not started can be withdrawn.');
            }

            $locked->forceFill(['status' => DataRequestStatus::Cancelled, 'failure' => 'Withdrawn by staff.', 'completed_at' => now()])->save();
            $request->setRawAttributes($locked->getAttributes(), true);
            $this->audit->record('data_erasure.withdrawn', $locked, $by, 'Erasure withdrawn: '.$locked->summary());
        });
    }

    /** A link to the export's file, signed for LINK_MINUTES, for the console or the workspace. */
    public function downloadUrl(DataRequest $request, bool $console): string
    {
        if (! $request->isDownloadable()) {
            throw new DataRequestRefused('This export has no file to download.');
        }

        $expires = now()->addMinutes(self::LINK_MINUTES);

        return $console
            ? URL::temporarySignedRoute('filament.console.data-request-download', $expires, ['dataRequest' => $request->id])
            : URL::temporarySignedRoute('workspace.settings.data-export.download', $expires, [
                'organization' => Organization::query()->findOrFail($request->organization_id)->slug,
                'dataRequest' => $request->id,
            ]);
    }

    public function recordDownload(DataRequest $request, PlatformStaff|User $by): void
    {
        DataRequest::query()->whereKey($request->id)->update([
            'downloads' => DB::raw('downloads + 1'),
            'last_downloaded_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->record('data_export.downloaded', $request, $by, 'Data export downloaded: '.$request->summary());
    }

    /** Deletes the files of exports past their 7 days. */
    public function expireFiles(): int
    {
        $expired = 0;

        DataRequest::query()->whereNotNull('file_path')->whereNull('file_deleted_at')->where('file_expires_at', '<=', now())->orderBy('id')
            ->each(function (DataRequest $request) use (&$expired): void {
                Storage::disk(Export\DataExportBuilder::DISK)->delete((string) $request->file_path);
                $request->forceFill(['file_deleted_at' => now()])->save();
                $this->audit->record('data_export.file_deleted', $request, null, 'Data export file deleted after '.self::FILE_DAYS.' days: '.$request->summary());
                $expired++;
            });

        return $expired;
    }

    /** Staff need `privacy.requests`; a member, `privacy.export` in the organization (OrganizationPolicy::exportData). */
    private function authorize(Organization $organization, PlatformStaff|User $by): void
    {
        $allowed = $by instanceof PlatformStaff
            ? $by->holdsStaffPermission(StaffPermission::PrivacyRequests)
            : Gate::forUser($by)->allows('exportData', $organization);

        if (! $allowed) {
            throw new DataRequestRefused('You may not make data protection requests for this organization.');
        }
    }

    private function refuseDuplicate(DataRequestKind $kind, Organization $organization, ?int $tenantProfileId): void
    {
        $open = DataRequest::query()
            ->where('organization_id', $organization->id)
            ->where('kind', $kind)
            ->when($tenantProfileId === null, fn ($query) => $query->whereNull('tenant_profile_id'), fn ($query) => $query->where('tenant_profile_id', $tenantProfileId))
            ->whereIn('status', [DataRequestStatus::AwaitingApproval, DataRequestStatus::Waiting, DataRequestStatus::Queued, DataRequestStatus::Running])
            ->lockForUpdate()
            ->exists();

        if ($open) {
            throw new DataRequestRefused($kind === DataRequestKind::Export ? 'The same export is already being made.' : 'An erasure of the same data is already open.');
        }
    }

    private function open(DataRequestKind $kind, DataRequestScope $scope, Organization $organization, ?int $tenantProfileId, string $reason, PlatformStaff|User $by, DataRequestStatus $status): DataRequest
    {
        $request = new DataRequest;
        $request->forceFill([
            'kind' => $kind,
            'scope' => $scope,
            'organization_id' => $organization->id,
            'tenant_profile_id' => $tenantProfileId,
            'status' => $status,
            'reason' => mb_substr(trim($reason), 0, 500),
            'requested_by_staff_id' => $by instanceof PlatformStaff ? $by->id : null,
            'requested_by_user_id' => $by instanceof User ? $by->id : null,
        ])->save();

        return $request;
    }
}
