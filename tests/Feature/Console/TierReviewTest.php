<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Compliance\ApplicationIncomplete;
use App\Compliance\TierApplications;
use App\Enums\ChecklistItem;
use App\Enums\ComplianceDocumentStatus;
use App\Enums\ComplianceDocumentType;
use App\Enums\TierApplicationStatus;
use App\Enums\TransactionTier;
use App\Models\ComplianceDocument;
use App\Models\OnboardingChecklistItem;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\TierApplication;
use App\Models\User;
use App\Notifications\TierApplicationDecided;
use App\Onboarding\OnboardingChecklist;
use App\Platform\Compliance\DocumentLinks;
use App\Platform\Compliance\ReviewApplication;
use App\Platform\Compliance\TierReview;
use App\Platform\Compliance\TierReviewRefused;
use App\Platform\Filament\Resources\TierApplications\Pages\ListTierApplications;
use App\Platform\Filament\Resources\TierApplications\Pages\ViewTierApplication;
use App\Platform\Filament\Resources\TierApplications\RelationManagers\DocumentsRelationManager;
use App\Platform\Staff\StaffRoles;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\LivewireHttp;

/*
 * P01-03, the console side (PLATFORM_CONSOLE.md § 1, ONBOARDING_COMPLIANCE.md
 * § 5.3): the compliance review queue, an application's page, approve /
 * reject / more information, document review, and staff access to the
 * document files (signed, short-lived, permission-checked, audited).
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    Storage::fake('compliance');
    Notification::fake();
    seedStaffRoles();
    seedOrganizationRoles();
    $this->console = '/'.config('app.admin_path');

    $this->acme = Organization::factory()->create(['name' => 'Acme Homes', 'slug' => 'acme-homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ngozi Owner', 'email' => 'ngozi@acme.test']), $this->acme);
    $this->officer = staffNamed('Tobi', ['compliance-officer']);
});

describe('acceptance: an approved application changes the organization\'s tier and is audited', function () {
    it('approves from the console: Tier 2, audited with the staff member and reason, owners emailed', function () {
        inOrganization($this->acme, function () {
            app(OnboardingChecklist::class)->start();
            app(OnboardingChecklist::class)->reopen(ChecklistItem::Tier, ['requested_tier' => 2]);
        });
        $application = submittedApplication($this->acme, $this->owner);
        $this->actingAs($this->officer, 'staff');

        Livewire::test(ViewTierApplication::class, ['record' => $application->id])
            ->assertActionVisible('approve')
            ->callAction('approve', data: ['reason' => 'CAC and SCUML checked on the registries', 'note_to_owner' => 'Welcome to Tier 2.'])
            ->assertNotified('Approved. The organization is now at Tier 2.');

        $organization = $this->acme->fresh();
        $decided = inOrganization($this->acme, fn () => TierApplication::query()->findOrFail($application->id));

        expect($organization->transaction_tier)->toBe(TransactionTier::Two)
            ->and($decided->status)->toBe(TierApplicationStatus::Approved)
            ->and($decided->reviewed_by_staff_id)->toBe($this->officer->id)
            ->and($decided->decided_at)->not->toBeNull()
            ->and($decided->open_organization_id)->toBeNull()
            ->and($decided->review_note)->toBe('Welcome to Tier 2.')
            ->and(inOrganization($this->acme, fn () => ComplianceDocument::query()->pluck('status')->unique()->all()))->toBe([ComplianceDocumentStatus::Accepted])
            ->and(inOrganization($this->acme, fn () => OnboardingChecklistItem::query()->where('item', 'tier')->sole()->isDone()))->toBeTrue();

        // Audited: the application decision and the tier change, each with the staff member and the reason.
        $approved = AuditEntry::query()->where('event', 'tier_application.approved')->sole();
        $changed = AuditEntry::query()->where('event', 'organization.tier_changed')->sole();

        expect($approved->log_name)->toBe('compliance')
            ->and($approved->causer_type)->toBe((new PlatformStaff)->getMorphClass())
            ->and($approved->causer_id)->toBe($this->officer->id)
            ->and($approved->reason)->toBe('CAC and SCUML checked on the registries')
            ->and($approved->subject_id)->toBe($application->id)
            ->and($changed->subject_type)->toBe((new Organization)->getMorphClass())
            ->and($changed->subject_id)->toBe($this->acme->id)
            ->and($changed->causer_id)->toBe($this->officer->id)
            ->and($changed->reason)->toBe('CAC and SCUML checked on the registries')
            ->and($changed->properties['old'])->toBe(['transaction_tier' => 1])
            ->and($changed->properties['attributes'])->toBe(['transaction_tier' => 2])
            // Logged once: the model's own change entry is not written too.
            ->and(AuditEntry::query()->where('log_name', 'organization')->where('subject_id', $this->acme->id)->where('event', 'updated')->exists())->toBeFalse();

        $this->artisan('goproperty:audit-verify')->assertSuccessful();

        Notification::assertSentTo($this->owner, TierApplicationDecided::class, function (TierApplicationDecided $notice) {
            $mail = $notice->toMail($this->owner);

            return $notice->outcome === TierApplicationStatus::Approved
                && $notice->queue === 'notifications'
                && str_contains((string) $mail->subject, 'Tier 2')
                && str_contains(implode(' ', $mail->introLines), 'Welcome to Tier 2.')
                && ! str_contains(implode(' ', $mail->introLines), 'registries');
        });

        // The organization's console page shows the change in its history.
        $this->get($this->console.'/customers/'.$this->acme->id)->assertOk()->assertSee('Tier 2');
    });

    it('moves a Tier 3 application to Tier 3 through the one service', function () {
        $application = submittedApplication($this->acme, $this->owner, TransactionTier::Three);

        app(TierReview::class)->approve($this->officer, reviewOf($application), 'Call held with the directors');

        expect($this->acme->fresh()->transaction_tier)->toBe(TransactionTier::Three);
    });

    it('does not change the tier on rejection, and lets the owner apply again', function () {
        $application = submittedApplication($this->acme, $this->owner);
        $this->actingAs($this->officer, 'staff');

        Livewire::test(ViewTierApplication::class, ['record' => $application->id])
            ->callAction('reject', data: ['reason' => 'SCUML certificate belongs to another company', 'note_to_owner' => 'The SCUML certificate is not in your company\'s name.']);

        expect($this->acme->fresh()->transaction_tier)->toBe(TransactionTier::One)
            ->and(reviewOf($application)->status)->toBe(TierApplicationStatus::Rejected)
            ->and(AuditEntry::query()->where('event', 'tier_application.rejected')->sole()->reason)->toBe('SCUML certificate belongs to another company')
            ->and(AuditEntry::query()->where('event', 'organization.tier_changed')->exists())->toBeFalse();

        Notification::assertSentTo($this->owner, TierApplicationDecided::class, fn (TierApplicationDecided $notice) => $notice->outcome === TierApplicationStatus::Rejected
            && $notice->note === 'The SCUML certificate is not in your company\'s name.');

        // The owner sees the outcome and may start again.
        $this->actingAs($this->owner)->get('/o/acme-homes/compliance/tier')->assertOk()
            ->assertSee('Not approved')->assertSee('not in your company')->assertSee('Start application');
        expect(inOrganization($this->acme, fn () => app(TierApplications::class)->start($this->owner, TransactionTier::Two))->status)->toBe(TierApplicationStatus::Draft);
    });

    it('needs a reason, and an application waiting for compliance', function () {
        $application = submittedApplication($this->acme, $this->owner);
        $review = app(TierReview::class);

        expect(fn () => $review->approve($this->officer, reviewOf($application), '  '))->toThrow(TierReviewRefused::class, 'reason')
            ->and(fn () => $review->reject($this->officer, reviewOf($application), ''))->toThrow(TierReviewRefused::class, 'reason');

        $draft = tierApplication(Organization::factory()->create(), joinAs(User::factory()->create(), $other = Organization::query()->latest('id')->first()));
        expect(fn () => $review->approve($this->officer, reviewOf($draft), 'ok'))->toThrow(TierReviewRefused::class, 'Draft')
            ->and($other->fresh()->transaction_tier)->toBe(TransactionTier::One);

        $review->approve($this->officer, reviewOf($application), 'Checked');
        expect(fn () => $review->approve($this->officer, reviewOf($application), 'Again'))->toThrow(TierReviewRefused::class)
            ->and(fn () => $review->reject($this->officer, reviewOf($application), 'Undo'))->toThrow(TierReviewRefused::class)
            ->and(AuditEntry::query()->where('event', 'organization.tier_changed')->count())->toBe(1);
    });

    it('refuses to approve while a required document has been rejected', function () {
        $application = submittedApplication($this->acme, $this->owner);
        $scuml = inOrganization($this->acme, fn () => ComplianceDocument::query()->where('type', 'scuml_certificate')->sole());

        app(TierReview::class)->rejectDocument($this->officer, reviewDocument($scuml), 'Blurred: the registration number cannot be read.');

        expect(fn () => app(TierReview::class)->approve($this->officer, reviewOf($application), 'Looks fine'))
            ->toThrow(TierReviewRefused::class, 'Upload the SCUML registration certificate.')
            ->and($this->acme->fresh()->transaction_tier)->toBe(TransactionTier::One);
    });
});

describe('the more-information loop', function () {
    it('sends the application back, the owner answers with a new document, and it returns to the queue', function () {
        $application = submittedApplication($this->acme, $this->owner);
        $this->actingAs($this->officer, 'staff');

        Livewire::test(ViewTierApplication::class, ['record' => $application->id])
            ->callAction('startReview')
            ->callAction('requestMoreInfo', data: ['request' => 'Please send a proof of address for the Ikeja office.']);

        expect(reviewOf($application)->status)->toBe(TierApplicationStatus::MoreInfoNeeded)
            ->and(AuditEntry::query()->where('event', 'tier_application.more_info_requested')->sole()->reason)->toBe('Please send a proof of address for the Ikeja office.');
        Notification::assertSentTo($this->owner, TierApplicationDecided::class, fn (TierApplicationDecided $notice) => $notice->outcome === TierApplicationStatus::MoreInfoNeeded
            && str_contains(implode(' ', $notice->toMail($this->owner)->introLines), 'Ikeja office'));

        // Staff cannot decide while it waits for the owner.
        expect(fn () => app(TierReview::class)->approve($this->officer, reviewOf($application), 'ok'))->toThrow(TierReviewRefused::class, 'waiting for the organization');

        // The owner sees the request, uploads, answers and sends it back.
        $page = $this->actingAs($this->owner)->get('/o/acme-homes/compliance/tier')->assertOk()
            ->assertSee('Compliance needs more information')->assertSee('Ikeja office');
        uploadDocument($this->acme, $this->owner, inOrganization($this->acme, fn () => TierApplication::query()->findOrFail($application->id)), ComplianceDocumentType::ProofOfAddress);
        $snapshot = LivewireHttp::snapshot($this->actingAs($this->owner)->get('/o/acme-homes/compliance/tier'));
        LivewireHttp::update($this, $snapshot, ['note' => 'Added the Ikeja utility bill.'], [['submit']])->assertOk();

        $again = reviewOf($application);
        expect($again->status)->toBe(TierApplicationStatus::Submitted)
            ->and($again->applicant_note)->toBe('Added the Ikeja utility bill.')
            ->and(AuditEntry::query()->where('event', 'tier_application.resubmitted')->sole()->causer_id)->toBe($this->owner->id);

        // Back in the queue, then approved.
        $this->actingAs($this->officer, 'staff');
        Livewire::test(ListTierApplications::class)->assertCanSeeTableRecords([$again]);
        app(TierReview::class)->approve($this->officer, $again, 'Address confirmed');
        expect($this->acme->fresh()->transaction_tier)->toBe(TransactionTier::Two);
    });

    it('shows a rejected document and its note to the owner, who replaces it', function () {
        $application = submittedApplication($this->acme, $this->owner);
        $scuml = inOrganization($this->acme, fn () => ComplianceDocument::query()->where('type', 'scuml_certificate')->sole());
        $this->actingAs($this->officer, 'staff');

        Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => reviewOf($application), 'pageClass' => ViewTierApplication::class])
            ->callTableAction('reject', reviewDocument($scuml), data: ['note' => 'Blurred: the number cannot be read.']);
        app(TierReview::class)->requestMoreInfo($this->officer, reviewOf($application), 'See the note on the SCUML certificate.');

        $this->actingAs($this->owner)->get('/o/acme-homes/compliance/tier')->assertOk()->assertSee('Blurred: the number cannot be read.');
        expect(fn () => inOrganization($this->acme, fn () => app(TierApplications::class)->submit($this->owner, TierApplication::query()->findOrFail($application->id))))
            ->toThrow(ApplicationIncomplete::class, 'SCUML');

        uploadDocument($this->acme, $this->owner, inOrganization($this->acme, fn () => TierApplication::query()->findOrFail($application->id)), ComplianceDocumentType::ScumlCertificate);
        expect(inOrganization($this->acme, fn () => app(TierApplications::class)->submit($this->owner, TierApplication::query()->findOrFail($application->id)))->status)->toBe(TierApplicationStatus::Submitted);
    });
});

describe('the review queue', function () {
    it('lists applications waiting for compliance, oldest first, without drafts or decided ones', function () {
        $older = submittedApplication($this->acme, $this->owner);
        $this->travel(1)->hours();
        $other = Organization::factory()->create(['name' => 'Lekki Lets']);
        $newer = submittedApplication($other, joinAs(User::factory()->create(), $other), TransactionTier::Three);
        $draftOrg = Organization::factory()->create();
        $draft = tierApplication($draftOrg, joinAs(User::factory()->create(), $draftOrg));
        $decidedOrg = Organization::factory()->create();
        $decided = submittedApplication($decidedOrg, joinAs(User::factory()->create(), $decidedOrg));
        app(TierReview::class)->reject($this->officer, reviewOf($decided), 'No');

        $this->actingAs($this->officer, 'staff');

        Livewire::test(ListTierApplications::class)
            ->assertCanSeeTableRecords([reviewOf($older), reviewOf($newer)], inOrder: true)
            ->assertCanNotSeeTableRecords([reviewOf($draft), reviewOf($decided)])
            ->assertSee('Acme Homes')
            ->assertSee('Tier 1 → Tier 3');

        $this->get($this->console.'/tier-applications')->assertOk();
        $this->get($this->console.'/tier-applications/'.$older->id)->assertOk()->assertSee('Every required document is in.');
    });
});

describe('who may review', function () {
    it('refuses the queue, the page and the documents to staff without the compliance permissions', function (string $role) {
        $application = submittedApplication($this->acme, $this->owner);
        $document = inOrganization($this->acme, fn () => ComplianceDocument::query()->firstOrFail());
        $staff = staffNamed('Sade', [$role]);

        $this->actingAs($staff, 'staff')->get($this->console.'/tier-applications')->assertForbidden();
        $this->actingAs($staff, 'staff')->get($this->console.'/tier-applications/'.$application->id)->assertForbidden();
        $this->actingAs($staff, 'staff')->get($this->console.'/document-expiry')->assertForbidden();
        $this->actingAs($staff, 'staff')->get(app(DocumentLinks::class)->for(reviewDocument($document)))->assertForbidden();

        expect(fn () => app(TierReview::class)->approve($staff, reviewOf($application), 'x'))->toThrow(TierReviewRefused::class, 'permission')
            ->and(fn () => app(TierReview::class)->requestMoreInfo($staff, reviewOf($application), 'x'))->toThrow(TierReviewRefused::class, 'permission')
            ->and(fn () => app(TierReview::class)->rejectDocument($staff, reviewDocument($document), 'x'))->toThrow(TierReviewRefused::class, 'permission')
            ->and($this->acme->fresh()->transaction_tier)->toBe(TransactionTier::One)
            ->and(AuditEntry::query()->where('event', 'compliance_document.viewed')->exists())->toBeFalse();
    })->with(['operations-manager', 'finance-admin', 'support-agent', 'technical-admin', 'analyst']);

    it('lets a reviewer without tiers.approve ask for information but not decide, nor open files without compliance.view_documents', function () {
        $application = submittedApplication($this->acme, $this->owner);
        $document = inOrganization($this->acme, fn () => ComplianceDocument::query()->firstOrFail());
        [$reviewerRole] = app(StaffRoles::class)->createRole(staffMember(), 'Junior reviewer', 'Reviews only', ['tiers.review']);
        $junior = staffNamed('Jide');
        $junior->syncRoles([]);
        $junior->assignRole($reviewerRole);
        $junior = $junior->fresh();

        expect(Gate::forUser($junior)->allows('viewAny', ReviewApplication::class))->toBeTrue()
            ->and(Gate::forUser($junior)->allows('approve', reviewOf($application)))->toBeFalse()
            ->and(Gate::forUser($junior)->allows('viewFile', reviewDocument($document)))->toBeFalse()
            ->and(fn () => app(TierReview::class)->approve($junior, reviewOf($application), 'x'))->toThrow(TierReviewRefused::class, 'permission');

        $this->actingAs($junior, 'staff');
        Livewire::test(ViewTierApplication::class, ['record' => $application->id])
            ->assertActionHidden('approve')
            ->assertActionHidden('reject')
            ->assertActionVisible('requestMoreInfo');
        Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => reviewOf($application), 'pageClass' => ViewTierApplication::class])
            ->assertTableActionHidden('open', reviewDocument($document))
            ->assertSee('Hidden')
            ->assertDontSee('NUM-SCUML_-12345');
        $this->get(app(DocumentLinks::class)->for(reviewDocument($document)))->assertForbidden();
    });
});

describe('document files', function () {
    it('opens a document for a compliance officer through a signed link, and audits the view', function () {
        submittedApplication($this->acme, $this->owner);
        $document = inOrganization($this->acme, fn () => ComplianceDocument::query()->where('type', 'scuml_certificate')->sole());
        $link = app(DocumentLinks::class)->for(reviewDocument($document));

        $response = $this->actingAs($this->officer, 'staff')->get($link)->assertOk();

        expect($response->getContent())->toStartWith('%PDF-1.4')
            ->and($response->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer');

        $viewed = AuditEntry::query()->where('event', 'compliance_document.viewed')->sole();
        expect($viewed->causer_id)->toBe($this->officer->id)
            ->and($viewed->subject_type)->toBe((new ComplianceDocument)->getMorphClass())
            ->and($viewed->subject_id)->toBe($document->id);

        // The console's documents list links to it for the officer.
        Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => reviewOf(inOrganization($this->acme, fn () => TierApplication::query()->sole())), 'pageClass' => ViewTierApplication::class])
            ->assertTableActionVisible('open', reviewDocument($document))
            ->assertSee('NUM-SCUML_-12345');
    });

    it('is not reachable without a valid signature, once the link has expired, or outside the console', function () {
        submittedApplication($this->acme, $this->owner);
        $document = inOrganization($this->acme, fn () => ComplianceDocument::query()->firstOrFail());
        $link = app(DocumentLinks::class)->for(reviewDocument($document));

        // No signature, a changed document id, a changed expiry.
        $this->actingAs($this->officer, 'staff')->get(route(DocumentLinks::ROUTE, ['document' => $document->id]))->assertForbidden();
        $this->actingAs($this->officer, 'staff')->get(str_replace('/'.$document->id.'/', '/'.($document->id + 1).'/', $link))->assertForbidden();
        $this->actingAs($this->officer, 'staff')->get((string) preg_replace('/expires=\d+/', 'expires='.(time() + 86400), $link))->assertForbidden();

        // Expired.
        $this->travel(config('compliance.signed_url_minutes') + 1)->minutes();
        $this->actingAs($this->officer, 'staff')->get($link)->assertForbidden();
        $this->travelBack();

        // A customer session (the org-owner who uploaded it) is not a staff session.
        auth('staff')->logout();
        $fresh = app(DocumentLinks::class)->for(reviewDocument($document));
        $this->actingAs($this->owner, 'web')->get($fresh)->assertRedirect(route('filament.console.auth.login'));

        // Staff without 2FA are sent to enrol first: StaffTwoFactorEnforcementTest walks this route too.

        // Nothing serves the compliance disk directly, and what is on it is ciphertext.
        expect(collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->getName())->filter(fn ($name) => is_string($name) && str_contains($name, 'storage.compliance')))->toBeEmpty()
            ->and(config('filesystems.disks.compliance.serve') ?? false)->toBeFalse()
            ->and(config('filesystems.disks.compliance.root'))->not->toStartWith(public_path())
            ->and(Storage::disk('compliance')->get($document->path))->not->toContain('%PDF');
        // The `local` disk's own signed file route never serves the compliance disk.
        expect($this->get('/storage/'.$document->path)->isSuccessful())->toBeFalse();

        expect(AuditEntry::query()->where('event', 'compliance_document.viewed')->exists())->toBeFalse();
    });

    it('refuses a file that does not match its checksum', function () {
        submittedApplication($this->acme, $this->owner);
        $document = inOrganization($this->acme, fn () => ComplianceDocument::query()->firstOrFail());
        Storage::disk('compliance')->put($document->path, encrypt('%PDF-1.4 swapped', false));

        $this->actingAs($this->officer, 'staff')->get(app(DocumentLinks::class)->for(reviewDocument($document)))->assertStatus(409);
        expect(AuditEntry::query()->where('event', 'compliance_document.viewed')->exists())->toBeFalse();
    });
});
