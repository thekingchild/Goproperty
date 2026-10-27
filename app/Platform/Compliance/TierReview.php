<?php

declare(strict_types=1);

namespace App\Platform\Compliance;

use App\Compliance\ComplianceAudit;
use App\Compliance\OrganizationTier;
use App\Compliance\RequiredDocuments;
use App\Enums\ChecklistItem;
use App\Enums\ComplianceDocumentStatus;
use App\Enums\TierApplicationStatus;
use App\Models\ComplianceDocument;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\TierApplication;
use App\Notifications\TierApplicationDecided;
use App\Onboarding\OnboardingChecklist;
use App\Platform\Staff\StaffPermission;
use App\Tenancy\OrganizationContext;
use App\Tenancy\OrganizationOwners;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Compliance staff review Transaction Tier applications
 * (ONBOARDING_COMPLIANCE.md § 5.3, PLATFORM_CONSOLE.md § 1): `tiers.review`
 * to start a review, ask for more information and accept or reject single
 * documents; `tiers.approve` to approve or reject the application (both
 * held by `compliance-officer` and `super-admin`). Each step re-checks the
 * permission and the application's state with its row locked, is written
 * to the audit log (log `compliance`) with the staff member and the reason,
 * and the org-owners are emailed about decisions and requests.
 *
 * Approval moves the organization to the tier asked for through
 * App\Compliance\OrganizationTier (never directly) and ticks the setup
 * checklist's Tier item. Rejection changes nothing on the organization.
 * Works on App\Models\TierApplication inside the organization's context.
 */
final readonly class TierReview
{
    public const int NOTE_MAX = 2000;

    public function __construct(
        private OrganizationContext $context,
        private ComplianceAudit $audit,
        private OrganizationTier $tiers,
        private RequiredDocuments $requirements,
        private OnboardingChecklist $checklist,
        private OrganizationOwners $owners,
    ) {}

    /** Marks a submitted application as being reviewed by this staff member. @throws TierReviewRefused */
    public function startReview(PlatformStaff $actor, ReviewApplication $record): void
    {
        $this->ensure($actor, StaffPermission::TiersReview);

        $this->within($record, function (TierApplication $application, Organization $organization) use ($actor): void {
            if ($application->status !== TierApplicationStatus::Submitted) {
                throw new TierReviewRefused('Only a submitted application can be taken for review.');
            }

            $this->move($application, TierApplicationStatus::UnderReview, $actor);
            $this->audit->record('tier_application.review_started', $application, $actor, "Started reviewing {$organization->name}'s {$application->requested_tier->label()} application", $this->change(TierApplicationStatus::Submitted, $application));
        });
    }

    /** Sends the application back to the owner with what is needed. @throws TierReviewRefused */
    public function requestMoreInfo(PlatformStaff $actor, ReviewApplication $record, string $request): void
    {
        $this->ensure($actor, StaffPermission::TiersReview);
        $request = $this->text($request, 'Say what the organization must send or explain.');

        $this->within($record, function (TierApplication $application, Organization $organization) use ($actor, $request): void {
            $from = $this->ensureWithCompliance($application);

            $application->review_note = $request;
            $this->move($application, TierApplicationStatus::MoreInfoNeeded, $actor);

            $this->audit->record('tier_application.more_info_requested', $application, $actor, "Asked {$organization->name} for more information", $this->change($from, $application), $request);
            $this->notifyOwners($organization, $application, $request);
        });
    }

    /**
     * Approves the application: the organization moves to the tier asked
     * for, its pending documents are accepted, the owners are emailed.
     *
     * @throws TierReviewRefused
     */
    public function approve(PlatformStaff $actor, ReviewApplication $record, string $reason, ?string $noteToOwner = null): void
    {
        $this->ensure($actor, StaffPermission::TiersApprove);
        $reason = $this->text($reason, 'Give a reason: it is kept in the audit log with your name.');
        $noteToOwner = $this->optionalText($noteToOwner);

        $this->within($record, function (TierApplication $application, Organization $organization) use ($actor, $reason, $noteToOwner): void {
            $from = $this->ensureWithCompliance($application);
            $problems = $this->requirements->problems($application);

            if ($problems !== []) {
                throw new TierReviewRefused('Not every required document is in order, so it cannot be approved: '.implode(' ', $problems).' Ask for more information instead.');
            }

            foreach ($application->documents()->where('status', ComplianceDocumentStatus::Pending->value)->get() as $document) {
                $this->review($document, ComplianceDocumentStatus::Accepted, $actor, null);
            }

            $application->review_note = $noteToOwner;
            $application->decided_at = now();
            $application->open_organization_id = null;
            $this->move($application, TierApplicationStatus::Approved, $actor);

            $this->audit->record('tier_application.approved', $application, $actor, "Approved {$organization->name} for {$application->requested_tier->label()}", $this->change($from, $application), $reason);
            $this->tiers->change($organization, $application->requested_tier, $actor, $reason, $application);
            $this->checklist->complete(ChecklistItem::Tier, null, ['approved_tier' => $application->requested_tier->value]);
            $this->notifyOwners($organization, $application, $noteToOwner);
        });
    }

    /** Rejects the application; the organization keeps its tier. @throws TierReviewRefused */
    public function reject(PlatformStaff $actor, ReviewApplication $record, string $reason, ?string $noteToOwner = null): void
    {
        $this->ensure($actor, StaffPermission::TiersApprove);
        $reason = $this->text($reason, 'Give a reason: it is kept in the audit log with your name.');
        $noteToOwner = $this->optionalText($noteToOwner);

        $this->within($record, function (TierApplication $application, Organization $organization) use ($actor, $reason, $noteToOwner): void {
            $from = $this->ensureWithCompliance($application);

            $application->review_note = $noteToOwner;
            $application->decided_at = now();
            $application->open_organization_id = null;
            $this->move($application, TierApplicationStatus::Rejected, $actor);

            $this->audit->record('tier_application.rejected', $application, $actor, "Rejected {$organization->name}'s {$application->requested_tier->label()} application", $this->change($from, $application), $reason);
            $this->notifyOwners($organization, $application, $noteToOwner);
        });
    }

    /** @throws TierReviewRefused */
    public function acceptDocument(PlatformStaff $actor, ReviewDocument $record): void
    {
        $this->decideDocument($actor, $record, ComplianceDocumentStatus::Accepted, null);
    }

    /** The note tells the owner what is wrong with it. @throws TierReviewRefused */
    public function rejectDocument(PlatformStaff $actor, ReviewDocument $record, string $note): void
    {
        $this->decideDocument($actor, $record, ComplianceDocumentStatus::Rejected, $this->text($note, 'Say what is wrong with the document; the organization sees this.'));
    }

    private function decideDocument(PlatformStaff $actor, ReviewDocument $record, ComplianceDocumentStatus $to, ?string $note): void
    {
        $this->ensure($actor, StaffPermission::TiersReview);
        $application = $record->application ?? throw new TierReviewRefused('This document is not part of an application.');

        $this->within($application, function (TierApplication $locked) use ($actor, $record, $to, $note): void {
            if ($locked->status->isDecided()) {
                throw new TierReviewRefused('The application has been decided; its documents can no longer be reviewed.');
            }

            $document = $locked->documents()->whereKey($record->id)->lockForUpdate()->first()
                ?? throw new TierReviewRefused('That document has been removed by the organization.');

            if (! in_array($document->status, $to === ComplianceDocumentStatus::Accepted ? [ComplianceDocumentStatus::Pending] : [ComplianceDocumentStatus::Pending, ComplianceDocumentStatus::Accepted], true)) {
                throw new TierReviewRefused("The {$document->type->label()} is {$document->status->label()}, so it cannot be ".($to === ComplianceDocumentStatus::Accepted ? 'accepted.' : 'rejected.'));
            }

            $from = $document->status;
            $this->review($document, $to, $actor, $note);

            $this->audit->record($to === ComplianceDocumentStatus::Accepted ? 'compliance_document.accepted' : 'compliance_document.rejected', $document, $actor,
                ($to === ComplianceDocumentStatus::Accepted ? 'Accepted' : 'Rejected')." the {$document->type->label()}", [
                    'old' => ['status' => $from->value],
                    'attributes' => ['status' => $to->value],
                    'tier_application_id' => $locked->id,
                ], $note);
        });
    }

    private function review(ComplianceDocument $document, ComplianceDocumentStatus $to, PlatformStaff $actor, ?string $note): void
    {
        $document->forceFill([
            'status' => $to,
            'reviewed_by_staff_id' => $actor->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();
    }

    /**
     * Runs the step inside the organization's context, in a transaction,
     * with the application row locked.
     *
     * @param  Closure(TierApplication, Organization): void  $step
     */
    private function within(ReviewApplication $record, Closure $step): void
    {
        $organization = $record->organization();

        $this->context->run($organization, function () use ($record, $organization, $step): void {
            DB::transaction(function () use ($record, $organization, $step): void {
                $application = TierApplication::query()->whereKey($record->id)->lockForUpdate()->first()
                    ?? throw new TierReviewRefused('That application does not exist.');

                $step($application, $organization);
            });
        });
    }

    private function move(TierApplication $application, TierApplicationStatus $to, PlatformStaff $actor): void
    {
        $application->status = $to;
        $application->reviewed_by_staff_id = $actor->id;
        $application->save();
    }

    /** @throws TierReviewRefused */
    private function ensureWithCompliance(TierApplication $application): TierApplicationStatus
    {
        if (! $application->status->isWithCompliance()) {
            throw new TierReviewRefused($application->status === TierApplicationStatus::MoreInfoNeeded
                ? 'The application is waiting for the organization to answer.'
                : "The application is {$application->status->label()}, so it cannot be decided now.");
        }

        return $application->status;
    }

    /** @throws TierReviewRefused */
    private function ensure(PlatformStaff $actor, StaffPermission $permission): void
    {
        if (! $actor->holdsStaffPermission($permission)) {
            throw new TierReviewRefused('You do not have permission to do this ('.$permission->label().').');
        }
    }

    /** @throws TierReviewRefused */
    private function text(string $value, string $whenEmpty): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new TierReviewRefused($whenEmpty);
        }

        if (mb_strlen($value) > self::NOTE_MAX) {
            throw new TierReviewRefused('That text is too long.');
        }

        return $value;
    }

    /** @throws TierReviewRefused */
    private function optionalText(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : '';

        return $value === '' ? null : $this->text($value, '');
    }

    /** @return array<string, mixed> */
    private function change(TierApplicationStatus $from, TierApplication $application): array
    {
        return [
            'old' => ['status' => $from->value],
            'attributes' => ['status' => $application->status->value],
            'organization_id' => $application->organization_id,
            'requested_tier' => $application->requested_tier->value,
        ];
    }

    private function notifyOwners(Organization $organization, TierApplication $application, ?string $note): void
    {
        $notice = new TierApplicationDecided($application->status, $organization->name, $organization->slug, $application->requested_tier->label(), $note);

        foreach ($this->owners->withEmail($organization) as $owner) {
            $owner->notify($notice);
        }
    }
}
