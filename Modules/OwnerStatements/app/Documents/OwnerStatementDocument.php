<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Documents;

use App\Documents\Document;
use App\Documents\DocumentBranding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\OwnerStatements\Statements\MonthStatement;
use Modules\OwnerStatements\Statements\OwnerStatement;

/**
 * The monthly owner statement (BRANDING_DOCUMENTS.md § 4 "Owner
 * statement", P08-05): the period, rent collected per unit, the agency's
 * commission, utilities collected (and whether they pass to the owner),
 * the expenses charged to the owner, the net due to the owner and the
 * payout record (settled on a date with a reference, or not yet), in the
 * agency's branding and layout. A settled month prints its snapshot with
 * the branding it was settled with (kept once as a PDF, evidence); an
 * unsettled month prints the figures worked out now, marked as such.
 * View `ownerstatements::documents.statement.{layout}`.
 */
final readonly class OwnerStatementDocument implements Document
{
    public function __construct(
        public MonthStatement $month,
        public DocumentBranding $brand,
        public CarbonImmutable $preparedAt,
    ) {}

    public function statement(): OwnerStatement
    {
        return $this->month->statement;
    }

    public function view(): string
    {
        return 'ownerstatements::documents.statement.'.$this->brand->layout()->value;
    }

    public function data(): array
    {
        return ['document' => $this, 'statement' => $this->month->statement, 'month' => $this->month];
    }

    public function brand(): DocumentBranding
    {
        return $this->brand;
    }

    public function fileName(): string
    {
        return 'owner-statement-'.$this->month->month()->key().'-'.Str::slug($this->month->statement->ownerName).'.pdf';
    }

    public function footerLine(): string
    {
        return $this->brand->name().' · Owner statement '.$this->month->month()->label();
    }

    /** "SETTLED", "UNSETTLED" or "IN PROGRESS". */
    public function statusWord(): string
    {
        return strtoupper($this->month->statusLabel());
    }
}
