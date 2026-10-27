<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Media\EvidenceFileProtected;
use App\Media\Media;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Enums\ReceiptStatus;
use Modules\Payments\Events\ReceiptVoided;
use Modules\Payments\Jobs\RenderReceipt;
use Modules\Payments\Models\Receipt;
use Modules\Payments\Notifications\ReceiptVoidRequested;
use Modules\Payments\Receipts\Receipts;
use Modules\Payments\Receipts\ReceiptVoidRefused;
use Modules\Payments\Receipts\ReceiptVoids;
use Tests\Support\LivewireHttp;
use Tests\Support\PdfVisualDiff;

require_once __DIR__.'/receipt-helpers.php';

/*
 * P03-10b: voiding a receipt (BRANDING_DOCUMENTS.md § 3; P03 decision 4).
 * A void is asked for with a reason by an owner, administrator or agent,
 * and approved by a *different* owner or administrator; an organization
 * with a single owner and no administrator voids alone, flagged
 * `single_approver`. A voided receipt is re-printed as a new file with the
 * VOID watermark (the original is kept), and the verify page says voided.
 * Never while staff impersonate, nothing in a suspended organization,
 * another organization's receipt is 404 everywhere, and agents assigned to
 * buildings reach their tenants' receipts only.
 */

beforeEach(function () {
    holdReceiptPdfs();
    seedOrganizationRoles();
    $this->travelTo('2026-12-25 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Tunde Bakare']), $this->acme);
    $this->admin = joinAs(User::factory()->create(['name' => 'Ngozi Eze']), $this->acme, OrganizationRole::Admin);
    $this->agent = joinAs(User::factory()->create(['name' => 'Bola Agent']), $this->acme, OrganizationRole::Agent);
    $this->otherOwner = joinAs(User::factory()->create(['name' => 'Chidi Bello']), $this->other);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348030000001', 'email' => 'kemi@example.test']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']), $this->kemi, ['billingCycle' => 'monthly', 'rent' => '100,000', 'deposit' => '0']);
    $this->bisi = tenantIn($this->other, ['first_name' => 'Foreign', 'last_name' => 'Tenant', 'phone' => '+2348030000009', 'email' => 'foreign@example.test']);
    activeLease($this->other, $this->otherOwner, unitIn($this->other, buildingIn($this->other, ['name' => 'Foreign Court'])), $this->bisi, ['billingCycle' => 'monthly', 'rent' => '100,000', 'deposit' => '0']);
    runBillGeneration();

    $this->payment = confirmedPayment($this->acme, $this->owner, $this->kemi, '100,000');
    $this->receipt = receiptOf($this->acme, $this->payment);
    $this->foreign = receiptOf($this->other, confirmedPayment($this->other, $this->otherOwner, $this->bisi, '50,000'));
});

afterEach(fn () => billsFreshRequest());

/** @return array<string, bool> ability => allowed, as ReceiptPolicy answers inside the organization */
function receiptAbilitiesOf(User $user, Organization $organization, Receipt $receipt): array
{
    return inOrganization($organization, fn (): array => [
        'viewAny' => Gate::forUser($user)->allows('viewAny', Receipt::class),
        'view' => Gate::forUser($user)->allows('view', $receipt),
        'export' => Gate::forUser($user)->allows('export', Receipt::class),
        'viewDocument' => Gate::forUser($user)->allows('viewDocument', $receipt),
        'voidRequest' => Gate::forUser($user)->allows('voidRequest', $receipt),
        'voidApprove' => Gate::forUser($user)->allows('voidApprove', $receipt),
        'voidAlone' => Gate::forUser($user)->allows('voidAlone', $receipt),
        'voidDismiss' => Gate::forUser($user)->allows('voidDismiss', $receipt),
        'update' => Gate::forUser($user)->allows('update', $receipt),
        'delete' => Gate::forUser($user)->allows('delete', $receipt),
    ]);
}

describe('acceptance: void needs a second approver', function () {
    it('voids a receipt only once a different owner or administrator approves the request', function () {
        Notification::fake();
        Event::fake([ReceiptVoided::class]);

        $requested = requestReceiptVoid($this->acme, $this->agent, $this->receipt, 'Recorded against the wrong tenant');

        expect($requested->status())->toBe(ReceiptStatus::VoidRequested)
            ->and($requested->voided_at)->toBeNull()
            ->and($requested->void_requested_by_user_id)->toBe($this->agent->id)
            ->and(AuditEntry::query()->where('event', 'receipt.void_requested')->where('causer_id', $this->agent->id)->exists())->toBeTrue();
        // The approvers are told; the requester is not.
        Notification::assertSentTo([$this->owner, $this->admin], ReceiptVoidRequested::class, fn (ReceiptVoidRequested $notice): bool => $notice->number === $this->receipt->number && $notice->reason === 'Recorded against the wrong tenant');
        Notification::assertNotSentTo($this->agent, ReceiptVoidRequested::class);

        // An agent may ask but never approve; nothing changes.
        expect(receiptAbilitiesOf($this->agent, $this->acme, $requested))->toMatchArray(['voidApprove' => false, 'voidAlone' => false, 'voidDismiss' => true])
            ->and(fn () => approveReceiptVoid($this->acme, $this->agent, $requested))->toThrow(AuthorizationException::class)
            ->and(storedReceipt($this->acme, $this->receipt)->voided_at)->toBeNull();
        Event::assertNotDispatched(ReceiptVoided::class);

        // Another member — the administrator — approves.
        $voided = approveReceiptVoid($this->acme, $this->admin, $requested);

        expect($voided->status())->toBe(ReceiptStatus::Voided)
            ->and($voided->voided_by_user_id)->toBe($this->agent->id)
            ->and($voided->void_approved_by_user_id)->toBe($this->admin->id)
            ->and($voided->void_reason)->toBe('Recorded against the wrong tenant')
            ->and($voided->void_single_approver)->toBeFalse()
            ->and(storedPayment($this->acme, $this->payment)->status)->toBe(PaymentStatus::Confirmed);
        Event::assertDispatched(ReceiptVoided::class, fn (ReceiptVoided $event): bool => $event->receiptId === $this->receipt->id && $event->requestedByUserId === $this->agent->id && $event->approvedByUserId === $this->admin->id && ! $event->singleApprover);
        Queue::assertPushedOn('pdf', RenderReceipt::class, fn (RenderReceipt $job): bool => $job->receiptId === $this->receipt->id && $job->void);

        $audit = AuditEntry::query()->where('event', 'receipt.voided')->sole();
        expect($audit->causer_id)->toBe($this->admin->id)
            ->and($audit->properties['requested_by_user_id'])->toBe($this->agent->id)
            ->and($audit->properties['approved_by_user_id'])->toBe($this->admin->id)
            ->and($audit->properties['single_approver'])->toBeFalse()
            ->and($audit->properties['reason'])->toBe('Recorded against the wrong tenant');

        // Voided once: nothing more to ask for or approve.
        expect(receiptAbilitiesOf($this->owner, $this->acme, $voided))->toMatchArray(['voidRequest' => false, 'voidApprove' => false, 'voidAlone' => false, 'voidDismiss' => false])
            ->and(fn () => requestReceiptVoid($this->acme, $this->owner, $voided))->toThrow(AuthorizationException::class);
    });

    it('never lets the member who asked approve their own request, whatever their role', function () {
        $requested = requestReceiptVoid($this->acme, $this->admin, $this->receipt);

        expect(receiptAbilitiesOf($this->admin, $this->acme, $requested))->toMatchArray(['voidApprove' => false, 'voidAlone' => false, 'voidDismiss' => true])
            ->and(fn () => approveReceiptVoid($this->acme, $this->admin, $requested))->toThrow(AuthorizationException::class, 'another owner or administrator must approve it');

        // Even past the policy, the service refuses under the receipt's lock.
        Gate::before(fn () => true);
        expect(fn () => approveReceiptVoid($this->acme, $this->admin, $requested))->toThrow(ReceiptVoidRefused::class, 'You asked for this void')
            ->and(storedReceipt($this->acme, $this->receipt)->voided_at)->toBeNull();

        // The owner is a second person: approved.
        expect(approveReceiptVoid($this->acme, $this->owner, $requested)->void_approved_by_user_id)->toBe($this->owner->id);
    });

    it('lets the only owner of an organization with no administrator void alone, flagged single approver and audited', function () {
        $solo = Organization::factory()->create(['slug' => 'solo-homes', 'name' => 'Solo Homes']);
        $soloOwner = joinAs(User::factory()->create(['name' => 'Sola Owner']), $solo);
        $soloAgent = joinAs(User::factory()->create(['name' => 'Sola Agent']), $solo, OrganizationRole::Agent);
        $tenant = tenantIn($solo, ['first_name' => 'Solo', 'last_name' => 'Tenant', 'phone' => '+2348030000011', 'email' => 'solo@example.test']);
        $first = receiptOf($solo, confirmedPayment($solo, $soloOwner, $tenant, '10,000'));
        $second = receiptOf($solo, confirmedPayment($solo, $soloOwner, $tenant, '20,000'));

        expect(receiptAbilitiesOf($soloOwner, $solo, $first))->toMatchArray(['voidAlone' => true])
            ->and(receiptAbilitiesOf($soloAgent, $solo, $first))->toMatchArray(['voidAlone' => false, 'voidRequest' => true])
            ->and(fn () => voidReceiptAlone($solo, $soloAgent, $first))->toThrow(AuthorizationException::class)
            ->and(fn () => voidReceiptAlone($solo, $soloOwner, $first, ''))->toThrow(ValidationException::class, 'Say why the receipt should be voided.');

        $voided = voidReceiptAlone($solo, $soloOwner, $first, 'Issued twice for the same transfer');

        expect($voided->status())->toBe(ReceiptStatus::Voided)
            ->and($voided->void_single_approver)->toBeTrue()
            ->and($voided->voided_by_user_id)->toBe($soloOwner->id)
            ->and($voided->void_approved_by_user_id)->toBe($soloOwner->id)
            ->and($voided->void_reason)->toBe('Issued twice for the same transfer');
        $audit = AuditEntry::query()->where('event', 'receipt.voided')->where('subject_id', $first->id)->sole();
        expect($audit->properties['single_approver'])->toBeTrue()
            ->and($audit->description)->toBe('Receipt voided by the single approver');

        // An agent's request there is approved by the owner as a second person, not flagged.
        requestReceiptVoid($solo, $soloAgent, $second);
        expect(receiptAbilitiesOf($soloOwner, $solo, storedReceipt($solo, $second)))->toMatchArray(['voidApprove' => true, 'voidAlone' => false])
            ->and(approveReceiptVoid($solo, $soloOwner, $second)->void_single_approver)->toBeFalse();
    });

    it('refuses the single-approver path wherever a second owner or administrator exists', function () {
        expect(receiptAbilitiesOf($this->owner, $this->acme, $this->receipt))->toMatchArray(['voidAlone' => false, 'voidRequest' => true])
            ->and(receiptAbilitiesOf($this->admin, $this->acme, $this->receipt))->toMatchArray(['voidAlone' => false])
            ->and(fn () => voidReceiptAlone($this->acme, $this->owner, $this->receipt))->toThrow(AuthorizationException::class);

        Gate::before(fn () => true);
        expect(fn () => voidReceiptAlone($this->acme, $this->owner, $this->receipt))->toThrow(ReceiptVoidRefused::class, 'Another owner or administrator can approve voids here')
            ->and(storedReceipt($this->acme, $this->receipt)->voided_at)->toBeNull();
    });

    it('keeps the receipt valid when the request is withdrawn or declined, and refuses a second request while one waits', function () {
        $requested = requestReceiptVoid($this->acme, $this->agent, $this->receipt);
        expect(receiptAbilitiesOf($this->admin, $this->acme, $requested))->toMatchArray(['voidRequest' => false])
            ->and(fn () => requestReceiptVoid($this->acme, $this->admin, $requested))->toThrow(AuthorizationException::class);

        expect(dismissReceiptVoid($this->acme, $this->agent, $requested)->status())->toBe(ReceiptStatus::Valid);

        requestReceiptVoid($this->acme, $this->agent, $this->receipt, 'Second thoughts');
        expect(dismissReceiptVoid($this->acme, $this->owner, $this->receipt)->status())->toBe(ReceiptStatus::Valid)
            ->and(AuditEntry::query()->where('event', 'receipt.void_dismissed')->pluck('description')->all())->toBe(['Receipt void request withdrawn', 'Receipt void request declined'])
            ->and(fn () => approveReceiptVoid($this->acme, $this->owner, $this->receipt))->toThrow(AuthorizationException::class);
    });

    it('voids from the receipt page: ask, then a second member approves', function () {
        $agentPage = receiptsSnapshot($this->agent, "/{$this->receipt->id}");
        receiptsUpdate($this->agent, $agentPage, ['reason' => 'Wrong tenant'], [['requestVoid']])->assertOk()->assertSee('Your request is sent.');
        receiptsPage($this->agent, "/{$this->receipt->id}")->assertOk()->assertSee('data-test="void-request"', false)->assertDontSee('data-test="approve-void"', false)->assertSee('Withdraw request');

        $adminPage = receiptsSnapshot($this->admin, "/{$this->receipt->id}");
        receiptsPage($this->admin, "/{$this->receipt->id}")->assertSee('data-test="approve-void"', false)->assertSee('Decline');
        receiptsUpdate($this->admin, $adminPage, [], [['approveVoid']])->assertOk()->assertSee('The receipt is voided.');

        receiptsPage($this->owner, "/{$this->receipt->id}")->assertOk()->assertSee('data-test="receipt-voided"', false)->assertSee('Asked by Bola Agent, approved by Ngozi Eze.')
            ->assertDontSee('data-test="void-request-form"', false);
        expect(storedReceipt($this->acme, $this->receipt)->status())->toBe(ReceiptStatus::Voided);
    });
});

it('sub: the verify page shows "voided" after a void, and nothing about the tenant or the reason', function () {
    verifyReceiptPage($this->receipt)->assertOk()->assertSee('data-status="valid"', false)
        ->assertSee('Official receipt')->assertSee($this->receipt->number)->assertSee('Adeyemi Realty Ltd')->assertSee('₦100,000')
        ->assertDontSee('Kemi')->assertDontSee('+2348030000001');

    requestReceiptVoid($this->acme, $this->agent, $this->receipt, 'Recorded against the wrong tenant');
    verifyReceiptPage($this->receipt)->assertOk()->assertSee('data-status="valid"', false);

    approveReceiptVoid($this->acme, $this->owner, $this->receipt);
    verifyReceiptPage($this->receipt)->assertOk()->assertSee('data-status="voided"', false)->assertSee('Voided on 25/12/2026')
        ->assertDontSee('wrong tenant')->assertDontSee('Kemi');
});

it('acceptance: keeps the original PDF after a void and prints the VOID copy as a second kept file', function () {
    requirePdfTools();
    renderReceiptPdf($this->acme, $this->receipt);
    $original = inOrganization($this->acme, fn () => app(Receipts::class)->kept($this->receipt));
    $originalBytes = (string) file_get_contents($original->getPath());

    requestReceiptVoid($this->acme, $this->agent, $this->receipt, 'Recorded against the wrong tenant');
    approveReceiptVoid($this->acme, $this->owner, $this->receipt);
    $voided = storedReceipt($this->acme, $this->receipt);
    renderReceiptPdf($this->acme, $voided, void: true);
    renderReceiptPdf($this->acme, $voided, void: true);
    renderReceiptPdf($this->acme, $voided);

    $media = inOrganization($this->acme, fn () => Media::query()->where('model_type', (new Receipt)->getMorphClass())->where('model_id', $this->receipt->id)->orderBy('id')->get());
    $copy = $media->last();

    expect($media)->toHaveCount(2)
        ->and($media->map(fn (Media $file) => $file->getCustomProperty('document_key'))->all())->toBe(['receipt', 'receipt-void'])
        ->and($media->first()->id)->toBe($original->id)
        ->and((string) file_get_contents($original->getPath()))->toBe($originalBytes)
        ->and(storedReceipt($this->acme, $this->receipt)->document_media_id)->toBe($original->id)
        ->and(storedReceipt($this->acme, $this->receipt)->void_document_media_id)->toBe($copy->id)
        ->and($copy->file_name)->not->toBe($original->file_name);

    $copyText = PdfVisualDiff::text((string) file_get_contents($copy->getPath()));
    expect($copyText)->toContain('VOID', 'Recorded against the wrong tenant', $this->receipt->number)
        ->and(PdfVisualDiff::text($originalBytes))->not->toContain('Recorded against the wrong tenant');

    // Both files are evidence; the downloads serve each kept file.
    foreach ($media as $file) {
        expect(fn () => inOrganization($this->acme, fn () => $file->delete()))->toThrow(EvidenceFileProtected::class);
    }

    $download = fn (string $path) => receiptsPage($this->owner, "/{$this->receipt->id}{$path}")->assertRedirect();
    expect($download('/pdf')->headers->get('Location'))->toContain($original->uuid)
        ->and($download('/void.pdf')->headers->get('Location'))->toContain($copy->uuid);
    receiptsPage($this->owner, "/{$this->receipt->id}/document")->assertOk()->assertSee('Recorded against the wrong tenant')->assertSee('VOID');
});

it('enforces the role rules in ReceiptPolicy', function (OrganizationRole $role, array $expected) {
    $user = joinAs(User::factory()->create(), $this->acme, $role);

    expect(receiptAbilitiesOf($user, $this->acme, $this->receipt))->toBe($expected);
})->with([
    'org-owner' => [OrganizationRole::Owner, ['viewAny' => true, 'view' => true, 'export' => true, 'viewDocument' => true, 'voidRequest' => true, 'voidApprove' => false, 'voidAlone' => false, 'voidDismiss' => false, 'update' => false, 'delete' => false]],
    'org-admin' => [OrganizationRole::Admin, ['viewAny' => true, 'view' => true, 'export' => true, 'viewDocument' => true, 'voidRequest' => true, 'voidApprove' => false, 'voidAlone' => false, 'voidDismiss' => false, 'update' => false, 'delete' => false]],
    'agent' => [OrganizationRole::Agent, ['viewAny' => true, 'view' => true, 'export' => true, 'viewDocument' => true, 'voidRequest' => true, 'voidApprove' => false, 'voidAlone' => false, 'voidDismiss' => false, 'update' => false, 'delete' => false]],
    'property-owner' => [OrganizationRole::PropertyOwner, ['viewAny' => false, 'view' => false, 'export' => false, 'viewDocument' => false, 'voidRequest' => false, 'voidApprove' => false, 'voidAlone' => false, 'voidDismiss' => false, 'update' => false, 'delete' => false]],
    'tenant' => [OrganizationRole::Tenant, ['viewAny' => false, 'view' => false, 'export' => false, 'viewDocument' => false, 'voidRequest' => false, 'voidApprove' => false, 'voidAlone' => false, 'voidDismiss' => false, 'update' => false, 'delete' => false]],
]);

it('sub: impersonating staff see and export receipts, but never ask for, approve or make a void, nor open the printed receipt, in either mode', function (ImpersonationMode $mode) {
    seedStaffRoles();
    $requested = requestReceiptVoid($this->acme, $this->agent, $this->receipt);
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    $handOff = $mode === ImpersonationMode::Write
        ? startSupport($staff, $this->owner, $this->acme, ImpersonationMode::Write)
        : startSupport($staff, $this->owner, $this->acme);
    enterSupport($handOff)->assertRedirect('/o/acme-homes');

    $list = asSupport()->get('/o/acme-homes/receipts')->assertOk()->assertSee($this->receipt->number)->assertSee('data-test="export-receipts-csv"', false);
    $show = LivewireHttp::snapshot(asSupport()->get("/o/acme-homes/receipts/{$this->receipt->id}")->assertOk()
        ->assertDontSee('data-test="approve-void"', false)->assertDontSee('data-test="dismiss-void"', false)->assertDontSee('data-test="receipt-pdf"', false));

    foreach (['/document', '/pdf'] as $path) {
        freshRequest();
        asSupport()->get("/o/acme-homes/receipts/{$this->receipt->id}{$path}")->assertForbidden();
    }

    foreach ([['approveVoid'], ['dismissVoid'], ['voidAlone'], ['requestVoid']] as $call) {
        freshRequest();
        LivewireHttp::update(test(), $show, ['reason' => 'Staff void'], [$call])->assertForbidden();
    }

    freshRequest();
    $pressed = LivewireHttp::update(test(), LivewireHttp::snapshot($list), [], [['export', ['receipts', 'csv']]])->assertOk();

    expect(LivewireHttp::redirect($pressed))->toContain('/exports/')
        ->and(storedReceipt($this->acme, $requested)->status())->toBe(ReceiptStatus::VoidRequested)
        ->and(AuditEntry::query()->whereIn('event', ['receipt.voided', 'receipt.void_dismissed'])->exists())->toBeFalse();

    if ($mode === ImpersonationMode::Write) {
        expect(AuditEntry::query()->where('event', 'impersonation.blocked')->where('subject_id', $handOff->session->id)->exists())->toBeTrue();
    }
})->with([
    'read-only' => [ImpersonationMode::ReadOnly],
    'write' => [ImpersonationMode::Write],
]);

it('voids nothing in a suspended organization, while its receipts stay readable and printable', function () {
    $requested = requestReceiptVoid($this->acme, $this->agent, $this->receipt);
    $page = receiptsSnapshot($this->owner, "/{$this->receipt->id}");
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    receiptsPage($this->owner)->assertOk()->assertSee($this->receipt->number)->assertSee('data-test="export-receipts-csv"', false);
    receiptsPage($this->owner, "/{$this->receipt->id}")->assertOk()->assertDontSee('data-test="approve-void"', false)->assertSee('data-test="receipt-pdf"', false);
    receiptsPage($this->owner, "/{$this->receipt->id}/document")->assertOk()->assertSee($this->receipt->number);
    receiptsUpdate($this->owner, $page, [], [['approveVoid']])->assertForbidden();
    receiptsUpdate($this->owner, $page, [], [['dismissVoid']])->assertForbidden();

    expect(fn () => approveReceiptVoid($this->acme, $this->owner, $requested))->toThrow(AuthorizationException::class)
        ->and(storedReceipt($this->acme, $this->receipt)->status())->toBe(ReceiptStatus::VoidRequested);
});

it('answers 404 for another organization\'s receipt guessed by ID, on pages, prints, lists and actions', function () {
    foreach (['', '/document', '/pdf', '/void.pdf'] as $path) {
        receiptsPage($this->owner, "/{$this->foreign->id}{$path}")->assertNotFound();
    }
    receiptsPage($this->owner, '/999999')->assertNotFound();
    receiptsPage($this->owner, '/abc')->assertNotFound();

    foreach (['', '?q=Foreign', '?q='.$this->foreign->number, '?tenant='.$this->bisi->id, '?status=valid'] as $query) {
        receiptsPage($this->owner, $query)->assertOk()->assertDontSee('Foreign Tenant')->assertDontSee('data-receipt="'.$this->foreign->id.'"', false);
    }

    // A tampered snapshot (production: debug off answers 419).
    config(['app.debug' => false]);
    $data = json_decode(receiptsSnapshot($this->owner, "/{$this->receipt->id}"), true);
    $data['data']['receiptId'] = $this->foreign->id;
    receiptsUpdate($this->owner, (string) json_encode($data), ['reason' => 'Hijack'], [['requestVoid']])->assertStatus(419);

    // The writer refuses it too (the organization scope hides it; the policy says no).
    expect(fn () => inOrganization($this->acme, fn () => app(ReceiptVoids::class)->request($this->owner, $this->foreign, 'Hijack')))->toThrow(AuthorizationException::class)
        ->and(inOrganization($this->acme, fn () => Receipt::query()->find($this->foreign->id)))->toBeNull()
        ->and(storedReceipt($this->other, $this->foreign)->status())->toBe(ReceiptStatus::Valid);

    // A member of both organizations sees each one's own receipts there.
    joinAs($this->owner, $this->other);
    expect(listedReceipts(receiptsPage($this->owner)))->toBe([$this->receipt->id])
        ->and(listedReceipts(receiptsPage($this->owner, '', 'other-realty')))->toBe([$this->foreign->id]);
});

it('shows an agent assigned to buildings only their tenants\' receipts', function () {
    $oak = buildingIn($this->acme, ['name' => 'Oak Lodge', 'slug' => 'oak-lodge']);
    $oakTenant = tenantIn($this->acme, ['first_name' => 'Oak', 'last_name' => 'Tenant', 'phone' => '+2348030000003', 'email' => 'oak@example.test']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $oak, ['unit_label' => 'Oak 1']), $oakTenant, ['billingCycle' => 'monthly', 'rent' => '100,000', 'deposit' => '0']);
    runBillGeneration();
    $oakReceipt = receiptOf($this->acme, confirmedPayment($this->acme, $this->owner, $oakTenant, '100,000'));
    assignIn($this->acme, $this->agent, $this->palm);

    expect(listedReceipts(receiptsPage($this->agent)))->toBe([$this->receipt->id])
        ->and(listedReceipts(receiptsPage($this->agent, '?tenant='.$oakTenant->id)))->toBe([]);
    foreach (['', '/document', '/pdf'] as $path) {
        receiptsPage($this->agent, "/{$oakReceipt->id}{$path}")->assertNotFound();
    }

    expect(fn () => requestReceiptVoid($this->acme, $this->agent, $oakReceipt))->toThrow(fn (AuthorizationException $e) => expect($e->status())->toBe(404))
        ->and(exportColumn(exportRows($this->agent, '/receipts', 'receipts', 'csv'), 'Receipt'))->toBe([$this->receipt->number]);

    // An agent with no assignment sees every receipt.
    expect(listedReceipts(receiptsPage(joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent))))->toBe([$oakReceipt->id, $this->receipt->id]);
});
