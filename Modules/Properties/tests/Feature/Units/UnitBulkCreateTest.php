<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Enums\UnitType;
use Modules\Properties\Events\UnitCreated;
use Modules\Properties\Models\Unit;
use Modules\Properties\Units\CreateUnitsInBulk;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';

/*
 * P02-02 AC: "Creating 10 units in one bulk action works", all or nothing
 * in one transaction; a label the building already has gives a clear error
 * naming it, and nothing is added (Units\CreateUnitsInBulk).
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->building = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);

    // A real listener (not Event::fake()): UnitCreated waits for the commit,
    // so a rolled-back add must send nothing.
    $this->sent = [];
    Event::listen(UnitCreated::class, fn (UnitCreated $event) => $this->sent[] = $event);
});

afterEach(fn () => app(OrganizationContext::class)->clear());

it('creates 10 units in one bulk action from the page, with the same details, audited, and sends UnitCreated for each', function () {
    $snapshot = unitsSnapshot($this->owner, 'palm-court', '/bulk');

    $queries = 0;
    DB::listen(function ($query) use (&$queries): void {
        if (str_starts_with(strtolower($query->sql), 'insert into `units`')) {
            $queries++;
        }
    });
    // Every unit is saved one transaction deeper than the request runs.
    $levels = [];
    Unit::creating(function () use (&$levels): void {
        $levels[] = DB::transactionLevel();
    });
    $base = DB::transactionLevel();

    $response = LivewireHttp::update($this, $snapshot, bulkForm(), [['save']])->assertOk();

    $units = unitsOf($this->acme);
    expect(LivewireHttp::errors($response))->toBe([])
        ->and(LivewireHttp::redirect($response))->toBe('http://localhost/o/acme-homes/buildings/palm-court')
        ->and($units)->toHaveCount(10)
        ->and($units->pluck('unit_label')->all())->toBe(['Flat 1', 'Flat 2', 'Flat 3', 'Flat 4', 'Flat 5', 'Flat 6', 'Flat 7', 'Flat 8', 'Flat 9', 'Flat 10'])
        ->and($units->pluck('property_id')->unique()->all())->toBe([$this->building->id])
        ->and($units->pluck('organization_id')->unique()->all())->toBe([$this->acme->id])
        ->and($units->every(fn (Unit $unit): bool => $unit->unit_type === UnitType::Apartment && $unit->bedrooms === 2
            && $unit->status === UnitStatus::Vacant
            && $unit->base_rent_kobo->equals(Money::naira(1_800_000)) && $unit->service_charge_kobo->kobo === 20_000_000))->toBeTrue()
        ->and($queries)->toBe(10)
        ->and(array_unique($levels))->toBe([$base + 1]);

    expect(AuditEntry::query()->where('log_name', 'properties')->where('event', 'unit.created')->count())->toBe(10)
        ->and(AuditEntry::query()->where('event', 'unit.created')->first()->causer_id)->toBe($this->owner->id)
        ->and(collect($this->sent)->pluck('unitId')->all())->toBe($units->pluck('id')->all())
        ->and(collect($this->sent)->every(fn (UnitCreated $event): bool => $event->organizationId === $this->acme->id && $event->propertyId === $this->building->id && ! $event->restored))->toBeTrue();

    buildingsPage($this->owner, '/palm-court')->assertOk()
        ->assertSee('10 units were added to Palm Court.')
        ->assertSee('0 of 10 units occupied (0%)')
        ->assertSee('Flat 10');
});

it('rolls back all of them with a clear error naming each label the building already has', function () {
    unitIn($this->acme, $this->building, ['unit_label' => 'Flat 7']);
    unitIn($this->acme, $this->building, ['unit_label' => 'flat 9']);
    $this->sent = [];
    $snapshot = unitsSnapshot($this->owner, 'palm-court', '/bulk');

    $response = LivewireHttp::update($this, $snapshot, bulkForm(), [['save']])->assertOk();

    // Livewire keeps only errors of its own fields between requests, so the
    // per-label errors are read from the page it sent back.
    $html = LivewireHttp::html($response);
    expect(LivewireHttp::redirect($response))->toBeNull()
        ->and($html)->toContain('Flat 7, Flat 9 already exist in Palm Court. Change the first number or the pattern: no unit was added.')
        ->and($html)->toContain('<li>Flat 7 already exists in Palm Court.</li>')
        ->and($html)->toContain('<li>Flat 9 already exists in Palm Court.</li>')
        ->and(unitsOf($this->acme)->pluck('unit_label')->all())->toBe(['Flat 7', 'flat 9'])
        ->and(AuditEntry::query()->where('event', 'unit.created')->count())->toBe(0)
        ->and($this->sent)->toBe([]);

    try {
        inOrganization($this->acme, fn () => app(CreateUnitsInBulk::class)->handle($this->owner, $this->building, bulkForm()));
        $this->fail('The add went through.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toBe([
            'labels' => ['Flat 7, Flat 9 already exist in Palm Court. Change the first number or the pattern: no unit was added.'],
            'labels.6' => ['Flat 7 already exists in Palm Court.'],
            'labels.8' => ['Flat 9 already exists in Palm Court.'],
        ]);
    }

    // Starting after them works.
    $response = LivewireHttp::update($this, unitsSnapshot($this->owner, 'palm-court', '/bulk'), bulkForm(['start' => '10']), [['save']])->assertOk();
    expect(LivewireHttp::errors($response))->toBe([])
        ->and(unitsOf($this->acme))->toHaveCount(12);
});

it('rolls back the units already added when a label is taken during the add, in one transaction', function () {
    // Another request adds "Flat 9" after the labels were checked: the
    // unique index stops the add at Flat 9, and Flat 1 to Flat 8 go too.
    $raced = false;
    Unit::creating(function (Unit $unit) use (&$raced): void {
        if ($unit->unit_label === 'Flat 3' && ! $raced) {
            $raced = true;
            DB::table('units')->insert([
                'organization_id' => $this->acme->id,
                'property_id' => $this->building->id,
                'unit_label' => 'Flat 9',
                'unit_type' => 'apartment',
                'status' => 'vacant',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    $error = null;

    try {
        inOrganization($this->acme, fn () => app(CreateUnitsInBulk::class)->handle($this->owner, $this->building, bulkForm()));
    } catch (ValidationException $refused) {
        $error = $refused->errors();
    }

    expect($raced)->toBeTrue()
        ->and($error['labels'][0] ?? null)->toBe('Flat 9 already exists in Palm Court. Change the first number or the pattern: no unit was added.')
        ->and($error['labels.8'][0] ?? null)->toBe('Flat 9 already exists in Palm Court.')
        ->and(unitsOf($this->acme))->toHaveCount(0)
        ->and(AuditEntry::query()->where('event', 'unit.created')->count())->toBe(0)
        ->and($this->sent)->toBe([]);
});

it('makes the labels from the pattern and the first number', function () {
    $units = inOrganization($this->acme, fn () => app(CreateUnitsInBulk::class)->handle($this->owner, $this->building, bulkForm(['pattern' => '  A{n} ', 'start' => '101', 'count' => '3', 'unitType' => 'shop'])));

    expect(array_map(fn (Unit $unit): string => $unit->unit_label, $units))->toBe(['A101', 'A102', 'A103'])
        ->and($units[0]->unit_type)->toBe(UnitType::Shop)
        ->and(CreateUnitsInBulk::labels('Block B, Flat {n}', 0, 2))->toBe(['Block B, Flat 0', 'Block B, Flat 1']);
});

it('previews the labels on the page', function () {
    $snapshot = unitsSnapshot($this->owner, 'palm-court', '/bulk');

    $html = LivewireHttp::html(LivewireHttp::update($this, $snapshot, ['pattern' => 'Shop {n}', 'start' => '5', 'count' => '4'])->assertOk());
    expect($html)->toContain('Adds 4 units: Shop 5 to Shop 8.');

    $html = LivewireHttp::html(LivewireHttp::update($this, $snapshot, ['pattern' => 'Shop'])->assertOk());
    expect($html)->toContain('Enter a pattern with {n}');
});

it('refuses an unusable pattern, count or amount on the server, adding nothing', function (array $changes, string $field) {
    $response = LivewireHttp::update($this, unitsSnapshot($this->owner, 'palm-court', '/bulk'), bulkForm($changes), [['save']])->assertOk();

    expect(LivewireHttp::errors($response))->toHaveKey($field)
        ->and(unitsOf($this->acme))->toHaveCount(0);
})->with([
    'no {n}' => [['pattern' => 'Flat'], 'pattern'],
    'two {n}' => [['pattern' => 'Flat {n}-{n}'], 'pattern'],
    'stray braces' => [['pattern' => 'Flat {n} {x}'], 'pattern'],
    'markup' => [['pattern' => '<b>{n}</b>'], 'pattern'],
    'no units' => [['count' => '0'], 'count'],
    'too many units' => [['count' => (string) (CreateUnitsInBulk::MAX_UNITS + 1)], 'count'],
    'negative start' => [['start' => '-1'], 'start'],
    'not a number' => [['start' => 'one'], 'start'],
    'no rent' => [['baseRent' => ''], 'baseRent'],
    'negative rent' => [['baseRent' => '-5,000'], 'baseRent'],
    'three decimals' => [['baseRent' => '1,000.505'], 'baseRent'],
    'occupied by hand' => [['status' => 'occupied'], 'status'],
    'unknown type' => [['unitType' => 'castle'], 'unitType'],
]);

it('brings back an archived unit whose label is added again, with the new details, audited as restored', function () {
    $old = unitIn($this->acme, $this->building, ['unit_label' => 'Flat 2', 'bedrooms' => 1]);
    inOrganization($this->acme, fn () => $old->delete());
    $this->sent = [];

    inOrganization($this->acme, fn () => app(CreateUnitsInBulk::class)->handle($this->owner, $this->building, bulkForm(['count' => '3'])));

    $units = unitsOf($this->acme);
    expect($units)->toHaveCount(3)
        ->and($units->every(fn (Unit $unit): bool => ! $unit->trashed()))->toBeTrue()
        ->and($units->firstWhere('id', $old->id)->bedrooms)->toBe(2)
        ->and(AuditEntry::query()->where('event', 'unit.restored')->sole()->subject_id)->toBe($old->id)
        ->and(AuditEntry::query()->where('event', 'unit.created')->count())->toBe(2)
        ->and(collect($this->sent)->firstWhere('unitId', $old->id)?->restored)->toBeTrue();
});
