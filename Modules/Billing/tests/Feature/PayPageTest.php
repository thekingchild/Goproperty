<?php

declare(strict_types=1);

use App\Banking\BankAccounts;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Modules\Billing\Bills\BillService;
use Modules\Billing\Contracts\BillLedger;
use Modules\Billing\Contracts\PayableBill;
use Modules\Billing\Contracts\PaymentOption;
use Modules\Billing\Contracts\PaymentOptions;
use Modules\Billing\Models\Bill;
use Tests\Support\NarrowScreen;

require_once __DIR__.'/invoice-helpers.php';

/*
 * P03-05c: the branded public pay page, /pay/{reference}
 * (BRANDING_DOCUMENTS.md § 1 "Pay page", ONBOARDING_COMPLIANCE.md § 4.1):
 * no sign-in, the agency's branding as on the invoice, the bill and the
 * amount outstanding, the agency's bank account with the reference for
 * the narration, and the online payment options slot (P03-08). Found by
 * the reference alone (decision 2's lookup); unknown references are a
 * plain 404; rate-limited; never another organization's bill and never
 * the tenant's details.
 */

beforeEach(function () {
    // The invoice PDF (rendered on the pdf queue) is the invoice tests' business.
    holdInvoicePdfs();
    seedOrganizationRoles();
    withoutAccountNameLookups();
    $this->travelTo('2026-12-25 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes Ltd']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->otherOwner = joinAs(User::factory()->create(), $this->other);
    invoiceBranding($this->acme, $this->owner, ['tradingName' => 'Adeyemi Realty', 'phone' => '+234 803 000 0000', 'email' => 'accounts@adeyemirealty.ng']);
    $this->account = addBankAccount($this->acme, $this->owner, '0123456789', 'Adeyemi Realty Ltd');
    addBankAccount($this->other, $this->otherOwner, '0987654321', 'Bello Homes Ltd');

    $palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court', 'address' => '22 Fola Osibo Road, Lekki Phase 1', 'state' => 'Lagos']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222', 'email' => 'kemi.adebayo@example.com']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $palm, ['unit_label' => 'Flat 3B']), $this->kemi);
    activeLease($this->other, $this->otherOwner, unitIn($this->other, buildingIn($this->other, ['name' => 'Foreign Court', 'slug' => 'foreign-court'])), tenantIn($this->other, ['first_name' => 'Foreign', 'last_name' => 'Tenant', 'phone' => '+2348035550000', 'email' => 'foreign.tenant@example.com']), ['rent' => '2,400,000']);
    runBillGeneration();

    $this->bill = billsOf($this->acme)->sole();
    $this->foreign = billsOf($this->other)->sole();
});

afterEach(fn () => billsFreshRequest());

/** Applies a payment of $naira to the bill, as Payments will through the bill ledger. */
function payTowards(Organization $organization, Bill $bill, int $naira): void
{
    inOrganization($organization, fn () => DB::transaction(fn () => app(BillLedger::class)->apply($bill->id, Money::naira($naira))));
}

/** A payment option (as P03-08's Paystack button will be) that offers bills up to $limit naira. */
function onlineOption(int $limit): PaymentOption
{
    return new class($limit) implements PaymentOption
    {
        public function __construct(private int $limit) {}

        public function offers(PayableBill $bill): bool
        {
            return ! $bill->outstanding->greaterThan(Money::naira($this->limit));
        }

        public function render(PayableBill $bill): Htmlable
        {
            return new HtmlString('<a class="test-pay-online" href="https://pay.example.test/'.e($bill->paymentReference).'">Pay '.e($bill->outstanding->format()).' online</a>');
        }
    };
}

describe('the pay page', function () {
    it('shows the agency\'s branding, the bill, the amount to pay and the bank account, without signing in', function () {
        $response = payPage($this->bill->payment_reference)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Adeyemi Realty')
            ->assertSee('+234 803 000 0000')
            ->assertSee('background: #0e6b5c', false)
            ->assertSee('Pay your bill')
            ->assertSee('INV-ADR-2026-000001')
            ->assertSee('Palm Court — Flat 3B')
            ->assertSee('Rent')
            ->assertSee('01/01/2027 – 31/12/2027')
            ->assertSee('data-test="pay-outstanding"', false)
            ->assertSee('₦1,500,000')
            ->assertSee('Due 01/01/2027')
            ->assertSee('Guaranty Trust Bank (GTBank)')
            ->assertSee('Adeyemi Realty Ltd')
            ->assertSee('0123456789')
            ->assertSee('data-test="pay-reference">'.$this->bill->payment_reference, false)
            ->assertSee('Put '.$this->bill->payment_reference.' in your transfer narration');

        expect((string) $response->headers->get('Content-Security-Policy'))->toContain("script-src 'self'")->toContain("frame-ancestors 'none'")
            ->and($response->getContent())->not->toContain('<script');
    });

    it('never shows the tenant\'s name or contact details, nor the address', function () {
        payPage($this->bill->payment_reference)->assertOk()
            ->assertDontSee('Kemi')
            ->assertDontSee('Adebayo')
            ->assertDontSee('+2348031112222')
            ->assertDontSee('kemi.adebayo@example.com')
            ->assertDontSee('Fola Osibo');
    });

    it('shows what is still owed after a part payment, and that a paid bill is paid, without bank details', function () {
        payTowards($this->acme, $this->bill, 500_000);
        payPage($this->bill->payment_reference)->assertOk()
            ->assertSee('data-test="pay-status">Partly paid', false)
            ->assertSee('₦1,000,000')
            ->assertSee('data-test="pay-paid">₦500,000', false)
            ->assertSee('0123456789');

        payTowards($this->acme, $this->bill, 1_000_000);
        payPage($this->bill->payment_reference)->assertOk()
            ->assertSee('This bill is paid')
            ->assertSee('data-test="pay-status">Paid', false)
            ->assertSee('data-test="pay-nothing-due"', false)
            ->assertDontSee('data-test="pay-outstanding"', false)
            ->assertDontSee('data-test="pay-bank"', false)
            ->assertDontSee('0123456789');
    });

    it('says a void bill is void and asks for nothing', function () {
        inOrganization($this->acme, fn () => app(BillService::class)->void($this->owner, storedBill($this->acme, $this->bill), 'Kemi moved out'));

        payPage($this->bill->payment_reference)->assertOk()
            ->assertSee('This bill is void')
            ->assertSee('Do not pay it')
            ->assertSee('INV-ADR-2026-000001')
            ->assertSee('on 25/12/2026')
            ->assertDontSee('Kemi moved out')
            ->assertDontSee('₦1,500,000')
            ->assertDontSee('0123456789')
            ->assertDontSee('data-test="pay-bank"', false);
    });

    it('shows the branding the invoice was issued with, not branding changed later', function () {
        invoiceBranding($this->acme, $this->owner, ['tradingName' => 'Renamed Homes', 'primaryColor' => '#1D4ED8']);

        payPage($this->bill->payment_reference)->assertOk()
            ->assertSee('Adeyemi Realty')
            ->assertSee('background: #0e6b5c', false)
            ->assertDontSee('Renamed Homes')
            ->assertDontSee('#1d4ed8');
    });

    it('shows the primary usable account as it is now, and asks the payer to contact the agency when there is none', function () {
        $second = addBankAccount($this->acme, $this->owner, '1122334455', 'Adeyemi Realty Ltd', '044');
        inOrganization($this->acme, fn () => app(BankAccounts::class)->makePrimary($this->owner, $second));
        payPage($this->bill->payment_reference)->assertOk()->assertSee('1122334455')->assertDontSee('0123456789');

        inOrganization($this->acme, fn () => app(BankAccounts::class)->deactivate($this->owner, $second));
        inOrganization($this->acme, fn () => app(BankAccounts::class)->deactivate($this->owner, $this->account->fresh()));
        payPage($this->bill->payment_reference)->assertOk()
            ->assertSee('data-test="pay-no-bank"', false)
            ->assertSee('has not added a bank account yet')
            ->assertDontSee('1122334455')
            ->assertDontSee('0123456789');
    });

    it('takes a reference as typed: lower case, spaces, dashes, O for 0', function () {
        $reference = $this->bill->payment_reference;
        $typed = strtolower(strtr(str_replace('-', ' ', $reference), ['0' => 'O', '1' => 'l']));

        payPage(rawurlencode($typed))->assertStatus(301)->assertRedirect('/pay/'.$reference);
        payPage(str_replace('-', '', $reference))->assertStatus(301)->assertRedirect('/pay/'.$reference);
    });
});

describe('sub-acceptance: an unknown reference gets a 404', function () {
    it('answers the same plain 404 for every reference that finds no bill', function (string $reference) {
        payPage($reference)->assertNotFound()->assertDontSee('Adeyemi');
    })->with([
        'never issued' => ['GP-ZZZZZZZ'],
        'not a reference' => ['PAY-2026-0001'],
        'too short' => ['GP-7KQ2M'],
        'letters outside the alphabet' => ['GP-UUUUUUU'],
    ]);

    it('answers 404 for the reference of a bill removed by an import rollback', function () {
        inOrganization($this->acme, fn () => storedBill($this->acme, $this->bill)->delete());

        payPage($this->bill->payment_reference)->assertNotFound();
    });
});

describe('cross-organization', function () {
    it('shows each reference\'s own organization\'s bill only, whoever is signed in', function () {
        payPage($this->foreign->payment_reference)->assertOk()
            ->assertSee('Bello Homes Ltd')
            ->assertSee($this->foreign->number)
            ->assertSee('₦2,400,000')
            ->assertSee('0987654321')
            ->assertDontSee('Adeyemi')
            ->assertDontSee('0123456789')
            ->assertDontSee('₦1,500,000');

        // Signed in to the other organization: our reference still shows only our bill.
        billsFreshRequest();
        $this->actingAs($this->otherOwner)->get('/pay/'.$this->bill->payment_reference)->assertOk()
            ->assertSee('Adeyemi Realty')
            ->assertSee('0123456789')
            ->assertDontSee('Bello Homes')
            ->assertDontSee('0987654321');
    });
});

describe('sub-acceptance: /pay is rate-limited', function () {
    it('allows 30 pages a minute per address, then 429, unknown references included', function () {
        for ($i = 0; $i < 29; $i++) {
            payPage($this->bill->payment_reference)->assertOk();
        }

        payPage('GP-ZZZZZZZ')->assertNotFound();
        payPage($this->bill->payment_reference)->assertStatus(429);
        payPage($this->foreign->payment_reference)->assertStatus(429);

        $this->travel(61)->seconds();
        payPage($this->bill->payment_reference)->assertOk();
    });
});

describe('the online payment options slot (P03-08)', function () {
    it('shows the options that offer the bill on the pay page and a Pay online link on the invoice', function () {
        app(PaymentOptions::class)->register(onlineOption(2_000_000));

        payPage($this->bill->payment_reference)->assertOk()
            ->assertSee('data-test="pay-online"', false)
            ->assertSee('<a class="test-pay-online" href="https://pay.example.test/'.$this->bill->payment_reference.'">Pay ₦1,500,000 online</a>', false);
        invoicePage($this->owner, $this->bill)->assertOk()->assertSee(route('pay.show', $this->bill->payment_reference));
    });

    it('offers nothing online above the option\'s limit, or once the bill is paid', function () {
        app(PaymentOptions::class)->register(onlineOption(1_000_000));

        payPage($this->bill->payment_reference)->assertOk()->assertDontSee('data-test="pay-online"', false);
        invoicePage($this->owner, $this->bill)->assertOk()->assertDontSee(route('pay.show', $this->bill->payment_reference));

        payTowards($this->acme, $this->bill, 1_500_000);
        payPage($this->bill->payment_reference)->assertOk()->assertDontSee('data-test="pay-online"', false);
    });
});

describe('phones (UX_DESIGN.md § 1: 360 px)', function () {
    it('lays out on a 360 px screen without sideways scrolling, in real Chrome', function (string $state) {
        $gotenberg = requireGotenberg();
        app(PaymentOptions::class)->register(onlineOption(2_000_000));

        match ($state) {
            'part paid' => payTowards($this->acme, $this->bill, 500_000),
            'paid' => payTowards($this->acme, $this->bill, 1_500_000),
            'void' => inOrganization($this->acme, fn () => app(BillService::class)->void($this->owner, storedBill($this->acme, $this->bill), 'Duplicate')),
            default => null,
        };

        $screen = NarrowScreen::measure($gotenberg, payPage($this->bill->payment_reference)->assertOk()->getContent(), 360);

        expect($screen['viewport'])->toBe(360)
            ->and($screen['overflow'])->toBe(0);
    })->with(['to pay' => 'open', 'part paid' => 'part paid', 'paid' => 'paid', 'void' => 'void']);
});
