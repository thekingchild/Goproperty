<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Exports\ListExportRequests;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Modules\Properties\Enums\PropertyType;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Enums\UnitType;
use Modules\Properties\Models\Unit;
use Modules\SaasBilling\Transfers\BillingTransferService;

require_once __DIR__.'/helpers.php';

/*
 * P02-07 AC "exports respect the current filters and org scope": an export
 * holds exactly the rows the member sees on the page with the filters and
 * search set now (on every page of it, in the page's order), never another
 * organization's, and a narrowed agent's export only their buildings
 * (P02-03). Columns: money from kobo, times in Lagos, statuses by label.
 */

beforeEach(function () {
    Notification::fake();
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Other Realty']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    $this->otherOwner = joinAs(User::factory()->create(['name' => 'Chidi Owner']), $this->other);

    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court', 'property_type' => PropertyType::BlockOfFlats, 'state' => 'Lagos']);
    $this->cedar = buildingIn($this->acme, ['name' => 'Cedar Gardens', 'slug' => 'cedar-gardens', 'property_type' => PropertyType::Commercial, 'state' => 'Oyo']);
    inOrganization($this->acme, function (): void {
        foreach (range(1, 20) as $i) {
            Unit::factory()->in($this->palm)->create(['unit_label' => "Flat {$i}", 'status' => $i <= 4 ? UnitStatus::Occupied : UnitStatus::Vacant]);
        }
        foreach (range(1, 10) as $i) {
            Unit::factory()->in($this->cedar)->create(['unit_label' => "Shop {$i}", 'unit_type' => UnitType::Shop]);
        }
    });

    // Another organization with the same names.
    $this->otherPalm = buildingIn($this->other, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    inOrganization($this->other, function (): void {
        foreach (range(1, 5) as $i) {
            Unit::factory()->in($this->otherPalm)->create(['unit_label' => "Flat {$i}", 'status' => UnitStatus::Occupied]);
        }
    });
});

afterEach(fn () => exportsFreshRequest());

it('exports exactly the rows the page shows with its current filters, on every page and in its order, never another organization\'s', function () {
    // No filter: all 30 units, though the page shows 25 at a time, in the page's order.
    $rows = exportRows($this->owner, '/units', 'units');
    $labels = exportColumn($rows, 'Unit');
    expect($labels)->toHaveCount(30)
        ->and(array_slice($labels, 0, 12))->toBe(['Shop 1', 'Shop 2', 'Shop 3', 'Shop 4', 'Shop 5', 'Shop 6', 'Shop 7', 'Shop 8', 'Shop 9', 'Shop 10', 'Flat 1', 'Flat 2'])
        ->and(array_values(array_unique(exportColumn($rows, 'Building'))))->toBe(['Cedar Gardens', 'Palm Court']);
    $page = inOrganization($this->acme, fn () => Unit::query()->whereKey(listedUnits(unitListPage($this->owner)))->pluck('unit_label', 'id'));
    expect(array_slice($labels, 0, 25))->toBe(array_map(fn (int $id): string => $page[$id], listedUnits(unitListPage($this->owner))));

    // Filters typed on the page, and filters from the URL.
    expect(exportColumn(exportRows($this->owner, '/units', 'units', 'csv', ['status' => 'occupied']), 'Unit'))->toBe(['Flat 1', 'Flat 2', 'Flat 3', 'Flat 4'])
        ->and(exportColumn(exportRows($this->owner, '/units', 'units', 'xlsx', ['building' => (string) $this->cedar->id, 'search' => 'shop 1']), 'Unit'))->toBe(['Shop 1', 'Shop 10'])
        ->and(exportColumn(exportRows($this->owner, '/units?type=shop&q=cedar', 'units'), 'Unit'))->toHaveCount(10)
        // A value the list does not know is ignored, as on the page.
        ->and(exportColumn(exportRows($this->owner, '/units?status=nonsense', 'units'), 'Unit'))->toHaveCount(30);

    // Another organization's building id gives nothing, not its units.
    expect(exportRows($this->owner, '/units', 'units', 'csv', ['building' => (string) $this->otherPalm->id]))->toHaveCount(1);

    // The other organization exports only its own.
    expect(exportColumn(exportRows($this->otherOwner, '/units', 'units', 'csv', [], 'other-realty'), 'Unit'))->toBe(['Flat 1', 'Flat 2', 'Flat 3', 'Flat 4', 'Flat 5']);

    // Buildings: type, state and archived filters.
    expect(exportColumn(exportRows($this->owner, '/buildings', 'buildings'), 'Building name'))->toBe(['Cedar Gardens', 'Palm Court'])
        ->and(exportColumn(exportRows($this->owner, '/buildings?state=Lagos', 'buildings'), 'Building name'))->toBe(['Palm Court'])
        ->and(exportColumn(exportRows($this->owner, '/buildings', 'buildings', 'csv', ['type' => 'commercial']), 'Building name'))->toBe(['Cedar Gardens'])
        ->and(exportRows($this->owner, '/buildings', 'buildings', 'csv', ['archived' => true]))->toHaveCount(1);

    // The building page's status board: its own units, with its status filter.
    expect(exportColumn(exportRows($this->owner, '/buildings/palm-court?status=occupied', 'units'), 'Unit'))->toBe(['Flat 1', 'Flat 2', 'Flat 3', 'Flat 4'])
        ->and(exportColumn(exportRows($this->owner, '/buildings/cedar-gardens', 'units'), 'Unit'))->toHaveCount(10);
});

it('exports the owners the page shows: search, archived, and never another organization\'s', function () {
    ownerIn($this->acme, ['first_name' => 'Adaeze', 'last_name' => 'Okafor', 'phone' => '+2348031234567']);
    $archived = ownerIn($this->acme, ['first_name' => 'Bola', 'last_name' => 'Adeyemi']);
    inOrganization($this->acme, fn () => $archived->delete());
    ownerIn($this->other, ['first_name' => 'Adaeze', 'last_name' => 'Okafor']);

    expect(exportColumn(exportRows($this->owner, '/owners', 'owners'), 'Name'))->toBe(['Adaeze Okafor'])
        ->and(exportColumn(exportRows($this->owner, '/owners', 'owners', 'csv', ['search' => '0803 123']), 'Phone'))->toBe(['+2348031234567'])
        ->and(exportRows($this->owner, '/owners', 'owners', 'csv', ['search' => 'nobody']))->toHaveCount(1)
        ->and(exportColumn(exportRows($this->owner, '/owners?archived=1', 'owners'), 'Name'))->toBe(['Bola Adeyemi'])
        ->and(exportColumn(exportRows($this->otherOwner, '/owners', 'owners', 'csv', [], 'other-realty'), 'Name'))->toBe(['Adaeze Okafor']);
});

it('gives a narrowed agent only the buildings assigned to them, in every list', function () {
    $agent = joinAs(User::factory()->create(['name' => 'Bola Agent']), $this->acme, OrganizationRole::Agent);
    $free = joinAs(User::factory()->create(['name' => 'Emeka Agent']), $this->acme, OrganizationRole::Agent);
    $palmOwner = ownerIn($this->acme, ['first_name' => 'Ngozi', 'last_name' => 'Eze']);
    $cedarOwner = ownerIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bello']);
    inOrganization($this->acme, function () use ($palmOwner, $cedarOwner): void {
        $this->palm->update(['owner_profile_id' => $palmOwner->id]);
        $this->cedar->update(['owner_profile_id' => $cedarOwner->id]);
    });
    assignIn($this->acme, $agent, $this->palm);

    expect(exportColumn(exportRows($agent, '/units', 'units'), 'Building'))->toHaveCount(20)->each->toBe('Palm Court')
        ->and(exportRows($agent, '/units', 'units', 'xlsx', ['building' => (string) $this->cedar->id]))->toHaveCount(1)
        ->and(exportColumn(exportRows($agent, '/buildings', 'buildings'), 'Building name'))->toBe(['Palm Court'])
        ->and(exportColumn(exportRows($agent, '/owners', 'owners'), 'Name'))->toBe(['Ngozi Eze'])
        ->and(exportColumn(exportRows($agent, '/owners', 'owners'), 'Buildings'))->toBe(['1'])
        ->and(exportColumn(exportRows($agent, '/buildings/palm-court', 'building_agents'), 'Agent'))->toBe(['Bola Agent']);

    // Another building is not theirs to export, however it is asked for.
    exportPage($agent, '/buildings/cedar-gardens')->assertNotFound();
    exportsFreshRequest();
    $this->actingAs($agent);
    expect(fn () => inOrganization($this->acme, fn () => app(ListExportRequests::class)->start($agent, 'building_agents', 'csv', ['building' => $this->cedar->id])))
        ->toThrow(fn (AuthorizationException $refused) => expect($refused->status())->toBe(404));

    // An agent with no assignment sees, and exports, every building.
    expect(exportColumn(exportRows($free, '/units', 'units'), 'Unit'))->toHaveCount(30)
        ->and(exportColumn(exportRows($free, '/owners', 'owners'), 'Name'))->toBe(['Ngozi Eze', 'Tunde Bello']);
});

it('exports the billing offers made and received by this organization only', function () {
    $third = Organization::factory()->create(['slug' => 'third-homes', 'name' => 'Third Homes']);
    $thirdOwner = joinAs(User::factory()->create(), $third);
    inOrganization($this->acme, fn () => app(BillingTransferService::class)->initiate($this->owner, $this->other));
    inOrganization($this->other, fn () => app(BillingTransferService::class)->initiate($this->otherOwner, $this->acme));
    // An offer between two other organizations.
    inOrganization($third, fn () => app(BillingTransferService::class)->initiate($thirdOwner, $this->other));

    $made = exportRows($this->owner, '/billing-transfers', 'billing_transfers_made');
    $received = exportRows($this->owner, '/billing-transfers', 'billing_transfers_received', 'xlsx');

    expect(exportColumn($made, 'Offered to'))->toBe(['Other Realty'])
        ->and(exportColumn($made, 'Status'))->toBe(['Waiting for an answer'])
        ->and(exportColumn($received, 'Offered by organization'))->toBe(['Other Realty'])
        ->and(exportColumn($received, 'Offered by'))->toBe(['Chidi Owner'])
        ->and(exportColumn(exportRows($this->otherOwner, '/billing-transfers', 'billing_transfers_received', 'csv', [], 'other-realty'), 'Offered by organization'))->toBe(['Third Homes', 'Acme Homes']);
});

it('writes money from kobo, times in Lagos, and enums by their label', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-27 23:30:00', 'UTC'));
    inOrganization($this->acme, fn () => Unit::factory()->in($this->palm)->create([
        'unit_label' => 'Penthouse',
        'unit_type' => UnitType::Apartment,
        'bedrooms' => 3,
        'base_rent_kobo' => Money::kobo(150_000_050),
        'service_charge_kobo' => Money::zero(),
        'status' => UnitStatus::UnderMaintenance,
    ]));

    foreach (['csv', 'xlsx'] as $format) {
        $rows = exportRows($this->owner, '/units', 'units', $format, ['search' => 'Penthouse']);

        expect(array_combine($rows[0], $rows[1]))->toMatchArray([
            'Building' => 'Palm Court',
            'Unit' => 'Penthouse',
            'Type' => UnitType::Apartment->label(),
            'Bedrooms' => '3',
            'Rent per year' => '1500000.50',
            'Service charge per year' => '0.00',
            'Status' => 'Under maintenance',
            'Building archived' => 'No',
            // 23:30 UTC is 00:30 the next day in Lagos (UTC+1).
            'Added on' => '2026-10-28 00:30',
        ]);
    }

    // Numbers stay numbers in Excel; text is never a formula.
    $download = openDownload($this->owner, pressExport($this->owner, '/units', 'units', 'xlsx', ['search' => 'Penthouse']));
    $xml = xlsxSheetXml($download->streamedContent());
    expect($xml)->toContain('<v>3</v>')
        ->not->toContain('<f>');
});

it('names the file after the organization, the list and the Lagos time', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-27 13:05:00', 'UTC'));

    $download = openDownload($this->owner, pressExport($this->owner, '/units', 'units', 'xlsx'))->assertOk();

    expect($download->headers->get('Content-Disposition'))->toBe('attachment; filename="acme-homes-units-2026-10-27-1405.xlsx"')
        ->and($download->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($download->headers->get('Cache-Control'))->toContain('no-store');

    $csv = openDownload($this->owner, pressExport($this->owner, '/owners', 'owners'))->assertOk();
    expect($csv->headers->get('Content-Disposition'))->toBe('attachment; filename="acme-homes-owners-2026-10-27-1405.csv"')
        ->and($csv->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8');
});
