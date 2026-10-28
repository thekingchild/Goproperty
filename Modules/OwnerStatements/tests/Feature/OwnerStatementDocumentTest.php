<?php

declare(strict_types=1);

use App\Documents\DocumentLayout;
use App\Documents\DocumentRenderer;
use App\Documents\RenderTarget;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\OwnerStatements\Documents\OwnerStatementDocument;
use Modules\OwnerStatements\Documents\StatementPdfs;
use Modules\OwnerStatements\Statements\MonthStatement;
use Tests\Support\DocumentCssLint;
use Tests\Support\NarrowScreen;
use Tests\Support\PdfVisualDiff;

require_once __DIR__.'/statement-world.php';

/*
 * P08-05 AC: the monthly owner statement PDF (BRANDING_DOCUMENTS.md § 4:
 * period, rent collected per unit, commission, expenses, net due, payout
 * record) renders through the document pipeline in the agency's branding
 * and layout, with both drivers — dompdf always; the Chrome half is skipped
 * only where Gotenberg is unreachable (it runs in CI, P03 decision 6). A
 * settled month's PDF is kept once (evidence) with the branding of the day
 * it was settled.
 */

beforeEach(function () {
    Storage::fake('media');
    Notification::fake();
    statementWorld();
    setStatementTerms($this->acme, $this->owner, $this->ngoziAtAcme, ['commission' => '10']);
    $this->travelTo('2027-02-02 10:00:00');
});

afterEach(fn () => billsFreshRequest());

/** Adeyemi Realty's branding in the layout (as the owner portal's world, plus its letterhead). */
function statementBranding(object $test, string $layout): void
{
    invoiceBranding($test->acme, $test->owner, ['tradingName' => 'Adeyemi Realty', 'primaryColor' => '#0E6B5C', 'layout' => $layout]);
}

function statementDocument(object $test, string $month = '2027-01'): OwnerStatementDocument
{
    $statement = statementOf($test->acme, $test->ngoziAtAcme, $month);

    return inOrganization($test->acme, fn (): OwnerStatementDocument => app(StatementPdfs::class)->document($statement));
}

/** What January's statement prints, whatever the driver. */
function januaryStatementText(): array
{
    return ['Adeyemi Realty', '14 Admiralty Way', 'January 2027', 'Ngozi Eze', 'Marina Heights', 'Palm Court', 'Unit 1', 'Unit 2', 'Flat 3B',
        '1,770,000', '177,000', '40,000', '1,553,000', 'Replaced the bathroom tap', '10%'];
}

describe('acceptance: the owner statement PDF renders with the agency\'s branding, with both drivers', function () {
    it('prints on one A4 page with dompdf, in the agency\'s layout and name', function (DocumentLayout $layout) {
        statementBranding($this, $layout->value);
        $document = statementDocument($this);
        $pdf = app(DocumentRenderer::class)->pdf($document, 'dompdf');

        requirePdfTools();
        expect($document->view())->toBe('ownerstatements::documents.statement.'.$layout->value)
            ->and(strtolower($document->brand()->primary()))->toBe('#0e6b5c')
            ->and(PdfVisualDiff::rasterise($pdf))->toHaveCount(1)
            ->and(PdfVisualDiff::text($pdf))->toContain('OWNER STATEMENT', 'UNSETTLED', 'Not settled yet', 'Page 1 of 1', ...januaryStatementText())
            ->and(PdfVisualDiff::text($pdf))->not->toContain('Office printer', 'Cedar House', '+2348031230001');
    })->with([DocumentLayout::Modern, DocumentLayout::Classic, DocumentLayout::Compact]);

    it('prints the same with Chrome (Gotenberg) as with dompdf, within the threshold', function (DocumentLayout $layout) {
        requirePdfTools();
        requireGotenberg();
        statementBranding($this, $layout->value);
        $document = statementDocument($this);
        $renderer = app(DocumentRenderer::class);

        $diff = PdfVisualDiff::compare($renderer->pdf($document, 'dompdf'), $chrome = $renderer->pdf($document, 'chrome'));

        expect($diff['pages'])->toBe([1, 1])
            ->and(PdfVisualDiff::text($chrome))->toContain('Page 1 of 1', ...januaryStatementText())
            ->and($diff['score'])->toBeLessThan(PdfVisualDiff::THRESHOLD);
    })->with([DocumentLayout::Modern, DocumentLayout::Classic, DocumentLayout::Compact]);
});

it('keeps a settled month\'s PDF once, in the branding of the day it was settled, with the payout record', function () {
    statementBranding($this, 'modern');
    $settled = settleStatement($this->acme, $this->owner, $this->ngoziAtAcme, '2027-01', '2027-02-02', 'TRF-NGOZI-JAN');
    // Renamed afterwards: the settled statement keeps the name it was settled with.
    invoiceBranding($this->acme, $this->owner, ['tradingName' => 'Adeyemi Homes Renamed', 'primaryColor' => '#7A2E8C']);

    $first = agencyStatementPage($this->owner, $this->ngoziAtAcme, '/2027-01/pdf');
    $pdf = followInvoice($first);
    $again = followInvoice(agencyStatementPage($this->owner, $this->ngoziAtAcme, '/2027-01/pdf'));
    $owner = followInvoice(ownerPortalPage($this->ngozi, "/statements/{$this->ngoziAtAcme->id}/2027-01/pdf"));

    requirePdfTools();
    expect(PdfVisualDiff::text($pdf))->toContain('OWNER STATEMENT', 'SETTLED', 'Paid to the owner on 02/02/2027,', 'reference TRF-NGOZI-JAN.', 'Adeyemi Realty', '1,553,000')
        ->and(PdfVisualDiff::text($pdf))->not->toContain('Renamed')
        ->and($again)->toBe($pdf)
        ->and($owner)->toBe($pdf)
        ->and(inOrganization($this->acme, fn () => $settled->fresh()?->getMedia('issued_documents')->count()))->toBe(1)
        ->and(inOrganization($this->acme, fn () => $settled->fresh()?->getFirstMedia('issued_documents')?->getCustomProperty('document_key')))->toBe('owner-statement-v1');

    // Unsettled and settled again: a second kept PDF; the first stays.
    unsettleStatement($this->acme, $this->owner, $this->ngoziAtAcme, '2027-01');
    settleStatement($this->acme, $this->owner, $this->ngoziAtAcme, '2027-01', '2027-02-02', 'TRF-NGOZI-JAN-2');
    expect(PdfVisualDiff::text(followInvoice(agencyStatementPage($this->owner, $this->ngoziAtAcme, '/2027-01/pdf'))))->toContain('TRF-NGOZI-JAN-2', 'Adeyemi Homes Renamed')
        ->and(inOrganization($this->acme, fn () => $settled->fresh()?->getMedia('issued_documents')->count()))->toBe(2);
});

it('renders a month not settled now, every time, without keeping it', function () {
    statementBranding($this, 'compact');
    $pdf = agencyStatementPage($this->owner, $this->ngoziAtAcme, '/2027-01/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');

    requirePdfTools();
    expect(PdfVisualDiff::text((string) $pdf->getContent()))->toContain('UNSETTLED', ...januaryStatementText())
        ->and(Storage::disk('media')->allFiles())->toBe([]);
});

it('uses only table layouts and simple CSS as rendered, for every layout and target', function (DocumentLayout $layout, RenderTarget $target) {
    statementBranding($this, $layout->value);

    expect(DocumentCssLint::problems(app(DocumentRenderer::class)->html(statementDocument($this), $target)))->toBe([]);
})->with([DocumentLayout::Modern, DocumentLayout::Classic, DocumentLayout::Compact])->with(RenderTarget::cases());

it('lays the statement document out on a 360 px screen without sideways scrolling, in real Chrome', function (DocumentLayout $layout) {
    $gotenberg = requireGotenberg();
    statementBranding($this, $layout->value);
    $html = inOrganization($this->acme, fn () => app(StatementPdfs::class)->web(statementOf($this->acme, $this->ngoziAtAcme, '2027-01')));

    $screen = NarrowScreen::measure($gotenberg, $html, 360);

    expect($screen['viewport'])->toBe(360)->and($screen['overflow'])->toBe(0);
})->with([DocumentLayout::Modern, DocumentLayout::Classic, DocumentLayout::Compact]);

it('is a MonthStatement of the settled snapshot when settled', function () {
    settleStatement($this->acme, $this->owner, $this->ngoziAtAcme, '2027-01', '2027-02-02');

    expect(statementDocument($this)->month)->toBeInstanceOf(MonthStatement::class)
        ->and(statementDocument($this)->statusWord())->toBe('SETTLED')
        ->and(statementDocument($this, '2027-02')->statusWord())->toBe('IN PROGRESS');
});
