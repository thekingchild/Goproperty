<?php

declare(strict_types=1);

use App\Branding\Branding;
use App\Documents\DocumentBranding;
use App\Documents\DocumentCounter;
use App\Documents\DocumentNumberer;
use App\Documents\DocumentType;
use App\Models\Organization;
use App\Tenancy\MissingOrganizationContext;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/documents.php';

/*
 * P03-04a: document numbers `{PREFIX}-{YYYY}-{seq}` from document_counters
 * (ARCHITECTURE.md § 6.5, BRANDING_DOCUMENTS.md § 1 and § 5). Concurrency,
 * real rollbacks and the refusal outside a transaction need committed rows
 * and are in tests/Drill/DocumentNumberingDrillTest.php.
 */

beforeEach(function () {
    $this->acme = Organization::factory()->create(['name' => 'Adeyemi Realty Ltd']);
    $this->other = Organization::factory()->create(['name' => 'Bello Homes Ltd']);
});

afterEach(fn () => app(OrganizationContext::class)->clear());

/** Takes a number in its own transaction, as an issuing module does, in that organization. */
function issueNumber(Organization $organization, DocumentType $type, ?DateTimeInterface $issuedAt = null, ?Branding $branding = null): string
{
    return app(OrganizationContext::class)->run(
        $organization,
        fn (): string => DB::transaction(fn (): string => app(DocumentNumberer::class)->next($type, $issuedAt, $branding)),
    );
}

it('formats numbers as {PREFIX}-{YYYY}-{seq} with the organization\'s default prefixes', function () {
    $issued = CarbonImmutable::parse('2026-11-01 09:00', 'Africa/Lagos');

    expect(issueNumber($this->acme, DocumentType::Invoice, $issued))->toBe('INV-ADR-2026-000001')
        ->and(issueNumber($this->acme, DocumentType::Receipt, $issued))->toBe('RCT-ADR-2026-000001')
        ->and(issueNumber($this->acme, DocumentType::LeaseContract, $issued))->toBe('LS-ADR-2026-000001')
        ->and(issueNumber($this->acme, DocumentType::Invoice, $issued))->toBe('INV-ADR-2026-000002')
        ->and(issueNumber($this->other, DocumentType::Invoice, $issued))->toBe('INV-BEH-2026-000001');
});

it('uses the prefixes saved in the organization\'s branding', function () {
    $this->acme->forceFill(['branding' => ['invoice_prefix' => 'ADR-INV', 'receipt_prefix' => 'R-01', 'lease_prefix' => 'TENANCY']])->save();
    $issued = CarbonImmutable::parse('2027-03-15 12:00', 'Africa/Lagos');

    expect(issueNumber($this->acme, DocumentType::Invoice, $issued))->toBe('ADR-INV-2027-000001')
        ->and(issueNumber($this->acme, DocumentType::Receipt, $issued))->toBe('R-01-2027-000001')
        ->and(issueNumber($this->acme, DocumentType::LeaseContract, $issued))->toBe('TENANCY-2027-000001');
});

it('takes the prefix from the branding the document is issued with, when given', function () {
    $this->acme->forceFill(['branding' => ['invoice_prefix' => 'INV-NOW']])->save();
    $snapshot = sampleBranding(changes: ['invoice_prefix' => 'INV-SNAP']);
    $issued = CarbonImmutable::parse('2026-11-01 09:00', 'Africa/Lagos');

    expect(issueNumber($this->acme, DocumentType::Invoice, $issued, $snapshot))->toBe('INV-SNAP-2026-000001')
        ->and(issueNumber($this->acme, DocumentType::Invoice, $issued))->toBe('INV-NOW-2026-000002');
});

it('pads the sequence to six digits and goes on past 999999', function () {
    $issued = CarbonImmutable::parse('2026-06-01 09:00', 'Africa/Lagos');
    issueNumber($this->acme, DocumentType::Invoice, $issued);

    app(OrganizationContext::class)->run($this->acme, fn () => DocumentCounter::query()->firstOrFail()->forceFill(['last_seq' => 999_998])->save());

    expect(issueNumber($this->acme, DocumentType::Invoice, $issued))->toBe('INV-ADR-2026-999999')
        ->and(issueNumber($this->acme, DocumentType::Invoice, $issued))->toBe('INV-ADR-2026-1000000')
        ->and(DocumentNumberer::format('INV-ADR', 2027, 142))->toBe('INV-ADR-2027-000142')
        ->and((new DocumentBranding(sampleBranding(), 'GoProperty', true))->number('RCT-ADR', 2027, 318))->toBe('RCT-ADR-2027-000318');
});

it('counts each organization separately', function () {
    $issued = CarbonImmutable::parse('2026-11-01 09:00', 'Africa/Lagos');

    $numbers = [];

    foreach ([$this->acme, $this->other, $this->acme, $this->acme, $this->other] as $organization) {
        $numbers[] = issueNumber($organization, DocumentType::Invoice, $issued);
    }

    expect($numbers)->toBe(['INV-ADR-2026-000001', 'INV-BEH-2026-000001', 'INV-ADR-2026-000002', 'INV-ADR-2026-000003', 'INV-BEH-2026-000002']);

    $counters = DB::table('document_counters')->orderBy('organization_id')->get(['organization_id', 'doc_type', 'year', 'last_seq']);

    expect($counters->map(fn (object $row): array => [(int) $row->organization_id, $row->doc_type, (int) $row->year, (int) $row->last_seq])->all())
        ->toBe([[$this->acme->id, 'invoice', 2026, 3], [$this->other->id, 'invoice', 2026, 2]]);
});

it('counts each document type separately', function () {
    $issued = CarbonImmutable::parse('2026-11-01 09:00', 'Africa/Lagos');

    expect(issueNumber($this->acme, DocumentType::Invoice, $issued))->toBe('INV-ADR-2026-000001')
        ->and(issueNumber($this->acme, DocumentType::Invoice, $issued))->toBe('INV-ADR-2026-000002')
        ->and(issueNumber($this->acme, DocumentType::Receipt, $issued))->toBe('RCT-ADR-2026-000001')
        ->and(issueNumber($this->acme, DocumentType::Invoice, $issued))->toBe('INV-ADR-2026-000003')
        ->and(issueNumber($this->acme, DocumentType::Receipt, $issued))->toBe('RCT-ADR-2026-000002');
});

it('starts again at 1 each year, the year of issue in Lagos', function () {
    expect(issueNumber($this->acme, DocumentType::Invoice, CarbonImmutable::parse('2026-03-01 09:00', 'Africa/Lagos')))->toBe('INV-ADR-2026-000001')
        ->and(issueNumber($this->acme, DocumentType::Invoice, CarbonImmutable::parse('2026-12-31 22:59', 'UTC')))->toBe('INV-ADR-2026-000002')
        // 23:30 UTC on 31 December is 00:30 on 1 January in Lagos.
        ->and(issueNumber($this->acme, DocumentType::Invoice, CarbonImmutable::parse('2026-12-31 23:30', 'UTC')))->toBe('INV-ADR-2027-000001')
        ->and(issueNumber($this->acme, DocumentType::Invoice, CarbonImmutable::parse('2027-01-02 10:00', 'Africa/Lagos')))->toBe('INV-ADR-2027-000002')
        // A late document for last year goes on with last year's sequence.
        ->and(issueNumber($this->acme, DocumentType::Invoice, CarbonImmutable::parse('2026-12-31 18:00', 'Africa/Lagos')))->toBe('INV-ADR-2026-000003');
});

it('numbers maintenance tickets TKT-000001 per organization, one sequence that never restarts (P04-01)', function () {
    $this->acme->forceFill(['branding' => ['invoice_prefix' => 'ADR-INV']])->save();

    expect(issueNumber($this->acme, DocumentType::Ticket, CarbonImmutable::parse('2026-12-31 22:00', 'Africa/Lagos')))->toBe('TKT-000001')
        ->and(issueNumber($this->acme, DocumentType::Ticket, CarbonImmutable::parse('2027-01-01 09:00', 'Africa/Lagos')))->toBe('TKT-000002')
        ->and(issueNumber($this->other, DocumentType::Ticket))->toBe('TKT-000001')
        ->and(issueNumber($this->acme, DocumentType::Invoice, CarbonImmutable::parse('2027-01-01 09:00', 'Africa/Lagos')))->toBe('ADR-INV-2027-000001')
        ->and(DocumentType::Ticket->yearly())->toBeFalse()
        ->and(DocumentType::Invoice->yearly())->toBeTrue();

    $counters = DB::table('document_counters')->where('doc_type', 'ticket')->orderBy('organization_id')->get(['organization_id', 'year', 'last_seq']);
    expect($counters->map(fn (object $row): array => [(int) $row->organization_id, (int) $row->year, (int) $row->last_seq])->all())
        ->toBe([[$this->acme->id, 0, 2], [$this->other->id, 0, 1]]);
});

it('takes the year from now in Lagos when no date is given', function () {
    $this->travelTo(CarbonImmutable::parse('2027-12-31 23:30', 'UTC'));

    expect(issueNumber($this->acme, DocumentType::Receipt))->toBe('RCT-ADR-2028-000001');
});

it('does not use up a number in a rolled-back transaction (inside an outer one)', function () {
    $issued = CarbonImmutable::parse('2026-11-01 09:00', 'Africa/Lagos');

    expect(issueNumber($this->acme, DocumentType::Invoice, $issued))->toBe('INV-ADR-2026-000001');

    app(OrganizationContext::class)->run($this->acme, function () use ($issued) {
        DB::transaction(function () use ($issued) {
            try {
                DB::transaction(function () use ($issued) {
                    expect(app(DocumentNumberer::class)->next(DocumentType::Invoice, $issued))->toBe('INV-ADR-2026-000002')
                        ->and(app(DocumentNumberer::class)->next(DocumentType::Receipt, $issued))->toBe('RCT-ADR-2026-000001');

                    throw new DomainException('The bill failed to save.');
                });
            } catch (DomainException) {
            }

            expect(app(DocumentNumberer::class)->next(DocumentType::Invoice, $issued))->toBe('INV-ADR-2026-000002');
        });
    });

    expect(issueNumber($this->acme, DocumentType::Receipt, $issued))->toBe('RCT-ADR-2026-000001')
        ->and(issueNumber($this->acme, DocumentType::Invoice, $issued))->toBe('INV-ADR-2026-000003');
});

it('needs an organization context', function () {
    DB::transaction(function () {
        expect(fn () => app(DocumentNumberer::class)->next(DocumentType::Invoice))->toThrow(MissingOrganizationContext::class);
    });

    expect(DB::table('document_counters')->count())->toBe(0);
});

it('keeps one counter per organization, type and year in the database', function () {
    app(OrganizationContext::class)->run($this->acme, function () {
        DocumentCounter::query()->create(['doc_type' => DocumentType::Invoice, 'year' => 2026, 'last_seq' => 4]);

        expect(fn () => DocumentCounter::query()->create(['doc_type' => DocumentType::Invoice, 'year' => 2026, 'last_seq' => 0]))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    app(OrganizationContext::class)->run($this->other, function () {
        expect(DocumentCounter::query()->create(['doc_type' => DocumentType::Invoice, 'year' => 2026])->exists)->toBeTrue();
    });
});

it('lists the numbered document types with their branding prefixes', function () {
    $branding = sampleBranding();

    // Maintenance tickets (P04-01) are numbered with the fixed prefix TKT, not the branding's.
    expect(DocumentType::values())->toBe(['invoice', 'receipt', 'lease_contract', 'ticket'])
        ->and(array_map(fn (DocumentType $type): string => $type->prefix($branding), DocumentType::cases()))->toBe(['INV-ADR', 'RCT-ADR', 'LS-ADR', 'TKT'])
        ->and(array_map(fn (DocumentType $type): string => $type->label(), DocumentType::cases()))->toBe(['Invoice', 'Receipt', 'Lease contract', 'Maintenance ticket']);
});
