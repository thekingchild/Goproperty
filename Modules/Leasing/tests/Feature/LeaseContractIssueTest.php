<?php

declare(strict_types=1);

use App\Branding\BrandingSettings;
use App\Documents\DocumentRenderer;
use App\Documents\Verification\DocumentVerification;
use App\Documents\Verification\VerificationCodes;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Media\EvidenceFileProtected;
use App\Media\Media;
use App\Media\MediaUrls;
use App\Models\Organization;
use App\Models\User;
use App\Settings\SettingsStore;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Leasing\Documents\ContractClauses;
use Modules\Leasing\Documents\LeaseContracts;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Events\LeaseActivated;
use Modules\Leasing\Jobs\RenderLeaseContract;
use Modules\Leasing\Leases\LeaseRefused;
use Modules\Leasing\Leases\LeaseService;
use Modules\Leasing\Models\Lease;
use Modules\Leasing\Models\LeaseContract;
use Tests\Support\PdfVisualDiff;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../../tests/Support/documents.php';

/*
 * P03-03b: lease contracts are issued when a lease is activated (a
 * renewal's too), their PDF rendered once on the `pdf` queue and kept as
 * evidence, re-printed from the kept file through the lease page, checked
 * on the public verify page, and never reachable from another
 * organization (ARCHITECTURE.md § 6.5, BRANDING_DOCUMENTS.md § 4–5).
 */

beforeEach(function () {
    Storage::fake('media');
    seedOrganizationRoles();
    $this->travelTo('2026-12-20 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes Ltd']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->otherOwner = joinAs(User::factory()->create(), $this->other);
    $landlord = ownerIn($this->acme, ['first_name' => 'Babatunde', 'last_name' => 'Adeyemi']);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court', 'address' => '22 Fola Osibo Road, Lekki Phase 1', 'state' => 'Lagos', 'owner_profile_id' => $landlord->id]);
    $this->flat = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 3B']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222', 'email' => 'kemi.adebayo@example.com']);
});

afterEach(fn () => leasesFreshRequest());

/** Drafts and activates a lease through LeaseService, its contract PDF rendered at once (the sync queue). */
function activatedWithContract(Organization $organization, User $by, mixed $unit, mixed $tenant, array $changes = []): Lease
{
    $lease = draftLease($organization, $by, $unit, $tenant, $changes);

    return inOrganization($organization, fn (): Lease => app(LeaseService::class)->activate($by, $lease));
}

/** The contract issued for the lease, as stored. */
function contractOf(Organization $organization, Lease $lease): ?LeaseContract
{
    return inOrganization($organization, fn (): ?LeaseContract => LeaseContract::query()->where('lease_id', $lease->id)->first());
}

/** The contract's kept PDF media. */
function contractMedia(Organization $organization, LeaseContract $contract): ?Media
{
    return inOrganization($organization, fn (): ?Media => app(LeaseContracts::class)->kept($contract));
}

/** GET the lease's contract download as the user. */
function downloadContract(User $user, Lease $lease, string $slug = 'acme-homes'): TestResponse
{
    leasesFreshRequest();

    return test()->actingAs($user)->get("/o/{$slug}/leases/{$lease->id}/contract");
}

/** Follows a download's redirect to the signed media link and returns the PDF bytes. */
function followContract(TestResponse $download): string
{
    $location = (string) $download->assertRedirect()->headers->get('Location');
    expect($location)->toContain('/media/')->toContain('signature=');

    return test()->get($location)->assertOk()->assertHeader('Content-Type', 'application/pdf')->streamedContent();
}

/** Saves the organization's branding as the settings page does. */
function contractBranding(Organization $organization, User $by, array $changes): void
{
    inOrganization($organization, fn () => app(BrandingSettings::class)->save($by, $organization, $changes + [
        'tradingName' => '',
        'primaryColor' => '#0E6B5C',
        'accentColor' => '#F2B53A',
        'address' => '14 Admiralty Way, Lekki Phase 1, Lagos',
        'rcNumber' => 'RC 1234567',
        'phone' => '+234 803 000 0000',
        'email' => 'accounts@adeyemirealty.ng',
        'website' => 'adeyemirealty.ng',
        'invoicePrefix' => 'INV-ADR',
        'receiptPrefix' => 'RCT-ADR',
        'leasePrefix' => 'LS-ADR',
        'invoiceNotes' => '',
        'receiptFooter' => '',
        'layout' => 'modern',
        'emailSenderName' => '',
        'hidePoweredBy' => false,
    ]));
}

describe('acceptance: generated once on activation, reused on re-print', function () {
    it('renders the contract PDF when the lease is activated and serves that same file on every re-print', function () {
        $counter = countingPdfDriver();

        $lease = activatedWithContract($this->acme, $this->owner, $this->flat, $this->kemi);
        $contract = contractOf($this->acme, $lease);
        $media = contractMedia($this->acme, $contract);
        $rendersAtActivation = $counter->renders;
        $bytes = Storage::disk('media')->get($media->getPathRelativeToRoot());

        expect($contract)->not->toBeNull()
            ->and($contract->contract_number)->toBe($lease->contract_number)->toBe('LS-ADR-2026-000001')
            ->and($contract->document_media_id)->toBe($media->id)
            ->and($contract->issued_at->toDateTimeString())->toBe($lease->activated_at->toDateTimeString())
            ->and($rendersAtActivation)->toBeGreaterThan(0)
            ->and($media->collection_name)->toBe(DocumentRenderer::COLLECTION)
            ->and($media->model_type)->toBe((new LeaseContract)->getMorphClass())
            ->and($media->model_id)->toBe($contract->id)
            ->and($media->mime_type)->toBe('application/pdf')
            ->and($media->name)->toBe('LS-ADR-2026-000001')
            ->and($media->getCustomProperty('document_key'))->toBe(LeaseContracts::DOCUMENT_KEY)
            ->and($bytes)->toStartWith('%PDF-');

        // Re-printing, twice, serves the kept file: nothing is rendered again.
        $first = followContract(downloadContract($this->owner, $lease));
        $second = followContract(downloadContract($this->owner, $lease));

        expect($first)->toBe($bytes)
            ->and($second)->toBe($bytes)
            ->and($counter->renders)->toBe($rendersAtActivation)
            ->and(inOrganization($this->acme, fn () => Media::query()->where('collection_name', DocumentRenderer::COLLECTION)->count()))->toBe(1);

        requirePdfTools();
        expect(PdfVisualDiff::text($bytes))->toContain('TENANCY AGREEMENT', 'LS-ADR-2026-000001', 'Kemi Adebayo', 'Babatunde Adeyemi', 'Palm Court — Flat 3B', '₦1,500,000 yearly', '₦150,000');
    });

    it('issues the contract after the activation commits and queues its PDF on the pdf queue; a refused activation issues nothing', function () {
        Queue::fake();
        $lease = activatedWithContract($this->acme, $this->owner, $this->flat, $this->kemi);
        $contract = contractOf($this->acme, $lease);

        Queue::assertPushedOn('pdf', RenderLeaseContract::class, fn (RenderLeaseContract $job): bool => $job->contractId === $contract->id && $job->organizationId === $this->acme->id);
        expect($contract->verification_code)->toMatch('/^[0-9A-HJKMNP-TV-Z]{10}$/')
            ->and(inOrganization($this->acme, fn () => DocumentVerification::query()->where('code', $contract->verification_code)->value('kind')))->toBe('lease_contract')
            ->and(contractMedia($this->acme, $contract))->toBeNull();

        // The unit is let: a second activation is refused and issues no contract, code or job.
        $waiting = draftLease($this->acme, $this->owner, $this->flat, tenantIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare', 'phone' => '+2348039998888', 'email' => 'tunde.bakare@example.com']));
        expect(fn () => inOrganization($this->acme, fn () => app(LeaseService::class)->activate($this->owner, $waiting)))->toThrow(LeaseRefused::class);
        Queue::assertPushed(RenderLeaseContract::class, 1);
        expect(contractOf($this->acme, $waiting))->toBeNull()
            ->and(inOrganization($this->acme, fn () => DocumentVerification::query()->count()))->toBe(1);

        // The queued job renders it; running it again changes nothing (idempotent).
        $counter = countingPdfDriver();
        (new RenderLeaseContract($this->acme->id, $contract->id))->handle(app(OrganizationContext::class), app(LeaseContracts::class));
        $renders = $counter->renders;
        $media = contractMedia($this->acme, $contract);
        (new RenderLeaseContract($this->acme->id, $contract->id))->handle(app(OrganizationContext::class), app(LeaseContracts::class));

        expect($media)->not->toBeNull()
            ->and($counter->renders)->toBe($renders)
            ->and(contractOf($this->acme, $lease)->document_media_id)->toBe($media->id);
    });

    it('issues a renewal its own contract when it is activated, and leaves the renewed lease\'s contract as it was', function () {
        $lease = activatedWithContract($this->acme, $this->owner, $this->flat, $this->kemi);
        $first = contractOf($this->acme, $lease);
        $firstBytes = Storage::disk('media')->get(contractMedia($this->acme, $first)->getPathRelativeToRoot());

        $renewal = inOrganization($this->acme, fn (): Lease => app(LeaseService::class)->renew($this->owner, $lease, leaseInput(['startDate' => '2028-01-01', 'endDate' => '2028-12-31', 'rent' => '1,800,000'])));
        $renewal = inOrganization($this->acme, fn (): Lease => app(LeaseService::class)->activate($this->owner, $renewal));
        $second = contractOf($this->acme, $renewal);

        expect($second->contract_number)->toBe('LS-ADR-2026-000002')
            ->and($second->details['renewal_of'])->toBe('LS-ADR-2026-000001')
            ->and($second->verification_code)->not->toBe($first->verification_code)
            ->and(storedLease($this->acme, $lease)->status)->toBe(LeaseStatus::Renewed)
            ->and(followContract(downloadContract($this->owner, $lease)))->toBe($firstBytes);

        requirePdfTools();
        expect(PdfVisualDiff::text(followContract(downloadContract($this->owner, $renewal))))->toContain('LS-ADR-2026-000002', 'Renews', 'LS-ADR-2026-000001', '₦1,800,000 yearly', '1 Jan 2028 to 31 Dec 2028');
    });

    it('issues the contract on its first download for a lease activated before contracts were issued', function () {
        $draft = draftLease($this->acme, $this->owner, $this->flat, $this->kemi);
        Event::fake([LeaseActivated::class]);
        $lease = inOrganization($this->acme, fn (): Lease => app(LeaseService::class)->activate($this->owner, $draft));

        expect(contractOf($this->acme, $lease))->toBeNull();

        $bytes = followContract(downloadContract($this->owner, $lease));
        $contract = contractOf($this->acme, $lease);

        expect($contract)->not->toBeNull()
            ->and($contract->issued_at->toDateTimeString())->toBe($lease->activated_at->toDateTimeString())
            ->and($bytes)->toStartWith('%PDF-')
            ->and(followContract(downloadContract($this->owner, $lease)))->toBe($bytes);
    });
});

describe('acceptance: the snapshot is unaffected by later branding changes', function () {
    it('prints the branding, parties and clauses as they were at activation, even when they change before the PDF is rendered', function () {
        contractBranding($this->acme, $this->owner, ['tradingName' => 'Adeyemi Realty', 'primaryColor' => '#0E6B5C', 'layout' => 'compact']);
        inOrganization($this->acme, fn () => app(SettingsStore::class)->set(ContractClauses::SETTING, 'Clause at issue: {tenant_name} rents {unit}.'));

        Queue::fake();
        $lease = activatedWithContract($this->acme, $this->owner, $this->flat, $this->kemi);
        $contract = contractOf($this->acme, $lease);
        $htmlAtIssue = inOrganization($this->acme, fn () => app(DocumentRenderer::class)->web(app(LeaseContracts::class)->document($contract)));

        // Everything changes before the queue gets to the PDF: name, colour, layout, clauses, the tenant's and the building's details.
        contractBranding($this->acme, $this->owner, ['tradingName' => 'Renamed Homes', 'primaryColor' => '#1D4ED8', 'layout' => 'classic', 'leasePrefix' => 'LS-REN']);
        inOrganization($this->acme, fn () => app(SettingsStore::class)->set(ContractClauses::SETTING, 'Clause changed later.'));
        inOrganization($this->acme, fn () => $this->kemi->forceFill(['first_name' => 'Changed'])->save());
        inOrganization($this->acme, fn () => $this->palm->forceFill(['name' => 'Renamed Court'])->save());

        (new RenderLeaseContract($this->acme->id, $contract->id))->handle(app(OrganizationContext::class), app(LeaseContracts::class));
        $contract = contractOf($this->acme, $lease);
        $bytes = followContract(downloadContract($this->owner, $lease));
        $htmlNow = inOrganization($this->acme, fn () => app(DocumentRenderer::class)->web(app(LeaseContracts::class)->document($contract)));

        expect($htmlNow)->toBe($htmlAtIssue)
            ->and($htmlNow)->toContain('Adeyemi Realty', 'class="layout-compact"', 'Clause at issue: Kemi Adebayo rents Flat 3B.', 'background: #0e6b5c')
            ->and($htmlNow)->not->toContain('Renamed Homes', 'Clause changed later.', 'Changed Adebayo', 'Renamed Court', '#1d4ed8');

        requirePdfTools();
        $text = PdfVisualDiff::text($bytes);
        expect($text)->toContain('Adeyemi Realty', 'Clause at issue: Kemi Adebayo rents Flat 3B.', 'Palm Court')
            ->and($text)->not->toContain('Renamed', 'Clause changed later', 'Changed Adebayo');

        // Later still: re-printing serves the same kept bytes.
        contractBranding($this->acme, $this->owner, ['tradingName' => 'Third Name', 'layout' => 'modern']);
        expect(followContract(downloadContract($this->owner, $lease)))->toBe($bytes);
    });
});

describe('acceptance: /verify resolves contracts', function () {
    it('shows a contract in force as valid, with its term and rent and nothing about the people or the address', function () {
        $lease = activatedWithContract($this->acme, $this->owner, $this->flat, $this->kemi);
        $code = contractOf($this->acme, $lease)->verification_code;

        $this->get('/verify/'.$code)->assertOk()
            ->assertSee('This tenancy agreement is genuine')
            ->assertSee('Adeyemi Realty Ltd')
            ->assertSee('LS-ADR-2026-000001')
            ->assertSee('data-status="valid"', false)
            ->assertSee('1 Jan 2027 to 31 Dec 2027')
            ->assertSee('In force')
            ->assertDontSee('Kemi')
            ->assertDontSee('+2348031112222')
            ->assertDontSee('Babatunde')
            ->assertDontSee('Fola Osibo');
    });

    it('shows a renewed lease\'s contract as superseded by its renewal\'s, which is valid', function () {
        $lease = activatedWithContract($this->acme, $this->owner, $this->flat, $this->kemi);
        $renewal = inOrganization($this->acme, fn (): Lease => app(LeaseService::class)->renew($this->owner, $lease, leaseInput(['startDate' => '2028-01-01', 'endDate' => '2028-12-31'])));

        // A renewal still waiting as a draft supersedes nothing.
        $this->get('/verify/'.contractOf($this->acme, $lease)->verification_code)->assertOk()->assertSee('data-status="valid"', false);

        $renewal = withoutContractPdfs(fn () => inOrganization($this->acme, fn (): Lease => app(LeaseService::class)->activate($this->owner, $renewal)));

        $this->get('/verify/'.contractOf($this->acme, $lease)->verification_code)->assertOk()
            ->assertSee('This tenancy agreement was replaced')
            ->assertSee('data-status="superseded"', false)
            ->assertSee('Replaced by')
            ->assertSee('LS-ADR-2026-000002');
        $this->get('/verify/'.contractOf($this->acme, $renewal)->verification_code)->assertOk()
            ->assertSee('data-status="valid"', false)
            ->assertSee('1 Jan 2028 to 31 Dec 2028');
    });

    it('says how an ended lease ended, and answers 404 for a code nobody issued', function () {
        $lease = activatedWithContract($this->acme, $this->owner, $this->flat, $this->kemi);
        inOrganization($this->acme, fn () => app(LeaseService::class)->terminate($this->owner, $lease, 'Moved out early'));

        $this->get('/verify/'.contractOf($this->acme, $lease)->verification_code)->assertOk()
            ->assertSee('data-status="valid"', false)
            ->assertSee('Ended early on 20/12/2026')
            ->assertDontSee('Moved out early');
        $this->get('/verify/'.app(VerificationCodes::class)->generate())->assertNotFound();
    });
});

describe('acceptance: another organization\'s contract is not reachable', function () {
    it('answers 404 for another organization\'s lease contract, by URL, by guessed ID and by a signed link of the other organization', function () {
        $ours = activatedWithContract($this->acme, $this->owner, $this->flat, $this->kemi);
        $theirs = activatedWithContract($this->other, $this->otherOwner, unitIn($this->other, buildingIn($this->other, ['name' => 'Foreign Court', 'slug' => 'foreign-court'])), tenantIn($this->other, ['first_name' => 'Foreign', 'last_name' => 'Tenant', 'phone' => '+2348035550000', 'email' => 'foreign.tenant@example.com']));
        $theirMedia = contractMedia($this->other, contractOf($this->other, $theirs));

        // Their lease's id under our organization, and our lease under theirs (not a member).
        downloadContract($this->owner, $theirs)->assertNotFound();
        downloadContract($this->owner, $ours, 'other-realty')->assertNotFound();
        downloadContract($this->otherOwner, $ours)->assertNotFound();

        // A link signed for their file but naming our organization finds nothing.
        $forged = str_replace('/media/'.$this->other->id.'/', '/media/'.$this->acme->id.'/', app(MediaUrls::class)->temporary($theirMedia));
        $this->get($forged)->assertNotFound();

        // Inside our organization their contract does not exist.
        expect(inOrganization($this->acme, fn () => LeaseContract::query()->pluck('lease_id')->all()))->toBe([$ours->id])
            ->and(inOrganization($this->acme, fn () => Media::query()->whereKey($theirMedia->id)->exists()))->toBeFalse();

        // Their code shows their organization's contract only.
        $this->get('/verify/'.contractOf($this->other, $theirs)->verification_code)->assertOk()
            ->assertSee('Bello Homes Ltd')->assertDontSee('Adeyemi Realty');
    });
});

describe('acceptance: the evidence file cannot be deleted', function () {
    it('refuses to delete the kept PDF or the issued contract, and never changes either', function () {
        $lease = activatedWithContract($this->acme, $this->owner, $this->flat, $this->kemi);
        $contract = contractOf($this->acme, $lease);
        $media = contractMedia($this->acme, $contract);

        expect(fn () => inOrganization($this->acme, fn () => $media->delete()))->toThrow(EvidenceFileProtected::class)
            ->and(fn () => inOrganization($this->acme, fn () => $contract->delete()))->toThrow(LogicException::class)
            ->and(fn () => inOrganization($this->acme, fn () => $contract->forceFill(['clauses' => 'Rewritten.'])->save()))->toThrow(LogicException::class)
            ->and(fn () => inOrganization($this->acme, fn () => $contract->forceFill(['document_media_id' => $media->id + 1])->save()))->toThrow(LogicException::class);

        expect(contractMedia($this->acme, $contract)?->id)->toBe($media->id)
            ->and(Storage::disk('media')->exists($media->getPathRelativeToRoot()))->toBeTrue()
            ->and(contractOf($this->acme, $lease)->clauses)->toBe(ContractClauses::DEFAULT)
            ->and(contractOf($this->acme, $lease)->document_media_id)->toBe($media->id);
    });
});

describe('who may download the contract', function () {
    it('lets owners, administrators and agents download an activated lease\'s contract, never a draft\'s', function (OrganizationRole $role) {
        $lease = activeLease($this->acme, $this->owner, $this->flat, $this->kemi);
        $draft = draftLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 4']), tenantIn($this->acme, ['first_name' => 'Ngozi', 'last_name' => 'Eze', 'phone' => '+2348037776666', 'email' => 'ngozi.eze@example.com']));
        $member = joinAs(User::factory()->create(), $this->acme, $role);

        downloadContract($member, $lease)->assertRedirect();
        downloadContract($member, $draft)->assertForbidden();
    })->with([
        'org-owner' => [OrganizationRole::Owner],
        'org-admin' => [OrganizationRole::Admin],
        'agent' => [OrganizationRole::Agent],
    ]);

    it('answers 404 to an agent assigned to other buildings, and to property owners, tenants and strangers', function () {
        $lease = activeLease($this->acme, $this->owner, $this->flat, $this->kemi);
        $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
        assignIn($this->acme, $agent, buildingIn($this->acme, ['name' => 'Oak Lodge', 'slug' => 'oak-lodge']));

        downloadContract($agent, $lease)->assertNotFound();
        downloadContract(User::factory()->create(), $lease)->assertNotFound();

        assignIn($this->acme, $agent, $this->palm);
        downloadContract($agent, $lease)->assertRedirect();
    });

    it('still serves the contract while the organization is suspended (a read), but never to impersonating staff', function () {
        $lease = activeLease($this->acme, $this->owner, $this->flat, $this->kemi);
        $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();
        downloadContract($this->owner, $lease)->assertRedirect();
        $this->acme->forceFill(['status' => OrganizationStatus::Active])->save();

        $staff = staffMember(['name' => 'Kemi Support', 'email' => 'kemi@goproperty.ng']);
        enterSupport(startSupport($staff, $this->owner, $this->acme))->assertRedirect('/o/acme-homes');
        asSupport()->get("/o/acme-homes/leases/{$lease->id}/contract")->assertForbidden();
        asSupport()->get("/o/acme-homes/leases/{$lease->id}")->assertOk()->assertDontSee('data-test="download-contract"', false);
    });

    it('offers the download on the lease page once the lease is active', function () {
        $draft = draftLease($this->acme, $this->owner, $this->flat, $this->kemi);
        leasesPage($this->owner, "/{$draft->id}")->assertOk()->assertDontSee('data-test="download-contract"', false);

        $lease = withoutContractPdfs(fn () => inOrganization($this->acme, fn (): Lease => app(LeaseService::class)->activate($this->owner, $draft)));
        leasesPage($this->owner, "/{$lease->id}")->assertOk()
            ->assertSee('data-test="download-contract"', false)
            ->assertSee('href="http://localhost/o/acme-homes/leases/'.$lease->id.'/contract"', false);
    });
});
