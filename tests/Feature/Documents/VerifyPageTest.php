<?php

declare(strict_types=1);

use App\Documents\Verification\DocumentVerifiers;
use App\Documents\Verification\VerificationCodes;
use App\Documents\Verification\VerificationResolver;
use App\Documents\Verification\VerificationStatus;
use App\Documents\Verification\VerifiedDocument;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;

/*
 * P03-05b: the public verify page, /verify/{code} and the /verify form
 * (BRANDING_DOCUMENTS.md § 2 item 8, § 3 item 7), through the
 * DocumentVerifiers registry of P03-05a. Sub-acceptance: the page shows
 * valid or voided; an unknown code gets a plain 404.
 */

/**
 * A module's resolver as Payments or Billing will register it: answers for
 * the codes it was given, inside the issuing organization.
 */
function pageResolver(array $documents): VerificationResolver
{
    return new class($documents) implements VerificationResolver
    {
        public function __construct(private array $documents) {}

        public function resolve(string $code, Organization $organization): ?VerifiedDocument
        {
            // Runs inside the code's organization, never another.
            expect(app(OrganizationContext::class)->id())->toBe($organization->id);

            return $this->documents[$code] ?? null;
        }
    };
}

function verifiedDoc(string $kind, VerificationStatus $status, array $extra = []): VerifiedDocument
{
    return new VerifiedDocument(
        kind: $kind,
        title: $kind === 'receipt' ? 'Official receipt' : 'Invoice',
        number: $kind === 'receipt' ? 'RCT-ADR-2026-000318' : 'INV-ADR-2027-000142',
        issuer: $extra['issuer'] ?? 'Adeyemi Realty Ltd',
        issuedAt: CarbonImmutable::parse('2026-12-14 09:42'),
        status: $status,
        amount: Money::kobo(290_000_000),
        statusNote: $extra['statusNote'] ?? null,
        details: $extra['details'] ?? [],
        replacedBy: $extra['replacedBy'] ?? null,
    );
}

/** Issues a code for $kind in $organization, as an issuing module does. */
function issueCode(Organization $organization, string $kind): string
{
    return app(OrganizationContext::class)->run($organization, fn (): string => app(VerificationCodes::class)->issue($kind));
}

beforeEach(function () {
    RateLimiter::clear('verify');
    $this->acme = Organization::factory()->create(['name' => 'Adeyemi Realty Ltd']);
    $this->other = Organization::factory()->create(['name' => 'Other Homes Ltd']);

    $this->receiptCode = issueCode($this->acme, 'receipt');
    $this->voidedCode = issueCode($this->acme, 'receipt');
    $this->invoiceCode = issueCode($this->other, 'invoice');
    $this->replacedCode = issueCode($this->other, 'invoice');
    $this->forgottenCode = issueCode($this->acme, 'receipt');
    $this->leaseCode = issueCode($this->acme, 'lease');

    app(DocumentVerifiers::class)->register('receipt', pageResolver([
        $this->receiptCode => verifiedDoc('receipt', VerificationStatus::Valid),
        $this->voidedCode => verifiedDoc('receipt', VerificationStatus::Voided, ['statusNote' => 'Voided on 15/12/2026']),
    ]));
    app(DocumentVerifiers::class)->register('invoice', pageResolver([
        $this->invoiceCode => verifiedDoc('invoice', VerificationStatus::Valid, ['issuer' => 'Other Homes Ltd', 'details' => ['Payment status' => 'Partly paid']]),
        $this->replacedCode => verifiedDoc('invoice', VerificationStatus::Superseded, ['issuer' => 'Other Homes Ltd', 'replacedBy' => 'INV-ADR-2027-000150']),
    ]));
});

afterEach(fn () => app(OrganizationContext::class)->clear());

describe('sub-acceptance: the verify page shows valid or voided', function () {
    it('shows a valid receipt: issuer, document, date, amount and status', function () {
        $this->get('/verify/'.$this->receiptCode)
            ->assertOk()
            ->assertSee('This official receipt is genuine')
            ->assertSee('Adeyemi Realty Ltd')
            ->assertSee('Official receipt RCT-ADR-2026-000318')
            ->assertSee('14/12/2026')
            ->assertSee('₦2,900,000')
            ->assertSee('data-status="valid"', false)
            ->assertSee('verify-pill--valid">Valid', false)
            ->assertSee(VerificationCodes::format($this->receiptCode))
            ->assertDontSee('was voided');
    });

    it('shows a voided receipt as voided', function () {
        $this->get('/verify/'.$this->voidedCode)
            ->assertOk()
            ->assertSee('This official receipt was voided')
            ->assertSee('data-status="voided"', false)
            ->assertSee('verify-pill--voided">Voided', false)
            ->assertSee('Voided on 15/12/2026')
            ->assertSee('no longer proof of anything')
            ->assertDontSee('is genuine');
    });

    it('shows a superseded invoice and what replaced it', function () {
        $this->get('/verify/'.$this->replacedCode)
            ->assertOk()
            ->assertSee('This invoice was replaced')
            ->assertSee('data-status="superseded"', false)
            ->assertSee('INV-ADR-2027-000150');
    });

    it('asks the module of the code\'s own organization, whichever it is', function () {
        $this->get('/verify/'.$this->invoiceCode)
            ->assertOk()
            ->assertSee('This invoice is genuine')
            ->assertSee('Issued by <b>Other Homes Ltd</b>', false)
            ->assertSee('Payment status')
            ->assertSee('Partly paid');
    });

    it('takes a code as printed or typed: dashes, spaces, lower case, O for 0', function () {
        $printed = VerificationCodes::format($this->receiptCode);
        $typed = strtolower(strtr($printed, ['0' => 'o', '1' => 'l']));

        $this->get('/verify/'.$printed)->assertStatus(301)->assertRedirect('/verify/'.$this->receiptCode);
        $this->get('/verify/'.$typed)->assertStatus(301)->assertRedirect('/verify/'.$this->receiptCode);
    });

    it('shows nothing about people or bank accounts, only what VerifiedDocument carries', function () {
        $html = $this->get('/verify/'.$this->receiptCode)->assertOk()->getContent();

        expect($html)->not->toContain('Chiamaka', '+234', 'Account number', 'Phone')
            ->and($html)->toContain('<meta name="robots" content="noindex, nofollow">');
    });

    it('is never cached and never indexed', function () {
        $response = $this->get('/verify/'.$this->receiptCode)->assertOk();

        expect($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
            ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'self'");
    });

    it('works signed in or not, with no organization chosen', function () {
        $this->actingAs(User::factory()->create())
            ->get('/verify/'.$this->voidedCode)
            ->assertOk()
            ->assertSee('was voided');
    });
});

describe('sub-acceptance: an unknown code gets a plain 404', function () {
    it('answers 404 for a code nobody issued, and says nothing about it', function () {
        $unknown = app(VerificationCodes::class)->generate();

        $response = $this->get('/verify/'.$unknown);

        $response->assertNotFound()
            ->assertDontSee('Adeyemi')
            ->assertDontSee('Official receipt');
    });

    it('answers the same 404 for every way a code can fail', function (string $why) {
        $code = match ($why) {
            'malformed' => 'ABC-123-NOT-A-CODE',
            'too short' => 'AB12',
            'resolver no longer knows it' => $this->forgottenCode,
            'no module verifies its kind' => $this->leaseCode,
            'letters outside the alphabet' => 'UUUUUUUUUU',
        };

        $this->get('/verify/'.$code)->assertNotFound()->assertDontSee('Adeyemi');
    })->with(['malformed', 'too short', 'resolver no longer knows it', 'no module verifies its kind', 'letters outside the alphabet']);

    it('does not reveal another organization\'s documents through a code of the wrong kind', function () {
        // Only the resolver of the code's own kind and organization is asked.
        $this->get('/verify/'.$this->leaseCode)->assertNotFound();
        $this->get('/verify/'.$this->invoiceCode)->assertOk()->assertDontSee('Adeyemi');
    });
});

describe('the /verify form ("visit …/verify and enter")', function () {
    it('asks for the code printed on the document', function () {
        $this->get('/verify')
            ->assertOk()
            ->assertSee('Verify a document')
            ->assertSee('name="code"', false);
    });

    it('sends a well-formed code to its page, as typed', function () {
        $this->get('/verify?code='.urlencode(strtolower(VerificationCodes::format($this->receiptCode))))
            ->assertRedirect('/verify/'.$this->receiptCode);
    });

    it('says when what was typed cannot be a code', function () {
        $this->get('/verify?code=hello')
            ->assertStatus(422)
            ->assertSee('That is not a verification code');
    });
});

it('is rate-limited per address, the form and the pages together', function () {
    foreach (range(1, 20) as $attempt) {
        $this->get('/verify/'.$this->receiptCode)->assertOk();
    }

    foreach (range(1, 10) as $attempt) {
        $this->get('/verify')->assertOk();
    }

    $this->get('/verify/'.$this->receiptCode)->assertStatus(429);
    $this->get('/verify')->assertStatus(429);
    $this->get('/verify/'.app(VerificationCodes::class)->generate())->assertStatus(429);
});
