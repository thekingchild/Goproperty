<?php

declare(strict_types=1);

use App\Media\MediaMover;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\CrossOrganizationWrite;
use App\Tenancy\Moves\BuildingMove;
use App\Tenancy\Moves\BuildingMoveFailed;
use App\Tenancy\Moves\BuildingRecordMovers;
use App\Tenancy\Moves\MoveDirection;
use App\Tenancy\Moves\MovesBuildingRecords;
use App\Tenancy\Moves\OrganizationMoves;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Modules\Billing\Handover\UtilitiesMover;
use Modules\Leasing\Handover\LeasesMover;
use Modules\Properties\Handover\BuildingsMover;
use Modules\Properties\Models\Property;
use Modules\Tenants\Handover\TenantsMover;

/*
 * P03-14 (ARCHITECTURE.md § 6.1, § 4): the one sanctioned way to change a
 * record's organization. BelongsToOrganization still refuses it on every
 * save; BuildingMove::reassign() works only while OrganizationMoves runs a
 * move, only on organization-owned rows that are in the organization the
 * buildings leave; only the management handover runs moves.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->from = Organization::factory()->create(['slug' => 'eze-properties']);
    $this->to = Organization::factory()->create(['slug' => 'acme-homes']);
    $this->building = inOrganization($this->from, fn () => Property::factory()->create(['name' => 'Palm Court']));
    $this->theirs = inOrganization($this->to, fn () => Property::factory()->create(['name' => 'Office Block']));
});

afterEach(fn () => app(OrganizationContext::class)->clear());

/** A mover the test runs inside a real move (registered last-but-media). */
final class ProbeMover implements MovesBuildingRecords
{
    /** @var Closure(BuildingMove): void|null */
    public static ?Closure $step = null;

    public function order(): int
    {
        return 5;
    }

    public function move(BuildingMove $move): void
    {
        (self::$step)($move);
    }
}

function runProbeMove(Closure $step): BuildingMove
{
    ProbeMover::$step = $step;
    $movers = new BuildingRecordMovers(app());
    $movers->register(ProbeMover::class);
    app()->instance(BuildingRecordMovers::class, $movers);

    return DB::transaction(fn () => app(OrganizationMoves::class)->run(test()->from, test()->to, MoveDirection::Handover, [test()->building->id], 1, test()->from, null));
}

it('still refuses to move a record to another organization on save', function () {
    expect(fn () => inOrganization($this->from, function () {
        $building = Property::query()->findOrFail($this->building->id);
        $building->organization_id = $this->to->id;
        $building->save();
    }))->toThrow(CrossOrganizationWrite::class);

    expect(DB::table('properties')->where('id', $this->building->id)->value('organization_id'))->toBe($this->from->id);
});

it('changes a record\'s organization only while a move runs', function () {
    $move = new BuildingMove($this->from, $this->to, MoveDirection::Handover, [$this->building->id], 1, $this->from, null, null, [], app(OrganizationContext::class));

    expect(fn () => $move->reassign(Property::class, [$this->building->id]))->toThrow(LogicException::class, 'only while a building move runs');

    runProbeMove(fn (BuildingMove $move) => $move->reassign(Property::class, [$this->building->id]));
    expect(DB::table('properties')->where('id', $this->building->id)->value('organization_id'))->toBe($this->to->id);

    // Once the move is over, its object no longer moves anything.
    expect(fn () => $move->reassign(Property::class, [$this->building->id]))->toThrow(LogicException::class);
});

it('refuses rows that are not in the organization the buildings leave, and records that are not organization-owned', function () {
    expect(fn () => runProbeMove(fn (BuildingMove $move) => $move->reassign(Property::class, [$this->building->id, $this->theirs->id])))
        ->toThrow(BuildingMoveFailed::class, '1 of 2 records are no longer in');
    expect(fn () => runProbeMove(fn (BuildingMove $move) => $move->reassign(User::class, [1])))->toThrow(LogicException::class, 'not an organization-owned model')
        ->and(fn () => runProbeMove(fn (BuildingMove $move) => $move->reassign(Property::class, [$this->building->id], ['organization_id' => $this->theirs->organization_id])))->toThrow(LogicException::class, 'set by the move itself');

    expect(DB::table('properties')->orderBy('id')->pluck('organization_id', 'id')->all())->toBe([$this->building->id => $this->from->id, $this->theirs->id => $this->to->id]);
});

it('runs every module\'s mover, in order, media last', function () {
    expect(array_map(fn (MovesBuildingRecords $mover): string => $mover::class, app(BuildingRecordMovers::class)->all()))
        ->toBe([BuildingsMover::class, TenantsMover::class, LeasesMover::class, UtilitiesMover::class, MediaMover::class]);
});

it('lets only the management handover run moves, and only OrganizationMoves start one', function () {
    $modules = collect(File::directories(base_path('Modules')))->map(fn (string $path): string => basename($path))->reject(fn (string $module): bool => $module === 'Handovers');

    expect('App')->not->toUse(OrganizationMoves::class)->ignoring(OrganizationMoves::class);

    foreach ($modules as $module) {
        expect("Modules\\{$module}")->not->toUse(OrganizationMoves::class);
    }

    // `new BuildingMove(` appears in OrganizationMoves only (outside tests).
    $makers = collect([...File::allFiles(app_path()), ...File::allFiles(base_path('Modules'))])
        ->filter(fn ($file): bool => $file->getExtension() === 'php' && ! str_contains($file->getPathname(), '/tests/'))
        ->filter(fn ($file): bool => str_contains($file->getContents(), 'new BuildingMove('))
        ->map(fn ($file): string => str_replace(base_path().'/', '', $file->getPathname()))
        ->values()
        ->all();
    expect($makers)->toBe(['app/Tenancy/Moves/OrganizationMoves.php']);
});
