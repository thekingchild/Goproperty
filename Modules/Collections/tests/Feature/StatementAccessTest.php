<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Exports\ListExports;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Modules\Collections\Lists\StatementLineList;
use Modules\Collections\Models\BankStatementImport;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../../tests/Support/impersonation.php';

/*
 * P03-07a: who may see and upload bank statements (ARCHITECTURE.md § 5
 * "Bank statements", BankStatementImportPolicy): org-owners and org-admins.
 * Agents see no statement, narrowed or not (a statement is the whole
 * account's history with no building to narrow by); property owners,
 * tenants and strangers get 404. Another organization's statement, lines
 * and file answer 404 everywhere. A suspended organization reads and
 * exports but uploads nothing; impersonating staff read and export but
 * never upload nor download the file. Both lists export.
 */

beforeEach(function () {
    Storage::fake('media');
    seedOrganizationRoles();
    $this->travelTo('2026-02-03 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    $this->otherOwner = joinAs(User::factory()->create(['name' => 'Bola Other']), $this->other);
    $this->account = statementAccount($this->acme, $this->owner);
    $this->statement = uploadStatement($this->acme, $this->owner, $this->account, statementCsv(gtStatementRows(januaryTransactions())));
    $theirAccount = statementAccount($this->other, $this->otherOwner, '0987654321', '044');
    $this->foreign = uploadStatement($this->other, $this->otherOwner, $theirAccount, statementCsv(gtStatementRows([
        ['03/01/2026', 'ZX001', '', '999,000.00', '9,999,000.00', 'FOREIGN CREDIT FROM ZAINAB'],
    ], '0987654321'), 'their-statement.csv'));
});

afterEach(fn () => exportsFreshRequest());

/** @return array<string, bool> ability => allowed, as BankStatementImportPolicy answers inside the organization */
function statementAbilitiesOf(User $user, Organization $organization, BankStatementImport $statement): array
{
    return inOrganization($organization, fn (): array => [
        'viewAny' => Gate::forUser($user)->allows('viewAny', BankStatementImport::class),
        'view' => Gate::forUser($user)->allows('view', $statement),
        'export' => Gate::forUser($user)->allows('export', BankStatementImport::class),
        'create' => Gate::forUser($user)->allows('create', BankStatementImport::class),
        'downloadFile' => Gate::forUser($user)->allows('downloadFile', $statement),
        'update' => Gate::forUser($user)->allows('update', $statement),
        'delete' => Gate::forUser($user)->allows('delete', $statement),
    ]);
}

it('lets only org-owners and org-admins see and upload statements', function (OrganizationRole $role, bool $allowed) {
    $user = joinAs(User::factory()->create(), $this->acme, $role);

    expect(statementAbilitiesOf($user, $this->acme, $this->statement))->toBe([
        'viewAny' => $allowed, 'view' => $allowed, 'export' => $allowed, 'create' => $allowed,
        'downloadFile' => $allowed, 'update' => false, 'delete' => false,
    ]);
})->with([
    'org-owner' => [OrganizationRole::Owner, true],
    'org-admin' => [OrganizationRole::Admin, true],
    'agent' => [OrganizationRole::Agent, false],
    'property-owner' => [OrganizationRole::PropertyOwner, false],
    'tenant' => [OrganizationRole::Tenant, false],
]);

it('grants the statement permissions to owners and administrators, classed for both central guards', function () {
    expect(OrganizationRole::Owner->permissions())->toContain(OrganizationPermission::ViewStatements, OrganizationPermission::ImportStatements)
        ->and(OrganizationRole::Admin->permissions())->toContain(OrganizationPermission::ViewStatements, OrganizationPermission::ImportStatements)
        ->and(OrganizationRole::Agent->permissions())->not->toContain(OrganizationPermission::ViewStatements)
        ->and(OrganizationRole::Agent->permissions())->not->toContain(OrganizationPermission::ImportStatements)
        ->and(OrganizationPermission::ViewStatements->allowedWhileSuspended())->toBeTrue()
        ->and(OrganizationPermission::ViewStatements->blockedWhileImpersonating())->toBeFalse()
        ->and(OrganizationPermission::ImportStatements->allowedWhileSuspended())->toBeFalse()
        ->and(OrganizationPermission::ImportStatements->blockedWhileImpersonating())->toBeTrue();
});

it('opens the statement pages for owners and administrators, 403 for agents, 404 for property owners, tenants and strangers', function () {
    $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);

    foreach ([$this->owner, $admin] as $user) {
        statementsPage($user)->assertOk()->assertSee('gtbank-january.csv')->assertSee('data-nav="statements"', false)
            ->assertSee('data-test="export-bank_statements-csv"', false)->assertSee('data-test="upload-statement"', false);
        statementsPage($user, "/{$this->statement->id}")->assertOk()->assertSee('TRF FROM KEMI ADEBAYO GP-7KQ2M9X RENT')
            ->assertSee('data-test="export-bank_statement_lines-csv"', false)->assertSee('data-test="download-statement"', false);
        statementsPage($user, '/upload')->assertOk()->assertSee('data-test="read-statement"', false);
        statementsPage($user, "/{$this->statement->id}/file")->assertRedirectContains('/media/');
    }

    foreach ([joinAs(User::factory()->create(), $this->acme, OrganizationRole::PropertyOwner), joinAs(User::factory()->create(), $this->acme, OrganizationRole::Tenant), User::factory()->create()] as $user) {
        statementsPage($user)->assertNotFound();
        statementsPage($user, "/{$this->statement->id}")->assertNotFound();
        statementsPage($user, '/upload')->assertNotFound();
        statementsPage($user, "/{$this->statement->id}/file")->assertNotFound();
    }
});

it('shows agents no statement, whether or not they are assigned to buildings', function () {
    $unassigned = joinAs(User::factory()->create(['name' => 'Chidi Agent']), $this->acme, OrganizationRole::Agent);
    $narrowed = joinAs(User::factory()->create(['name' => 'Dayo Agent']), $this->acme, OrganizationRole::Agent);
    assignIn($this->acme, $narrowed, buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']));

    foreach ([$unassigned, $narrowed] as $agent) {
        exportPage($agent, '')->assertOk()->assertDontSee('data-nav="statements"', false);
        statementsPage($agent)->assertForbidden();
        statementsPage($agent, "/{$this->statement->id}")->assertForbidden();
        statementsPage($agent, '/upload')->assertForbidden();
        statementsPage($agent, "/{$this->statement->id}/file")->assertForbidden();

        expect(fn () => uploadStatement($this->acme, $agent, $this->account, statementCsv(gtStatementRows(januaryTransactions()))))->toThrow(AuthorizationException::class)
            ->and(fn () => inOrganization($this->acme, fn () => app(ListExports::class)->find('bank_statement_lines')?->authorize($agent, [])))->toThrow(AuthorizationException::class);
    }

    // An export sent from an owner's page snapshot by an agent is refused too.
    $snapshot = LivewireHttp::snapshot(statementsPage($this->owner, "/{$this->statement->id}")->assertOk());
    statementsUpdate($unassigned, $snapshot, [], [['export', ['bank_statement_lines', 'csv']]])->assertForbidden();
});

it('answers 404 for another organization\'s statement guessed by ID, and never shows its lines', function () {
    statementsPage($this->owner, "/{$this->foreign->id}")->assertNotFound();
    statementsPage($this->owner, "/{$this->foreign->id}/file")->assertNotFound();
    statementsPage($this->owner, '/999999')->assertNotFound();
    statementsPage($this->owner, '/abc')->assertNotFound();
    statementsPage($this->owner)->assertOk()->assertDontSee('their-statement.csv');

    // Their statement's id as a filter of ours, or of the export, finds nothing of theirs.
    $ours = statementsPage($this->owner, "/{$this->statement->id}?q=ZAINAB")->assertOk();
    expect(listedStatementLines($ours))->toBe([]);
    $rows = exportRows($this->owner, "/statements/{$this->statement->id}", 'bank_statement_lines', 'csv');
    expect(exportColumn($rows, 'Narration'))->not->toContain('FOREIGN CREDIT FROM ZAINAB')
        ->and(inOrganization($this->acme, fn () => app(StatementLineList::class)->query($this->owner, ['statement' => $this->foreign->id])->count()))->toBe(0);

    // A tampered snapshot pointing at their statement (production: debug off answers 419).
    config(['app.debug' => false]);
    $data = json_decode(LivewireHttp::snapshot(statementsPage($this->owner, "/{$this->statement->id}")->assertOk()), true);
    $data['data']['statementId'] = $this->foreign->id;
    statementsUpdate($this->owner, (string) json_encode($data), ['search' => 'x'])->assertStatus(419);

    // Their owner, a member of both, and the other workspace.
    statementsPage($this->otherOwner, "/{$this->statement->id}", 'other-realty')->assertNotFound();
    statementsPage($this->otherOwner, '', 'acme-homes')->assertNotFound();
    joinAs($this->owner, $this->other);
    expect(listedStatements(statementsPage($this->owner)))->toBe([$this->statement->id])
        ->and(listedStatements(statementsPage($this->owner, '', 'other-realty')))->toBe([$this->foreign->id]);
    statementsPage($this->owner, "/{$this->foreign->id}", 'acme-homes')->assertNotFound();
    statementsPage($this->owner, "/{$this->foreign->id}", 'other-realty')->assertOk()->assertSee('FOREIGN CREDIT FROM ZAINAB');
});

it('keeps statements read-only while the organization is suspended: read and export, no upload', function () {
    $upload = LivewireHttp::snapshot(statementsPage($this->owner, '/upload')->assertOk());
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    statementsPage($this->owner)->assertOk()->assertSee('gtbank-january.csv')->assertSee('data-test="export-bank_statements-csv"', false)->assertDontSee('data-test="upload-statement"', false);
    statementsPage($this->owner, "/{$this->statement->id}")->assertOk()->assertSee('TRF FROM KEMI ADEBAYO GP-7KQ2M9X RENT');
    statementsPage($this->owner, '/upload')->assertForbidden();
    statementsUpdate($this->owner, $upload, [], [['read']])->assertForbidden();
    expect(fn () => uploadStatement($this->acme, $this->owner, $this->account, statementCsv(gtStatementRows(januaryTransactions()))))->toThrow(AuthorizationException::class);

    $rows = exportRows($this->owner, "/statements/{$this->statement->id}", 'bank_statement_lines', 'csv');
    expect(count($rows))->toBe(6)
        ->and(inOrganization($this->acme, fn () => BankStatementImport::query()->count()))->toBe(1);
});

it('lets impersonating staff look at statements and export them, but never upload one or download its file, in either mode', function (ImpersonationMode $mode) {
    seedStaffRoles();
    $upload = LivewireHttp::snapshot(statementsPage($this->owner, '/upload')->assertOk());
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    $handOff = startSupport($staff, $this->owner, $this->acme, $mode);
    enterSupport($handOff)->assertRedirect('/o/acme-homes');

    asSupport()->get('/o/acme-homes/statements')->assertOk()->assertSee('gtbank-january.csv')->assertDontSee('data-test="upload-statement"', false);
    $show = asSupport()->get("/o/acme-homes/statements/{$this->statement->id}")->assertOk()
        ->assertSee('TRF FROM KEMI ADEBAYO GP-7KQ2M9X RENT')->assertDontSee('data-test="download-statement"', false);
    asSupport()->get('/o/acme-homes/statements/upload')->assertForbidden();
    asSupport()->get("/o/acme-homes/statements/{$this->statement->id}/file")->assertForbidden();

    freshRequest();
    LivewireHttp::update(test(), $upload, [], [['read']])->assertForbidden();
    freshRequest();
    LivewireHttp::update(test(), $upload, [], [['import']])->assertForbidden();
    freshRequest();
    $pressed = LivewireHttp::update(test(), LivewireHttp::snapshot($show), [], [['export', ['bank_statement_lines', 'csv']]])->assertOk();

    expect(LivewireHttp::redirect($pressed))->toContain('/exports/')
        ->and(inOrganization($this->acme, fn () => BankStatementImport::query()->count()))->toBe(1);

    if ($mode === ImpersonationMode::Write) {
        expect(AuditEntry::query()->where('event', 'impersonation.blocked')->where('subject_id', $handOff->session->id)->exists())->toBeTrue();
    }
})->with([
    'read-only' => [ImpersonationMode::ReadOnly],
    'write' => [ImpersonationMode::Write],
]);

it('exports the statements list and a statement\'s lines, in Excel and CSV, with the page\'s filters', function (string $format) {
    $rows = exportRows($this->owner, '/statements', 'bank_statements', $format);
    expect($rows[0])->toBe(['Bank account (masked)', 'File', 'Format', 'From', 'To', 'Transactions read', 'Lines added', 'Credits added', 'Debits (not matched)', 'Duplicates skipped', 'Unreadable rows', 'Status', 'Uploaded by', 'Uploaded on'])
        ->and(array_slice($rows, 1))->toBe([['Guaranty Trust Bank (GTBank) ···6789', 'gtbank-january.csv', 'CSV', '2026-01-02', '2026-01-28', '5', '5', '3', '2', '0', '0', 'Ready', 'Ada Owner', '2026-02-03 11:00']]);

    $lines = exportRows($this->owner, "/statements/{$this->statement->id}", 'bank_statement_lines', $format);
    expect($lines[0])->toBe(['Date', 'Narration', 'Sender or beneficiary', 'Reference', 'Credit', 'Debit', 'Balance', 'Match status', 'Bank account (masked)', 'Statement', 'Row in the file'])
        ->and($lines[1])->toBe(['2026-01-02', 'TRF FROM KEMI ADEBAYO GP-7KQ2M9X RENT', '', 'FT26002A1', '250000.00', '', '1250000.00', 'To match', 'Guaranty Trust Bank (GTBank) ···6789', 'gtbank-january.csv', '8'])
        ->and($lines[2])->toBe(['2026-01-05', 'POS PURCHASE SHOPRITE LEKKI', '', 'FT26005B2', '', '15000.00', '1235000.00', 'Not matched (debit)', 'Guaranty Trust Bank (GTBank) ···6789', 'gtbank-january.csv', '9'])
        ->and(count($lines))->toBe(6);

    // The page's filters go into the export: credits to match only, and a search.
    $credits = exportRows($this->owner, "/statements/{$this->statement->id}", 'bank_statement_lines', $format, ['direction' => 'credit', 'status' => 'unmatched']);
    expect(exportColumn($credits, 'Narration'))->toBe(['TRF FROM KEMI ADEBAYO GP-7KQ2M9X RENT', 'NIP TRF FROM TUNDE BAKARE FLAT 3B', 'TRF FROM AMAKA OBI GP-3HW8D2T'])
        ->and(exportColumn(exportRows($this->owner, "/statements/{$this->statement->id}", 'bank_statement_lines', $format, ['search' => 'bakare']), 'Narration'))->toBe(['NIP TRF FROM TUNDE BAKARE FLAT 3B']);
})->with(['xlsx', 'csv']);

it('filters the statements list by account and status', function () {
    $second = statementAccount($this->acme, $this->owner, '0222222222', '044');
    $feb = uploadStatement($this->acme, $this->owner, $second, statementCsv(gtStatementRows(januaryTransactions(), '0222222222'), 'access-feb.csv'));

    expect(listedStatements(statementsPage($this->owner)))->toBe([$feb->id, $this->statement->id])
        ->and(listedStatements(statementsPage($this->owner, '?account='.$second->id)))->toBe([$feb->id])
        ->and(listedStatements(statementsPage($this->owner, '?account='.$this->account->id.'&status=ready')))->toBe([$this->statement->id])
        ->and(listedStatements(statementsPage($this->owner, '?status=failed')))->toBe([]);
});
