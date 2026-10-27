<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Compliance\ApplicationIncomplete;
use App\Compliance\TierApplications;
use App\Enums\ComplianceDocumentStatus;
use App\Enums\ComplianceDocumentType;
use App\Enums\TransactionTier;
use App\Models\ComplianceDocument;
use App\Models\Organization;
use App\Models\TierApplication;
use App\Models\User;
use App\Notifications\ComplianceDocumentsExpiring;
use App\Platform\Compliance\DocumentExpiryCheck;
use App\Platform\Compliance\ReviewDocument;
use App\Platform\Compliance\TierReview;
use App\Platform\Filament\Resources\DocumentExpiry\Pages\ListDocumentExpiry;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * P01-03, document expiry tracking (ONBOARDING_COMPLIANCE.md § 5.3–5.4:
 * expiry dates tracked, reminders 30 days ahead, `document_expiring` /
 * `document_expired` flags to the org-owner). The docs say nothing about
 * the tier on expiry, so it is flagged only: the tier stays.
 */

beforeEach(function () {
    Storage::fake('compliance');
    Notification::fake();
    seedStaffRoles();
    seedOrganizationRoles();

    $this->acme = Organization::factory()->create(['name' => 'Acme Homes', 'slug' => 'acme-homes']);
    $this->owner = joinAs(User::factory()->create(['email' => 'ngozi@acme.test']), $this->acme);
    $this->officer = staffNamed('Tobi', ['compliance-officer']);
});

/** An approved Tier 2 application whose SCUML certificate expires in $days days. */
function approvedWithExpiringScuml(Organization $organization, User $owner, int $days): ComplianceDocument
{
    $application = tierApplication($organization, $owner, TransactionTier::Two, without: [ComplianceDocumentType::ScumlCertificate]);
    $scuml = uploadDocument($organization, $owner, $application, ComplianceDocumentType::ScumlCertificate, expiresAt: CarbonImmutable::today()->addDays($days));
    inOrganization($organization, fn () => app(TierApplications::class)->submit($owner, $application));
    app(TierReview::class)->approve(test()->officer, ReviewDocument::query()->findOrFail($scuml->id)->application, 'Checked');

    return $scuml;
}

function documentNow(Organization $organization, ComplianceDocument $document): ComplianceDocument
{
    return inOrganization($organization, fn () => ComplianceDocument::query()->findOrFail($document->id));
}

it('flags a document 30 days before it expires, once, and tells the owner', function () {
    $scuml = approvedWithExpiringScuml($this->acme, $this->owner, 45);

    expect(app(DocumentExpiryCheck::class)->run())->toBe(['expiring' => 0, 'expired' => 0]);

    $this->travel(20)->days();
    expect(app(DocumentExpiryCheck::class)->run())->toBe(['expiring' => 1, 'expired' => 0])
        ->and(documentNow($this->acme, $scuml)->expiring_notified_at)->not->toBeNull()
        ->and(documentNow($this->acme, $scuml)->status)->toBe(ComplianceDocumentStatus::Accepted)
        ->and(AuditEntry::query()->where('event', 'compliance_document.expiring')->sole()->subject_id)->toBe($scuml->id);

    Notification::assertSentToTimes($this->owner, ComplianceDocumentsExpiring::class, 1);
    Notification::assertSentTo($this->owner, ComplianceDocumentsExpiring::class, function (ComplianceDocumentsExpiring $notice) {
        $mail = $notice->toMail($this->owner);

        return $notice->queue === 'notifications'
            && str_contains(implode(' ', $mail->introLines), 'Expires on')
            && str_contains(implode(' ', $mail->introLines), 'SCUML registration certificate')
            && $mail->actionUrl === route('workspace.compliance.tier', ['organization' => 'acme-homes']);
    });

    // Running again (or twice a day) tells nobody twice.
    expect(app(DocumentExpiryCheck::class)->run())->toBe(['expiring' => 0, 'expired' => 0]);
    Notification::assertSentToTimes($this->owner, ComplianceDocumentsExpiring::class, 1);

    // The owner sees it next to the document.
    $this->actingAs($this->owner)->get('/o/acme-homes/compliance/tier')->assertOk()->assertSee('Expires in 25 days');
});

it('marks a document expired after its date, tells the owner, and leaves the tier alone', function () {
    $scuml = approvedWithExpiringScuml($this->acme, $this->owner, 10);

    app(DocumentExpiryCheck::class)->run();
    $this->travel(11)->days();
    expect(app(DocumentExpiryCheck::class)->run())->toBe(['expiring' => 0, 'expired' => 1]);

    $document = documentNow($this->acme, $scuml);
    expect($document->status)->toBe(ComplianceDocumentStatus::Expired)
        ->and($document->expired_notified_at)->not->toBeNull()
        ->and($this->acme->fresh()->transaction_tier)->toBe(TransactionTier::Two)
        ->and(AuditEntry::query()->where('event', 'compliance_document.expired')->sole()->properties['attributes'])->toBe(['status' => 'expired']);

    Notification::assertSentToTimes($this->owner, ComplianceDocumentsExpiring::class, 2);
    Notification::assertSentTo($this->owner, ComplianceDocumentsExpiring::class, fn (ComplianceDocumentsExpiring $notice) => $notice->documents[0]['expired'] === true
        && str_contains((string) $notice->toMail($this->owner)->subject, 'have expired'));

    expect(app(DocumentExpiryCheck::class)->run())->toBe(['expiring' => 0, 'expired' => 0]);
});

it('stops an expired document from counting towards an open application', function () {
    $application = tierApplication($this->acme, $this->owner, TransactionTier::Two, without: [ComplianceDocumentType::CacCertificate]);
    uploadDocument($this->acme, $this->owner, $application, ComplianceDocumentType::CacCertificate, expiresAt: CarbonImmutable::today()->addDays(3));

    $this->travel(5)->days();
    app(DocumentExpiryCheck::class)->run();

    expect(fn () => inOrganization($this->acme, fn () => app(TierApplications::class)->submit($this->owner, TierApplication::query()->findOrFail($application->id))))
        ->toThrow(ApplicationIncomplete::class, 'Upload the CAC certificate.');
});

it('ignores documents far from expiry, without a date, or of rejected applications', function () {
    approvedWithExpiringScuml($this->acme, $this->owner, 200);

    $other = Organization::factory()->create();
    $otherOwner = joinAs(User::factory()->create(), $other);
    $rejected = tierApplication($other, $otherOwner, TransactionTier::Two, without: [ComplianceDocumentType::ScumlCertificate]);
    uploadDocument($other, $otherOwner, $rejected, ComplianceDocumentType::ScumlCertificate, expiresAt: CarbonImmutable::today()->addDays(5));
    inOrganization($other, fn () => app(TierApplications::class)->submit($otherOwner, $rejected));
    app(TierReview::class)->reject($this->officer, reviewOf($rejected), 'Not eligible');

    expect(app(DocumentExpiryCheck::class)->run())->toBe(['expiring' => 0, 'expired' => 0]);
    Notification::assertNotSentTo([$this->owner, $otherOwner], ComplianceDocumentsExpiring::class);
});

it('runs daily from the scheduler, in-process', function () {
    approvedWithExpiringScuml($this->acme, $this->owner, 5);

    $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'compliance-document-expiry');

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->expression)->toBe('15 3 * * *');

    $event->run(app());
    Notification::assertSentToTimes($this->owner, ComplianceDocumentsExpiring::class, 1);
});

it('lists flagged documents in the console for compliance staff', function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    $soon = approvedWithExpiringScuml($this->acme, $this->owner, 12);
    $other = Organization::factory()->create(['name' => 'Lekki Lets']);
    $later = approvedWithExpiringScuml($other, joinAs(User::factory()->create(), $other), 300);

    $this->actingAs($this->officer, 'staff');
    Livewire::test(ListDocumentExpiry::class)
        ->assertCanSeeTableRecords([ReviewDocument::query()->findOrFail($soon->id)])
        ->assertCanNotSeeTableRecords([ReviewDocument::query()->findOrFail($later->id)])
        ->assertSee('Acme Homes')
        ->assertSee('SCUML registration certificate');

    // Staff without `tiers.review` get 403: TierReviewTest "refuses the queue, the page and the documents".
    $this->get('/'.config('app.admin_path').'/document-expiry')->assertOk();
});
