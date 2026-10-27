<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ComplianceDocumentStatus;
use App\Models\PlatformStaff;
use App\Platform\Compliance\ReviewDocument;
use App\Platform\Staff\StaffPermission as P;

/**
 * Compliance documents in the console (ONBOARDING_COMPLIANCE.md § 5.3:
 * visible only to compliance roles, every view logged). The list of
 * documents (type, dates, status) needs `tiers.review`; opening a file,
 * and seeing document numbers and names, needs `compliance.view_documents`
 * (`compliance-officer`, `super-admin`; never `operations-manager`,
 * `finance-admin` or `support-agent`, PLATFORM_CONSOLE.md § 1).
 */
final class ReviewDocumentPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::TiersReview);
    }

    public function view(PlatformStaff $actor, ReviewDocument $document): bool
    {
        return $this->viewAny($actor);
    }

    /** The file itself, and the encrypted details on it. */
    public function viewFile(PlatformStaff $actor, ReviewDocument $document): bool
    {
        return $actor->holdsStaffPermission(P::ComplianceViewDocuments);
    }

    public function accept(PlatformStaff $actor, ReviewDocument $document): bool
    {
        return $this->viewAny($actor) && $document->status === ComplianceDocumentStatus::Pending && $this->applicationOpen($document);
    }

    public function reject(PlatformStaff $actor, ReviewDocument $document): bool
    {
        return $this->viewAny($actor)
            && in_array($document->status, [ComplianceDocumentStatus::Pending, ComplianceDocumentStatus::Accepted], true)
            && $this->applicationOpen($document);
    }

    public function create(PlatformStaff $actor): bool
    {
        return false;
    }

    public function update(PlatformStaff $actor, ReviewDocument $document): bool
    {
        return false;
    }

    public function delete(PlatformStaff $actor, ReviewDocument $document): bool
    {
        return false;
    }

    public function deleteAny(PlatformStaff $actor): bool
    {
        return false;
    }

    private function applicationOpen(ReviewDocument $document): bool
    {
        return $document->deleted_at === null && $document->application?->status->isOpen() === true;
    }
}
