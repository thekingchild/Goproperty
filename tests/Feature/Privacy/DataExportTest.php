<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Models\DataRequest;
use App\Models\Organization;
use App\Models\User;
use App\Privacy\Data\PersonalDataMap;
use App\Privacy\Data\RowSerializer;
use App\Privacy\DataRequestRefused;
use App\Privacy\DataRequests;
use App\Privacy\DataRequestStatus;
use App\Privacy\Erasure\RetentionEnforcer;
use App\Privacy\Export\DataExportBuilder;
use App\Privacy\Jobs\RunDataRequest;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OrganizationScopedModels;
use Tests\Support\PersonalDataWorld as World;

/*
 * P09-02 acceptance: "Export contains all of a tenant's data and nothing of
 * others". Two organizations, look-alike people (the same name in one
 * organization, and one person who rents from both), and the organization-
 * scoped models' own rows as noise; the export of one tenant holds exactly
 * their rows of every table the map says holds tenants' rows.
 */

beforeEach(function () {
    Storage::fake('media');
    Storage::fake('data-exports');
    $this->staff = staffMember();
    $this->acme = Organization::factory()->create(['name' => 'Acme Realty']);
    $this->bayo = Organization::factory()->create(['name' => 'Bayo Homes']);

    // Ada rents from both organizations with one portal account; another Ada Obi rents from Acme too.
    $this->ada = User::factory()->create(['name' => 'Ada Obi', 'email' => 'ada@example.ng', 'phone' => '+2348031110001']);
    $this->otherAda = User::factory()->create(['name' => 'Ada Obi', 'email' => 'ada.obi@example.ng', 'phone' => '+2348031110002']);
    $this->chidi = User::factory()->create(['name' => 'Chidi Eze', 'email' => 'chidi@example.ng', 'phone' => '+2348031110003']);

    $acmeBuilding = World::building($this->acme->id);
    $bayoBuilding = World::building($this->bayo->id);
    $this->mine = World::tenant($this->acme->id, $acmeBuilding, 'Ada', 'Obi', '+2348031110001', 'ada@example.ng', $this->ada->id);
    $this->lookAlike = World::tenant($this->acme->id, $acmeBuilding, 'Ada', 'Obi', '+2348031110002', 'ada.obi@example.ng', $this->otherAda->id, '99999999999');
    $this->neighbour = World::tenant($this->acme->id, $acmeBuilding, 'Chidi', 'Eze', '+2348031110003', 'chidi@example.ng', $this->chidi->id, '55555555555');
    $this->elsewhere = World::tenant($this->bayo->id, $bayoBuilding, 'Ada', 'Obi', '+2348031110001', 'ada@example.ng', $this->ada->id);

    // One row of every organization-scoped model in each organization (they bring their own tenants).
    foreach ([$this->acme, $this->bayo] as $organization) {
        app(OrganizationContext::class)->run($organization, function (): void {
            foreach (array_keys(OrganizationScopedModels::factories()) as $class) {
                OrganizationScopedModels::make($class);
            }
        });
    }
});

/** @return array{tables: array<string, list<array<string, mixed>>>, files: list<string>, manifest: array<string, mixed>, text: string} */
function readDataExport(DataRequest $request): array
{
    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('data-exports')->path((string) $request->file_path)))->toBeTrue();
    $tables = [];
    $files = [];
    $text = '';

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $content = (string) $zip->getFromIndex($i);
        $text .= $content;

        if (str_starts_with($name, 'data/')) {
            $tables[basename($name, '.json')] = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } elseif (str_starts_with($name, 'files/')) {
            $files[] = $name;
        }
    }

    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
    $zip->close();

    return ['tables' => $tables, 'files' => $files, 'manifest' => $manifest, 'text' => $text];
}

it('acceptance: a tenant\'s export holds every one of their rows in every table, and nothing of anyone else', function () {
    $tenantId = $this->mine['tenant_profiles'][0];
    $request = app(DataRequests::class)->export($this->acme, $tenantId, 'Ticket 4411: the tenant asked by email; identity checked against the lease.', $this->staff);
    $request->refresh();

    expect($request->status)->toBe(DataRequestStatus::Ready)
        ->and($request->file_sha256)->toBe(hash_file('sha256', Storage::disk('data-exports')->path((string) $request->file_path)));

    $export = readDataExport($request);
    $map = app(PersonalDataMap::class);

    // Walk every organization-scoped model: its table is either a tenant's
    // (and then the world gave Ada rows there, all of them exported and
    // only those) or says why a tenant's export leaves it out.
    $walked = [];

    foreach (array_keys(OrganizationScopedModels::factories()) as $class) {
        $table = (new $class)->getTable();
        $rule = $map->rule($table);
        $walked[$table] = true;

        if ($rule->person === null) {
            expect($export['tables'])->not->toHaveKey($table)
                ->and($export['manifest']['tables_left_out'])->toHaveKey($table);

            continue;
        }

        expect($this->mine[$table] ?? [])->not->toBeEmpty("the world gives Ada no {$table} row")
            ->and(array_column($export['tables'][$table], 'id'))->toEqualCanonicalizing($this->mine[$table], "{$table} holds other rows than Ada's");
    }

    // The tables with no model of their own (the consent records) too.
    foreach ($map->rules() as $table => $rule) {
        if ($rule->person !== null && ! isset($walked[$table])) {
            expect(array_column($export['tables'][$table], 'id'))->toEqualCanonicalizing($this->mine[$table] ?? []);
        }
    }

    // Nothing of the look-alike, the neighbour, or Ada's own tenancy at Bayo Homes.
    foreach ([$this->lookAlike, $this->neighbour, $this->elsewhere] as $someoneElse) {
        foreach ($someoneElse as $table => $ids) {
            expect(array_intersect(array_column($export['tables'][$table] ?? [], 'id'), $ids))->toBe([], "{$table} holds another person's rows");
        }
    }

    expect($export['text'])->not->toContain('+2348031110002', 'ada.obi@example.ng', 'Chidi', '99999999999', 'Internal: ')
        // Ada's own files: the proof, the ticket attachment, the issued receipt.
        ->and($export['files'])->toHaveCount(3)
        // Her ID number only by its last four characters; no token hash.
        ->and($export['tables']['tenant_profiles'][0]['id_value'])->toBe(RowSerializer::MASK.' 8901')
        ->and($export['text'])->not->toContain('12345678901')
        ->and($export['tables']['invitations'][0])->not->toHaveKey('token_hash')
        ->and($export['manifest']['tenant_profile_id'])->toBe($tenantId)
        ->and($export['manifest']['rows']['tenant_profiles'])->toBe(1);
});

it('acceptance: an organization\'s export holds every row of its own in every table it exports, and nothing of the other organization', function () {
    $request = app(DataRequests::class)->export($this->acme, null, 'Owner asked for a copy before leaving', $this->staff);
    $export = readDataExport($request->refresh());
    $builder = app(DataExportBuilder::class);
    $exported = $builder->tables($request);

    foreach (app(PersonalDataMap::class)->rules() as $table => $rule) {
        $acme = DB::table($table)->where('organization_id', $this->acme->id)->pluck('id')->map(intval(...))->all();

        if (! isset($exported[$table])) {
            expect($export['tables'])->not->toHaveKey($table)
                ->and($export['manifest']['tables_left_out'][$table])->toBe($rule->notInOrganizationExport);

            continue;
        }

        expect(array_column($export['tables'][$table], 'id'))->toEqualCanonicalizing($acme, "{$table} is not exactly Acme's rows")
            ->and(collect($export['tables'][$table])->pluck('organization_id')->unique()->filter()->values()->all())->toBe(count($acme) > 0 ? [$this->acme->id] : []);
    }

    $acmeFiles = DB::table('media')->where('organization_id', $this->acme->id)->count();

    // Every file on the disk (the scoped models' probe media rows have none).
    expect(count($export['files']) + $export['manifest']['files_missing_from_storage'])->toBe($acmeFiles)
        ->and($export['files'])->toHaveCount(count($this->mine['media']) + count($this->lookAlike['media']) + count($this->neighbour['media']))
        ->and($export['tables'])->not->toHaveKey('compliance_flags')
        ->and($export['tables'])->not->toHaveKey('impersonation_sessions')
        // Secrets never leave; encrypted values only by their last four characters.
        ->and($export['text'])->not->toContain('0123456789')
        ->and($export['tables']['organization_bank_accounts'][0])->not->toHaveKey('account_fingerprint')
        ->and($export['tables']['organization_bank_accounts'][0]['account_number'])->toBe(RowSerializer::MASK.' 6789');
});

it('builds a big export over several runs, keeping its place', function () {
    // Fewer than 1 second per run: the job queues itself again until it is done.
    $request = DataRequest::query()->forceCreate([
        'kind' => 'export', 'scope' => 'organization', 'organization_id' => $this->acme->id,
        'status' => DataRequestStatus::Running, 'reason' => 'Test', 'requested_by_staff_id' => $this->staff->id,
    ]);
    $builder = app(DataExportBuilder::class);
    $runs = 0;

    do {
        $done = app(OrganizationContext::class)->run($this->acme, fn (): bool => $builder->work($request->refresh(), microtime(true) + 0.01));
        $runs++;
    } while (! $done && $runs < 500);

    expect($done)->toBeTrue()
        ->and($runs)->toBeGreaterThan(3)
        ->and(readDataExport($request->refresh())['tables']['tenant_profiles'])->toHaveCount(DB::table('tenant_profiles')->where('organization_id', $this->acme->id)->count());
});

it('refuses a tenant of another organization, and the same export twice at once', function () {
    $bayoTenant = $this->elsewhere['tenant_profiles'][0];

    expect(fn () => app(DataRequests::class)->export($this->acme, $bayoTenant, 'Wrong organization', $this->staff))
        ->toThrow(DataRequestRefused::class, 'no such tenant');

    $this->withoutExceptionHandling();
    Queue::fake();
    app(DataRequests::class)->export($this->acme, $this->mine['tenant_profiles'][0], 'First', $this->staff);

    expect(fn () => app(DataRequests::class)->export($this->acme, $this->mine['tenant_profiles'][0], 'Again', $this->staff))
        ->toThrow(DataRequestRefused::class, 'already being made');
    Queue::assertPushed(RunDataRequest::class, 1);
});

it('audits the request and the ready file without personal data, and deletes the file after 7 days', function () {
    $this->freezeTime();
    $request = app(DataRequests::class)->export($this->acme, $this->mine['tenant_profiles'][0], 'Ticket 4411 for Ada Obi', $this->staff)->refresh();
    $entries = AuditEntry::query()->where('log_name', 'privacy')->orderBy('id')->get();

    expect($entries->pluck('event')->all())->toBe(['data_export.requested', 'data_export.ready'])
        ->and(json_encode($entries->pluck('properties')))->not->toContain('Ada')
        ->and($entries->first()->description)->not->toContain('Ada');

    $this->travel(7)->days();
    app(RetentionEnforcer::class)->run();

    expect(Storage::disk('data-exports')->exists((string) $request->file_path))->toBeFalse()
        ->and($request->refresh()->file_deleted_at)->not->toBeNull()
        ->and($request->isDownloadable())->toBeFalse();
});
