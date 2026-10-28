<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Documents;

use App\Documents\BrandingSnapshot;
use App\Documents\BrandingSnapshots;
use App\Documents\DocumentRenderer;
use App\Media\Media;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Modules\OwnerStatements\Statements\MonthStatement;

/**
 * The owner statement as a document (P08-05; BRANDING_DOCUMENTS.md § 4,
 * § 5), through Core's DocumentRenderer only:
 *
 * - a settled month: its snapshot in the branding kept when it was
 *   settled, prepared on the day it was settled, and its PDF rendered once
 *   and kept in the settlement's `issued_documents` media (evidence) under
 *   its own key per settling (`owner-statement-v{version}`), so a month
 *   unsettled and settled again keeps both PDFs;
 * - a month not settled: the figures and the branding now, rendered on
 *   each download and never kept (they may still change).
 *
 * Inside the managing organization.
 */
final readonly class StatementPdfs
{
    public function __construct(
        private DocumentRenderer $renderer,
        private BrandingSnapshots $brandings,
        private OrganizationContext $context,
    ) {}

    public function document(MonthStatement $month): OwnerStatementDocument
    {
        $settlement = $month->settlement;

        if ($month->isSettled() && $settlement !== null && is_array($settlement->branding_snapshot)) {
            return new OwnerStatementDocument($month, BrandingSnapshot::fromArray($settlement->branding_snapshot)->documentBranding(), $settlement->settled_at ?? CarbonImmutable::now());
        }

        return new OwnerStatementDocument($month, $this->brandings->take($this->context->get())->documentBranding(), CarbonImmutable::now());
    }

    /** A settled month's kept PDF (rendered the first time); null for a month not settled. */
    public function kept(MonthStatement $month): ?Media
    {
        $settlement = $month->settlement;

        if (! $month->isSettled() || $settlement === null) {
            return null;
        }

        return $this->renderer->stored($settlement, $settlement->documentKey(), $this->document($month));
    }

    /** The PDF of a month not settled, rendered now. */
    public function rendered(MonthStatement $month): string
    {
        return $this->renderer->pdf($this->document($month));
    }

    /** The statement as a web page (the same HTML as the PDF). */
    public function web(MonthStatement $month): string
    {
        return $this->renderer->web($this->document($month));
    }
}
