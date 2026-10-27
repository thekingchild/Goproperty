<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Marketing\PlanCatalogue;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Import\Batches\ImportBatches;
use Modules\Import\Batches\ImportRefused;
use Modules\Import\Enums\ImportFileType;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Reading\SpreadsheetFile;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Properties\Enums\OwnerType;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Models\Unit;
use Modules\Tenants\Models\TenantProfile;

require_once __DIR__.'/helpers.php';

/*
 * P02-06c: the rules of the Owners, Buildings and Units sheets
 * (DATA_IMPORT.md § 1–3): matching, exact errors, the dry-run warnings
 * (rent differs, the bill rises), unit statuses (P02-02), what makes a
 * batch "used", the 4-sheet template, and who may import.
 */

beforeEach(function () {
    Storage::fake('media');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
});

afterEach(function () {
    app(OrganizationContext::class)->clear();
});

it('reports every bad cell of the Owners, Buildings and Units sheets with its exact row, column and message', function () {
    $owners = [PORTFOLIO_OWNERS,
        ['O-1', 'Bola Adeyemi', 'Person', '0802 555 0101', 'bola@', 'Ikoyi', 'Lagos', '', '', '', ''],                // 2: email
        ['O-2', 'Kemi', '', '0802 555 0102', '', '', 'Wakanda', '', '', '', ''],                                    // 3: one name, state
        ['O-3', 'Adeyemi Holdings', 'Partnership', '', '', '', '', '', 'CAC number', 'ABC', ''],                     // 4: type, CAC format
        ['O-4', 'Chidi Obi', '', '', '', '', '', '', 'NIN', '', ''],                                                // 5: type without number
        ['O-5', 'Ngozi <b>Eze</b>', '', '', '', '', '', '', '', '12345678901', ''],                                 // 6: markup, number without type
        ['O-1', 'Femi Ojo', '', '0802 555 0101', '', '', '', '', '', '', ''],                                       // 7: row 2's ID and phone
    ];
    $buildings = [PORTFOLIO_BUILDINGS,
        ['B-1', 'Palm Court', '', 'Castle', '1 Palm Avenue', 'Lagos', '', '', '', '', ''],                          // 2: type
        ['B-2', 'Cedar Court', '', 'Estate', '', '', '', '', str_repeat('x', 41), '1700', ''],                     // 3: new needs address and state; amenity; year
        ['B-3', 'Palm Court', '', 'Estate', '3 Palm Avenue', 'Lagos', '', '', '', '', ''],                          // 4: row 2's name
        ['B-4', 'Unity <i>Towers</i>', '', 'Estate', '4 Unity Road', 'FCT', '', '', '', 'old', ''],                 // 5: markup, year not a number
        ['B-5', 'Royal Gardens', '', '', '5 Royal Road', 'abuja', '', '', '', '', ''],                              // 6: new needs a type ("abuja" is the FCT)
    ];
    $units = [PORTFOLIO_UNITS,
        ['U-1', 'Royal Gardens', 'Flat {1}', 'Flat', '', '', '', '', '100,000', '', '', ''],                       // 2: label markup (building has an error)
        ['U-2', '', 'Flat 2', 'Flat', '', '', '', '', '100,000', '', '', ''],                                       // 3: no building
        ['U-3', 'Royal Gardens', '', '', '30', '', '0', '', 'lots', '-1', 'Sold', ''],                              // 4: no label, bathrooms? no: bedrooms, size, rent, service charge, status
        ['U-4', 'Royal Gardens', 'Shop 1', '', '', '', '', '', '', '', '', ''],                                     // 5: new needs type and rent
        ['U-5', 'Royal Gardens', 'Shop 2', 'Shop', '', '', '', '', '₦10,000,000,001', '', '', ''],                   // 6: rent too big
    ];

    $batch = portfolioDryRun($this->acme, $this->owner, portfolioWorkbook(['Owners' => $owners, 'Buildings' => $buildings, 'Units' => $units]));

    expect($batch->status)->toBe(ImportStatus::DryRunFailed)
        ->and(portfolioIssues($batch, 'owners'))->toBe([
            [2, 'Email', 'E', 'error', '"bola@" is not an email address.'],
            [3, 'State', 'G', 'error', '"Wakanda" is not a Nigerian state. Choose one from the list, such as Lagos or FCT.'],
            [3, 'Name', 'B', 'error', 'Enter the owner\'s first and last name, or choose Company as the type.'],
            [4, 'Type', 'C', 'error', 'Choose one of: Person, Company.'],
            [4, 'ID number', 'J', 'error', 'Enter the CAC number as on the certificate, such as RC 1234567.'],
            [5, 'ID number', 'J', 'error', 'Enter the ID number, or leave the ID type empty.'],
            [6, 'ID type', 'I', 'error', 'Choose the type of ID for this number: NIN or CAC number.'],
            [6, 'Name', 'B', 'error', 'Use plain text only (no < or >).'],
            [7, 'Your ID', 'A', 'error', 'This ID is already on row 2. Each owner goes on one row.'],
            [7, 'Phone', 'D', 'error', 'This phone number is already on row 2. Each owner goes on one row with their own number.'],
        ])
        ->and(portfolioIssues($batch, 'properties'))->toBe([
            [2, 'Type', 'D', 'error', 'Choose one of: Block of flats, Estate, Duplex, Bungalow, Commercial, Mixed use.'],
            [3, 'Amenities', 'I', 'error', 'Keep each amenity to 40 characters, separated by commas.'],
            [3, 'Year built', 'J', 'error', 'Enter a year between 1800 and '.(now()->year + 5).', such as 2015.'],
            [4, 'Building name', 'B', 'error', 'A building with this name is already on row 2. Give each building its own name: units name their building by it.'],
            [5, 'Building name', 'B', 'error', 'Use plain text only (no < or >).'],
            [5, 'Year built', 'J', 'error', 'Enter a year between 1800 and '.(now()->year + 5).', such as 2015.'],
            [6, 'Type', 'D', 'error', 'Choose the building\'s type.'],
        ])
        ->and(portfolioIssues($batch, 'units'))->toBe([
            [2, 'Building', 'B', 'error', 'No building is called "Royal Gardens", here or in the Buildings sheet. Add it to the Buildings sheet first.'],
            [2, 'Unit', 'C', 'error', 'Use plain text only (no < or > or { or }).'],
            [3, 'Building', 'B', 'error', 'Enter the unit\'s building: its name or Your ID from the Buildings sheet.'],
            [4, 'Building', 'B', 'error', 'No building is called "Royal Gardens", here or in the Buildings sheet. Add it to the Buildings sheet first.'],
            [4, 'Unit', 'C', 'error', 'Enter the unit\'s name, such as Flat 3B.'],
            [4, 'Status', 'K', 'error', 'Choose one of: Vacant, Under maintenance, Unavailable. Occupied and reserved come from the unit\'s lease.'],
            [4, 'Bedrooms', 'E', 'error', 'Enter a whole number from 0 to 20.'],
            [4, 'Size (sq m)', 'G', 'error', 'Enter a whole number from 1 to 100,000.'],
            [4, 'Rent per year', 'I', 'error', 'Enter an amount in naira, such as 150,000 or ₦150,000.50.'],
            [4, 'Service charge per year', 'J', 'error', 'The service charge cannot be negative.'],
            [5, 'Building', 'B', 'error', 'No building is called "Royal Gardens", here or in the Buildings sheet. Add it to the Buildings sheet first.'],
            [6, 'Building', 'B', 'error', 'No building is called "Royal Gardens", here or in the Buildings sheet. Add it to the Buildings sheet first.'],
            [6, 'Rent per year', 'I', 'error', 'Enter an amount below ₦10,000,000,000.'],
        ]);

    // With Royal Gardens valid, a new unit needs its type and rent.
    $buildings[5][3] = 'Estate';
    $batch = portfolioDryRun($this->acme, $this->owner, portfolioWorkbook(['Buildings' => [PORTFOLIO_BUILDINGS, $buildings[5]], 'Units' => [PORTFOLIO_UNITS, $units[4]]]));

    expect(portfolioIssues($batch, 'units'))->toBe([
        [2, 'Type', 'D', 'error', 'Choose the unit\'s type.'],
        [2, 'Rent per year', 'I', 'error', 'Enter the unit\'s yearly rent (0 if there is none).'],
    ])->and(buildingsOf($this->acme))->toHaveCount(0);
});

it('matches owners by Your ID, then phone, then name, and keeps namesakes with other phone numbers apart', function () {
    $bola = ownerIn($this->acme, ['first_name' => 'Bola', 'last_name' => 'Adeyemi', 'phone' => '+2348025550101', 'email' => 'bola@example.com']);
    $chidi = ownerIn($this->acme, ['first_name' => 'Chidi', 'last_name' => 'Obi', 'phone' => null]);
    $twins = [ownerIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bello', 'phone' => null]), ownerIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bello', 'phone' => null])];

    $owners = [PORTFOLIO_OWNERS,
        ['', 'BOLA  ADEYEMI', '', '+234 802 555 0101', '', 'New address', '', '', '', '', ''],   // 2: by phone (name in capitals)
        ['', 'Chidi Obi', '', '0802 555 0103', '', '', '', '', '', '', ''],                      // 3: by name: he had no phone, now has this one
        ['', 'Bola Adeyemi', '', '0802 555 0104', '', '', '', '', '', '', ''],                   // 4: a namesake with another phone: a new owner
        ['', 'Tunde Bello', '', '', '', '', '', '', '', '', ''],                                 // 5: two owners on file have this name
        ['', 'Ngozi Eze', '', '', '', '', '', '', '', '', ''],                                   // 6: new, no phone
        ['', 'Ngozi Eze', '', '', '', '', '', '', '', '', ''],                                   // 7: the same again
        ['', 'Eze Holdings', 'company', '', '', '', '', '', '', '', ''],                         // 8: a company, one word is fine
    ];

    $batch = portfolioDryRun($this->acme, $this->owner, portfolioWorkbook(['Owners' => $owners]));

    expect(portfolioIssues($batch, 'owners'))->toBe([
        [4, 'Name', 'B', 'warning', 'An owner called Bola Adeyemi is already on file with another phone number. This row adds a second owner; to update that one instead, use the phone number on file or Your ID.'],
        [5, null, null, 'error', 'More than one owner on file matches this row (Tunde Bello). Add Your ID, or the phone number on file, so GoProperty knows which one.'],
        [7, 'Name', 'B', 'error', 'This owner is already on row 6. Each owner goes on one row; give namesakes their own phone numbers.'],
    ]);

    unset($owners[4], $owners[6]);
    // Buildings name owners by phone, by name, or ambiguously.
    $buildings = [PORTFOLIO_BUILDINGS,
        ['', 'Palm Court', '0802 555 0103', 'Estate', '1 Palm Avenue', 'Lagos', '', '', '', '', ''],   // Chidi by his new phone
        ['', 'Cedar Court', 'ngozi eze', 'Estate', '2 Cedar Road', 'Lagos', '', '', '', '', ''],      // Ngozi, added by this file
        ['', 'Unity Towers', 'Bola Adeyemi', 'Estate', '3 Unity Road', 'Lagos', '', '', '', '', ''],  // two Bolas once this file is in
        ['', 'Royal Gardens', 'Eze Holdings', 'Estate', '4 Royal Road', 'Lagos', '', '', '', '', ''],
    ];

    $batch = portfolioDryRun($this->acme, $this->owner, portfolioWorkbook(['Owners' => array_values($owners), 'Buildings' => $buildings]));

    expect(portfolioIssues($batch, 'properties'))->toBe([
        [4, 'Owner', 'C', 'error', 'More than one owner is called "Bola Adeyemi". Use their phone number or Your ID from the Owners sheet.'],
    ])->and(collect($batch->dry_run_report['sheets'])->firstWhere('target', 'properties')['preview']['rows'][1]['values'][1])->toBe('Ngozi Eze');

    $buildings[3][2] = '0802 555 0104';
    $batch = portfolioImport($this->acme, $this->owner, portfolioWorkbook(['Owners' => array_values($owners), 'Buildings' => $buildings]));
    $all = ownersOf($this->acme);

    expect($batch->result_counts['targets']['owners'])->toEqual(['create' => 3, 'update' => 2, 'restore' => 0, 'skip' => 0])
        ->and($all)->toHaveCount(7)
        ->and($all->firstWhere('id', $bola->id)->address)->toBe('New address')
        ->and($all->firstWhere('id', $bola->id)->email)->toBe('bola@example.com')
        ->and($all->firstWhere('id', $bola->id)->display_name)->toBe('BOLA ADEYEMI')
        ->and($all->firstWhere('id', $chidi->id)->phone)->toBe('+2348025550103')
        ->and($all->firstWhere('display_name', 'Eze Holdings')->type)->toBe(OwnerType::Company)
        ->and(buildingsOf($this->acme)->pluck('owner_profile_id', 'name')->all())->toBe([
            'Palm Court' => $chidi->id,
            'Cedar Court' => $all->firstWhere('display_name', 'Ngozi Eze')->id,
            'Unity Towers' => $all->firstWhere('phone', '+2348025550104')->id,
            'Royal Gardens' => $all->firstWhere('display_name', 'Eze Holdings')->id,
        ])
        ->and($twins[0]->fresh())->not->toBeNull();
});

it('matches buildings by name and units by building and label, without case, and never moves a unit to another building', function () {
    $palm = buildingIn($this->acme, ['name' => 'Palm Court']);
    $flat = unitIn($this->acme, $palm, ['unit_label' => 'Flat 1', 'description' => 'Keep me']);
    inOrganization($this->acme, fn () => $flat->forceFill(['external_ref' => 'U-1'])->save());
    buildingIn($this->acme, ['name' => 'Twin Towers']);
    buildingIn($this->acme, ['name' => 'Twin Towers']);
    $cedar = buildingIn($this->acme, ['name' => 'Cedar Court']);

    $units = [PORTFOLIO_UNITS,
        ['', 'palm court', 'FLAT 1', '', '', '', '', '', '', '', '', ''],                          // 2: updates Flat 1 (nothing to change)
        ['', 'PALM COURT', 'Flat 2', 'self con', '', '', '', '', '500000', '', '', ''],            // 3: new self-contain
        ['', 'Twin Towers', 'Flat 1', 'Flat', '', '', '', '', '500000', '', '', ''],              // 4: two buildings have this name
        ['U-1', 'Cedar Court', 'Flat 1', 'Flat', '', '', '', '', '500000', '', '', ''],          // 5: U-1 is Palm Court's
        ['', 'Palm Court', 'flat 2', 'Flat', '', '', '', '', '500000', '', '', ''],               // 6: row 3's unit again
    ];

    $batch = portfolioDryRun($this->acme, $this->owner, portfolioWorkbook(['Units' => $units]));

    expect(portfolioIssues($batch, 'units'))->toBe([
        [3, null, null, 'warning', 'Each unit this import adds or brings back counts towards your GoProperty bill. You have 1 billable units now; the summary shows the total and the monthly price with this file\'s units.'],
        [4, 'Building', 'B', 'error', 'More than one building is called "Twin Towers". Use the building\'s Your ID.'],
        [5, 'Your ID', 'A', 'error', 'This ID is Flat 1 of Palm Court. A unit cannot move to another building: check the ID or the building.'],
        [6, 'Unit', 'C', 'error', 'flat 2 of Palm Court is already on row 3. Each unit goes on one row.'],
    ]);

    // Renaming through Your ID: the new label must be free in the building.
    unitIn($this->acme, $palm, ['unit_label' => 'Flat 9']);
    $rename = [PORTFOLIO_UNITS, ['U-1', 'Palm Court', 'Flat 9', '', '', '', '', '', '', '', '', '']];
    expect(portfolioIssues(portfolioDryRun($this->acme, $this->owner, portfolioWorkbook(['Units' => $rename])), 'units'))
        ->toBe([[2, 'Unit', 'C', 'error', 'Flat 9 already exists in Palm Court.']]);

    $batch = portfolioImport($this->acme, $this->owner, portfolioWorkbook(['Units' => [$units[0], $units[1], $units[2], ['', 'Cedar Court', 'Flat 1', 'Flat', '', '', '', '', '500000', '', '', '']]]));

    expect($batch->result_counts['targets']['units'])->toEqual(['create' => 2, 'update' => 1, 'restore' => 0, 'skip' => 0])
        ->and($flat->fresh()->description)->toBe('Keep me')
        ->and($flat->fresh()->unit_label)->toBe('FLAT 1')
        ->and(unitsOf($this->acme)->where('property_id', $palm->id)->pluck('unit_label')->sort()->values()->all())->toBe(['FLAT 1', 'Flat 2', 'Flat 9'])
        ->and(unitsOf($this->acme)->where('property_id', $cedar->id)->pluck('unit_label')->all())->toBe(['Flat 1']);
});

it('skips an archived building and its units with a warning, and restores neither', function () {
    $archived = buildingIn($this->acme, ['name' => 'Palm Court', 'status' => 'archived']);
    $unit = unitIn($this->acme, $archived, ['unit_label' => 'Flat 1']);

    $batch = portfolioDryRun($this->acme, $this->owner, portfolioWorkbook([
        'Buildings' => [PORTFOLIO_BUILDINGS, ['', 'Palm Court', '', 'Estate', 'New address', 'Lagos', '', '', '', '', '']],
        'Units' => [PORTFOLIO_UNITS, ['', 'Palm Court', 'Flat 1', 'Shop', '', '', '', '', '1', '', '', ''], ['', 'Palm Court', 'Flat 2', 'Shop', '', '', '', '', '1', '', '', '']],
    ]));

    expect($batch->status)->toBe(ImportStatus::DryRunPassed)
        ->and($batch->dry_run_report['counts'])->toEqual(['create' => 0, 'update' => 0, 'restore' => 0, 'skip' => 3])
        ->and($batch->dry_run_report['units'])->toBe(0)
        ->and(portfolioIssues($batch, 'properties'))->toBe([[2, null, null, 'warning', 'Palm Court is archived, so this row is skipped (and so are its units). Restore the building on its page first to import changes to it.']])
        ->and(portfolioIssues($batch, 'units'))->toBe([
            [2, 'Building', 'B', 'warning', 'Palm Court is archived, so this unit is skipped. Restore the building on its page first to add or change its units.'],
            [3, 'Building', 'B', 'warning', 'Palm Court is archived, so this unit is skipped. Restore the building on its page first to add or change its units.'],
        ]);

    $batch = portfolioCommit($this->acme, $this->owner, $batch);

    expect($batch->result_counts['targets']['properties'])->toEqual(['create' => 0, 'update' => 0, 'restore' => 0, 'skip' => 1])
        ->and($batch->result_counts['skipped'][0])->toEqual(['target' => 'properties', 'row' => 2, 'reason' => 'Palm Court is archived.'])
        ->and($archived->fresh()->address)->not->toBe('New address')
        ->and($unit->fresh()->unit_type->value)->toBe('apartment')
        ->and(unitsOf($this->acme))->toHaveCount(1);
});

it('skips rows whose building was archived after the dry run, and lists them on the report', function () {
    $palm = buildingIn($this->acme, ['name' => 'Palm Court']);
    $batch = portfolioDryRun($this->acme, $this->owner, portfolioWorkbook(['Units' => portfolioUnitRows(0, 0) + [1 => ['', 'Palm Court', 'Flat 1', 'Flat', '', '', '', '', '1,000', '', '', '']]]));
    expect($batch->status)->toBe(ImportStatus::DryRunPassed);

    inOrganization($this->acme, fn () => $palm->forceFill(['status' => 'archived'])->save());
    $batch = portfolioCommit($this->acme, $this->owner, $batch);

    expect($batch->status)->toBe(ImportStatus::Committed)
        ->and($batch->result_counts['targets']['units'])->toEqual(['create' => 0, 'update' => 0, 'restore' => 0, 'skip' => 1])
        ->and($batch->result_counts['skipped'])->toEqual([['target' => 'units', 'row' => 2, 'reason' => 'Palm Court is archived.']])
        ->and(unitsOf($this->acme))->toHaveCount(0);
});

it('imports occupied and reserved units as vacant with a warning, and never changes the status of a unit a lease holds', function () {
    $palm = buildingIn($this->acme, ['name' => 'Palm Court']);
    $let = unitIn($this->acme, $palm, ['unit_label' => 'Flat 1']);
    inOrganization($this->acme, fn () => app(UnitDirectory::class)->setStatus($let->id, 'occupied', 'lease started'));
    $free = unitIn($this->acme, $palm, ['unit_label' => 'Flat 2', 'status' => UnitStatus::Unavailable]);

    $units = [PORTFOLIO_UNITS,
        ['', 'Palm Court', 'Flat 1', '', '', '', '', '', '', '', 'Vacant', ''],               // 2: held by a lease
        ['', 'Palm Court', 'Flat 2', '', '', '', '', '', '', '', 'Let', ''],                  // 3: "Let" is occupied: keeps unavailable
        ['', 'Palm Court', 'Flat 3', 'Flat', '', '', '', '', '1,000', '', 'Reserved', ''],    // 4: new: vacant
        ['', 'Palm Court', 'Flat 4', 'Flat', '', '', '', '', '1,000', '', 'under repair', ''], // 5: new: under maintenance
        ['', 'Palm Court', 'Flat 5', 'Flat', '', '', '', '', '1,000', '', '', ''],            // 6: new: vacant
    ];

    $batch = portfolioDryRun($this->acme, $this->owner, portfolioWorkbook(['Units' => $units]));

    expect(portfolioIssues($batch, 'units'))->toBe([
        [2, 'Status', 'K', 'warning', 'A lease holds this unit, so its status stays occupied: the Status cell is ignored.'],
        [3, 'Status', 'K', 'warning', 'Occupied comes from the unit\'s lease, so this unit is imported as unavailable. Importing its lease marks it occupied.'],
        [4, 'Status', 'K', 'warning', 'Reserved comes from the unit\'s lease, so this unit is imported as vacant. Importing its lease marks it reserved.'],
        [4, null, null, 'warning', 'Each unit this import adds or brings back counts towards your GoProperty bill. You have 2 billable units now; the summary shows the total and the monthly price with this file\'s units.'],
    ])->and(array_column(array_column(collect($batch->dry_run_report['sheets'])->firstWhere('target', 'units')['preview']['rows'], 'values'), 6))
        ->toBe(['Occupied', 'Unavailable', 'Vacant', 'Under maintenance', 'Vacant']);

    portfolioCommit($this->acme, $this->owner, $batch);

    expect(unitsOf($this->acme)->pluck('status', 'unit_label')->map->value->all())->toBe([
        'Flat 1' => 'occupied',
        'Flat 2' => 'unavailable',
        'Flat 3' => 'vacant',
        'Flat 4' => 'under_maintenance',
        'Flat 5' => 'vacant',
    ]);
});

it('warns when a unit\'s rent differs from the one on file, and shows what the units do to the GoProperty bill', function () {
    $palm = buildingIn($this->acme, ['name' => 'Palm Court']);
    $flat = unitIn($this->acme, $palm, ['unit_label' => 'Flat 1', 'base_rent_kobo' => Money::naira(1_200_000)]);
    unitIn($this->acme, $palm, ['unit_label' => 'Flat 2', 'base_rent_kobo' => Money::naira(1_500_000)]);
    unitIn($this->acme, buildingIn($this->acme, ['name' => 'Old Block', 'status' => 'archived']), ['unit_label' => 'Flat 1']);

    $units = [PORTFOLIO_UNITS,
        ['', 'Palm Court', 'Flat 1', '', '', '', '', '', '₦1,350,000', '', '', ''],      // rent changes
        ['', 'Palm Court', 'Flat 2', '', '', '', '', '', '1500000', '', '', ''],         // the same rent
        ['', 'Palm Court', 'Flat 3', 'Flat', '', '', '', '', '1,000,000', '', '', ''],
        ['', 'Palm Court', 'Flat 4', 'Flat', '', '', '', '', '1,000,000', '', '', ''],
    ];

    $batch = portfolioDryRun($this->acme, $this->owner, portfolioWorkbook(['Units' => $units]));

    // Archived buildings' units are not billed (BILLING_PAYMENTS.md § 1.4): 2 now.
    expect(inOrganization($this->acme, fn () => app(UnitDirectory::class)->countBillable()))->toBe(2)
        ->and($batch->dry_run_report['units'])->toBe(2)
        ->and(portfolioIssues($batch, 'units'))->toBe([
            [2, 'Rent per year', 'I', 'warning', 'The rent on file is ₦1,200,000 a year; this import changes it to ₦1,350,000.'],
            [4, null, null, 'warning', 'Each unit this import adds or brings back counts towards your GoProperty bill. You have 2 billable units now; the summary shows the total and the monthly price with this file\'s units.'],
        ]);

    // The review page prices the total with the plan catalogue (P02 decision 6).
    $plans = app(PlanCatalogue::class);
    $plan = collect($plans->plans())->first(fn ($plan): bool => $plan->key === $this->acme->signup_plan_key) ?? $plans->bestFor(4);
    $this->actingAs($this->owner)->get("/o/acme-homes/imports/{$batch->id}")->assertOk()
        ->assertSee("This file adds 2 units to the 2 you have. On the {$plan->name} plan, 4 units cost {$plan->priceFor(4)->format()} a month (before VAT), against {$plan->priceFor(2)->format()} now.");

    portfolioCommit($this->acme, $this->owner, $batch);

    expect($flat->fresh()->base_rent_kobo->kobo)->toBe(135_000_000)
        ->and(inOrganization($this->acme, fn () => app(UnitDirectory::class)->countBillable()))->toBe(4);
});

it('refuses a rollback once a lease holds an imported unit, or a building or owner of the import is in use', function () {
    $file = fn () => portfolioWorkbook(['Owners' => portfolioOwnerRows(1), 'Buildings' => portfolioBuildingRows(1, 1), 'Units' => portfolioUnitRows(1, 2)]);

    // A lease holds a unit (Leasing sets occupied or reserved through UnitDirectory).
    $batch = portfolioImport($this->acme, $this->owner, $file());
    $unit = unitsOf($this->acme)->first();
    inOrganization($this->acme, fn () => app(UnitDirectory::class)->setStatus($unit->id, 'reserved', 'lease drafted'));
    expect(fn () => portfolioRollback($this->acme, $this->owner, $batch))->toThrow(ImportRefused::class, 'Some units from this import have been used since');

    // The lease is gone: the unit is free, and the rollback works.
    inOrganization($this->acme, fn () => app(UnitDirectory::class)->setStatus($unit->id, 'vacant', 'lease cancelled'));
    expect(portfolioRollback($this->acme, $this->owner, $batch)->status)->toBe(ImportStatus::RolledBack);

    // A unit added on the pages to an imported building.
    $batch = portfolioImport($this->acme, $this->owner, $file());
    $building = buildingsOf($this->acme)->sole();
    unitIn($this->acme, $building, ['unit_label' => 'Shop 1']);
    expect(fn () => portfolioRollback($this->acme, $this->owner, $batch))->toThrow(ImportRefused::class, 'Some buildings from this import have been used since');
    expect(unitsOf($this->acme)->filter->trashed())->toHaveCount(0);

    // A building added on the pages for an imported owner.
    $other = Organization::factory()->create(['slug' => 'other-realty']);
    $theirOwner = joinAs(User::factory()->create(), $other);
    $batch = portfolioImport($other, $theirOwner, $file());
    buildingIn($other, ['name' => 'Head Office', 'owner_profile_id' => ownersOf($other)->sole()->id]);
    expect(fn () => portfolioRollback($other, $theirOwner, $batch))->toThrow(ImportRefused::class, 'Some owners from this import have been used since');
});

it('offers the four sheets in the template with dropdowns, and a filled-in template imports its examples', function () {
    $response = $this->actingAs($this->owner)->get('/o/acme-homes/imports/template')->assertOk();
    $path = (string) tempnam(sys_get_temp_dir(), 'gp-template-');
    copy($response->baseResponse->getFile()->getPathname(), $path);
    $file = SpreadsheetFile::open($path, ImportFileType::Xlsx);
    $rows = fn (int $sheet): array => array_values(iterator_to_array($file->rows($sheet), false));

    expect(array_column($file->sheets(), 'name'))->toBe(['Instructions', 'Owners', 'Buildings', 'Units', 'Tenants'])
        ->and($rows(1)[0])->toBe(PORTFOLIO_OWNERS)
        ->and($rows(2)[0])->toBe(PORTFOLIO_BUILDINGS)
        ->and($rows(3)[0])->toBe(PORTFOLIO_UNITS)
        ->and($rows(4)[0])->toBe(PORTFOLIO_TENANTS)
        ->and($rows(2)[1][2])->toBe($rows(1)[1][1])   // the example building names the example owner
        ->and($rows(3)[1][1])->toBe($rows(2)[1][1]);  // the example unit names the example building

    // Dropdowns for the fixed values, from the hidden Lists sheet.
    $zip = new ZipArchive;
    $zip->open($path);
    $validations = [];

    foreach ([2 => 'Owners', 3 => 'Buildings', 4 => 'Units', 5 => 'Tenants'] as $n => $sheet) {
        preg_match_all('/sqref="([A-Z]+)2:/', (string) $zip->getFromName("xl/worksheets/sheet{$n}.xml"), $found);
        $validations[$sheet] = $found[1];
    }

    $zip->close();

    expect($validations)->toEqual([
        'Owners' => ['C', 'G', 'I'],      // Type, State, ID type
        'Buildings' => ['D', 'F'],        // Type, State
        'Units' => ['D', 'K'],            // Type, Status
        'Tenants' => ['K'],               // ID type
    ]);

    $batch = portfolioDryRun($this->acme, $this->owner, new UploadedFile($path, 'template.xlsx', null, null, true));

    expect($batch->status)->toBe(ImportStatus::DryRunPassed)
        ->and(collect($batch->sheets())->pluck('target')->all())->toBe([null, 'owners', 'properties', 'units', 'tenants'])
        ->and($batch->dry_run_report['counts'])->toEqual(['create' => 4, 'update' => 0, 'restore' => 0, 'skip' => 0]);

    portfolioCommit($this->acme, $this->owner, $batch);
    $building = buildingsOf($this->acme)->sole();
    $unit = unitsOf($this->acme)->sole();

    expect($building->owner_profile_id)->toBe(ownersOf($this->acme)->sole()->id)
        ->and($unit->property_id)->toBe($building->id)
        ->and($unit->base_rent_kobo->kobo)->toBe(150_000_000)
        ->and(inOrganization($this->acme, fn () => TenantProfile::query()->count()))->toBe(1);
});

it('lets only owners and administrators import, so a narrowed agent never imports into buildings they cannot see', function (OrganizationRole $role, bool $allowed) {
    $member = joinAs(User::factory()->create(), $this->acme, $role);
    $upload = fn () => inOrganization($this->acme, fn () => app(ImportBatches::class)->upload($member, portfolioWorkbook(['Units' => portfolioUnitRows(1, 1)])));

    if ($allowed) {
        expect($upload()->status)->toBe(ImportStatus::Uploaded);
    } else {
        expect($upload)->toThrow(AuthorizationException::class);
    }
})->with([
    'org-owner' => [OrganizationRole::Owner, true],
    'org-admin' => [OrganizationRole::Admin, true],
    'agent' => [OrganizationRole::Agent, false],
    'property owner' => [OrganizationRole::PropertyOwner, false],
    'tenant' => [OrganizationRole::Tenant, false],
]);

it('reads a one-sheet CSV of units for a building already on file', function () {
    $palm = buildingIn($this->acme, ['name' => 'Palm Court']);
    $handle = fopen('php://temp', 'w+');

    foreach ([['Building name', 'Flat no', 'Unit type', 'Annual rent', 'Service charge'], ['Palm Court', 'A1', 'Mini flat', 'N850,000', 'NGN 50,000'], ['Palm Court', 'Shop 1', 'Shop', '600000', '']] as $line) {
        fputcsv($handle, $line, escape: '');
    }

    rewind($handle);
    $csv = UploadedFile::fake()->createWithContent('units.csv', (string) stream_get_contents($handle));
    $batch = portfolioImport($this->acme, $this->owner, $csv);

    expect($batch->kind)->toBe('units')
        ->and($batch->status)->toBe(ImportStatus::Committed)
        ->and(unitsOf($this->acme)->map(fn (Unit $unit): array => [$unit->property_id, $unit->unit_label, $unit->unit_type->value, $unit->base_rent_kobo->kobo, $unit->service_charge_kobo->kobo])->all())->toBe([
            [$palm->id, 'A1', 'apartment', 85_000_000, 5_000_000],
            [$palm->id, 'Shop 1', 'shop', 60_000_000, 0],
        ]);
});
