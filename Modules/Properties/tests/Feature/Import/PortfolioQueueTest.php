<?php

declare(strict_types=1);

use App\Hosting\Queue\QueueTick;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Jobs\CommitImportSheet;
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

it('commits a 2,000-row workbook on the imports queue, each job well within 50 seconds on the shared profile', function () {
    config(['queue.default' => 'database']);

    $batch = portfolioDryRun($this->acme, $this->owner, portfolioWorkbook(portfolioFixture()));
    $batch = portfolioCommit($this->acme, $this->owner, $batch);

    $job = DB::table('jobs')->sole();
    expect($batch->status)->toBe(ImportStatus::Committing)
        ->and($job->queue)->toBe('imports')
        ->and(json_decode($job->payload, true)['displayName'])->toBe(CommitImportSheet::class)
        ->and(json_decode($job->payload, true)['timeout'])->toBe(50)
        ->and(config('hosting.queue_tick.job_timeout'))->toBeGreaterThanOrEqual(50);

    $runs = [];
    $startedAt = null;
    Event::listen(JobProcessing::class, function () use (&$startedAt): void {
        $startedAt = hrtime(true);
    });
    Event::listen(JobProcessed::class, function (JobProcessed $event) use (&$runs, &$startedAt): void {
        $command = unserialize($event->job->payload()['data']['command']);
        $runs[] = [$command->target, (hrtime(true) - $startedAt) / 1e9];
    });

    // The in-process queue tick, as cron runs it on a shared host.
    for ($tick = 0; $tick < 20 && inOrganization($this->acme, fn () => $batch->fresh())->status === ImportStatus::Committing; $tick++) {
        app(QueueTick::class)->run();
    }

    $batch = inOrganization($this->acme, fn () => $batch->fresh());

    expect($batch->status)->toBe(ImportStatus::Committed)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(array_map('count', portfolioTables()))->toEqual(['owner_profiles' => 100, 'properties' => 200, 'units' => 1500, 'tenant_profiles' => 200])
        // One sheet after another in dependency order.
        ->and(array_values(array_unique(array_column($runs, 0))))->toBe(['owners', 'properties', 'units', 'tenants'])
        // Each job stops after import.job_seconds (30) and queues the rest, so none comes near 50 s.
        ->and(max(array_column($runs, 1)))->toBeLessThan(35.0);
});

it('never matches another organization\'s owner, building or unit', function () {
    // Another organization holds the same names, phones, IDs and labels, some archived.
    $other = Organization::factory()->create(['slug' => 'other-realty']);
    $theirOwner = ownerIn($other, ['first_name' => 'Owner1', 'last_name' => 'Adeyemi', 'phone' => portfolioOwnerPhone(1)]);
    inOrganization($other, fn () => $theirOwner->forceFill(['external_ref' => 'O-1'])->save());
    $theirArchivedOwner = ownerIn($other, ['first_name' => 'Owner2', 'last_name' => 'Adeyemi', 'phone' => portfolioOwnerPhone(2)]);
    inOrganization($other, fn () => $theirArchivedOwner->delete());
    $theirBuilding = buildingIn($other, ['name' => 'Building 1', 'owner_profile_id' => $theirOwner->id]);
    inOrganization($other, fn () => $theirBuilding->forceFill(['external_ref' => 'B-1'])->save());
    $theirUnit = unitIn($other, $theirBuilding, ['unit_label' => 'Flat 1']);
    inOrganization($other, fn () => $theirUnit->forceFill(['external_ref' => 'U-1-1'])->save());
    $theirArchivedUnit = unitIn($other, $theirBuilding, ['unit_label' => 'Flat 2']);
    inOrganization($other, fn () => $theirArchivedUnit->delete());
    $theirs = fn (): array => [ownersOf($other)->toArray(), buildingsOf($other)->toArray(), unitsOf($other)->toArray()];
    $before = $theirs();

    $sheets = [
        'Owners' => portfolioOwnerRows(2),
        'Buildings' => [...portfolioBuildingRows(1, 2), ['', 'Building 2', 'Owner2 Adeyemi', 'Block of flats', '2 Palm Avenue', 'Lagos', '', '', '', '', '']],
        'Units' => [...portfolioUnitRows(1, 2), ['', 'Building 2', 'Flat 1', 'Flat', '', '', '', '', '900,000', '', '', '']],
    ];

    // A building or owner only the other organization has is unknown here.
    $unknown = portfolioDryRun($this->acme, $this->owner, portfolioWorkbook(['Units' => [PORTFOLIO_UNITS, ['', 'B-1', 'Flat 9', 'Flat', '', '', '', '', '900,000', '', '', '']], 'Buildings' => [PORTFOLIO_BUILDINGS, ['', 'Building 9', 'O-1', 'Estate', '9 Palm Avenue', 'Lagos', '', '', '', '', '']]]));
    expect(portfolioIssues($unknown, 'properties'))->toBe([[2, 'Owner', 'C', 'error', 'No owner is called or has the number "O-1", here or in the Owners sheet. Add them to the Owners sheet, or leave Owner empty when your organization owns the building.']])
        ->and(portfolioIssues($unknown, 'units'))->toBe([[2, 'Building', 'B', 'error', 'No building is called "B-1", here or in the Buildings sheet. Add it to the Buildings sheet first.']]);

    $batch = portfolioDryRun($this->acme, $this->owner, portfolioWorkbook($sheets));

    expect($batch->dry_run_report['counts'])->toEqual(['create' => 7, 'update' => 0, 'restore' => 0, 'skip' => 0])
        // No "archived" warning about the other organization's archived owner or unit.
        ->and(collect($batch->dry_run_report['sheets'])->flatMap(fn (array $sheet) => $sheet['issues'])->pluck('severity')->all())->toBe(['warning'])
        ->and(collect($batch->dry_run_report['sheets'])->flatMap(fn (array $sheet) => $sheet['issues'])->first()['message'])->toContain('You have 0 billable units now');

    $batch = portfolioCommit($this->acme, $this->owner, $batch);
    $owners = ownersOf($this->acme);

    expect($batch->result_counts['targets']['units'])->toEqual(['create' => 3, 'update' => 0, 'restore' => 0, 'skip' => 0])
        ->and($owners)->toHaveCount(2)
        ->and($owners->pluck('organization_id')->unique()->values()->all())->toBe([$this->acme->id])
        ->and(buildingsOf($this->acme)->pluck('owner_profile_id')->all())->toBe($owners->pluck('id')->all())
        ->and(unitsOf($this->acme)->pluck('property_id')->unique()->values()->all())->toBe(buildingsOf($this->acme)->pluck('id')->all())
        ->and($theirs())->toEqual($before);

    // A rollback here never touches the other organization's records.
    portfolioRollback($this->acme, $this->owner, $batch);
    expect($theirs())->toEqual($before)
        ->and(unitsOf($this->acme)->filter->trashed())->toHaveCount(3);
});
