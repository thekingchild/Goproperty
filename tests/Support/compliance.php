<?php

declare(strict_types=1);

use App\Compliance\RequiredDocuments;
use App\Compliance\TierApplications;
use App\Enums\ComplianceDocumentType;
use App\Enums\TransactionTier;
use App\Models\ComplianceDocument;
use App\Models\Organization;
use App\Models\TierApplication;
use App\Models\User;
use App\Platform\Compliance\ReviewApplication;
use App\Platform\Compliance\ReviewDocument;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

/*
 * Transaction Tier applications for tests/Feature/Compliance and the
 * console review tests (ONBOARDING_COMPLIANCE.md § 5.3). Expects
 * Storage::fake('compliance') and the customer roles seeded.
 */

/** A small real PDF (the type is sniffed from the bytes, not the name). */
function pdfUpload(string $name = 'document.pdf', string $marker = 'GoProperty test document'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n% {$marker}\ntrailer << /Root 1 0 R >>\n%%EOF\n");
}

/** Runs the callback inside the organization's context. */
function inOrganization(Organization $organization, Closure $callback): mixed
{
    return app(OrganizationContext::class)->run($organization, $callback);
}

/**
 * Uploads one document of the type with valid details through the service.
 */
function uploadDocument(Organization $organization, User $owner, TierApplication $application, ComplianceDocumentType $type, ?CarbonImmutable $expiresAt = null, ?CarbonImmutable $issuedAt = null): ComplianceDocument
{
    return inOrganization($organization, fn () => app(TierApplications::class)->upload(
        $owner,
        $application,
        $type,
        pdfUpload($type->value.'.pdf'),
        $type->needsDocumentNumber() ? 'NUM-'.strtoupper(substr($type->value, 0, 6)).'-12345' : null,
        $type->needsHolderName() ? 'Ngozi Owner' : null,
        $issuedAt ?? ($type->needsIssueDate() ? CarbonImmutable::today()->subWeeks(2) : null),
        $expiresAt,
    ));
}

/**
 * A draft application with every required document of the tier, except
 * those listed in $without (and, for Tier 3, the compliance contact unless
 * $withContact).
 *
 * @param  list<ComplianceDocumentType>  $without
 */
function tierApplication(Organization $organization, User $owner, TransactionTier $tier = TransactionTier::Two, array $without = [], bool $withContact = true): TierApplication
{
    $service = app(TierApplications::class);
    $application = inOrganization($organization, fn () => $service->start($owner, $tier));

    foreach (app(RequiredDocuments::class)->required($tier) as $type) {
        if (! in_array($type, $without, true)) {
            uploadDocument($organization, $owner, $application, $type);
        }
    }

    if ($tier === TransactionTier::Three && $withContact) {
        inOrganization($organization, fn () => $service->saveComplianceContact($owner, $application, 'Tolu Compliance', 'tolu@acme.test', '+2348031112222'));
    }

    return inOrganization($organization, fn () => TierApplication::query()->findOrFail($application->id));
}

/** A complete application, submitted. */
function submittedApplication(Organization $organization, User $owner, TransactionTier $tier = TransactionTier::Two): TierApplication
{
    $application = tierApplication($organization, $owner, $tier);

    return inOrganization($organization, fn () => app(TierApplications::class)->submit($owner, $application));
}

function reviewOf(TierApplication $application): ReviewApplication
{
    return ReviewApplication::query()->findOrFail($application->id);
}

function reviewDocument(ComplianceDocument $document): ReviewDocument
{
    return ReviewDocument::query()->withTrashed()->findOrFail($document->id);
}
