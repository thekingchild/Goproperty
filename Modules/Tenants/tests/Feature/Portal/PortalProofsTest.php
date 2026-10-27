<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Media\Media;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Billing\Bills\BillService;
use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Enums\ProofSubmitter;
use Modules\Payments\Payments\PaymentService;
use Modules\Tenants\Livewire\Portal\PortalProofForm;
use Modules\Tenants\Portal\PortalPayments;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/portal-world.php';
require_once __DIR__.'/../../../../Payments/tests/Feature/proof-helpers.php';

/*
 * P03-11b: proofs of payment from the tenant portal (ONBOARDING_COMPLIANCE.md
 * § 4.1 "Proof upload stays as the tenant-side fallback", § 3 "the tenant
 * portal stays read-only and still accepts payment proofs"). The page
 * /portal/bills/{bill}/proof sends the proof through Payments'
 * Contracts\PaymentProofs (P03-09): a bank transfer waiting for the
 * agency's confirmation with the file kept as evidence. Only for the
 * tenant's own bill (404 otherwise), also while the agency is suspended,
 * never while GoProperty staff impersonate; the rate limits show as plain
 * messages on the form.
 */

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('media');
    portalWorld();
});

afterEach(fn () => billsFreshRequest());

/** The proof page of the bill as a Livewire test, as the user (uploads need Livewire's test client). */
function portalProofForm(User $user, int $billId): mixed
{
    billsFreshRequest();
    test()->actingAs($user);

    return Livewire::test(PortalProofForm::class, ['bill' => $billId]);
}

/** Fills the proof page and sends it. */
function sendPortalProof(User $user, int $billId, array $changes = [], ?UploadedFile $file = null): mixed
{
    $page = portalProofForm($user, $billId);
    chooseProofFile($page, $file ?? proofFile());

    foreach ($changes + ['amount' => '1,500,000', 'senderName' => 'Kemi Adebayo', 'bank' => 'Guaranty Trust Bank (GTBank)', 'reference' => 'GP rent Flat 3B'] as $field => $value) {
        $page->set($field, $value);
    }

    return $page->call('save');
}

describe('acceptance: a tenant can upload a proof for their own bill', function () {
    it('takes the tenant\'s proof from their bill\'s page: a payment waiting for the agency, with the file kept', function () {
        portalPage($this->kemi, "/bills/{$this->bill->id}")->assertOk()
            ->assertSee('Paid already?')
            ->assertSee(route('portal.bills.proof', $this->bill->id), false);

        portalPage($this->kemi, "/bills/{$this->bill->id}/proof")->assertOk()
            ->assertSee('Send a proof of payment')
            ->assertSee($this->bill->number)
            ->assertSee('Guaranty Trust Bank', false)
            ->assertSee('data-test="portal-proof-form"', false);

        // What she paid, when and from whose account start as the amount owed, today and her name.
        portalProofForm($this->kemi, $this->bill->id)
            ->assertSet('amount', '1,500,000')
            ->assertSet('paidOn', '2026-12-25')
            ->assertSet('senderName', 'Kemi Adebayo');

        sendPortalProof($this->kemi, $this->bill->id, ['amount' => '1,500,000', 'note' => 'Paid from my salary account'], proofFile('my-transfer.png', 7))
            ->assertHasNoErrors()
            ->assertRedirect(route('portal.bills.show', $this->bill->id));

        $proof = proofsOf($this->acme)->sole();
        $payment = proofPayment($this->acme, $proof);

        expect($proof->tenant_profile_id)->toBe($this->kemiAtAcme->id)
            ->and($proof->bill_id)->toBe($this->bill->id)
            ->and($proof->amount_kobo->kobo)->toBe(150_000_000)
            ->and($proof->sender_name)->toBe('Kemi Adebayo')
            ->and($proof->bank_name)->toBe('Guaranty Trust Bank (GTBank)')
            ->and($proof->reference)->toBe('GP rent Flat 3B')
            ->and($proof->note)->toBe('Paid from my salary account')
            ->and($proof->submitted_by)->toBe(ProofSubmitter::Tenant)
            ->and($proof->submitted_by_user_id)->toBe($this->kemi->id)
            ->and($proof->file_sha256)->toBe(hash('sha256', proofImageBytes(7)))
            ->and($payment->status)->toBe(PaymentStatus::PendingConfirmation)
            ->and($payment->source)->toBe(PaymentSource::ProofUpload)
            ->and($payment->bill_id)->toBe($this->bill->id)
            ->and($payment->recorded_by_user_id)->toBe($this->kemi->id);

        $media = inOrganization($this->acme, fn () => Media::query()->findOrFail($proof->media_id));
        Storage::disk('media')->assertExists($media->getPathRelativeToRoot());
        expect(Storage::disk('media')->get($media->getPathRelativeToRoot()))->toBe(proofImageBytes(7));

        // Her bill's page says so and lists the proof, waiting; the bill still asks for the money.
        $page = portalPage($this->kemi, "/bills/{$this->bill->id}")->assertOk()
            ->assertSee('Proofs sent for this bill')
            ->assertSee('Waiting for review')
            ->assertSee('₦1,500,000')
            ->assertSee('Sent by you on 25/12/2026')
            ->assertSee('Amount to pay');
        expect(portalIds($page, 'proof'))->toBe([$proof->id]);

        // The agency sees it in its confirmation queue.
        billsFreshRequest();
        $this->actingAs($this->owner)->get("/o/acme-homes/payments/{$payment->id}")->assertOk()->assertSee('Proof of payment');
    });

    it('shows where each proof stands: accepted with its receipt, rejected with the agency\'s reason', function () {
        sendPortalProof($this->kemi, $this->bill->id, ['amount' => '1,000,000'], proofFile('first.png', 1))->assertHasNoErrors();
        sendPortalProof($this->kemi, $this->bill->id, ['amount' => '500,000'], proofFile('second.png', 2))->assertHasNoErrors();
        [$first, $second] = proofsOf($this->acme)->all();

        confirmPayment($this->acme, $this->owner, proofPayment($this->acme, $first));
        inOrganization($this->acme, fn () => app(PaymentService::class)->reject($this->owner, proofPayment($this->acme, $second), 'No such credit on our statement'));
        $receipt = receiptOf($this->acme, $first->payment_id);

        portalPage($this->kemi, '/payments')->assertOk()
            ->assertSeeInOrder(['Receipts', $receipt->number, 'Proofs of payment'])
            ->assertSee('Accepted')
            ->assertSee('Rejected')
            ->assertSee('Reason: No such credit on our statement')
            ->assertSee(route('portal.receipts.show', $receipt->id), false)
            ->assertSee('For bill '.$this->bill->number);
    });
});

describe('sub: also while the agency is suspended', function () {
    it('still takes the tenant\'s proof while the agency is suspended or closed, and its members only read', function (OrganizationStatus $status) {
        $this->acme->forceFill(['status' => $status])->save();

        portalPage($this->kemi, "/bills/{$this->bill->id}")->assertOk()
            ->assertSee('Read-only for now')
            ->assertSee(route('portal.bills.proof', $this->bill->id), false);
        portalPage($this->kemi, "/bills/{$this->bill->id}/proof")->assertOk()
            ->assertSee('you can still send your proof', false)
            ->assertSee('data-test="portal-proof-form"', false);

        sendPortalProof($this->kemi, $this->bill->id)->assertHasNoErrors()->assertRedirect(route('portal.bills.show', $this->bill->id));

        expect(proofsOf($this->acme))->toHaveCount(1)
            ->and(proofPayment($this->acme, proofsOf($this->acme)->sole())->status)->toBe(PaymentStatus::PendingConfirmation);

        // The agency's members only read: they cannot upload one themselves.
        expect(fn () => submitMemberProof($this->acme, $this->owner, $this->kemiAtAcme, $this->bill))->toThrow(AuthorizationException::class);
        billsFreshRequest();
        $this->actingAs($this->owner)->get('/o/acme-homes/payments/proofs/create')->assertForbidden();
    })->with([
        'suspended' => [OrganizationStatus::Suspended],
        'cancelled' => [OrganizationStatus::Cancelled],
    ]);
});

describe('sub: never for another tenant\'s or organization\'s bill', function () {
    it('answers 404 for the proof page of a bill that is not the tenant\'s own', function (string $which) {
        $billId = match ($which) {
            'neighbour' => $this->neighbourBill->id,
            'other organization' => $this->foreignBill->id,
            default => 999999,
        };

        portalPage($this->kemi, "/bills/{$billId}/proof")->assertNotFound()->assertDontSee('Tunde')->assertDontSee('Foreign');

        // Nor through the portal's own writer, nor as a member who rents nothing.
        expect(app(PortalPayments::class)->submitProof($this->kemi, $billId, proofInput(), proofFile()))->toBeNull()
            ->and(app(PortalPayments::class)->proofPage($this->owner, $this->bill->id))->toBeNull()
            ->and(proofsOf($this->acme))->toHaveCount(0)
            ->and(proofsOf($this->other))->toHaveCount(0);
    })->with(['neighbour', 'other organization', 'missing']);

    it('refuses a proof page whose locked bill id is changed to someone else\'s bill', function () {
        $snapshot = LivewireHttp::snapshot(portalPage($this->kemi, "/bills/{$this->bill->id}/proof")->assertOk());
        $forged = json_decode($snapshot, true);
        $forged['data']['billId'] = $this->neighbourBill->id;

        billsFreshRequest();
        config(['app.debug' => false]);
        $response = LivewireHttp::update(test()->actingAs($this->kemi), (string) json_encode($forged), [], [['save', []]]);

        $response->assertStatus(419);
        expect((string) $response->getContent())->not->toContain($this->neighbourBill->number)
            ->and(proofsOf($this->acme))->toHaveCount(0);

        billsFreshRequest();
        $this->actingAs($this->kemi);
        $refused = LivewireHttp::update(test(), $snapshot, ['billId' => $this->neighbourBill->id], [['save', []]]);
        expect($refused->status())->not->toBe(200)
            ->and(proofsOf($this->acme))->toHaveCount(0);
    });

    it('takes no proof for a bill that is paid or cancelled, and says why', function () {
        confirmedPayment($this->acme, $this->owner, $this->kemiAtAcme, '1,500,000');

        portalPage($this->kemi, "/bills/{$this->bill->id}")->assertOk()->assertSee('This bill is paid')->assertDontSee(route('portal.bills.proof', $this->bill->id), false);
        portalPage($this->kemi, "/bills/{$this->bill->id}/proof")->assertOk()->assertSee('This bill is paid')->assertDontSee('data-test="portal-proof-form"', false);
        sendPortalProof($this->kemi, $this->bill->id)->assertHasErrors(['proof']);

        $bello = kemiAlsoAtBello();
        $belloBill = portalBillOf($this->other, $bello);
        inOrganization($this->other, fn () => app(BillService::class)->void($this->otherOwner, storedBill($this->other, $belloBill), 'Issued to the wrong tenant'));
        portalPage($this->kemi, "/bills/{$belloBill->id}/proof")->assertOk()->assertSee('This bill is cancelled: do not pay it');
        sendPortalProof($this->kemi, $belloBill->id)->assertHasErrors(['proof']);

        expect(proofsOf($this->acme))->toHaveCount(0)->and(proofsOf($this->other))->toHaveCount(0);
    });
});

describe('sub: rate limits surface as friendly errors', function () {
    it('says plainly when a bill has had too many proofs this hour, and records nothing more', function () {
        foreach (range(1, 5) as $seed) {
            submitTenantProof($this->acme, $this->kemi, $this->bill, proofFile("proof-{$seed}.png", $seed));
        }

        $page = sendPortalProof($this->kemi, $this->bill->id, [], proofFile('sixth.png', 6))
            ->assertHasErrors(['proof'])
            ->assertNoRedirect()
            ->assertSee('Too many proofs were sent for this bill. Try again in 60 minutes.')
            ->assertSee('data-test="portal-proof-warning"', false);

        expect(proofsOf($this->acme))->toHaveCount(5)
            ->and($page->get('amount'))->toBe('1,500,000');

        // An hour later it is taken again.
        $this->travel(61)->minutes();
        sendPortalProof($this->kemi, $this->bill->id, [], proofFile('later.png', 8))->assertHasNoErrors();
        expect(proofsOf($this->acme))->toHaveCount(6);
    });

    it('slows down someone retrying again and again, with a plain message', function () {
        $page = portalProofForm($this->kemi, $this->bill->id);

        foreach (range(1, PortalProofForm::ATTEMPTS_PER_MINUTE) as $try) {
            $page->call('save')->assertHasErrors(['file']);
        }

        $page->call('save')->assertHasErrors(['proof'])->assertSee('Too many tries. Try again in');
        expect(proofsOf($this->acme))->toHaveCount(0);
    });

    it('shows a refused file as a plain message under the field', function () {
        sendPortalProof($this->kemi, $this->bill->id, [], UploadedFile::fake()->createWithContent('transfer.png', '<?php echo "hi";'))
            ->assertHasErrors(['file'])
            ->assertSee('data-test="proof-file-error"', false);

        expect(proofsOf($this->acme))->toHaveCount(0);
    });
});

describe('sub: impersonation', function () {
    it('never sends a proof while GoProperty staff impersonate the tenant, in either mode', function (ImpersonationMode $mode) {
        joinAs($this->kemi, $this->acme, OrganizationRole::Agent);
        enterSupport(startSupport(staffMember(), $this->kemi, $this->acme, $mode))->assertRedirect();

        asSupport()->get("/portal/bills/{$this->bill->id}")->assertOk()
            ->assertSee('A GoProperty support session cannot send a proof for you.')
            ->assertDontSee(route('portal.bills.proof', $this->bill->id), false);
        $page = asSupport()->get("/portal/bills/{$this->bill->id}/proof")->assertOk()
            ->assertSee('A GoProperty support session cannot send a proof for you.')
            ->assertDontSee('data-test="portal-proof-form"', false);

        freshRequest();
        LivewireHttp::update(test(), LivewireHttp::snapshot($page), [], [['save', []]])->assertForbidden();

        expect(proofsOf($this->acme))->toHaveCount(0);
    })->with([
        'read-only' => [ImpersonationMode::ReadOnly],
        'write' => [ImpersonationMode::Write],
    ]);
});
