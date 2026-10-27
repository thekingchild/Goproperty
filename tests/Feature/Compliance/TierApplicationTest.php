<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Compliance\ApplicationIncomplete;
use App\Compliance\TierApplicationRefused;
use App\Compliance\TierApplications;
use App\Enums\ChecklistItem;
use App\Enums\ComplianceDocumentStatus;
use App\Enums\ComplianceDocumentType;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\TierApplicationStatus;
use App\Enums\TransactionTier;
use App\Livewire\Compliance\TierApplicationPage;
use App\Models\ComplianceDocument;
use App\Models\OnboardingChecklistItem;
use App\Models\Organization;
use App\Models\OrganizationOnboarding;
use App\Models\TierApplication;
use App\Models\User;
use App\Onboarding\OnboardingChecklist;
use App\Platform\Impersonation\ImpersonationMode;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/../../Support/impersonation.php';

/*
 * P01-03, the org-owner's side of a Transaction Tier application
 * (ONBOARDING_COMPLIANCE.md § 2, § 5.3): start, upload, submit (refused
 * while a required document is missing), the more-information loop, who may
 * apply, other organizations, and suspended organizations. The console
 * review is in tests/Feature/Console/TierReviewTest.php.
 */

beforeEach(function () {
    Storage::fake('compliance');
    Notification::fake();
    seedOrganizationRoles();

    $this->acme = Organization::factory()->create(['name' => 'Acme Homes', 'slug' => 'acme-homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ngozi Owner', 'email' => 'ngozi@acme.test']), $this->acme);
});

afterEach(fn () => app(OrganizationContext::class)->clear());

/** The page and its Livewire snapshot, as the owner's browser loads it. */
function tierPage(User $user, string $slug = 'acme-homes'): string
{
    $page = test()->actingAs($user)->get("/o/{$slug}/compliance/tier")->assertOk();

    return LivewireHttp::snapshot($page);
}

describe('acceptance: a Tier 2 application with missing SCUML cannot be submitted', function () {
    it('refuses to submit a Tier 2 application without the SCUML certificate (service)', function () {
        $application = tierApplication($this->acme, $this->owner, TransactionTier::Two, without: [ComplianceDocumentType::ScumlCertificate]);

        $refusal = null;

        try {
            inOrganization($this->acme, fn () => app(TierApplications::class)->submit($this->owner, $application));
        } catch (ApplicationIncomplete $incomplete) {
            $refusal = $incomplete;
        }

        expect($refusal)->toBeInstanceOf(ApplicationIncomplete::class)
            ->and($refusal?->problems)->toBe(['Upload the SCUML registration certificate.'])
            ->and(inOrganization($this->acme, fn () => TierApplication::query()->findOrFail($application->id))->status)->toBe(TierApplicationStatus::Draft)
            ->and(AuditEntry::query()->where('event', 'tier_application.submitted')->exists())->toBeFalse();
    });

    it('refuses to submit a Tier 2 application without the SCUML certificate (workspace page)', function () {
        $application = tierApplication($this->acme, $this->owner, TransactionTier::Two, without: [ComplianceDocumentType::ScumlCertificate]);
        $snapshot = tierPage($this->owner);

        $response = LivewireHttp::update($this, $snapshot, [], [['submit']])->assertOk();

        expect(LivewireHttp::html($response))->toContain('Not submitted yet')->toContain('Upload the SCUML registration certificate.')
            ->and(inOrganization($this->acme, fn () => TierApplication::query()->findOrFail($application->id))->status)->toBe(TierApplicationStatus::Draft);

        // With the certificate it goes.
        uploadDocument($this->acme, $this->owner, $application, ComplianceDocumentType::ScumlCertificate);
        $response = LivewireHttp::update($this, tierPage($this->owner), [], [['submit']])->assertOk();

        expect(LivewireHttp::html($response))->not->toContain('Not submitted yet')->toContain('Submitted. Compliance usually replies')
            ->and(inOrganization($this->acme, fn () => TierApplication::query()->findOrFail($application->id))->status)->toBe(TierApplicationStatus::Submitted);
    });

    it('refuses each missing Tier 2 document and accepts the complete set', function (ComplianceDocumentType $missing) {
        $application = tierApplication($this->acme, $this->owner, TransactionTier::Two, without: [$missing]);

        expect(fn () => inOrganization($this->acme, fn () => app(TierApplications::class)->submit($this->owner, $application)))
            ->toThrow(ApplicationIncomplete::class, $missing->label());
    })->with([
        'owner ID' => [ComplianceDocumentType::OwnerId],
        'CAC certificate' => [ComplianceDocumentType::CacCertificate],
        'CAC status report' => [ComplianceDocumentType::CacStatusReport],
        'SCUML certificate' => [ComplianceDocumentType::ScumlCertificate],
        'proof of address' => [ComplianceDocumentType::ProofOfAddress],
        'director ID' => [ComplianceDocumentType::DirectorId],
    ]);
});

describe('starting and filling an application', function () {
    it('starts from the tier asked for at signup and links the checklist item to the page', function () {
        inOrganization($this->acme, function () {
            OrganizationOnboarding::query()->create(['step' => 'data', 'requested_tier' => TransactionTier::Three, 'declared_annual_volume_kobo' => Money::naira(900_000_000), 'completed_at' => now()]);
            app(OnboardingChecklist::class)->start();
            app(OnboardingChecklist::class)->reopen(ChecklistItem::Tier, ['requested_tier' => 3]);
        });

        $this->actingAs($this->owner)->get('/o/acme-homes')->assertOk()
            ->assertSee(route('workspace.compliance.tier', $this->acme), false);

        $snapshot = tierPage($this->owner);
        expect(snapshotData($snapshot)['tier'])->toBe(3);

        LivewireHttp::update($this, $snapshot, [], [['start']])->assertOk();

        $application = inOrganization($this->acme, fn () => TierApplication::query()->sole());
        expect($application->requested_tier)->toBe(TransactionTier::Three)
            ->and($application->from_tier)->toBe(TransactionTier::One)
            ->and($application->status)->toBe(TierApplicationStatus::Draft)
            ->and($application->declared_annual_volume_kobo?->kobo)->toBe(90_000_000_000)
            ->and($application->open_organization_id)->toBe($this->acme->id)
            // The organization keeps working at its approved tier meanwhile.
            ->and($this->acme->fresh()->transaction_tier)->toBe(TransactionTier::One)
            ->and(AuditEntry::query()->where('event', 'tier_application.started')->sole()->causer_id)->toBe($this->owner->id);
    });

    it('allows one open application at a time and only upgrades', function () {
        $service = app(TierApplications::class);
        inOrganization($this->acme, fn () => $service->start($this->owner, TransactionTier::Two));

        expect(fn () => inOrganization($this->acme, fn () => $service->start($this->owner, TransactionTier::Three)))
            ->toThrow(TierApplicationRefused::class, 'already has an application in progress');

        $other = Organization::factory()->create(['transaction_tier' => TransactionTier::Two]);
        $otherOwner = joinAs(User::factory()->create(), $other);

        expect(fn () => inOrganization($other, fn () => $service->start($otherOwner, TransactionTier::Two)))->toThrow(TierApplicationRefused::class, 'already at Tier 2')
            ->and(fn () => inOrganization($other, fn () => $service->start($otherOwner, TransactionTier::One)))->toThrow(TierApplicationRefused::class);
    });

    it('uploads a document through the page, encrypted on the private compliance disk', function () {
        $application = inOrganization($this->acme, fn () => app(TierApplications::class)->start($this->owner, TransactionTier::Two));
        app(OrganizationContext::class)->set($this->acme);
        $this->actingAs($this->owner);

        Livewire::test(TierApplicationPage::class)
            ->set('documentType', ComplianceDocumentType::ScumlCertificate->value)
            ->set('file', pdfUpload('My SCUML cert.pdf', 'scuml-marker-7731'))
            ->set('documentNumber', 'SC-998877')
            ->set('expiresAt', now()->addYears(2)->toDateString())
            ->call('upload')
            ->assertHasNoErrors()
            ->assertSee('SCUML registration certificate uploaded.');

        // Livewire's plain temporary copy is gone (only its test-time metadata stays).
        expect(array_values(array_filter(Storage::disk('tmp-for-tests')->allFiles('livewire-tmp'), fn (string $path) => ! str_ends_with($path, '.json'))))->toBe([]);

        $document = ComplianceDocument::query()->sole();
        $raw = DB::table('compliance_documents')->where('id', $document->id)->first();
        $stored = Storage::disk('compliance')->get($document->path);

        expect($document->tier_application_id)->toBe($application->id)
            ->and($document->type)->toBe(ComplianceDocumentType::ScumlCertificate)
            ->and($document->status)->toBe(ComplianceDocumentStatus::Pending)
            ->and($document->mime)->toBe('application/pdf')
            ->and($document->original_name)->toBe('My SCUML cert.pdf')
            ->and($document->document_number)->toBe('SC-998877')
            ->and($document->path)->toStartWith($this->acme->id.'/')->not->toContain('SCUML')
            // At rest: neither the number, the name nor the file content is readable.
            ->and($raw->document_number)->not->toContain('998877')
            ->and($raw->original_name)->not->toContain('SCUML')
            ->and($stored)->not->toContain('scuml-marker-7731')->not->toContain('%PDF')
            ->and(decrypt($stored, false))->toContain('scuml-marker-7731')
            ->and($document->sha256)->toBe(hash('sha256', decrypt($stored, false)));

        $entry = AuditEntry::query()->where('event', 'compliance_document.uploaded')->sole();
        expect($entry->causer_id)->toBe($this->owner->id)
            ->and(json_encode($entry->properties))->not->toContain('998877');
    });

    it('sniffs the file type from its content and caps the size', function () {
        $application = inOrganization($this->acme, fn () => app(TierApplications::class)->start($this->owner, TransactionTier::Two));
        $upload = fn (UploadedFile $file) => inOrganization($this->acme, fn () => app(TierApplications::class)->upload(
            $this->owner, $application, ComplianceDocumentType::CacCertificate, $file, 'RC-1234',
        ));

        // A script renamed to .pdf, an SVG and an HTML page are refused whatever their name says.
        expect(fn () => $upload(UploadedFile::fake()->createWithContent('cac.pdf', "<?php echo 'x';")))->toThrow(TierApplicationRefused::class, 'Upload a PDF, JPEG or PNG file.')
            ->and(fn () => $upload(UploadedFile::fake()->createWithContent('cac.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')))->toThrow(TierApplicationRefused::class, 'Upload a PDF, JPEG or PNG file.')
            ->and(fn () => $upload(UploadedFile::fake()->createWithContent('cac.jpg', '<html><body>hi</body></html>')))->toThrow(TierApplicationRefused::class, 'Upload a PDF, JPEG or PNG file.')
            ->and(fn () => $upload(UploadedFile::fake()->createWithContent('cac.pdf', '')))->toThrow(TierApplicationRefused::class, 'empty');

        config(['compliance.max_upload_kb' => 1]);
        expect(fn () => $upload(UploadedFile::fake()->createWithContent('cac.pdf', '%PDF-1.4'.str_repeat('x', 2048))))->toThrow(TierApplicationRefused::class, 'larger than');

        // A real PNG is fine.
        config(['compliance.max_upload_kb' => 8192]);
        $png = UploadedFile::fake()->image('cac.png', 20, 20);
        expect($upload($png)->mime)->toBe('image/png')
            ->and(DB::table('compliance_documents')->count())->toBe(1)
            ->and(Storage::disk('compliance')->allFiles())->toHaveCount(1);
    });

    it('checks the details each document needs', function () {
        $application = inOrganization($this->acme, fn () => app(TierApplications::class)->start($this->owner, TransactionTier::Two));
        $service = app(TierApplications::class);
        $upload = fn (ComplianceDocumentType $type, ?string $number = null, ?string $holder = null, ?CarbonImmutable $issued = null, ?CarbonImmutable $expires = null) => inOrganization($this->acme, fn () => $service->upload($this->owner, $application, $type, pdfUpload(), $number, $holder, $issued, $expires));

        expect(fn () => $upload(ComplianceDocumentType::OwnerId, null, 'Ngozi'))->toThrow(TierApplicationRefused::class, 'document number')
            ->and(fn () => $upload(ComplianceDocumentType::OwnerId, 'A123', null))->toThrow(TierApplicationRefused::class, 'name on the document')
            ->and(fn () => $upload(ComplianceDocumentType::ProofOfAddress))->toThrow(TierApplicationRefused::class, 'date on the document')
            ->and(fn () => $upload(ComplianceDocumentType::ProofOfAddress, issued: CarbonImmutable::tomorrow()))->toThrow(TierApplicationRefused::class, 'future')
            ->and(fn () => $upload(ComplianceDocumentType::CacCertificate, 'RC1', expires: CarbonImmutable::yesterday()))->toThrow(TierApplicationRefused::class, 'expired')
            // Tier 2 does not take Tier 3's documents.
            ->and(fn () => $upload(ComplianceDocumentType::AmlPolicy))->toThrow(TierApplicationRefused::class, 'does not take')
            ->and(Storage::disk('compliance')->allFiles())->toBe([]);
    });

    it('refuses a proof of address older than 3 months at submission', function () {
        $application = tierApplication($this->acme, $this->owner, TransactionTier::Two, without: [ComplianceDocumentType::ProofOfAddress]);
        uploadDocument($this->acme, $this->owner, $application, ComplianceDocumentType::ProofOfAddress, issuedAt: CarbonImmutable::today()->subMonths(4));

        expect(fn () => inOrganization($this->acme, fn () => app(TierApplications::class)->submit($this->owner, $application)))
            ->toThrow(ApplicationIncomplete::class, 'must be dated within the last 3 months');

        // A newer one replaces it (the old one is kept, soft deleted).
        uploadDocument($this->acme, $this->owner, $application, ComplianceDocumentType::ProofOfAddress, issuedAt: CarbonImmutable::today()->subMonth());

        expect(inOrganization($this->acme, fn () => app(TierApplications::class)->submit($this->owner, $application))->status)->toBe(TierApplicationStatus::Submitted)
            ->and(inOrganization($this->acme, fn () => ComplianceDocument::query()->onlyTrashed()->where('type', 'proof_of_address')->count()))->toBe(1);
    });

    it('needs beneficial owners, the AML policy, the regulator registration and a compliance contact for Tier 3', function () {
        $application = tierApplication($this->acme, $this->owner, TransactionTier::Three, without: [ComplianceDocumentType::BeneficialOwners, ComplianceDocumentType::AmlPolicy], withContact: false);

        try {
            inOrganization($this->acme, fn () => app(TierApplications::class)->submit($this->owner, $application));
            $this->fail('A Tier 3 application without beneficial owners was submitted.');
        } catch (ApplicationIncomplete $incomplete) {
            expect($incomplete->problems)->toBe([
                'Upload the Beneficial owners.',
                'Upload the AML policy statement.',
                'Name your compliance contact (name, email and phone number).',
            ]);
        }

        uploadDocument($this->acme, $this->owner, $application, ComplianceDocumentType::BeneficialOwners);
        uploadDocument($this->acme, $this->owner, $application, ComplianceDocumentType::AmlPolicy);
        inOrganization($this->acme, fn () => app(TierApplications::class)->saveComplianceContact($this->owner, $application, 'Tolu Ade', 'tolu@acme.test', '+2348031112222'));

        $submitted = inOrganization($this->acme, fn () => app(TierApplications::class)->submit($this->owner, $application));
        expect($submitted->status)->toBe(TierApplicationStatus::Submitted)
            ->and(DB::table('tier_applications')->value('compliance_contact'))->not->toContain('tolu@acme.test')
            ->and($submitted->compliance_contact)->toBe(['name' => 'Tolu Ade', 'email' => 'tolu@acme.test', 'phone' => '+2348031112222']);
    });

    it('keeps several director IDs but replaces a single document type', function () {
        $application = inOrganization($this->acme, fn () => app(TierApplications::class)->start($this->owner, TransactionTier::Two));
        uploadDocument($this->acme, $this->owner, $application, ComplianceDocumentType::DirectorId);
        uploadDocument($this->acme, $this->owner, $application, ComplianceDocumentType::DirectorId);
        uploadDocument($this->acme, $this->owner, $application, ComplianceDocumentType::CacCertificate);
        uploadDocument($this->acme, $this->owner, $application, ComplianceDocumentType::CacCertificate);

        inOrganization($this->acme, function () {
            expect(ComplianceDocument::query()->where('type', 'director_id')->count())->toBe(2)
                ->and(ComplianceDocument::query()->where('type', 'cac_certificate')->count())->toBe(1)
                ->and(ComplianceDocument::query()->withTrashed()->where('type', 'cac_certificate')->count())->toBe(2);
        });
    });

    it('lets the owner remove a document that is not accepted, and nothing once submitted', function () {
        $application = tierApplication($this->acme, $this->owner);
        $service = app(TierApplications::class);
        $scuml = inOrganization($this->acme, fn () => ComplianceDocument::query()->where('type', 'scuml_certificate')->sole());

        inOrganization($this->acme, fn () => $service->remove($this->owner, $application, $scuml));
        expect(inOrganization($this->acme, fn () => ComplianceDocument::query()->whereKey($scuml->id)->exists()))->toBeFalse()
            ->and(AuditEntry::query()->where('event', 'compliance_document.removed')->exists())->toBeTrue();

        uploadDocument($this->acme, $this->owner, $application, ComplianceDocumentType::ScumlCertificate);
        $submitted = inOrganization($this->acme, fn () => $service->submit($this->owner, $application));
        $cac = inOrganization($this->acme, fn () => ComplianceDocument::query()->where('type', 'cac_certificate')->sole());

        expect(fn () => inOrganization($this->acme, fn () => $service->remove($this->owner, $submitted, $cac)))->toThrow(TierApplicationRefused::class, 'with compliance')
            ->and(fn () => uploadDocument($this->acme, $this->owner, $submitted, ComplianceDocumentType::CacCertificate))->toThrow(TierApplicationRefused::class, 'with compliance')
            ->and(fn () => inOrganization($this->acme, fn () => $service->submit($this->owner, $submitted)))->toThrow(TierApplicationRefused::class)
            ->and(Gate::forUser($this->owner)->allows('update', $submitted))->toBeFalse();
    });
});

describe('who may apply', function () {
    it('opens the page and the actions to the org-owner only', function (OrganizationRole $role) {
        $member = joinAs(User::factory()->create(), $this->acme, $role);

        $this->actingAs($member)->get('/o/acme-homes/compliance/tier')->assertForbidden();

        inOrganization($this->acme, function () use ($member) {
            expect(Gate::forUser($member)->allows('viewAny', TierApplication::class))->toBeFalse()
                ->and(Gate::forUser($member)->allows('create', TierApplication::class))->toBeFalse();
        });
    })->with([OrganizationRole::Admin, OrganizationRole::Agent]);

    it('answers 404 to people outside the workspace', function (OrganizationRole $role) {
        $tenant = joinAs(User::factory()->create(), $this->acme, $role);

        $this->actingAs($tenant)->get('/o/acme-homes/compliance/tier')->assertNotFound();
        $this->actingAs(User::factory()->create())->get('/o/acme-homes/compliance/tier')->assertNotFound();
    })->with([OrganizationRole::Tenant, OrganizationRole::PropertyOwner]);

    it('refuses a Livewire call from an administrator even with a stolen snapshot', function () {
        $snapshot = tierPage($this->owner);
        $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);

        $this->actingAs($admin);
        LivewireHttp::update($this, $snapshot, ['tier' => 2], [['start']])->assertForbidden();

        expect(inOrganization($this->acme, fn () => TierApplication::query()->count()))->toBe(0);
    });

    it('never shows or reaches another organization\'s application', function () {
        $other = Organization::factory()->create(['slug' => 'other-realty']);
        $otherOwner = joinAs(User::factory()->create(), $other);
        $theirs = tierApplication($other, $otherOwner, TransactionTier::Two);
        $theirDocument = inOrganization($other, fn () => ComplianceDocument::query()->firstOrFail());

        // Acme's owner sees no application on their own page.
        $snapshot = tierPage($this->owner);
        $this->actingAs($this->owner)->get('/o/acme-homes/compliance/tier')->assertDontSee('Applying for');

        // Removing their document from Acme's page: 404 (Acme has no open application to act on).
        LivewireHttp::update($this, $snapshot, [], [['removeDocument', [$theirDocument->id]]])->assertNotFound();

        // With an application of its own, the other organization's document is still not found.
        inOrganization($this->acme, fn () => app(TierApplications::class)->start($this->owner, TransactionTier::Two));
        LivewireHttp::update($this, tierPage($this->owner), [], [['removeDocument', [$theirDocument->id]]])->assertNotFound();

        // The other organization's page is a 404 for Acme's owner, and a snapshot from it is refused.
        $this->actingAs($this->owner)->get('/o/other-realty/compliance/tier')->assertNotFound();
        $this->actingAs($otherOwner);
        $foreign = tierPage($otherOwner, 'other-realty');
        $this->actingAs($this->owner);
        LivewireHttp::update($this, $foreign, [], [['submit']])->assertNotFound();

        expect(inOrganization($other, fn () => ComplianceDocument::query()->whereKey($theirDocument->id)->exists()))->toBeTrue()
            ->and(inOrganization($other, fn () => TierApplication::query()->findOrFail($theirs->id))->status)->toBe(TierApplicationStatus::Draft);
    });
});

describe('a suspended organization', function () {
    it('shows the application but refuses every change', function () {
        $application = tierApplication($this->acme, $this->owner, TransactionTier::Two);
        $snapshot = tierPage($this->owner);
        $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->actingAs($this->owner)->get('/o/acme-homes/compliance/tier')->assertOk()->assertSee('Applying for Tier 2');

        LivewireHttp::update($this, $snapshot, [], [['submit']])->assertForbidden();
        LivewireHttp::update($this, $snapshot, [], [['saveContact']])->assertForbidden();
        $document = inOrganization($this->acme, fn () => ComplianceDocument::query()->firstOrFail());
        LivewireHttp::update($this, $snapshot, [], [['removeDocument', [$document->id]]])->assertForbidden();

        inOrganization($this->acme->fresh(), function () use ($application) {
            expect(Gate::forUser($this->owner)->allows('viewAny', TierApplication::class))->toBeTrue()
                ->and(Gate::forUser($this->owner)->allows('create', TierApplication::class))->toBeFalse()
                ->and(Gate::forUser($this->owner)->allows('update', $application))->toBeFalse()
                ->and(TierApplication::query()->findOrFail($application->id)->status)->toBe(TierApplicationStatus::Draft)
                ->and(ComplianceDocument::query()->count())->toBe(6);
        });
    });
});

describe('the dashboard checklist', function () {
    it('keeps the Tier item open while an application waits', function () {
        inOrganization($this->acme, function () {
            app(OnboardingChecklist::class)->start();
            app(OnboardingChecklist::class)->reopen(ChecklistItem::Tier, ['requested_tier' => 2]);
        });
        submittedApplication($this->acme, $this->owner);

        expect(inOrganization($this->acme, fn () => OnboardingChecklistItem::query()->where('item', 'tier')->sole()->isDone()))->toBeFalse();
    });
});

describe('impersonation', function () {
    it('lets impersonating staff see the application but never apply, upload or submit, even in write mode', function () {
        seedStaffRoles();
        $application = tierApplication($this->acme, $this->owner, TransactionTier::Two);
        $handOff = startSupport(staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']), $this->owner, $this->acme, ImpersonationMode::Write);
        enterSupport($handOff)->assertRedirect('/o/acme-homes');

        $page = asSupport()->get('/o/acme-homes/compliance/tier')->assertOk()->assertSee('Applying for Tier 2');
        $snapshot = LivewireHttp::snapshot($page);

        freshRequest();
        LivewireHttp::update($this, $snapshot, [], [['submit']])->assertForbidden();
        freshRequest();
        LivewireHttp::update($this, $snapshot, ['contactName' => 'x'], [['saveContact']])->assertForbidden();

        expect(inOrganization($this->acme, fn () => TierApplication::query()->findOrFail($application->id))->status)->toBe(TierApplicationStatus::Draft)
            ->and(AuditEntry::query()->where('event', 'tier_application.submitted')->exists())->toBeFalse();
    });
});
