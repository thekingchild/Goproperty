<?php

declare(strict_types=1);

use App\Documents\DocumentLayout;
use App\Documents\DocumentRenderer;
use App\Documents\RenderTarget;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Media\Media;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Modules\Reports\Documents\PortfolioReportDocument;
use Modules\Reports\Reports\ReportFilters;
use Tests\Support\DocumentCssLint;

require_once __DIR__.'/report-world.php';
require_once __DIR__.'/../../../Properties/tests/Feature/Assignments/helpers.php';
require_once __DIR__.'/../../../../tests/Support/documents.php';

/*
 * P08-01: the Reports page (/o/{slug}/reports) — who opens it, its filters
 * (presets, dates, building), its PDF, and that a member narrowed to
 * buildings never sees more through it. The figures are in the other
 * files of this folder.
 */

beforeEach(function () {
    Notification::fake();
    statementWorld();
    $this->admin = joinAs(User::factory()->create(['name' => 'Bola Admin']), $this->acme, OrganizationRole::Admin);
    $this->agent = joinAs(User::factory()->create(['name' => 'Chidi Agent']), $this->acme, OrganizationRole::Agent);
});

afterEach(fn () => exportsFreshRequest());

it('opens for owners and administrators, with the sidebar link; agents are refused', function () {
    reportsPage($this->owner)->assertOk()->assertSee('data-nav="reports"', false)->assertSee('Collections vs billed')->assertSee('Arrears aging')
        ->assertSee('Upcoming renewals')->assertSee('Occupancy')->assertSee('Expenses by category')->assertSee('Building performance');
    reportsPage($this->admin)->assertOk()->assertSee('data-test="report-pdf"', false);

    // Agents hold no `reports.view`: no link, no page, no PDF.
    exportsFreshRequest();
    $this->actingAs($this->agent)->get('/o/acme-homes/buildings')->assertOk()->assertDontSee('data-nav="reports"', false);
    reportsPage($this->agent)->assertForbidden();
    reportsPdf($this->agent)->assertForbidden();
});

it('answers 404 to the landlord, to another organization\'s members and for another organization, and sends guests to sign in', function () {
    // Ngozi is `property-owner` at Adeyemi: she reads her statements in /owner, never the workspace.
    reportsPage($this->ngozi)->assertNotFound();
    reportsPdf($this->ngozi)->assertNotFound();
    reportsPage($this->otherOwner)->assertNotFound();
    reportsPdf($this->otherOwner)->assertNotFound();
    reportsPage($this->owner, '', 'other-realty')->assertNotFound();

    exportsFreshRequest();
    $this->get('/o/acme-homes/reports')->assertRedirect('/login');
});

it('cleans the filters: presets, dates out of order or after today, more than 24 months, nonsense', function () {
    $today = CarbonImmutable::parse('2027-01-26', 'Africa/Lagos');
    $range = fn (array $input) => [ReportFilters::fromInput($input, $today)->from->toDateString(), ReportFilters::fromInput($input, $today)->to->toDateString()];

    expect($range([]))->toBe(['2027-01-01', '2027-01-26'])
        ->and($range(['from' => 'yesterday', 'to' => '2027-01-10']))->toBe(['2027-01-01', '2027-01-26'])
        ->and($range(['from' => '2027-02-30', 'to' => '2027-03-01']))->toBe(['2027-01-01', '2027-01-26'])
        ->and($range(['from' => '2027-01-20', 'to' => '2026-12-01']))->toBe(['2026-12-01', '2027-01-20'])
        ->and($range(['from' => '2026-12-01', 'to' => '2027-06-30']))->toBe(['2026-12-01', '2027-01-26'])
        ->and($range(['from' => '2020-01-01', 'to' => '2027-01-26']))->toBe(['2025-01-27', '2027-01-26'])
        ->and($range(['from' => '1999-01-01', 'to' => '1999-06-01']))->toBe(['2000-01-01', '2000-01-01'])
        ->and(ReportFilters::fromInput(['building' => 'abc', 'days' => '7'], $today)->toArray())->toBe(['from' => '2027-01-01', 'to' => '2027-01-26', 'days' => 90]);

    // The page's preset picker sets the dates (and the URL); the dates name their preset.
    $response = reportsCall($this->owner, '', ['period' => 'last_month']);
    expect(reportState($response, 'from'))->toBe('2026-12-01')
        ->and(reportState($response, 'to'))->toBe('2026-12-31');
    reportsPage($this->owner, 'from=2026-12-01&to=2026-12-31')->assertOk()->assertSee('1 Dec 2026 – 31 Dec 2026');
    reportsPage($this->owner, 'from=2026-12-01&to=2027-09-30')->assertOk()->assertSee('1 Dec 2026 – 26 Jan 2027');

    // A building of another organization (or none at all) is dropped: every building.
    $page = reportsCall($this->owner, '', ['building' => (string) $this->lagoon->id]);
    expect(reportState($page, 'building'))->toBe('');
    reportsPage($this->owner, 'building='.$this->lagoon->id)->assertOk()->assertDontSee('Lagoon View');
});

it('shows a member narrowed to buildings only those buildings, never the organization\'s own money', function () {
    // An agent assigned to Palm Court, given `reports.view` directly.
    assignIn($this->acme, $this->agent, $this->palm);
    inOrganization($this->acme, fn () => $this->agent->givePermissionTo('reports.view'));
    recordExpense($this->acme, $this->owner, ['category' => 'staff', 'description' => 'Office assistant, January', 'amount' => '90,000', 'spentOn' => '2027-01-15']);

    $report = reportOf($this->acme, $this->agent, ['from' => '2027-01-01', 'to' => '2027-01-26']);
    expect(array_map(fn ($building) => $building->name, $report->occupancy->buildings))->toBe(['Palm Court'])
        ->and($report->collections->totals->collectedTotal()->kobo)->toBe(150_000_000)
        ->and($report->expenses->total()->kobo)->toBe(6_000_000)
        ->and($report->expenses->includesOrganization)->toBeFalse()
        ->and(array_map(fn ($line) => $line->name, $report->buildings->lines))->toBe(['Palm Court']);

    // Asking for Marina Heights: the page drops it, the PDF answers 404.
    reportsPage($this->agent, 'from=2027-01-01&to=2027-01-26&building='.$this->marina->id)->assertOk()->assertSee('₦1,500,000')->assertDontSee('Marina Heights');
    reportsPdf($this->agent, 'building='.$this->marina->id)->assertNotFound();
    reportsPdf($this->agent)->assertOk();
});

it('downloads the page as a PDF in the agency\'s branding, for the filters, rendered each time and never kept', function () {
    $media = inOrganization($this->acme, fn () => Media::query()->count());
    $response = reportsPdf($this->owner, 'from=2026-12-01&to=2027-01-26&building='.$this->marina->id)->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Cache-Control', 'no-store, private');
    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename="report-2026-12-01-to-2027-01-26-marina-heights.pdf"')
        ->and(str_starts_with((string) $response->getContent(), '%PDF-'))->toBeTrue()
        ->and(inOrganization($this->acme, fn () => Media::query()->count()))->toBe($media);

    // The document's text: the period, the building, the figures; the agency's name.
    $report = reportOf($this->acme, $this->owner, ['from' => '2026-12-01', 'to' => '2027-01-26', 'building' => (string) $this->marina->id]);
    $document = new PortfolioReportDocument($report, sampleBrand(), CarbonImmutable::now());
    $html = app(DocumentRenderer::class)->web($document);
    expect($html)->toContain('REPORT', '1 Dec 2026 – 26 Jan 2027', 'Marina Heights', '₦725,000', '₦295,000', 'Bisi Adeyinka')
        ->not->toContain('+2348031230001', 'bisi.adeyinka@example.com', 'GP-');
});

it('prints with table layouts and simple CSS only, in every layout and target', function (DocumentLayout $layout, RenderTarget $target) {
    $report = reportOf($this->acme, $this->owner, ['from' => '2026-12-01', 'to' => '2027-01-26']);
    $html = app(DocumentRenderer::class)->html(new PortfolioReportDocument($report, sampleBrand($layout), CarbonImmutable::now()), $target);

    expect(DocumentCssLint::problems($html))->toBe([]);
})->with(DocumentLayout::cases())->with(RenderTarget::cases());

it('stays open while the organization is suspended, exports included', function () {
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    reportsPage($this->owner)->assertOk();
    reportsPdf($this->owner)->assertOk();
    expect(count(reportRows($this->owner, 'report_buildings')))->toBeGreaterThan(1);
});

it('lets GoProperty staff read and export in a read-only support session', function () {
    seedStaffRoles();
    $handOff = startSupport(staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']), $this->owner, $this->acme, ImpersonationMode::ReadOnly);
    enterSupport($handOff)->assertRedirect('/o/acme-homes');

    asSupport()->get('/o/acme-homes/reports')->assertOk()->assertSee('Arrears aging');
    asSupport()->get('/o/acme-homes/reports/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
});
