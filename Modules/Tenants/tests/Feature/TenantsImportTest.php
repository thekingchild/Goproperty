<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\Import\Batches\ImportBatches;
use Modules\Import\Batches\ImportRefused;
use Modules\Import\Contracts\ImportTargets;
use Modules\Import\Enums\ImportFileType;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Reading\SpreadsheetFile;
use Modules\Tenants\Enums\KycStatus;
use Modules\Tenants\Enums\TenantIdType;
use Modules\Tenants\Import\TenantsImportTarget;
use Modules\Tenants\Models\TenantProfile;

require_once __DIR__.'/helpers.php';

/*
 * P02-06b: tenants arrive by import (DATA_IMPORT.md § 1 row 4), through the
 * real import engine: the Tenants target registered by the Tenants module,
 * phone as the natural key, exact per-row errors, re-import by phone,
 * archived matches restored (P02 decision 3), rollback, and nothing shared
 * across organizations.
 */

beforeEach(function () {
    Storage::fake('media');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
});

afterEach(function () {
    app(OrganizationContext::class)->clear();
});

it('is registered into the import engine by the Tenants module', function () {
    $target = app(ImportTargets::class)->find('tenants');

    expect($target)->toBeInstanceOf(TenantsImportTarget::class)
        ->and($target->label())->toBe('Tenants')
        ->and(array_map(fn ($column) => $column->key, array_filter($target->columns(), fn ($column) => $column->required)))->toBe([1 => 'first_name', 3 => 'phone']);
});

it('reports a missing or bad phone, and every other bad cell, with its exact row, column and message', function () {
    $rows = tenantRows(3);
    $rows[2][3] = '';                                    // row 3: no phone
    $rows[3][3] = '0803 12';                             // row 4: not a phone number
    array_splice($rows, 4, 0, [array_fill(0, 12, '')]);  // row 5 blank: ignored, numbering kept
    $rows[] = ['T-6', 'Twin', 'Okafor', '+234 803 000 0001', '', '', '', '', '', '', '', ''];  // row 6: row 2's phone
    $rows[] = ['T-7', 'Bola', 'Ade', '0803 000 0007', 'bola@', '', '', '', '0805', '', '', ''];  // row 7: email, next of kin phone
    $rows[] = ['T-8', 'Uche', 'Eze', '0803 000 0008', '', '', '', '', '', '', 'NIN', '1234'];   // row 8: NIN format
    $rows[] = ['T-9', 'Ngozi', 'Obi', '0803 000 0009', '', '', '', '', '', '', '', 'A01234567'];  // row 9: number without a type
    $rows[] = ['T-10', 'Femi', 'Ojo', '0803 000 0010', '', '', '', '', '', '', 'Library card', ''];  // row 10: unknown type
    $rows[] = ['T-11', '', '', '0803 000 0011', '', '', '', '', '', '', '', ''];                // row 11: no name
    $rows[] = ['T-12', 'Kemi', '', '0803 000 0012', '', '', '', '', '', '', '', ''];            // row 12: one name only
    $rows[] = ['T-2', 'Sade', 'Lawal', '0803 000 0013', '', '', '', '', '', '', '', ''];        // row 13: row 3's ID

    $batch = tenantsDryRun($this->acme, $this->owner, tenantsWorkbook(['Tenants' => $rows]));
    $sheet = $batch->dry_run_report['sheets'][0];

    expect($batch->status)->toBe(ImportStatus::DryRunFailed)
        ->and($sheet['target'])->toBe('tenants')
        ->and($sheet['rows'])->toBe(11)
        ->and(tenantIssues($batch))->toBe([
            [3, 'Phone', 'D', 'error', 'Enter the tenant\'s phone number.'],
            [4, 'Phone', 'D', 'error', '"0803 12" is not a phone number. Enter it like 0803 123 4567 or +234 803 123 4567.'],
            [6, 'Phone', 'D', 'error', 'This phone number is already on row 2. Each tenant goes on one row with their own number.'],
            [7, 'Email', 'E', 'error', '"bola@" is not an email address.'],
            [7, 'Next of kin phone', 'I', 'error', '"0805" is not a phone number. Enter it like 0803 123 4567 or +234 803 123 4567.'],
            [8, 'ID number', 'L', 'error', 'A NIN is 11 digits, such as 12345678901.'],
            [9, 'ID type', 'K', 'error', 'Choose the type of ID for this number: NIN, International passport, Driver\'s licence, Voter\'s card.'],
            [10, 'ID type', 'K', 'error', 'Choose one of: NIN, International passport, Driver\'s licence, Voter\'s card.'],
            [11, 'First name', 'B', 'error', 'Enter the tenant\'s first name.'],
            [12, 'Last name', 'C', 'error', 'Enter the tenant\'s last name, or their full name under First name.'],
            [13, 'Your ID', 'A', 'error', 'This ID is already on row 3. Each tenant goes on one row.'],
        ])
        ->and($sheet['errors'])->toBe(11)
        // Only row 2 passes.
        ->and($sheet['counts'])->toEqual(['create' => 1, 'update' => 0, 'restore' => 0, 'skip' => 0])
        ->and(tenantsOf($this->acme))->toHaveCount(0);

    // A failed dry run cannot be committed.
    expect(fn () => tenantsCommit($this->acme, $this->owner, $batch))->toThrow(ImportRefused::class);
    expect(tenantsOf($this->acme))->toHaveCount(0);
});

it('lists the bad phones in the downloadable problems workbook, next to their rows', function () {
    $rows = tenantRows(2);
    $rows[2][3] = 'call me';

    $batch = tenantsDryRun($this->acme, $this->owner, tenantsWorkbook(['Tenants' => $rows]));
    $response = $this->actingAs($this->owner)->get("/o/acme-homes/imports/{$batch->id}/problems")->assertOk();
    $path = (string) tempnam(sys_get_temp_dir(), 'gp-problems-');
    copy($response->baseResponse->getFile()->getPathname(), $path);
    $sheet = array_values(iterator_to_array(SpreadsheetFile::open($path, ImportFileType::Xlsx)->rows(0), false));

    expect($sheet[0])->toBe([...TENANT_HEADER, 'Problems', 'Row in your file'])
        ->and($sheet[2][12])->toBe('Phone (D): "call me" is not a phone number. Enter it like 0803 123 4567 or +234 803 123 4567.')
        ->and($sheet[2][13])->toBe(3);
});

it('writes nothing during a dry run', function () {
    $existing = tenantIn($this->acme, ['phone' => tenantPhone(1), 'occupation' => 'Teacher']);
    $batch = inOrganization($this->acme, fn () => app(ImportBatches::class)->upload($this->owner, tenantsWorkbook(['Tenants' => tenantRows(20)])));
    $before = DB::table('tenant_profiles')->orderBy('id')->get()->toArray();

    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql) === 1
            && ! preg_match('/`(import_batches|activity_log|audit_chain_head)`/', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $batch = inOrganization($this->acme, fn () => app(ImportBatches::class)->dryRun($this->owner, $batch));

    expect($writes)->toBe([])
        ->and(DB::table('tenant_profiles')->orderBy('id')->get()->toArray())->toEqual($before)
        ->and($batch->status)->toBe(ImportStatus::DryRunPassed)
        ->and($batch->dry_run_report['counts'])->toEqual(['create' => 19, 'update' => 1, 'restore' => 0, 'skip' => 0])
        ->and($existing->fresh()->occupation)->toBe('Teacher');
});

it('creates the previewed tenants on commit, phones in E.164, provenance on each, KYC unverified, nobody contacted', function () {
    Notification::fake();
    Mail::fake();

    $rows = tenantRows(3);
    $rows[] = ['', 'Mary Ann Okafor', '', '2348030000004', 'MARY@Example.COM', '', '', '', '', '', 'passport', 'a0123 4567'];

    $batch = tenantsDryRun($this->acme, $this->owner, tenantsWorkbook(['Tenants' => $rows]));
    expect($batch->dry_run_report['counts'])->toEqual(['create' => 4, 'update' => 0, 'restore' => 0, 'skip' => 0])
        ->and($batch->dry_run_report['sheets'][0]['preview']['columns'])->toBe(['First name', 'Last name', 'Phone', 'Email', 'Occupation', 'ID type'])
        ->and($batch->dry_run_report['sheets'][0]['preview']['rows'][0]['values'])->toBe(['Tenant1', 'Okafor', '+2348030000001', 'tenant1@example.com', 'Accountant', 'NIN']);

    $batch = tenantsCommit($this->acme, $this->owner, $batch);
    $tenants = tenantsOf($this->acme);
    $first = $tenants->firstWhere('external_ref', 'T-1');
    $mary = $tenants->firstWhere('phone', '+2348030000004');

    expect($batch->status)->toBe(ImportStatus::Committed)
        ->and($batch->result_counts['targets']['tenants'])->toEqual(['create' => 4, 'update' => 0, 'restore' => 0, 'skip' => 0])
        ->and($tenants)->toHaveCount(4)
        ->and($tenants->pluck('import_batch_id')->unique()->all())->toBe([$batch->id])
        ->and($tenants->pluck('organization_id')->unique()->all())->toBe([$this->acme->id])
        ->and($first->phone)->toBe('+2348030000001')
        ->and($first->first_name)->toBe('Tenant1')
        ->and($first->email)->toBe('tenant1@example.com')
        ->and($first->address)->toBe('1 Allen Avenue, Ikeja')
        ->and($first->emergency_contact)->toBe(['name' => 'Chidi Okafor', 'phone' => '+2348059876543', 'relationship' => 'Brother'])
        ->and($first->id_type)->toBe(TenantIdType::Nin)
        ->and($first->id_value)->toBe('10000000001')
        ->and($first->kyc_status)->toBe(KycStatus::Unverified)
        ->and($first->user_id)->toBeNull()
        // A full name under First name is split; the passport number is kept in capitals.
        ->and([$mary->first_name, $mary->last_name, $mary->email, $mary->external_ref])->toBe(['Mary Ann', 'Okafor', 'mary@example.com', null])
        ->and([$mary->id_type, $mary->id_value])->toBe([TenantIdType::Passport, 'A01234567']);

    // DATA_IMPORT.md § 2.6, AGENT.md § 4: imported tenants are never contacted.
    Notification::assertNothingSent();
    Mail::assertNothingOutgoing();
});

it('maps an agency\'s own tenant list by its headers', function () {
    $file = tenantsCsv([
        ['Tenant name', 'Mobile', 'E-mail', 'Job', 'NOK', 'NOK phone', 'Means of ID', 'NIN'],
        ['Chinedu Obi', '08031112222', 'chinedu@example.com', 'Banker', 'Ifeoma Obi', '08033334444', 'NIN', '12345678901'],
    ], 'my tenants.csv');

    $batch = tenantsImport($this->acme, $this->owner, $file);
    $tenant = tenantsOf($this->acme)->sole();

    expect($batch->status)->toBe(ImportStatus::Committed)
        ->and($batch->sheets()[0]->target)->toBe('tenants')
        ->and([$tenant->first_name, $tenant->last_name, $tenant->phone, $tenant->occupation, $tenant->id_value])->toBe(['Chinedu', 'Obi', '+2348031112222', 'Banker', '12345678901'])
        ->and($tenant->emergency_contact)->toBe(['name' => 'Ifeoma Obi', 'phone' => '+2348033334444', 'relationship' => null]);
});

it('updates a tenant matched by phone when re-imported, instead of adding them twice', function () {
    $first = tenantsImport($this->acme, $this->owner, tenantsWorkbook(['Tenants' => tenantRows(5)]));

    // The same tenants again, phones written another way, no IDs, a new
    // occupation, and an empty email cell (kept as it is).
    $rows = [TENANT_HEADER];
    foreach (range(1, 5) as $n) {
        $rows[] = ['', "Tenant{$n}", 'Okafor-Eze', '+234 803 000 000'.$n, '', '', 'Engineer', '', '', '', '', ''];
    }
    $rows[] = ['', 'New', 'Person', '0803 000 0006', '', '', '', '', '', '', '', ''];

    $again = tenantsDryRun($this->acme, $this->owner, tenantsWorkbook(['Tenants' => $rows]));
    expect($again->dry_run_report['counts'])->toEqual(['create' => 1, 'update' => 5, 'restore' => 0, 'skip' => 0])
        ->and($again->dry_run_report['errors'])->toBe(0);

    $again = tenantsCommit($this->acme, $this->owner, $again);
    $tenants = tenantsOf($this->acme);
    $updated = $tenants->firstWhere('phone', tenantPhone(3));

    expect($tenants)->toHaveCount(6)
        ->and($again->result_counts['targets']['tenants'])->toEqual(['create' => 1, 'update' => 5, 'restore' => 0, 'skip' => 0])
        ->and($updated->last_name)->toBe('Okafor-Eze')
        ->and($updated->occupation)->toBe('Engineer')
        ->and($updated->email)->toBe('tenant3@example.com')
        ->and($updated->external_ref)->toBe('T-3')
        ->and($updated->id_value)->toBe('10000000003')
        // Updated tenants keep the batch that created them.
        ->and($updated->import_batch_id)->toBe($first->id)
        ->and($tenants->firstWhere('phone', tenantPhone(6))->import_batch_id)->toBe($again->id);

    // The very same file a third time: nothing new.
    $third = tenantsImport($this->acme, $this->owner, tenantsWorkbook(['Tenants' => $rows]));
    expect($third->result_counts['targets']['tenants'])->toEqual(['create' => 0, 'update' => 6, 'restore' => 0, 'skip' => 0])
        ->and(tenantsOf($this->acme))->toHaveCount(6);
});

it('matches by the agency\'s ID first, so a tenant\'s new phone number updates them', function () {
    tenantsImport($this->acme, $this->owner, tenantsWorkbook(['Tenants' => tenantRows(2)]));
    $rows = tenantRows(2);
    $rows[1][3] = '0907 777 8888';

    $batch = tenantsImport($this->acme, $this->owner, tenantsWorkbook(['Tenants' => $rows]));

    expect($batch->result_counts['targets']['tenants'])->toEqual(['create' => 0, 'update' => 2, 'restore' => 0, 'skip' => 0])
        ->and(tenantsOf($this->acme)->firstWhere('external_ref', 'T-1')->phone)->toBe('+2349077778888')
        ->and(tenantsOf($this->acme))->toHaveCount(2);
});

it('refuses a row whose ID is one tenant\'s and whose phone is another\'s, and warns when a tenant\'s ID changes', function () {
    tenantsImport($this->acme, $this->owner, tenantsWorkbook(['Tenants' => tenantRows(2)]));
    $rows = tenantRows(2);
    $rows[1][3] = tenantPhone(2, local: true);   // T-1 with T-2's phone
    $rows[2][0] = 'NEW-2';                        // T-2's phone with a new ID
    $rows[2][3] = tenantPhone(2, local: true);

    $batch = tenantsDryRun($this->acme, $this->owner, tenantsWorkbook(['Tenants' => $rows]));

    expect(tenantIssues($batch))->toBe([
        [2, 'Phone', 'D', 'error', 'This phone number belongs to another tenant, Tenant2 Okafor. Each tenant needs their own number.'],
        [3, 'Phone', 'D', 'error', 'This phone number is already on row 2. Each tenant goes on one row with their own number.'],
        [3, 'Your ID', 'A', 'warning', 'This tenant was imported before with ID "T-2". Their ID becomes "NEW-2".'],
    ]);

    $rows = tenantRows(2);
    $rows[2][0] = 'NEW-2';
    $batch = tenantsDryRun($this->acme, $this->owner, tenantsWorkbook(['Tenants' => $rows]));

    expect(tenantIssues($batch))->toBe([
        [3, 'Your ID', 'A', 'warning', 'This tenant was imported before with ID "T-2". Their ID becomes "NEW-2".'],
    ])->and($batch->status)->toBe(ImportStatus::DryRunPassed);
});

it('restores an archived tenant matched by phone, audited, and a rollback archives them again', function () {
    $archived = tenantIn($this->acme, ['phone' => tenantPhone(2), 'first_name' => 'Old', 'last_name' => 'Name', 'external_ref' => null]);
    inOrganization($this->acme, fn () => $archived->delete());
    $kept = tenantIn($this->acme, ['phone' => tenantPhone(3), 'occupation' => 'Teacher']);

    $batch = tenantsDryRun($this->acme, $this->owner, tenantsWorkbook(['Tenants' => tenantRows(3)]));
    expect($batch->dry_run_report['counts'])->toEqual(['create' => 1, 'update' => 1, 'restore' => 1, 'skip' => 0])
        ->and(tenantIssues($batch))->toBe([[3, null, null, 'warning', 'This tenant was archived. Importing brings them back.']])
        ->and($batch->status)->toBe(ImportStatus::DryRunPassed);

    $batch = tenantsCommit($this->acme, $this->owner, $batch);
    $restored = inOrganization($this->acme, fn () => TenantProfile::query()->withTrashed()->findOrFail($archived->id));

    expect($batch->result_counts['targets']['tenants'])->toEqual(['create' => 1, 'update' => 1, 'restore' => 1, 'skip' => 0])
        ->and(tenantsOf($this->acme))->toHaveCount(3)
        ->and($restored->trashed())->toBeFalse()
        ->and($restored->first_name)->toBe('Tenant2')
        ->and($restored->external_ref)->toBe('T-2')
        ->and($restored->import_batch_id)->toBe($batch->id);

    $audit = AuditEntry::query()->where('log_name', 'tenants')->where('event', 'tenant.restored')->sole();
    expect($audit->subject_id)->toBe($archived->id)
        ->and($audit->properties['import_batch_id'])->toBe($batch->id)
        ->and($audit->properties['row'])->toBe(3);

    // Rollback archives what the batch created or restored; the updated tenant stays, with its changes.
    $batch = tenantsRollback($this->acme, $this->owner, $batch);
    $tenants = tenantsOf($this->acme);

    expect($batch->status)->toBe(ImportStatus::RolledBack)
        ->and($tenants->firstWhere('id', $archived->id)->trashed())->toBeTrue()
        ->and($tenants->firstWhere('phone', tenantPhone(1))->trashed())->toBeTrue()
        ->and($tenants->firstWhere('id', $kept->id)->trashed())->toBeFalse()
        ->and($tenants->firstWhere('id', $kept->id)->occupation)->toBe('Accountant')
        ->and(AuditEntry::query()->where('event', 'import.rolled_back')->sole()->properties['archived'])->toEqual(['tenants' => 2]);

    // Importing the file again brings both back instead of colliding with them.
    $again = tenantsImport($this->acme, $this->owner, tenantsWorkbook(['Tenants' => tenantRows(3)]));
    expect($again->result_counts['targets']['tenants'])->toEqual(['create' => 0, 'update' => 1, 'restore' => 2, 'skip' => 0])
        ->and(tenantsOf($this->acme)->filter->trashed())->toHaveCount(0);
});

it('refuses a rollback once an imported tenant has been given portal access', function () {
    $batch = tenantsImport($this->acme, $this->owner, tenantsWorkbook(['Tenants' => tenantRows(2)]));
    inOrganization($this->acme, fn () => TenantProfile::query()->where('external_ref', 'T-2')->update(['user_id' => User::factory()->create()->id]));

    expect(fn () => tenantsRollback($this->acme, $this->owner, $batch))->toThrow(ImportRefused::class, 'Some tenants from this import have been used since');
    expect(tenantsOf($this->acme)->filter->trashed())->toHaveCount(0);
});

it('never matches another organization\'s tenant with the same phone or ID', function () {
    $other = Organization::factory()->create(['slug' => 'other-realty']);
    $theirs = tenantIn($other, ['phone' => tenantPhone(1), 'external_ref' => 'T-2', 'first_name' => 'Theirs']);
    $theirArchived = tenantIn($other, ['phone' => tenantPhone(3), 'first_name' => 'Archived']);
    inOrganization($other, fn () => $theirArchived->delete());

    $batch = tenantsDryRun($this->acme, $this->owner, tenantsWorkbook(['Tenants' => tenantRows(3)]));
    expect($batch->dry_run_report['counts'])->toEqual(['create' => 3, 'update' => 0, 'restore' => 0, 'skip' => 0])
        ->and($batch->dry_run_report['warnings'])->toBe(0);

    $batch = tenantsCommit($this->acme, $this->owner, $batch);
    $ours = tenantsOf($this->acme);

    expect($ours)->toHaveCount(3)
        ->and($ours->pluck('organization_id')->unique()->all())->toBe([$this->acme->id])
        ->and($ours->pluck('phone')->all())->toBe([tenantPhone(1), tenantPhone(2), tenantPhone(3)])
        ->and($ours->pluck('id')->intersect([$theirs->id, $theirArchived->id]))->toBeEmpty();

    // The other organization's tenants are as they were, and a rollback here never touches them.
    tenantsRollback($this->acme, $this->owner, $batch);
    $theirsNow = tenantsOf($other);

    expect($theirsNow)->toHaveCount(2)
        ->and($theirsNow->firstWhere('id', $theirs->id)->first_name)->toBe('Theirs')
        ->and($theirsNow->firstWhere('id', $theirs->id)->trashed())->toBeFalse()
        ->and($theirsNow->firstWhere('id', $theirs->id)->import_batch_id)->toBeNull()
        ->and($theirsNow->firstWhere('id', $theirArchived->id)->trashed())->toBeTrue()
        // Both organizations hold a tenant with the same phone: unique per organization only.
        ->and(DB::table('tenant_profiles')->where('phone', tenantPhone(1))->count())->toBe(2);
});

it('keeps the ID number encrypted at rest, out of arrays and out of the dry-run report', function () {
    $batch = tenantsImport($this->acme, $this->owner, tenantsWorkbook(['Tenants' => tenantRows(1)]));
    $tenant = tenantsOf($this->acme)->sole();
    $raw = DB::table('tenant_profiles')->where('id', $tenant->id)->value('id_value');

    expect($raw)->not->toBe('10000000001')
        ->and($raw)->not->toContain('10000000001')
        ->and(Crypt::decryptString($raw))->toBe('10000000001')
        ->and($tenant->id_value)->toBe('10000000001')
        ->and($tenant->toArray())->not->toHaveKey('id_value')
        ->and($tenant->maskedId())->toBe('NIN ···0001')
        ->and(json_encode($batch->dry_run_report))->not->toContain('10000000001')
        ->and(json_encode($batch->result_counts))->not->toContain('10000000001');
});

it('makes a checked tenant unverified again when an import changes their ID number', function () {
    tenantsImport($this->acme, $this->owner, tenantsWorkbook(['Tenants' => tenantRows(2)]));
    inOrganization($this->acme, fn () => TenantProfile::query()->update(['kyc_status' => KycStatus::Verified->value]));
    $rows = tenantRows(2);
    $rows[1][11] = '22222222222';

    $batch = tenantsDryRun($this->acme, $this->owner, tenantsWorkbook(['Tenants' => $rows]));
    expect(tenantIssues($batch))->toBe([[2, 'ID number', 'L', 'warning', 'This tenant\'s ID was checked. A different ID number makes them unverified again.']]);

    tenantsCommit($this->acme, $this->owner, $batch);
    $tenants = tenantsOf($this->acme);

    expect($tenants->firstWhere('external_ref', 'T-1')->kyc_status)->toBe(KycStatus::Unverified)
        ->and($tenants->firstWhere('external_ref', 'T-1')->id_value)->toBe('22222222222')
        ->and($tenants->firstWhere('external_ref', 'T-2')->kyc_status)->toBe(KycStatus::Verified);
});

it('checks each kind of ID number', function (string $type, string $number, ?string $error) {
    $rows = [TENANT_HEADER, ['', 'Ada', 'Obi', '0803 000 0001', '', '', '', '', '', '', $type, $number]];

    $batch = tenantsDryRun($this->acme, $this->owner, tenantsWorkbook(['Tenants' => $rows]));

    expect(array_column(tenantIssues($batch), 4))->toBe($error === null ? [] : [$error]);
})->with([
    'NIN' => ['NIN', '123 4567 8901', null],
    'NIN too short' => ['nin', '1234567890', 'A NIN is 11 digits, such as 12345678901.'],
    'passport' => ['International passport', 'A01234567', null],
    'passport, other spelling' => ['Intl passport', 'b 1234 5678', null],
    'passport without its letter' => ['Passport', '012345678', 'An international passport number is a letter and 8 digits, such as A01234567.'],
    'driver\'s licence' => ['Drivers license', 'ABC12345AA12', null],
    'driver\'s licence too short' => ['Driver\'s licence', 'AB1234', 'A driver\'s licence number starts with three letters, such as ABC12345AA12.'],
    'voter\'s card' => ['PVC', '90F5 B0F8 E729 5346 795', null],
    'voter\'s card too short' => ['Voter\'s card', '90F5B0F8', 'A voter\'s card number (VIN) is 19 letters and digits, such as 90F5B0F8E7295346795.'],
    'type without number' => ['NIN', '', 'Enter the ID number, or leave the ID type empty.'],
    'none with a number' => ['None', '12345678901', 'Choose the type of ID for this number: NIN, International passport, Driver\'s licence, Voter\'s card.'],
    'none and no number' => ['None', '', null],
]);

it('refuses markup and over-long text in tenant cells', function () {
    $rows = [TENANT_HEADER, ['', '<b>Ada</b>', 'Obi', '0803 000 0001', '', str_repeat('a', 301), '', '', '', '', '', '']];

    $batch = tenantsDryRun($this->acme, $this->owner, tenantsWorkbook(['Tenants' => $rows]));

    expect(tenantIssues($batch))->toBe([
        [2, 'First name', 'B', 'error', 'Use plain text only (no < or >).'],
        [2, 'Address', 'F', 'error', 'Use at most 300 characters.'],
    ]);
});

it('offers a Tenants sheet in the import template, which maps itself when filled in', function () {
    $response = $this->actingAs($this->owner)->get('/o/acme-homes/imports/template')->assertOk();
    $path = (string) tempnam(sys_get_temp_dir(), 'gp-template-');
    copy($response->baseResponse->getFile()->getPathname(), $path);
    $file = SpreadsheetFile::open($path, ImportFileType::Xlsx);
    $sheets = array_column($file->sheets(), 'name');
    $index = array_search('Tenants', $sheets, true);
    $rows = array_values(iterator_to_array($file->rows((int) $index), false));
    $instructions = collect(iterator_to_array($file->rows(0), false))->flatten()->implode(' ');

    expect($index)->not->toBeFalse()
        ->and($rows[0])->toBe(TENANT_HEADER)
        ->and($rows[1])->toBe(['T-001', 'Adaeze', 'Okafor', '0803 123 4567', 'adaeze@example.com', '12 Allen Avenue, Ikeja', 'Accountant', 'Chidi Okafor', '0805 987 6543', 'Brother', 'NIN', '12345678901'])
        ->and($instructions)->toContain('The phone number is how GoProperty recognises a tenant')
        ->and($instructions)->toContain('NIN, International passport, Driver\'s licence, Voter\'s card');

    // The ID type column (K) offers the document types as a dropdown.
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = (string) $zip->getFromName('xl/worksheets/sheet'.($index + 1).'.xml');
    $zip->close();
    expect($xml)->toContain('sqref="K2:K10001"');

    // The template as downloaded imports its example tenant.
    $batch = tenantsImport($this->acme, $this->owner, new UploadedFile($path, 'template.xlsx', null, null, true));
    $tenant = tenantsOf($this->acme)->sole();

    expect($batch->status)->toBe(ImportStatus::Committed)
        ->and([$tenant->external_ref, $tenant->phone, $tenant->id_type])->toBe(['T-001', '+2348031234567', TenantIdType::Nin]);
});
