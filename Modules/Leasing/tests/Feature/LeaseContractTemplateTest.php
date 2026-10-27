<?php

declare(strict_types=1);

use App\Documents\DocumentLayout;
use App\Documents\DocumentRenderer;
use App\Documents\Parts\DocumentProperty;
use App\Documents\Parts\DocumentTenant;
use App\Documents\PdfPages;
use App\Documents\RenderTarget;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Modules\Leasing\Contracts\BillingCycle;
use Modules\Leasing\Documents\ContractClauses;
use Modules\Leasing\Documents\LeaseContractDocument;
use Tests\Support\DocumentCssLint;
use Tests\Support\NarrowScreen;
use Tests\Support\PdfVisualDiff;

require_once __DIR__.'/../../../../tests/Support/documents.php';

/*
 * P03-03b: the lease contract templates (BRANDING_DOCUMENTS.md § 4–5;
 * Modules/Leasing/resources/views/documents/contract) in the three layouts,
 * built from LeaseContractDocument, and the agency's clause template
 * rendered with whitelisted placeholders only (AGENT.md § 3 rule 10).
 *
 * Acceptance (IMPLEMENTATION_PLAN.md § 3 P03-03): the contract PDF renders
 * with both drivers. dompdf is always tested; the Chrome half needs
 * Gotenberg, which CI runs as a service (decision 6) and which is skipped
 * only on a machine without it.
 */

/** A full contract: an owner as landlord, a renewal, the agency's logo, signature and stamp, the standard clauses. */
function sampleLeaseContract(DocumentLayout $layout = DocumentLayout::Modern, string $clauses = ContractClauses::DEFAULT, string $tenantName = 'Chiamaka Adaeze Okafor-Williams'): LeaseContractDocument
{
    return new LeaseContractDocument(
        brand: fullBrand($layout),
        number: 'LS-ADR-2027-000012',
        issuedAt: CarbonImmutable::parse('2026-12-20 09:30:00'),
        tenant: new DocumentTenant($tenantName, '+234 812 345 6789', 'chiamaka.okafor-williams@example.com'),
        property: new DocumentProperty('Palm Court Apartments', 'Flat 3B', '22 Fola Osibo Road, Lekki Phase 1, Lagos'),
        landlord: 'Babatunde Adeyemi',
        cycle: BillingCycle::Annual,
        startDate: CarbonImmutable::parse('2027-01-01'),
        endDate: CarbonImmutable::parse('2027-12-31'),
        rent: Money::naira(1_500_000),
        deposit: Money::naira(150_000),
        dueDayOffset: 0,
        firstDue: CarbonImmutable::parse('2027-01-01'),
        paidUntil: null,
        renewalOf: 'LS-ADR-2026-000003',
        clauseTemplate: $clauses,
        verificationCode: '7KQ2MX9P4D',
    );
}

/** @return array<string, array{DocumentLayout}> */
function contractLayouts(): array
{
    return array_combine(
        array_map(static fn (DocumentLayout $layout): string => $layout->value, DocumentLayout::cases()),
        array_map(static fn (DocumentLayout $layout): array => [$layout], DocumentLayout::cases()),
    );
}

describe('acceptance: the contract PDF renders with both drivers', function () {
    it('prints with dompdf, in the bundled fonts, with every part of the contract and "page x of y"', function (DocumentLayout $layout) {
        $pdf = app(DocumentRenderer::class)->pdf(sampleLeaseContract($layout), 'dompdf');
        $pages = PdfPages::count($pdf);

        expect($pages)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(2)
            ->and($pdf)->toContain('/MediaBox [0.000 0.000 595.280 841.890]')
            ->and($pdf)->toMatch('#/BaseFont /[A-Z]{6}\+Inter-Regular#');

        requirePdfTools();
        $text = PdfVisualDiff::text($pdf);

        expect($text)->toContain(
            'TENANCY AGREEMENT', 'LS-ADR-2027-000012', 'LS-ADR-2026-000003', 'Adeyemi Realty',
            'LANDLORD', 'Babatunde Adeyemi', 'TENANT', 'Chiamaka Adaeze', 'PREMISES', 'Palm Court Apartments',
            '1 Jan 2027 to 31 Dec 2027', '₦1,500,000 yearly', 'One million, five hundred thousand', '₦150,000',
            'On the first day of each period', 'Signature and stamp', 'Witness', '7KQ2M-X9P4D',
            'governed by the laws of the Federal Republic of Nigeria', "Page {$pages} of {$pages}", 'Generated with GoProperty',
        );
    })->with(contractLayouts());

    it('prints the same with Chrome (Gotenberg) as with dompdf, within the threshold, page for page', function (DocumentLayout $layout) {
        requirePdfTools();
        requireGotenberg();
        $renderer = app(DocumentRenderer::class);
        $contract = sampleLeaseContract($layout);

        $dompdf = $renderer->pdf($contract, 'dompdf');
        $chrome = $renderer->pdf($contract, 'chrome');
        $diff = PdfVisualDiff::compare($dompdf, $chrome);
        $pages = PdfPages::count($dompdf);

        expect($diff['pages'])->toBe([$pages, $pages])
            ->and(PdfVisualDiff::text($chrome))->toContain('LS-ADR-2027-000012', '7KQ2M-X9P4D', "Page {$pages} of {$pages}")
            ->and($diff['score'])->toBeLessThan(PdfVisualDiff::THRESHOLD);
    })->with(contractLayouts());

    it('lays out on a 360 px screen without sideways scrolling, in real Chrome', function (DocumentLayout $layout) {
        $gotenberg = requireGotenberg();

        $measured = NarrowScreen::measure($gotenberg, app(DocumentRenderer::class)->web(sampleLeaseContract($layout)));

        expect($measured)->toBe(['viewport' => 360, 'overflow' => 0]);
    })->with(contractLayouts());

    it('uses the organization\'s layout: one template per layout, from the Leasing module', function (DocumentLayout $layout) {
        $contract = sampleLeaseContract($layout);

        expect($contract->view())->toBe("leasing::documents.contract.{$layout->value}")
            ->and(view()->exists($contract->view()))->toBeTrue()
            ->and($contract->fileName())->toBe('LS-ADR-2027-000012.pdf')
            ->and(app(DocumentRenderer::class)->web($contract))->toContain('class="layout-'.$layout->value.'"', 'Tenancy agreement LS-ADR-2027-000012');
    })->with(contractLayouts());

    it('finds no flex, grid or CSS variables in the contract as rendered, for every layout and target', function (DocumentLayout $layout, RenderTarget $target) {
        expect(DocumentCssLint::problems(app(DocumentRenderer::class)->html(sampleLeaseContract($layout), $target)))->toBe([]);
    })->with(contractLayouts())->with([RenderTarget::Web, RenderTarget::Dompdf, RenderTarget::Chrome]);
});

describe('signature blocks and parties', function () {
    it('prints a signature block for the landlord, signed by the agency with its signature and stamp, and one for the tenant', function () {
        $html = app(DocumentRenderer::class)->web(sampleLeaseContract());

        expect($html)->toContain('For the landlord, Babatunde Adeyemi, by Adeyemi Realty as managing agent', 'The tenant, Chiamaka Adaeze Okafor-Williams')
            ->and($html)->toContain('alt="Signature"', 'alt="Stamp"', 'Represented by Adeyemi Realty, managing agent')
            ->and(substr_count($html, '<td class="k">Witness</td>'))->toBe(2);
    });

    it('names the agency as landlord when the building has no owner on record', function () {
        $contract = new LeaseContractDocument(...[...get_object_vars(sampleLeaseContract()), 'landlord' => null]);
        $html = app(DocumentRenderer::class)->web($contract);

        expect($contract->landlordName())->toBe('Adeyemi Realty')
            ->and($contract->managedForOwner())->toBeFalse()
            ->and($html)->toContain('For the landlord, Adeyemi Realty</div>')
            ->and($html)->not->toContain('Represented by', 'as managing agent </div>');
    });

    it('shows the rent paid in advance and when the rent falls due', function () {
        $contract = new LeaseContractDocument(...[...get_object_vars(sampleLeaseContract()), 'dueDayOffset' => 5, 'paidUntil' => CarbonImmutable::parse('2027-03-31'), 'firstDue' => CarbonImmutable::parse('2027-04-06')]);
        $html = app(DocumentRenderer::class)->web($contract);

        expect($html)->toContain('5 days after each period starts', 'Rent paid up to', '31 Mar 2027', '6 Apr 2027');
    });

    it('refuses a contract without its verification code, or ending before it starts', function () {
        expect(fn () => new LeaseContractDocument(...[...get_object_vars(sampleLeaseContract()), 'verificationCode' => 'nope']))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new LeaseContractDocument(...[...get_object_vars(sampleLeaseContract()), 'endDate' => CarbonImmutable::parse('2026-12-31')]))->toThrow(InvalidArgumentException::class);
    });
});

describe('sub-AC: an agency clause template is data, never code (AGENT.md § 3 rule 10)', function () {
    it('prints {{ }}, {!! !!}, @php and Blade directives in a clause as plain text, and runs none of them', function () {
        $marker = sys_get_temp_dir().'/gp-contract-clause-'.bin2hex(random_bytes(6));
        $template = "The tenant owes {{ 1 + 1 }} keys and {!! app()->environment() !!} copies.\n\n@php file_put_contents('{$marker}', 'ran'); @endphp\n\n@include('documents.partials.brand-bar') @if(true) yes @endif {{ \$contract->number }}";
        $contract = sampleLeaseContract(clauses: $template);

        $html = app(DocumentRenderer::class)->web($contract);
        $pdf = app(DocumentRenderer::class)->pdf($contract, 'dompdf');

        expect(file_exists($marker))->toBeFalse()
            ->and($html)->toContain('The tenant owes {{ 1 + 1 }} keys and {!! app()-&gt;environment() !!} copies.')
            ->and($html)->toContain("@php file_put_contents(&#039;{$marker}&#039;, &#039;ran&#039;); @endphp")
            ->and($html)->toContain('@include(&#039;documents.partials.brand-bar&#039;) @if(true) yes @endif {{ $contract-&gt;number }}')
            ->and($html)->not->toContain('owes 2 keys', 'testing copies');

        requirePdfTools();
        $text = PdfVisualDiff::text($pdf);

        expect($text)->toContain('{{ 1 + 1 }}', '@php file_put_contents', '{{ $contract->number }}')
            ->and($text)->not->toContain('owes 2 keys');
    });

    it('escapes HTML in a clause and in the values filled in: a script prints as text', function () {
        $html = app(DocumentRenderer::class)->web(sampleLeaseContract(
            clauses: "<script>alert('clause')</script> <b>bold</b> <img src=x onerror=alert(1)>\n\nLet to {tenant_name}.",
            tenantName: '<script>alert("tenant")</script> Ada',
        ));

        expect($html)->toContain('&lt;script&gt;alert(&#039;clause&#039;)&lt;/script&gt; &lt;b&gt;bold&lt;/b&gt; &lt;img src=x onerror=alert(1)&gt;')
            ->and($html)->toContain('Let to &lt;script&gt;alert(&quot;tenant&quot;)&lt;/script&gt; Ada.')
            ->and($html)->not->toContain('<script>', '<b>bold</b>', '<img src=x');
    });

    it('fills in only the whitelisted placeholders and leaves unknown ones exactly as typed', function () {
        $clauses = ContractClauses::render(
            "{tenant_name} rents {unit} at {property} for {rent} {cycle} from {start_date} to {end_date} ({contract_number}).\n\n"
            ."Unknown: {favourite_colour}, {Tenant_Name}, {TENANT_NAME}, { tenant_name }, {tenant-name}, {{tenant_name}}, {{ tenant_name }}, {password}, {app_key}.\n\n"
            .'Deposit {deposit}; landlord {landlord}; agency {agency_name}; phone {tenant_phone}; in words {rent_in_words}; at {address}.',
            sampleLeaseContract()->placeholderValues() + ['favourite_colour' => 'blue', 'app_key' => 'secret'],
        );

        expect($clauses)->toBe([
            'Chiamaka Adaeze Okafor-Williams rents Flat 3B at Palm Court Apartments for ₦1,500,000 yearly from 1 Jan 2027 to 31 Dec 2027 (LS-ADR-2027-000012).',
            'Unknown: {favourite_colour}, {Tenant_Name}, {TENANT_NAME}, { tenant_name }, {tenant-name}, {{tenant_name}}, {{ tenant_name }}, {password}, {app_key}.',
            'Deposit ₦150,000; landlord Babatunde Adeyemi; agency Adeyemi Realty; phone +234 812 345 6789; in words One million, five hundred thousand naira only; at 22 Fola Osibo Road, Lekki Phase 1, Lagos.',
        ])->and(ContractClauses::unknownPlaceholders('{favourite_colour} {tenant_name} {password} {favourite_colour}'))->toBe(['favourite_colour', 'password']);
    });

    it('numbers clauses separated by a blank line and keeps line breaks inside a clause', function () {
        $html = app(DocumentRenderer::class)->web(sampleLeaseContract(clauses: "First clause,\nsecond line.\r\n\r\n\n   \nSecond clause.\n\n\n"));

        expect(ContractClauses::render("First clause,\nsecond line.\r\n\r\n\n   \nSecond clause.\n\n\n", []))->toBe(["First clause,\nsecond line.", 'Second clause.'])
            ->and($html)->toContain('<td class="clause-num">1.</td>', 'First clause,<br>', '<td class="clause-num">2.</td>')
            ->and($html)->not->toContain('<td class="clause-num">3.</td>');
    });

    it('ships standard clauses that use known placeholders only', function () {
        expect(ContractClauses::unknownPlaceholders(ContractClauses::DEFAULT))->toBe([])
            ->and(ContractClauses::render(ContractClauses::DEFAULT, sampleLeaseContract()->placeholderValues()))->toHaveCount(9)
            ->and(strlen(ContractClauses::DEFAULT))->toBeLessThan(ContractClauses::MAX_LENGTH);
    });
});
