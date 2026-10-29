<?php

declare(strict_types=1);

use App\Media\Media;
use App\Platform\Impersonation\ImpersonationMode;
use Modules\Leasing\Leases\LeaseService;
use Modules\Leasing\Models\Lease;
use Modules\Payments\Models\Receipt;
use Modules\Properties\Livewire\OwnerPortal\OwnerReceiptShow;
use Modules\Properties\Livewire\OwnerPortal\OwnerTenantShow;
use Modules\Properties\Livewire\OwnerPortal\OwnerUnitShow;
use Modules\Tenants\Models\TenantProfile;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/owner-world.php';
require_once __DIR__.'/../../../../Payments/tests/Feature/proof-helpers.php';
require_once __DIR__.'/../../../../../tests/Support/organization-settings.php';

/*
 * P03-16 acceptance (product owner 2026-10-29, docs/plans/owner-visibility-2026-10-29.md):
 * in /owner, read-only, the owner sees the tenants of her buildings in full
 * (ID number masked to its last four characters), each unit's lease
 * history, the issued lease contracts, the bills and receipts as on-screen
 * pages with the payment history (no bank account, no payment reference,
 * no tenant proofs) and each lease's deposit ledger. On the owner portal's
 * world (Ngozi Eze owns Palm Court at Adeyemi Realty and Lagoon View at
 * Bello Homes; Femi Bakare's Cedar House and Bello's Harbour Point are
 * other owners'), with fixed names, numbers and references:
 *
 * - Kemi Adebayo (Flat 3B) in full: address, occupation, next of kin,
 *   verified, NIN 12345678901; she paid her bill by bank transfer
 *   (reference TRF-KEMI-0001), receipt issued; a proof of payment she
 *   sent is waiting.
 * - Flat 4C: Bola Past's lease, ended early on 26/12/2026 ("Relocated to
 *   Abuja"), deposit ₦100,000 refunded and ₦50,000 kept; then a draft
 *   cancelled (Cancelled Draft) and a draft waiting (Draft Person).
 * - Both organizations keep a bank account; Tunde (Cedar House) and the
 *   Harbour Point tenant paid too.
 */

const KEMI_NIN = '12345678901';

beforeEach(function () {
    ownerWorld();
    holdReceiptPdfs();

    inOrganization($this->acme, fn () => TenantProfile::query()->findOrFail($this->kemi->id)->forceFill([
        'address' => '5 Admiralty Road, Lekki Phase 1',
        'occupation' => 'Software engineer',
        'emergency_contact' => ['name' => 'Tola Adebayo', 'phone' => '+2348039990000', 'relationship' => 'Sister'],
        'kyc_status' => 'verified',
        'id_type' => 'nin',
        'id_value' => KEMI_NIN,
    ])->save());

    $this->acmeAccount = addBankAccount($this->acme, $this->owner, '0123456789', 'Adeyemi Realty Ltd');
    $this->belloAccount = addBankAccount($this->other, $this->otherOwner, '0987654321', 'Bello Homes Ltd');

    $this->kemiPayment = confirmedPayment($this->acme, $this->owner, $this->kemi, '1,500,000', ['reference' => 'TRF-KEMI-0001']);
    $this->receipt = receiptOf($this->acme, $this->kemiPayment);
    $this->pendingProof = submitMemberProof($this->acme, $this->owner, $this->kemi, null, proofFile('kemi-transfer.png', 7), ['reference' => 'TRF-KEMI-PROOF-9']);

    $this->tunde = inOrganization($this->acme, fn (): TenantProfile => TenantProfile::query()->where('first_name', 'Tunde')->sole());
    $this->cedarReceipt = receiptOf($this->acme, confirmedPayment($this->acme, $this->owner, $this->tunde, '1,800,000', ['reference' => 'TRF-TUNDE-0002']));
    $this->foreign = inOrganization($this->other, fn (): TenantProfile => TenantProfile::query()->where('first_name', 'Foreign')->sole());
    $this->harbourReceipt = receiptOf($this->other, confirmedPayment($this->other, $this->otherOwner, $this->foreign, '2,400,000', ['reference' => 'TRF-FOREIGN-0003']));
    $this->musa = inOrganization($this->other, fn (): TenantProfile => TenantProfile::query()->where('first_name', 'Musa')->sole());

    // Flat 4C: a past lease, then a cancelled draft and a waiting one.
    $this->bola = tenantIn($this->acme, ['first_name' => 'Bola', 'last_name' => 'Past', 'phone' => '+2348030002222', 'email' => 'bola.past@example.com']);
    $this->pastLease = activeLease($this->acme, $this->owner, $this->flat4c, $this->bola, ['deposit' => '150,000']);
    $this->travelTo('2026-12-26 09:00:00');
    inOrganization($this->acme, fn () => app(LeaseService::class)->terminate($this->owner, storedLease($this->acme, $this->pastLease), 'Relocated to Abuja'));
    inOrganization($this->acme, fn () => app(LeaseService::class)->settleDeposit($this->owner, storedLease($this->acme, $this->pastLease), ['refunded' => '100,000', 'kept' => '50,000', 'note' => 'Broken door']));
    $this->cancelledDraft = draftLease($this->acme, $this->owner, $this->flat4c, tenantIn($this->acme, ['first_name' => 'Cancelled', 'last_name' => 'Draft', 'phone' => '+2348030003333']));
    inOrganization($this->acme, fn () => app(LeaseService::class)->terminate($this->owner, storedLease($this->acme, $this->cancelledDraft), 'Never signed'));
    $this->draftPerson = tenantIn($this->acme, ['first_name' => 'Draft', 'last_name' => 'Person', 'phone' => '+2348030001111', 'email' => 'draft.person@example.com']);
    $this->draft = draftLease($this->acme, $this->owner, $this->flat4c, $this->draftPerson);
});

afterEach(fn () => billsFreshRequest());

/** Every owner portal page of Ngozi's that this file adds to or changes, by name. */
function ngozisPages(): array
{
    $test = test();

    return [
        'home' => '',
        'building' => "/buildings/{$test->palm->id}",
        'unit 3B' => "/units/{$test->flat3b->id}",
        'unit 4C' => "/units/{$test->flat4c->id}",
        'tenant' => "/tenants/{$test->kemi->id}",
        'past tenant' => "/tenants/{$test->bola->id}",
        'lease' => "/leases/{$test->lease->id}",
        'past lease' => "/leases/{$test->pastLease->id}",
        'bill' => "/bills/{$test->bill->id}",
        'receipt' => "/receipts/{$test->receipt->id}",
        'Bello lease' => "/leases/{$test->belloLease->id}",
        'Bello tenant' => "/tenants/{$test->musa->id}",
    ];
}

describe('acceptance: an owner sees her tenants in full, read-only; the ID number never in full', function () {
    it('shows a tenant of her building with every detail, the ID number masked to its last four characters', function () {
        $page = ownerPortalPage($this->ngozi, "/tenants/{$this->kemi->id}")->assertOk()
            ->assertSee('Kemi Adebayo')->assertSee('Adeyemi Realty')
            ->assertSee('+2348031112222')->assertSee('kemi.adebayo@example.com')
            ->assertSee('5 Admiralty Road, Lekki Phase 1')->assertSee('Software engineer')
            ->assertSee('Tola Adebayo')->assertSee('(Sister)')->assertSee('+2348039990000')
            ->assertSee('Verified')->assertSee('NIN ···8901')
            ->assertSee('Flat 3B · Palm Court');

        expect(ownerPortalIds($page, 'lease'))->toBe([$this->lease->id])
            ->and((string) $page->getContent())->not->toContain(KEMI_NIN)->not->toContain('345678901')
            ->and(LivewireHttp::snapshot($page))->not->toContain(KEMI_NIN)->not->toContain('8901');
    });

    it('never puts the full ID number on any owner page or in a Livewire payload', function () {
        foreach (ngozisPages() as $name => $path) {
            $page = ownerPortalPage($this->ngozi, $path)->assertOk();
            $html = (string) $page->getContent();

            expect($html)->not->toContain(KEMI_NIN, $name);
            preg_match_all('/wire:snapshot="([^"]+)"/', $html, $snapshots);

            foreach ($snapshots[1] as $snapshot) {
                expect(html_entity_decode($snapshot, ENT_QUOTES | ENT_HTML5))->not->toContain(KEMI_NIN, $name);
            }
        }
    });

    it('shows the tenant\'s phone and email on the lease and bill pages, with a link to the tenant', function () {
        ownerPortalPage($this->ngozi, "/leases/{$this->lease->id}")->assertOk()
            ->assertSee('data-test="owner-lease-tenant-contacts"', false)->assertSee('+2348031112222')->assertSee('kemi.adebayo@example.com')
            ->assertSee(route('owner.tenants.show', $this->kemi->id), false);
        ownerPortalPage($this->ngozi, "/bills/{$this->bill->id}")->assertOk()
            ->assertSee('data-test="owner-bill-tenant-contacts"', false)->assertSee('+2348031112222')
            ->assertSee(route('owner.tenants.show', $this->kemi->id), false);
    });

    it('shows a past tenant too, and each agency\'s tenants under that agency only', function () {
        ownerPortalPage($this->ngozi, "/tenants/{$this->bola->id}")->assertOk()
            ->assertSee('Bola Past')->assertSee('+2348030002222')->assertSee('Terminated')->assertSee('Flat 4C · Palm Court')
            ->assertSee('Not verified')->assertSee('No ID on file');

        $musa = ownerPortalPage($this->ngozi, "/tenants/{$this->musa->id}")->assertOk()
            ->assertSee('Musa Danjuma')->assertSee('+2348036667777')->assertSee('Bello Homes')->assertSee('Suite 2 · Lagoon View')
            ->assertDontSee('Adeyemi Realty')->assertDontSee('Palm Court');
        expect(ownerPortalIds($musa, 'lease'))->toBe([$this->belloLease->id])
            ->and(ownerPortalIds($musa, 'organization'))->toBe([$this->other->id]);

        ownerPortalPage($this->ngozi, "/tenants/{$this->kemi->id}")->assertOk()->assertDontSee('Bello Homes')->assertDontSee('Lagoon View');
    });
});

describe('acceptance: another owner\'s tenant, lease, contract, bill, receipt or deposit is 404', function () {
    it('answers 404 for anything outside her own buildings, like a missing record', function (string $path) {
        $path = strtr($path, [
            '{tunde}' => (string) $this->tunde->id,
            '{foreign}' => (string) $this->foreign->id,
            '{draftPerson}' => (string) $this->draftPerson->id,
            '{cedarUnit}' => (string) $this->cedarLease->unit_id,
            '{harbourUnit}' => (string) $this->harbourLease->unit_id,
            '{cedarLease}' => (string) $this->cedarLease->id,
            '{harbourLease}' => (string) $this->harbourLease->id,
            '{cedarBill}' => (string) $this->cedarBill->id,
            '{cedarReceipt}' => (string) $this->cedarReceipt->id,
            '{harbourReceipt}' => (string) $this->harbourReceipt->id,
            '{draft}' => (string) $this->draft->id,
            '{cancelled}' => (string) $this->cancelledDraft->id,
        ]);

        ownerPortalPage($this->ngozi, $path)->assertNotFound()
            ->assertDontSee('Tunde Bakare')->assertDontSee('Foreign Tenant')->assertDontSee('Draft Person')->assertDontSee('Cedar House')->assertDontSee('Harbour Point');
    })->with([
        'another owner\'s tenant' => '/tenants/{tunde}',
        'another organization\'s tenant' => '/tenants/{foreign}',
        'a tenant with only a draft lease' => '/tenants/{draftPerson}',
        'another owner\'s unit (its lease history)' => '/units/{cedarUnit}',
        'another organization\'s unit' => '/units/{harbourUnit}',
        'another owner\'s lease and deposit' => '/leases/{cedarLease}',
        'another owner\'s contract' => '/leases/{cedarLease}/contract',
        'another organization\'s contract' => '/leases/{harbourLease}/contract',
        'another owner\'s bill' => '/bills/{cedarBill}',
        'another owner\'s receipt' => '/receipts/{cedarReceipt}',
        'another organization\'s receipt' => '/receipts/{harbourReceipt}',
        'a draft lease' => '/leases/{draft}',
        'a draft\'s contract' => '/leases/{draft}/contract',
        'a cancelled draft' => '/leases/{cancelled}',
        'a missing tenant' => '/tenants/999999',
        'a missing unit' => '/units/999999',
        'a missing receipt' => '/receipts/999999',
        'a missing contract' => '/leases/999999/contract',
    ]);

    it('answers 404 to the other owner for hers, and sends guests to sign in', function () {
        foreach (["/tenants/{$this->kemi->id}", "/units/{$this->flat3b->id}", "/receipts/{$this->receipt->id}", "/leases/{$this->lease->id}/contract", "/leases/{$this->lease->id}"] as $path) {
            ownerPortalPage($this->femi, $path)->assertNotFound()->assertDontSee('Kemi Adebayo');
            billsFreshRequest();
            $this->flushSession();
            $this->get('/owner'.$path)->assertRedirect('/login');
        }

        // Femi sees his own tenant and receipt.
        ownerPortalPage($this->femi, "/tenants/{$this->tunde->id}")->assertOk()->assertSee('Tunde Bakare')->assertSee('+2348034445555');
        ownerPortalPage($this->femi, "/receipts/{$this->cedarReceipt->id}")->assertOk()->assertSee($this->cedarReceipt->number);
    });

    it('stops showing the tenant, receipt and contract once her owner record there is archived', function () {
        inOrganization($this->acme, fn () => $this->ngoziAtAcme->delete());

        foreach (["/tenants/{$this->kemi->id}", "/units/{$this->flat3b->id}", "/receipts/{$this->receipt->id}", "/leases/{$this->lease->id}/contract"] as $path) {
            ownerPortalPage($this->ngozi, $path)->assertNotFound();
        }

        ownerPortalPage($this->ngozi, "/tenants/{$this->musa->id}")->assertOk();
    });
});

describe('acceptance: no bank account and no payment reference on any owner page', function () {
    it('shows neither organization\'s bank account, the bill\'s reference nor the payment\'s on any page, and offers no issued invoice or receipt', function () {
        $hidden = [
            '0123456789', '0987654321',
            (string) $this->bill->payment_reference, (string) $this->belloBill->payment_reference,
            'TRF-KEMI-0001', 'TRF-KEMI-PROOF-9', 'kemi-transfer.png',
        ];

        foreach (ngozisPages() as $name => $path) {
            $html = (string) ownerPortalPage($this->ngozi, $path)->assertOk()->getContent();

            foreach ($hidden as $value) {
                expect($html)->not->toContain($value, "{$name} shows {$value}");
            }

            expect($html)->not->toContain('/pay/', $name)->not->toContain('invoice.pdf', $name)->not->toContain('/receipts/'.$this->receipt->id.'/pdf', $name)
                ->not->toContain('/proofs/', $name);
        }
    });
});

describe('acceptance: billing as on-screen pages, with the payment history', function () {
    it('shows the bill with its lines, totals, paid and still owed, and the payment with its receipt', function () {
        $page = ownerPortalPage($this->ngozi, "/bills/{$this->bill->id}")->assertOk()
            ->assertSee('Bill '.$this->bill->number)->assertSee('Paid')->assertSee('₦1,500,000')
            ->assertSee('data-test="owner-bill-lines"', false)->assertSee('data-test="owner-bill-total"', false)
            ->assertSee('Bank transfer')->assertSee('Receipt '.$this->receipt->number);

        expect(ownerPortalIds($page, 'receipt'))->toBe([$this->receipt->id])
            ->and(substr_count((string) $page->getContent(), 'data-test="owner-payment"'))->toBe(1);
    });

    it('shows the receipt as a page: number, date, amount, method, the bill it settled and its status, then void', function () {
        $page = ownerPortalPage($this->ngozi, "/receipts/{$this->receipt->id}")->assertOk()
            ->assertSee('Receipt '.$this->receipt->number)->assertSee('Valid')->assertSee('₦1,500,000')
            ->assertSee('Bank transfer')->assertSee($this->bill->number)->assertSee('Paid in full')->assertSee('Kemi Adebayo')
            ->assertSee('25/12/2026')->assertDontSee('data-test="owner-receipt-elsewhere"', false);
        expect(ownerPortalIds($page, 'bill'))->toBe([$this->bill->id]);

        voidReceiptAlone($this->acme, $this->owner, $this->receipt, 'Recorded twice');
        ownerPortalPage($this->ngozi, "/receipts/{$this->receipt->id}")->assertOk()->assertSee('Voided')->assertSee('data-test="owner-receipt-voided"', false);
        ownerPortalPage($this->ngozi, "/leases/{$this->lease->id}")->assertOk()->assertSee('Receipt voided');
    });

    it('lists the lease\'s confirmed payments only: never one waiting for confirmation or a tenant\'s proof', function () {
        $page = ownerPortalPage($this->ngozi, "/leases/{$this->lease->id}")->assertOk()
            ->assertSee('data-test="owner-lease-payments"', false)->assertSee('Receipt '.$this->receipt->number)->assertSee('Bill '.$this->bill->number);

        expect(substr_count((string) $page->getContent(), 'data-test="owner-payment"'))->toBe(1)
            ->and(ownerPortalIds($page, 'receipt'))->toBe([$this->receipt->id]);
        ownerPortalPage($this->ngozi, "/leases/{$this->pastLease->id}")->assertOk()->assertSee('Nothing paid yet.');
    });

    it('shows a receipt that also paid another owner\'s bill with its part for her bills only', function () {
        // Tunde rents in both buildings: one payment settles his Palm Court and Cedar House bills.
        $flat5 = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 5']);
        $palmLease = activeLease($this->acme, $this->owner, $flat5, $this->tunde, ['rent' => '600,000', 'startDate' => '2026-12-26', 'endDate' => '2027-12-25']);
        runBillGeneration();
        $palmBill = leaseBillOf($this->acme, $palmLease);
        $cedarSecond = unitIn($this->acme, $this->cedar, ['unit_label' => 'Flat 2']);
        $cedarLease = activeLease($this->acme, $this->owner, $cedarSecond, $this->tunde, ['rent' => '400,000', 'startDate' => '2026-12-26', 'endDate' => '2027-12-25']);
        runBillGeneration();
        $cedarBill = leaseBillOf($this->acme, $cedarLease);
        $receipt = receiptOf($this->acme, confirmedPayment($this->acme, $this->owner, $this->tunde, '1,000,000', [], [$palmBill->id => '600,000', $cedarBill->id => '400,000']));

        $page = ownerPortalPage($this->ngozi, "/receipts/{$receipt->id}")->assertOk()
            ->assertSee('₦1,000,000')->assertSee($palmBill->number)->assertSee('₦600,000')
            ->assertDontSee($cedarBill->number)->assertDontSee('Cedar House')
            ->assertSee('data-test="owner-receipt-elsewhere"', false);
        expect(ownerPortalIds($page, 'bill'))->toBe([$palmBill->id]);
        ownerPortalPage($this->femi, "/receipts/{$receipt->id}")->assertOk()->assertSee($cedarBill->number)->assertDontSee($palmBill->number);
    });
});

describe('acceptance: lease history per unit; drafts never shown, past leases shown', function () {
    it('shows the unit\'s past lease as a timeline (moved in, ended early, moved out), never a draft', function () {
        $page = ownerPortalPage($this->ngozi, "/units/{$this->flat4c->id}")->assertOk()
            ->assertSee('Flat 4C · Palm Court')->assertSee('Bola Past')->assertSee('Terminated')
            ->assertSee('Moved in')->assertSee('01/01/2027')->assertSee('Ended early')->assertSee('26/12/2026')->assertSee('Relocated to Abuja')->assertSee('Moved out')
            ->assertDontSee('Draft Person')->assertDontSee('Cancelled Draft');

        expect(ownerPortalIds($page, 'lease'))->toBe([$this->pastLease->id]);
        preg_match_all('/data-test="owner-lease-event" data-type="([a-z_]+)"/', (string) $page->getContent(), $events);
        expect($events[1])->toBe(['moved_in', 'terminated', 'moved_out']);

        ownerPortalPage($this->ngozi, "/buildings/{$this->palm->id}")->assertOk()->assertDontSee('Draft Person');
    });

    it('shows a renewal with its rent change, the lease it follows renewed, and the deposit carried over', function () {
        $renewal = withoutContractPdfs(fn () => inOrganization($this->acme, fn (): Lease => app(LeaseService::class)->renew($this->owner, storedLease($this->acme, $this->lease), leaseInput([
            'startDate' => '2028-01-01', 'endDate' => '2028-12-31', 'rent' => '1,800,000',
        ]))));

        // A renewal still a draft is not shown.
        expect(ownerPortalIds(ownerPortalPage($this->ngozi, "/units/{$this->flat3b->id}")->assertOk(), 'lease'))->toBe([$this->lease->id]);
        ownerPortalPage($this->ngozi, "/leases/{$renewal->id}")->assertNotFound();

        withoutContractPdfs(fn () => inOrganization($this->acme, fn () => app(LeaseService::class)->activate($this->owner, storedLease($this->acme, $renewal))));

        $page = ownerPortalPage($this->ngozi, "/units/{$this->flat3b->id}")->assertOk()
            ->assertSee('Renewed')->assertSee('Rent changed')->assertSee('From ₦1,500,000 to ₦1,800,000 (yearly)');
        preg_match_all('/data-test="owner-unit-lease" data-lease="(\d+)"/', (string) $page->getContent(), $listed);
        expect(array_map('intval', $listed[1]))->toBe([$renewal->id, $this->lease->id]);
        preg_match_all('/data-test="owner-lease-event" data-type="([a-z_]+)"/', (string) $page->getContent(), $events);
        expect($events[1])->toBe(['renewed', 'rent_changed', 'moved_in']);

        ownerPortalPage($this->ngozi, "/leases/{$this->lease->id}")->assertOk()->assertSee('Carried over to the renewal')->assertSee('data-test="owner-deposit-balance"', false);
        ownerPortalPage($this->ngozi, "/leases/{$renewal->id}")->assertOk()->assertSee('Carried over from the previous lease')->assertSee('₦150,000');
    });
});

describe('acceptance: each lease\'s deposit held, deductions and refunds', function () {
    it('shows the deposit taken and held on an active lease, and the refund and the part kept on an ended one', function () {
        $active = ownerPortalPage($this->ngozi, "/leases/{$this->lease->id}")->assertOk()->assertSee('data-test="owner-lease-deposit"', false)->assertSee('Deposit taken');
        preg_match('/data-test="owner-deposit-balance"><span>Held now<\/span><span>([^<]+)</', (string) $active->getContent(), $held);
        expect($held[1] ?? null)->toBe('₦150,000');

        $past = ownerPortalPage($this->ngozi, "/leases/{$this->pastLease->id}")->assertOk()
            ->assertSee('Deposit taken')->assertSee('Refunded to the tenant')->assertSee('Kept')->assertSee('Broken door');
        preg_match_all('/data-test="owner-deposit-line" data-type="([a-z_]+)"/', (string) $past->getContent(), $lines);
        preg_match('/data-test="owner-deposit-balance"><span>Held now<\/span><span>([^<]+)</', (string) $past->getContent(), $held);
        expect($lines[1])->toBe(['held', 'refunded', 'kept'])
            ->and($held[1] ?? null)->toBe('₦0');
    });
});

describe('acceptance: the issued contract, to view and download', function () {
    it('sends the owner her lease\'s issued contract as kept, the same file every time', function () {
        $page = ownerPortalPage($this->ngozi, "/leases/{$this->lease->id}")->assertOk()->assertSee(route('owner.leases.contract', $this->lease->id), false);
        expect($page->getContent())->toContain('data-test="owner-lease-contract"');

        $pdf = followInvoice(ownerPortalPage($this->ngozi, "/leases/{$this->lease->id}/contract"));
        expect($pdf)->toStartWith('%PDF');
        $media = inOrganization($this->acme, fn (): int => Media::query()->count());

        ownerPortalPage($this->ngozi, "/leases/{$this->lease->id}/contract")->assertRedirect();
        expect(inOrganization($this->acme, fn (): int => Media::query()->count()))->toBe($media);
    });

    it('does not open the contract while GoProperty staff impersonate the owner', function () {
        $staff = staffMember();
        enterSupport(startSupport($staff, $this->ngozi, $this->acme, ImpersonationMode::Write));

        freshRequest();
        asSupport()->get("/owner/leases/{$this->lease->id}")->assertOk();
        freshRequest();
        asSupport()->get("/owner/leases/{$this->lease->id}/contract")->assertForbidden();
    });
});

describe('acceptance: nothing is editable on the new pages', function () {
    it('refuses every Livewire method call, and changes nothing', function (string $path, string $method, array $params) {
        $path = strtr($path, ['{unit}' => (string) $this->flat3b->id, '{tenant}' => (string) $this->kemi->id, '{receipt}' => (string) $this->receipt->id]);
        $before = inOrganization($this->acme, fn () => Receipt::query()->orderBy('id')->get()->map->only(['id', 'voided_at', 'void_requested_at'])->all());

        ownerPortalCall($this->ngozi, $path, $method, $params)->assertForbidden();

        expect(inOrganization($this->acme, fn () => Receipt::query()->orderBy('id')->get()->map->only(['id', 'voided_at', 'void_requested_at'])->all()))->toEqual($before);
    })->with([
        'unit: terminate' => ['/units/{unit}', 'terminate', []],
        'unit: $set' => ['/units/{unit}', '$set', ['unitId', 1]],
        'tenant: save' => ['/tenants/{tenant}', 'save', []],
        'tenant: mount again' => ['/tenants/{tenant}', 'mount', [999]],
        'receipt: void' => ['/receipts/{receipt}', 'requestVoid', ['reason']],
        'receipt: render' => ['/receipts/{receipt}', 'render', []],
    ]);

    it('declares the new pages read-only owner pages with no public action', function () {
        foreach ([OwnerUnitShow::class, OwnerTenantShow::class, OwnerReceiptShow::class] as $component) {
            $actions = array_filter(
                (new ReflectionClass($component))->getMethods(ReflectionMethod::IS_PUBLIC),
                fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $component && ! in_array($method->getName(), ['mount', 'render'], true),
            );

            expect($actions)->toBe([], $component);
        }
    });
});
