<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\ChecklistItem;
use App\Hosting\Queue\QueueTick;
use App\Models\OnboardingChecklistItem;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Modules\Import\Batches\DryRunner;
use Modules\Import\Batches\ImportBatches;
use Modules\Import\Batches\ImportRefused;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Jobs\CommitImportSheet;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Tests\Fixtures\ContactsTarget;
use Modules\Import\Tests\Fixtures\ImportFixtures;

require_once __DIR__.'/helpers.php';

/*
 * P02-06a: the queued commit (one job per sheet, in dependency order, on
 * the `imports` queue, each within the shared host's 50 s) and the 7-day
 * rollback (DATA_IMPORT.md § 2.4, § 2 "Undo"), on the fixture targets.
 */

beforeEach(function () {
    Storage::fake('media');
    ImportFixtures::install();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
});

afterEach(function () {
    ImportFixtures::drop();
    app(OrganizationContext::class)->clear();
});

function commitImport(Organization $organization, User $by, ImportBatch $batch): ImportBatch
{
    inAcme($organization, fn () => app(ImportBatches::class)->commit($by, $batch));

    return freshBatch($organization, $batch);
}

function rollbackImport(Organization $organization, User $by, ImportBatch $batch): ImportBatch
{
    return inAcme($organization, fn () => app(ImportBatches::class)->rollback($by, $batch));
}

it('commits exactly the records the dry run previewed', function () {
    inAcme($this->acme, fn () => DB::table(ImportFixtures::CONTACTS)->insert(['organization_id' => $this->acme->id, 'name' => 'Old name', 'phone' => '08030000003', 'created_at' => now(), 'updated_at' => now()]));
    $batch = dryRunImport($this->acme, $this->owner, importWorkbook(['Contacts' => contactRows(40), 'Notes' => [['Contact phone', 'Note'], ['08030000001', 'Prefers WhatsApp'], ['08030000003', 'Old contact']]]));
    $previewed = $batch->dry_run_report;

    $batch = commitImport($this->acme, $this->owner, $batch);
    $contacts = contactsOf($this->acme);

    expect($batch->status)->toBe(ImportStatus::Committed)
        ->and($batch->committed_by_user_id)->toBe($this->owner->id)
        ->and($batch->rows_done)->toBe($batch->rows_total)
        ->and($batch->result_counts['targets']['contacts'])->toEqual($previewed['sheets'][0]['counts'])
        ->and($batch->result_counts['targets']['notes'])->toEqual($previewed['sheets'][1]['counts'])
        ->and($previewed['sheets'][0]['counts'])->toEqual(['create' => 39, 'update' => 1, 'restore' => 0, 'skip' => 0])
        ->and($contacts)->toHaveCount(40)
        ->and(notesOf($this->acme))->toHaveCount(2);

    // Created rows carry the batch; the updated one keeps its own provenance.
    $updated = $contacts->firstWhere('phone', '08030000003');
    $created = $contacts->firstWhere('phone', '08030000007');
    expect($updated->name)->toBe('Contact 3')
        ->and($updated->import_batch_id)->toBeNull()
        ->and($created->import_batch_id)->toBe($batch->id)
        ->and($created->external_ref)->toBe('C-7')
        ->and($created->balance_kobo)->toBe(150050)
        ->and($created->joined_on)->toBe('2025-02-01')
        ->and($created->category)->toBe('Silver')
        ->and(AuditEntry::query()->where('log_name', 'imports')->where('event', 'import.committed')->exists())->toBeTrue()
        // The setup checklist's data item is done.
        ->and(inAcme($this->acme, fn () => OnboardingChecklistItem::query()->where('item', ChecklistItem::Data->value)->sole())->completed_by_user_id)->toBe($this->owner->id);
});

it('creates no duplicates when the same file is imported again, and restores archived matches', function () {
    $file = fn () => importWorkbook(['Contacts' => contactRows(10)]);
    commitImport($this->acme, $this->owner, dryRunImport($this->acme, $this->owner, $file()));
    inAcme($this->acme, fn () => ContactsTarget::table()->where('external_ref', 'C-4')->update(['deleted_at' => now()]));

    $again = dryRunImport($this->acme, $this->owner, $file());
    expect($again->dry_run_report['counts'])->toEqual(['create' => 0, 'update' => 9, 'restore' => 1, 'skip' => 0]);

    $again = commitImport($this->acme, $this->owner, $again);
    $restored = contactsOf($this->acme)->firstWhere('external_ref', 'C-4');

    expect(contactsOf($this->acme))->toHaveCount(10)
        ->and($again->result_counts['targets']['contacts'])->toEqual(['create' => 0, 'update' => 9, 'restore' => 1, 'skip' => 0])
        ->and($restored->deleted_at)->toBeNull()
        ->and($restored->import_batch_id)->toBe($again->id);
});

it('commits sheet by sheet in dependency order on the imports queue, each job well within 50 seconds on the shared profile', function () {
    config(['queue.default' => 'database', 'import.job_seconds' => 1, 'import.chunk_rows' => 50]);

    // The Notes sheet comes first in the file but depends on Contacts.
    $notes = [['Contact phone', 'Note']];
    for ($i = 1; $i <= 300; $i++) {
        $notes[] = ['0803'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), "Note {$i}"];
    }
    $batch = dryRunImport($this->acme, $this->owner, importWorkbook(['Notes' => $notes, 'Contacts' => contactRows(2000)]));
    expect($batch->status)->toBe(ImportStatus::DryRunPassed);

    $batch = commitImport($this->acme, $this->owner, $batch);
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

    for ($tick = 0; $tick < 20 && freshBatch($this->acme, $batch)->status === ImportStatus::Committing; $tick++) {
        app(QueueTick::class)->run();
    }

    $batch = freshBatch($this->acme, $batch);
    $targets = array_column($runs, 0);

    expect($batch->status)->toBe(ImportStatus::Committed)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(contactsOf($this->acme))->toHaveCount(2000)
        ->and(notesOf($this->acme))->toHaveCount(300)
        // The Contacts sheet needed more than one job (each stops after
        // import.job_seconds and queues the rest), and Notes came after it.
        ->and(count(array_filter($targets, fn (string $t): bool => $t === 'contacts')))->toBeGreaterThan(1)
        ->and(array_values(array_unique($targets)))->toBe(['contacts', 'notes'])
        ->and(max(array_column($runs, 1)))->toBeLessThan(25.0);
});

it('carries on from the last saved chunk after a failure, never writing a row twice', function () {
    config(['import.chunk_rows' => 50, 'queue.default' => 'database']);
    $batch = commitImport($this->acme, $this->owner, dryRunImport($this->acme, $this->owner, importWorkbook(['Contacts' => contactRows(200)])));
    $job = new CommitImportSheet($this->acme->id, $batch->id, 'contacts');

    ContactsTarget::$failOnName = 'Contact 120';
    expect(fn () => $job->handle(app(OrganizationContext::class), app(DryRunner::class)))->toThrow(RuntimeException::class, 'Fixture failure.');

    // Two chunks of 50 were saved with the cursor; the third rolled back.
    $stopped = freshBatch($this->acme, $batch);
    expect(contactsOf($this->acme))->toHaveCount(100)
        ->and($stopped->rows_done)->toBe(100)
        ->and($stopped->commit_cursor)->toEqual(['target' => 'contacts', 'row' => 102]);

    ContactsTarget::$failOnName = null;
    $job->handle(app(OrganizationContext::class), app(DryRunner::class));
    // A repeated job finds nothing left to do.
    $job->handle(app(OrganizationContext::class), app(DryRunner::class));

    $batch = freshBatch($this->acme, $batch);
    expect($batch->status)->toBe(ImportStatus::Committed)
        ->and(contactsOf($this->acme))->toHaveCount(200)
        ->and(contactsOf($this->acme)->pluck('phone')->unique())->toHaveCount(200)
        ->and($batch->result_counts['targets']['contacts']['create'])->toBe(200);
});

it('marks the batch as stopped part-way when a job gives up, and it can still be rolled back', function () {
    config(['import.chunk_rows' => 5, 'queue.default' => 'database']);
    $batch = commitImport($this->acme, $this->owner, dryRunImport($this->acme, $this->owner, importWorkbook(['Contacts' => contactRows(20)])));
    $job = new CommitImportSheet($this->acme->id, $batch->id, 'contacts');
    ContactsTarget::$failOnName = 'Contact 12';

    try {
        $job->handle(app(OrganizationContext::class), app(DryRunner::class));
    } catch (RuntimeException) {
        $job->failed(new RuntimeException('gave up'));
    }

    $batch = freshBatch($this->acme, $batch);
    expect($batch->status)->toBe(ImportStatus::PartiallyFailed)
        ->and($batch->failure_reason)->toContain('row 12')
        ->and(contactsOf($this->acme))->toHaveCount(10);

    rollbackImport($this->acme, $this->owner, $batch);
    expect(contactsOf($this->acme)->whereNull('deleted_at'))->toHaveCount(0);
});

it('rolls back within 7 days: what the batch created or restored is archived, updates stay, audited', function () {
    inAcme($this->acme, fn () => DB::table(ImportFixtures::CONTACTS)->insert(['organization_id' => $this->acme->id, 'name' => 'Kept', 'phone' => '08030000001', 'created_at' => now(), 'updated_at' => now()]));
    $batch = commitImport($this->acme, $this->owner, dryRunImport($this->acme, $this->owner, importWorkbook(['Contacts' => contactRows(5), 'Notes' => [['Contact phone', 'Note'], ['08030000002', 'x']]])));

    $this->travel(6)->days();
    $batch = rollbackImport($this->acme, $this->owner, $batch);
    $contacts = contactsOf($this->acme);
    $entry = AuditEntry::query()->where('log_name', 'imports')->where('event', 'import.rolled_back')->sole();

    expect($batch->status)->toBe(ImportStatus::RolledBack)
        ->and($batch->rolled_back_by_user_id)->toBe($this->owner->id)
        ->and($contacts)->toHaveCount(5)
        ->and($contacts->whereNull('deleted_at')->pluck('phone')->all())->toBe(['08030000001'])
        ->and($contacts->firstWhere('phone', '08030000001')->name)->toBe('Contact 1')
        ->and(notesOf($this->acme)->whereNull('deleted_at'))->toHaveCount(0)
        ->and($entry->causer_id)->toBe($this->owner->id)
        ->and($entry->properties['archived'])->toEqual(['notes' => 1, 'contacts' => 4]);

    // Not twice.
    expect(fn () => rollbackImport($this->acme, $this->owner, freshBatch($this->acme, $batch)))->toThrow(AuthorizationException::class);
});

it('refuses a rollback after 7 days', function () {
    $batch = commitImport($this->acme, $this->owner, dryRunImport($this->acme, $this->owner, importWorkbook(['Contacts' => contactRows(3)])));

    $this->travel(7)->days();
    $this->travel(1)->minutes();

    expect(fn () => rollbackImport($this->acme, $this->owner, $batch))->toThrow(ImportRefused::class, 'Imports can be rolled back for 7 days')
        ->and(freshBatch($this->acme, $batch)->status)->toBe(ImportStatus::Committed)
        ->and(contactsOf($this->acme)->whereNull('deleted_at'))->toHaveCount(3);
});

it('refuses a rollback once a record of the batch has been used', function () {
    $batch = commitImport($this->acme, $this->owner, dryRunImport($this->acme, $this->owner, importWorkbook(['Contacts' => contactRows(3)])));
    inAcme($this->acme, fn () => ContactsTarget::table()->where('external_ref', 'C-2')->update(['used_at' => now()]));

    expect(fn () => rollbackImport($this->acme, $this->owner, $batch))->toThrow(ImportRefused::class, 'have been used since')
        ->and(freshBatch($this->acme, $batch)->status)->toBe(ImportStatus::Committed)
        ->and(contactsOf($this->acme)->whereNull('deleted_at'))->toHaveCount(3);
});

it('commits only after a passed dry run, and only once', function () {
    $batch = uploadImport($this->acme, $this->owner, importWorkbook(['Contacts' => contactRows(2)]));
    expect(fn () => commitImport($this->acme, $this->owner, $batch))->toThrow(ImportRefused::class, 'Run a dry run without errors');

    $batch = commitImport($this->acme, $this->owner, inAcme($this->acme, fn () => app(ImportBatches::class)->dryRun($this->owner, $batch)));
    expect(fn () => commitImport($this->acme, $this->owner, $batch))->toThrow(ImportRefused::class)
        ->and(contactsOf($this->acme))->toHaveCount(2);
});
