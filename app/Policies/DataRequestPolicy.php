<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DataRequest;
use App\Models\PlatformStaff;
use App\Platform\Staff\StaffPermission as P;
use App\Privacy\DataRequestKind;
use App\Privacy\DataRequestStatus;

/**
 * Console → Compliance → Data requests (P09-02; PLATFORM_CONSOLE.md § 11):
 * `privacy.requests` asks for exports and erasures, downloads exports and
 * withdraws an erasure not yet started; approvers (`data.purge`) see the
 * list too. Requests are never edited or deleted.
 */
final class DataRequestPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::PrivacyRequests) || $actor->holdsStaffPermission(P::DataPurge);
    }

    public function view(PlatformStaff $actor, DataRequest $request): bool
    {
        return $this->viewAny($actor);
    }

    public function create(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::PrivacyRequests);
    }

    public function download(PlatformStaff $actor, DataRequest $request): bool
    {
        return $request->isDownloadable() && $actor->holdsStaffPermission(P::PrivacyRequests);
    }

    public function cancel(PlatformStaff $actor, DataRequest $request): bool
    {
        return $request->kind === DataRequestKind::Erasure
            && in_array($request->status, [DataRequestStatus::AwaitingApproval, DataRequestStatus::Waiting], true)
            && $actor->holdsStaffPermission(P::PrivacyRequests);
    }

    public function update(PlatformStaff $actor, DataRequest $request): bool
    {
        return false;
    }

    public function delete(PlatformStaff $actor, DataRequest $request): bool
    {
        return false;
    }
}
