<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Import\Batches\ImportRefused;
use Modules\Import\Contracts\ImportTargets;
use Modules\Import\Enums\ImportStatus;
use Modules\Properties\Import\OwnersImportTarget;
use Modules\Properties\Import\PropertiesImportTarget;
use Modules\Properties\Import\UnitsImportTarget;
use Modules\Properties\Models\Unit;

require_once __DIR__.'/helpers.php';

/*
 * P02-06c (IMPLEMENTATION_PLAN.md P02-06 AC): owners, buildings and units
 * arrive by import with the tenants, as one 2,000-row workbook through the
 * real engine (upload → dry run → queued commit → rollback). The fixture
 * workbook is generated here: 100 owners, 200 buildings, 1,500 units and
 * 200 tenants.
 */

beforeEach(function () {
    Storage::fake('media');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
});

afterEach(function () {
    app(OrganizationContext::class)->clear();
});

it('is registered into the import engine by the Properties module, owners → buildings → units → tenants', function () {
    $targets = app(ImportTargets::class);

    expect($targets->find('owners'))->toBeInstanceOf(OwnersImportTarget::class)
        ->and($targets->find('properties'))->toBeInstanceOf(PropertiesImportTarget::class)
        ->and($targets->find('units'))->toBeInstanceOf(UnitsImportTarget::class)
        ->and(array_map(fn ($target) => [$target->key(), $target->label(), $target->order()], $targets->all()))->toBe([
            ['owners', 'Owners', 10],
            ['properties', 'Buildings', 20],
            ['units', 'Units', 30],
            ['tenants', 'Tenants', 40],
            ['leases', 'Leases', 50], // the Leasing module's (P03-13a), after them
        ])
        ->and(array_column(array_map(fn ($c) => (array) $c, $targets->find('owners')->columns()), 'label'))->toBe(PORTFOLIO_OWNERS)
        ->and(array_column(array_map(fn ($c) => (array) $c, $targets->find('properties')->columns()), 'label'))->toBe(PORTFOLIO_BUILDINGS)
        ->and(array_column(array_map(fn ($c) => (array) $c, $targets->find('units')->columns()), 'label'))->toBe(PORTFOLIO_UNITS);
});

it('dry runs a 2,000-row workbook without saving anything, and returns the exact errors of each row', function () {
    $sheets = portfolioFixture();
    expect(array_sum(array_map(fn (array $rows): int => count($rows) - 1, $sheets)))->toBe(2000);

    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql) === 1
            && ! preg_match('/`(import_batches|activity_log|audit_chain_head|media|cache)`/', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    // A clean workbook passes, and nothing is saved.
    $clean = portfolioDryRun($this->acme, $this->owner, portfolioWorkbook($sheets));

    expect($writes)->toBe([])
        ->and(portfolioTables())->toEqual(['owner_profiles' => [], 'properties' => [], 'units' => [], 'tenant_profiles' => []])
        ->and($clean->status)->toBe(ImportStatus::DryRunPassed)
        ->and($clean->dry_run_report['rows'])->toBe(2000)
        ->and($clean->dry_run_report['errors'])->toBe(0)
        ->and($clean->dry_run_report['units'])->toBe(1500)
        ->and(array_column($clean->dry_run_report['sheets'], 'target'))->toBe(['owners', 'properties', 'units', 'tenants'])
        ->and(array_column($clean->dry_run_report['sheets'], 'rows'))->toBe([100, 200, 1500, 200])
        ->and(portfolioCounts($clean, 'owners'))->toEqual(['create' => 100, 'update' => 0, 'restore' => 0, 'skip' => 0])
        ->and(portfolioCounts($clean, 'properties'))->toEqual(['create' => 200, 'update' => 0, 'restore' => 0, 'skip' => 0])
        ->and(portfolioCounts($clean, 'units'))->toEqual(['create' => 1500, 'update' => 0, 'restore' => 0, 'skip' => 0])
        ->and(portfolioCounts($clean, 'tenants'))->toEqual(['create' => 200, 'update' => 0, 'restore' => 0, 'skip' => 0])
        // The one warning: the units raise the bill.
        ->and(portfolioIssues($clean, 'units'))->toBe([[2, null, null, 'warning', 'Each unit this import adds or brings back counts towards your GoProperty bill. You have 0 billable units now; the summary shows the total and the monthly price with this file\'s units.']]);

    // Bad cells across the sheets (array index n = owner/building/unit/tenant n = spreadsheet row n + 1).
    $sheets['Owners'][50][3] = '0803 12';            // row 51: not a phone number
    $sheets['Owners'][99][1] = '';                   // row 100: no name
    $sheets['Buildings'][150][2] = 'Nobody Known';   // row 151: an owner nobody has
    $sheets['Buildings'][180][5] = 'Atlantis';       // row 181: not a state
    $sheets['Units'][1][8] = '-5,000';               // row 2: negative rent
    $sheets['Units'][2][4] = 'three';                // row 3: bedrooms not a number
    $sheets['Units'][3][3] = 'Castle';               // row 4: unknown unit type
    $sheets['Units'][5][1] = 'Building 999';         // row 6: unknown building
    $sheets['Units'][204][2] = 'Flat 1';             // row 205: Building 4's Flat 1 is on row 5 already
    $sheets['Tenants'][10][3] = '';                  // row 11: no phone

    $batch = portfolioDryRun($this->acme, $this->owner, portfolioWorkbook($sheets));

    // Owners 50 and 99 are not valid, so buildings 50, 150 and 99, 199 cannot
    // name them; buildings 50, 99, 150, 180 and 199 are not valid, so their
    // units cannot name them: each row says so.
    $noOwner = fn (string $owner): string => "No owner is called or has the number \"{$owner}\", here or in the Owners sheet. Add them to the Owners sheet, or leave Owner empty when your organization owns the building.";
    $noBuilding = fn (string $building): string => "No building is called \"{$building}\", here or in the Buildings sheet. Add it to the Buildings sheet first.";
    $units = [];

    for ($n = 1; $n <= 1500; $n++) {
        $building = (($n - 1) % 200) + 1;
        $units[] = match (true) {
            $n === 1 => [2, 'Rent per year', 'I', 'error', 'The rent cannot be negative.'],
            $n === 2 => [3, 'Bedrooms', 'E', 'error', 'Enter a whole number, such as 3.'],
            $n === 3 => [4, 'Type', 'D', 'error', 'Choose one of: Flat, Self-contain, Shop, Office.'],
            $n === 4 => [5, null, null, 'warning', 'Each unit this import adds or brings back counts towards your GoProperty bill. You have 0 billable units now; the summary shows the total and the monthly price with this file\'s units.'],
            $n === 5 => [6, 'Building', 'B', 'error', $noBuilding('Building 999')],
            $n === 204 => [205, 'Unit', 'C', 'error', 'Flat 1 of Building 4 is already on row 5. Each unit goes on one row.'],
            in_array($building, [50, 99, 150, 180, 199], true) => [$n + 1, 'Building', 'B', 'error', $noBuilding("Building {$building}")],
            default => null,
        };
    }

    $units = array_values(array_filter($units));

    expect($writes)->toBe([])
        ->and(portfolioTables())->toEqual(['owner_profiles' => [], 'properties' => [], 'units' => [], 'tenant_profiles' => []])
        ->and($batch->status)->toBe(ImportStatus::DryRunFailed)
        ->and($batch->dry_run_report['rows'])->toBe(2000)
        ->and($batch->dry_run_report['errors'])->toBe(50)
        ->and($batch->dry_run_report['warnings'])->toBe(1)
        ->and(portfolioIssues($batch, 'owners'))->toBe([
            [51, 'Phone', 'D', 'error', '"0803 12" is not a phone number. Enter it like 0803 123 4567 or +234 803 123 4567.'],
            [100, 'Name', 'B', 'error', 'Enter the owner\'s name: a person\'s first and last name, or the company\'s name.'],
        ])
        ->and(portfolioIssues($batch, 'properties'))->toBe([
            [51, 'Owner', 'C', 'error', $noOwner('O-50')],
            [100, 'Owner', 'C', 'error', $noOwner('O-99')],
            [151, 'Owner', 'C', 'error', $noOwner('Nobody Known')],
            [181, 'State', 'F', 'error', '"Atlantis" is not a Nigerian state. Choose one from the list, such as Lagos or FCT.'],
            [200, 'Owner', 'C', 'error', $noOwner('O-99')],
        ])
        ->and(portfolioIssues($batch, 'units'))->toBe($units)
        ->and(count($units))->toBe(43)
        ->and(portfolioIssues($batch, 'tenants'))->toBe([[11, 'Phone', 'D', 'error', 'Enter the tenant\'s phone number.']])
        ->and(portfolioCounts($batch, 'owners'))->toEqual(['create' => 98, 'update' => 0, 'restore' => 0, 'skip' => 0])
        ->and(portfolioCounts($batch, 'properties'))->toEqual(['create' => 195, 'update' => 0, 'restore' => 0, 'skip' => 0])
        ->and(portfolioCounts($batch, 'units'))->toEqual(['create' => 1458, 'update' => 0, 'restore' => 0, 'skip' => 0]);

    // A failed dry run cannot be committed.
    expect(fn () => portfolioCommit($this->acme, $this->owner, $batch))->toThrow(ImportRefused::class);
    expect(portfolioTables()['units'])->toBe([]);
});
