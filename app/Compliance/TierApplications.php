<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Enums\ComplianceDocumentStatus;
use App\Enums\ComplianceDocumentType;
use App\Enums\TierApplicationStatus;
use App\Enums\TransactionTier;
use App\Models\ComplianceDocument;
use App\Models\Organization;
use App\Models\OrganizationOnboarding;
use App\Models\TierApplication;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The org-owner's side of a Transaction Tier application
 * (ONBOARDING_COMPLIANCE.md § 2 and § 5.3), for the organization in the
 * current OrganizationContext: start one for Tier 2 or 3 (from the tier
 * asked for at signup), upload the documents, name the compliance contact
 * (Tier 3), submit, and answer a request for more information by
 * submitting again. Submitting is refused while a required document is
 * missing (RequiredDocuments). The organization keeps its approved tier
 * until compliance approves (App\Platform\Compliance\TierReview).
 *
 * Authorization is the caller's (TierApplicationPolicy); a suspended
 * organization never gets here (OrganizationWriteGuard). Each change runs
 * with the application row locked and is written to the audit log.
 */
final readonly class TierApplications
{
    public const int NOTE_MAX = 2000;

    public function __construct(
        private OrganizationContext $context,
        private ComplianceFiles $files,
        private RequiredDocuments $requirements,
        private ComplianceAudit $audit,
    ) {}

    /** The application that is not decided yet, if any. */
    public function open(): ?TierApplication
    {
        return TierApplication::query()->whereIn('status', TierApplicationStatus::open())->latest('id')->first();
    }

    /** The newest application, open or decided. */
    public function latest(): ?TierApplication
    {
        return TierApplication::query()->latest('id')->first();
    }

    /** The tier asked for at signup, if it is above the approved tier. */
    public function suggestedTier(): ?TransactionTier
    {
        $requested = OrganizationOnboarding::query()->first()?->requested_tier;
        $current = $this->context->get()->transaction_tier;

        return $requested !== null && $requested->value > $current->value ? $requested : null;
    }

    /** @throws TierApplicationRefused */
    public function start(User $by, TransactionTier $tier): TierApplication
    {
        return DB::transaction(function () use ($by, $tier): TierApplication {
            $organization = Organization::query()->whereKey($this->context->id())->lockForUpdate()->firstOrFail();

            if ($this->open() !== null) {
                throw new TierApplicationRefused('Your organization already has an application in progress.');
            }

            $this->ensureUpgrade($organization, $tier);

            $application = new TierApplication([
                'from_tier' => $organization->transaction_tier,
                'requested_tier' => $tier,
                'declared_annual_volume_kobo' => OrganizationOnboarding::query()->first()?->declared_annual_volume_kobo,
                'status' => TierApplicationStatus::Draft,
                'started_by_user_id' => $by->id,
            ]);
            $application->open_organization_id = $organization->id;
            $application->save();

            $this->audit->record('tier_application.started', $application, $by, "Started an application for {$tier->label()}", [
                'attributes' => ['from_tier' => $organization->transaction_tier->value, 'requested_tier' => $tier->value, 'status' => TierApplicationStatus::Draft->value],
            ]);

            return $application;
        });
    }

    /** A draft may still change the tier it asks for. @throws TierApplicationRefused */
    public function changeTier(User $by, TierApplication $application, TransactionTier $tier): TierApplication
    {
        return DB::transaction(function () use ($by, $application, $tier): TierApplication {
            $locked = $this->locked($application);

            if ($locked->status !== TierApplicationStatus::Draft) {
                throw new TierApplicationRefused('The tier can only be changed before the application is submitted.');
            }

            $this->ensureUpgrade($this->context->get(), $tier);
            $from = $locked->requested_tier;

            if ($from === $tier) {
                return $locked;
            }

            $locked->requested_tier = $tier;
            $locked->save();

            $this->audit->record('tier_application.tier_changed', $locked, $by, "Now applying for {$tier->label()}", [
                'old' => ['requested_tier' => $from->value],
                'attributes' => ['requested_tier' => $tier->value],
            ]);

            return $locked;
        });
    }

    /**
     * Adds a document. A type that takes one document replaces the earlier
     * one (kept, soft deleted); director IDs and beneficial owners add up.
     *
     * @throws TierApplicationRefused
     */
    public function upload(
        User $by,
        TierApplication $application,
        ComplianceDocumentType $type,
        UploadedFile $file,
        ?string $documentNumber = null,
        ?string $holderName = null,
        ?CarbonImmutable $issuedAt = null,
        ?CarbonImmutable $expiresAt = null,
    ): ComplianceDocument {
        // Checked again under the lock below; this saves encrypting a file for nothing.
        $this->ensureEditable(TierApplication::query()->whereKey($application->getKey())->first() ?? $application);

        if (! $this->requirements->accepts($application->requested_tier, $type)) {
            throw new TierApplicationRefused("A {$application->requested_tier->label()} application does not take a {$type->label()}.");
        }

        $documentNumber = $this->clean($documentNumber);
        $holderName = $this->clean($holderName);
        $this->checkDetails($type, $documentNumber, $holderName, $issuedAt, $expiresAt);

        // Written before the transaction (no lock held while encrypting);
        // removed again if the record cannot be saved.
        $stored = $this->files->store($file, $this->context->id());

        try {
            return DB::transaction(function () use ($by, $application, $type, $stored, $documentNumber, $holderName, $issuedAt, $expiresAt): ComplianceDocument {
                $locked = $this->locked($application);
                $this->ensureEditable($locked);
                $replaced = [];

                if (! $type->allowsSeveral()) {
                    foreach ($locked->documents()->where('type', $type->value)->get() as $earlier) {
                        $earlier->delete();
                        $replaced[] = $earlier->id;
                    }
                }

                $document = ComplianceDocument::query()->create([
                    'tier_application_id' => $locked->id,
                    'type' => $type,
                    'disk' => $stored->disk,
                    'path' => $stored->path,
                    'original_name' => $stored->originalName,
                    'mime' => $stored->mime,
                    'size_bytes' => $stored->sizeBytes,
                    'sha256' => $stored->sha256,
                    'document_number' => $documentNumber,
                    'holder_name' => $holderName,
                    'issued_at' => $issuedAt,
                    'expires_at' => $expiresAt,
                    'uploaded_by_user_id' => $by->id,
                ]);

                $this->audit->record('compliance_document.uploaded', $document, $by, "Uploaded the {$type->label()}", array_filter([
                    'tier_application_id' => $locked->id,
                    'type' => $type->value,
                    'mime' => $stored->mime,
                    'size_bytes' => $stored->sizeBytes,
                    'sha256' => $stored->sha256,
                    'expires_at' => $expiresAt?->toDateString(),
                    'replaced_document_ids' => $replaced !== [] ? $replaced : null,
                ], static fn (mixed $value): bool => $value !== null));

                return $document;
            });
        } catch (Throwable $failure) {
            $this->files->discard($stored);

            throw $failure;
        }
    }

    /** Takes back a document compliance has not accepted. @throws TierApplicationRefused */
    public function remove(User $by, TierApplication $application, ComplianceDocument $document): void
    {
        DB::transaction(function () use ($by, $application, $document): void {
            $locked = $this->locked($application);
            $this->ensureEditable($locked);

            $row = $locked->documents()->whereKey($document->getKey())->lockForUpdate()->first()
                ?? throw new TierApplicationRefused('That document is not part of this application.');

            if ($row->status === ComplianceDocumentStatus::Accepted) {
                throw new TierApplicationRefused('Compliance has accepted this document, so it stays. Upload a newer one to replace it.');
            }

            $row->delete();

            $this->audit->record('compliance_document.removed', $row, $by, "Removed the {$row->type->label()}", [
                'tier_application_id' => $locked->id,
                'type' => $row->type->value,
            ]);
        });
    }

    /** Tier 3: the named compliance contact (stored encrypted). @throws TierApplicationRefused */
    public function saveComplianceContact(User $by, TierApplication $application, string $name, string $email, string $phone): TierApplication
    {
        return DB::transaction(function () use ($by, $application, $name, $email, $phone): TierApplication {
            $locked = $this->locked($application);
            $this->ensureEditable($locked);

            if (! $this->requirements->needsComplianceContact($locked->requested_tier)) {
                throw new TierApplicationRefused('Only a Tier 3 application names a compliance contact.');
            }

            $contact = ['name' => trim($name), 'email' => trim($email), 'phone' => trim($phone)];

            if (in_array('', $contact, true)) {
                throw new TierApplicationRefused('Give the compliance contact\'s name, email and phone number.');
            }

            $locked->compliance_contact = $contact;
            $locked->save();

            $this->audit->record('tier_application.contact_saved', $locked, $by, 'Named the compliance contact');

            return $locked;
        });
    }

    /**
     * Sends the application to compliance, the first time or after a
     * request for more information (with the owner's answer).
     *
     * @throws ApplicationIncomplete when a required document or detail is missing
     * @throws TierApplicationRefused
     */
    public function submit(User $by, TierApplication $application, ?string $note = null): TierApplication
    {
        return DB::transaction(function () use ($by, $application, $note): TierApplication {
            $locked = $this->locked($application);
            $this->ensureEditable($locked);
            $note = $this->clean($note);

            if ($note !== null && mb_strlen($note) > self::NOTE_MAX) {
                throw new TierApplicationRefused('The note is too long.');
            }

            $problems = $this->requirements->problems($locked);

            if ($problems !== []) {
                throw new ApplicationIncomplete($problems);
            }

            $again = $locked->status === TierApplicationStatus::MoreInfoNeeded;
            $from = $locked->status;
            $locked->forceFill([
                'status' => TierApplicationStatus::Submitted,
                'submitted_at' => now(),
                'submitted_by_user_id' => $by->id,
                'applicant_note' => $again ? $note : $locked->applicant_note,
            ])->save();

            $this->audit->record($again ? 'tier_application.resubmitted' : 'tier_application.submitted', $locked, $by,
                ($again ? 'Sent the application back to compliance' : 'Submitted the application').' for '.$locked->requested_tier->label(), [
                    'old' => ['status' => $from->value],
                    'attributes' => ['status' => TierApplicationStatus::Submitted->value],
                    'document_ids' => $locked->currentDocuments()->pluck('id')->all(),
                ]);

            return $locked;
        });
    }

    private function locked(TierApplication $application): TierApplication
    {
        return TierApplication::query()->whereKey($application->getKey())->lockForUpdate()->first()
            ?? throw new TierApplicationRefused('That application does not exist.');
    }

    /** @throws TierApplicationRefused */
    private function ensureEditable(TierApplication $application): void
    {
        if (! $application->status->isWithApplicant()) {
            throw new TierApplicationRefused($application->status->isWithCompliance()
                ? 'The application is with compliance now, so it cannot be changed.'
                : 'This application has been decided and cannot be changed.');
        }
    }

    /** @throws TierApplicationRefused */
    private function ensureUpgrade(Organization $organization, TransactionTier $tier): void
    {
        if ($tier === TransactionTier::One) {
            throw new TierApplicationRefused('Tier 1 is active straight away; there is nothing to apply for.');
        }

        if ($tier->value <= $organization->transaction_tier->value) {
            throw new TierApplicationRefused("Your organization is already at {$organization->transaction_tier->label()}.");
        }
    }

    /** @throws TierApplicationRefused */
    private function checkDetails(ComplianceDocumentType $type, ?string $number, ?string $holder, ?CarbonImmutable $issued, ?CarbonImmutable $expires): void
    {
        $today = now()->startOfDay();

        $problem = match (true) {
            $type->needsDocumentNumber() && $number === null => 'Enter the document number.',
            $type->needsHolderName() && $holder === null => 'Enter the name on the document.',
            $type->needsIssueDate() && $issued === null => 'Enter the date on the document.',
            $issued !== null && $issued->greaterThan($today) => 'The date on the document cannot be in the future.',
            $expires !== null && $expires->lessThan($today) => 'This document has expired. Upload a valid one.',
            $issued !== null && $expires !== null && $expires->lessThan($issued) => 'The expiry date is before the issue date.',
            $number !== null && mb_strlen($number) > 64, $holder !== null && mb_strlen($holder) > 120 => 'The document number or name is too long.',
            default => null,
        };

        if ($problem !== null) {
            throw new TierApplicationRefused($problem);
        }
    }

    private function clean(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
