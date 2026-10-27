<?php

declare(strict_types=1);

use App\Banking\BankAccounts;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Collections\Livewire\StatementUpload;
use Modules\Collections\Models\BankStatementImport;
use Modules\Collections\Statements\StatementImports;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../../tests/Support/media.php';

/*
 * P03-07a sub-AC "upload is validated": the statement file is checked
 * before anything is stored (UploadValidator, collection `statements`: a
 * renamed script or a zip that is not an Excel workbook is refused,
 * whatever its name), and so is the bank account (one of this
 * organization's, not removed; a statement whose heading names another
 * account number is refused). Then the upload page: account and file,
 * columns, import.
 */

beforeEach(function () {
    Storage::fake('media');
    seedOrganizationRoles();
    $this->travelTo('2026-02-03 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    $this->account = statementAccount($this->acme, $this->owner);
});

afterEach(fn () => exportsFreshRequest());

/** Expects the upload refused with this message on this field, and nothing stored. */
function expectStatementRefused(Closure $upload, string $field, string $message): void
{
    expect($upload)->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey($field)
        ->and(implode(' ', $e->errors()[$field]))->toContain($message));

    expect(DB::table('bank_statement_imports')->count())->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([]);
}

/** A zip with these entries, named like a workbook. */
function statementZip(array $entries, string $name = 'statement.xlsx'): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'gp-statement-zip-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);

    foreach ($entries as $entry => $content) {
        $zip->addFromString($entry, $content);
    }

    $zip->close();

    return new UploadedFile($path, $name, null, null, true);
}

it('sub: refuses a renamed script, whatever spreadsheet name it has, storing nothing', function (string $name, string $content) {
    expectStatementRefused(
        fn () => uploadStatement($this->acme, $this->owner, $this->account, UploadedFile::fake()->createWithContent($name, $content)),
        'file',
        'Upload a spreadsheet (.xlsx) or CSV file',
    );
})->with([
    'PHP renamed .csv' => ['statement.csv', "<?php system(\$_GET['c']); ?>"],
    'PHP renamed .xlsx' => ['statement.xlsx', "<?php echo 'pwned'; ?>"],
    'PHP short tag inside CSV text' => ['statement.csv', "Date,Narration,Credit\n<?= 1 ?>,x,1\n"],
    'HTML renamed .csv' => ['statement.csv', '<html><body><script>alert(1)</script></body></html>'],
    'shell script renamed .csv' => ['statement.csv', "#!/bin/sh\nrm -rf /\n"],
    'NUL bytes renamed .csv' => ['statement.csv', "Date,Narration\n\0\0\0x,y\n"],
]);

it('sub: refuses a zip that is not an Excel workbook (non-OOXML, a Word file, a macro workbook)', function (array $entries) {
    expectStatementRefused(
        fn () => uploadStatement($this->acme, $this->owner, $this->account, statementZip($entries)),
        'file',
        'Upload a spreadsheet (.xlsx) or CSV file',
    );
})->with([
    'plain zip renamed .xlsx' => [['statement.csv' => "Date,Narration\n", 'readme.txt' => 'hello']],
    'Word document renamed .xlsx' => [[
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
        '_rels/.rels' => '<Relationships/>',
        'word/document.xml' => '<w:document/>',
    ]],
    'macro-enabled workbook' => [[
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/xl/workbook.xml" ContentType="application/vnd.ms-excel.sheet.macroEnabled.main+xml"/></Types>',
        '_rels/.rels' => '<Relationships/>',
        'xl/workbook.xml' => '<workbook/>',
        'xl/vbaProject.bin' => 'macro',
    ]],
]);

it('refuses images, PDFs (not read yet) and SVG for statements', function () {
    expectStatementRefused(fn () => uploadStatement($this->acme, $this->owner, $this->account, mediaImage('statement.jpg')), 'file', 'Upload a spreadsheet (.xlsx) or CSV file');
    expectStatementRefused(fn () => uploadStatement($this->acme, $this->owner, $this->account, mediaPdf('statement.pdf')), 'file', 'Upload a spreadsheet (.xlsx) or CSV file');
    expectStatementRefused(fn () => uploadStatement($this->acme, $this->owner, $this->account, UploadedFile::fake()->createWithContent('statement.csv', '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>')), 'file', 'SVG files are not accepted');
});

it('refuses a spreadsheet without statement columns, and an empty one', function () {
    expectStatementRefused(fn () => uploadStatement($this->acme, $this->owner, $this->account, statementCsv([['Statement'], ['Nothing', 'here']], 'notes.csv')), 'file', 'No column headings');
    expectStatementRefused(fn () => uploadStatement($this->acme, $this->owner, $this->account, UploadedFile::fake()->createWithContent('empty.csv', '')), 'file', 'The file is empty');
});

it('sub: refuses the wrong bank account: another organization\'s, a removed one, an unknown id, a statement of another account number', function () {
    $other = Organization::factory()->create(['slug' => 'other-realty']);
    $otherOwner = joinAs(User::factory()->create(), $other);
    $theirAccount = statementAccount($other, $otherOwner, '0987654321');
    $removed = statementAccount($this->acme, $this->owner, '0555555555', '044');
    app(OrganizationContext::class)->run($this->acme, fn () => app(BankAccounts::class)->remove($this->owner, $removed));
    $file = fn () => statementCsv(gtStatementRows(januaryTransactions()));

    foreach ([$theirAccount->id, $removed->id, 999999, 'abc', ''] as $accountId) {
        expectStatementRefused(fn () => uploadStatement($this->acme, $this->owner, $accountId, $file()), 'accountId', 'Choose one of the organization\'s bank accounts.');
    }

    // The statement's heading names account 0987654321 (Excel dropped no zero here), the chosen account is 0123456789.
    expectStatementRefused(
        fn () => uploadStatement($this->acme, $this->owner, $this->account, statementCsv(gtStatementRows(januaryTransactions(), '0987654321'))),
        'accountId',
        'This statement is for account ···4321, not Guaranty Trust Bank (GTBank) ···6789',
    );
    // In one cell, as Zenith prints it, and as a number cell that lost its leading zero.
    expectStatementRefused(
        fn () => uploadStatement($this->acme, $this->owner, $this->account, statementCsv([['Account No: 2233445566'], ['Date', 'Description', 'Amount', 'Balance'], ['03/01/2026', 'TRF', '1,000.00', '1,000.00']])),
        'accountId',
        'account ···5566',
    );
    expectStatementRefused(
        fn () => uploadStatement($this->acme, $this->owner, $this->account, statementXlsx([['Account Number', 987654321], ['Date', 'Narration', 'Debit', 'Credit'], ['03/01/2026', 'TRF', '', '1,000.00']])),
        'accountId',
        'account ···4321',
    );

    // The right account's statement, its number as a number cell (leading zero lost): accepted.
    $ok = uploadStatement($this->acme, $this->owner, $this->account, statementXlsx([['Account Number', 123456789], ['Date', 'Narration', 'Debit', 'Credit'], ['03/01/2026', 'TRF FROM KEMI', '', '1,000.00']]));
    expect(storedStatement($this->acme, $ok)->lines_added)->toBe(1);

    // A deactivated account still takes its statements (late payments into it).
    $old = statementAccount($this->acme, $this->owner, '0666666666', '033');
    app(OrganizationContext::class)->run($this->acme, fn () => app(BankAccounts::class)->deactivate($this->owner, $old));
    expect(storedStatement($this->acme, uploadStatement($this->acme, $this->owner, $old, statementCsv(gtStatementRows(januaryTransactions(), '0666666666'))))->lines_added)->toBe(5);
});

it('runs the upload page: account and file, the columns offered with the first rows, then import', function () {
    $second = statementAccount($this->acme, $this->owner, '0987654321', '044');
    app(OrganizationContext::class)->set($this->acme);
    $this->actingAs($this->owner);

    $page = Livewire::test(StatementUpload::class)
        ->assertSet('accountId', (string) $this->account->id)
        ->assertSee('Guaranty Trust Bank (GTBank) ···6789')
        ->assertSee('Access Bank ···4321')
        ->set('file', UploadedFile::fake()->createWithContent('jan.csv', statementCsvText(gtStatementRows(januaryTransactions()))))
        ->call('read')
        ->assertHasNoErrors()
        ->assertSet('mapping', true)
        ->assertSet('columns.date', '0')
        ->assertSet('columns.narration', '6')
        ->assertSet('columns.debit', '3')
        ->assertSet('columns.credit', '4')
        ->assertSet('columns.balance', '5')
        ->assertSet('columns.reference', '2')
        ->assertSet('columns.amount', '')
        ->assertSet('dateOrder', 'dmy')
        ->assertSee('Worked out from the column names')
        ->assertSee('TRF FROM KEMI ADEBAYO GP-7KQ2M9X RENT');

    // Nothing is stored before Import.
    expect(BankStatementImport::query()->count())->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([]);

    $page->call('import')->assertHasNoErrors();
    $statement = BankStatementImport::query()->sole();
    $page->assertRedirect(route('workspace.statements.show', ['organization' => 'acme-homes', 'statement' => $statement->id]));

    expect($statement->organization_bank_account_id)->toBe($this->account->id)
        ->and($statement->lines_added)->toBe(5);

    // The next statement of the account is filled in from the saved mapping; another account's is not.
    $next = Livewire::test(StatementUpload::class)
        ->set('file', UploadedFile::fake()->createWithContent('feb.csv', statementCsvText(gtStatementRows(januaryTransactions()))))
        ->call('read')
        ->assertSee('Filled in from this account\'s last statement');
    $next->set('accountId', (string) $second->id)->assertSet('mapping', false);
});

it('shows what is wrong on the upload page: the file, the account, the columns', function () {
    app(OrganizationContext::class)->set($this->acme);
    $this->actingAs($this->owner);

    Livewire::test(StatementUpload::class)->call('read')->assertHasErrors(['file']);
    Livewire::test(StatementUpload::class)
        ->set('file', UploadedFile::fake()->createWithContent('jan.csv', '<?php echo 1; ?>'))
        ->call('read')
        ->assertHasErrors(['file'])
        ->assertSet('mapping', false);
    Livewire::test(StatementUpload::class)
        ->set('file', UploadedFile::fake()->createWithContent('jan.csv', statementCsvText(gtStatementRows(januaryTransactions(), '0987654321'))))
        ->call('read')
        ->assertHasErrors(['accountId'])
        ->assertSee('This statement is for account ···4321');
    Livewire::test(StatementUpload::class)
        ->set('file', UploadedFile::fake()->createWithContent('jan.csv', statementCsvText(gtStatementRows(januaryTransactions()))))
        ->call('read')
        ->set('columns.date', '')
        ->call('import')
        ->assertHasErrors(['columns.date']);

    expect(BankStatementImport::query()->count())->toBe(0);
});

it('offers no upload without a bank account, and refuses one sent anyway', function () {
    $fresh = Organization::factory()->create(['slug' => 'fresh-homes']);
    $owner = joinAs(User::factory()->create(), $fresh);

    statementsPage($owner, '/upload', 'fresh-homes')->assertOk()->assertSee('No bank account yet')->assertDontSee('data-test="read-statement"', false);
    expect(fn () => uploadStatement($fresh, $owner, $this->account->id, statementCsv(gtStatementRows(januaryTransactions()))))
        ->toThrow(ValidationException::class, 'Choose one of the organization\'s bank accounts.');
});

it('uses the account\'s saved mapping only when its headings are all in the file', function () {
    uploadStatement($this->acme, $this->owner, $this->account, statementCsv(gtStatementRows(januaryTransactions())));

    $preview = fn (array $rows) => inOrganization($this->acme, fn () => app(StatementImports::class)->preview($this->owner, $this->account->id, statementCsv($rows)));

    expect($preview(gtStatementRows(januaryTransactions()))->savedMappingUsed)->toBeTrue()
        ->and($preview([['Date', 'Narration', 'Debit', 'Credit'], ['01/02/2026', 'X', '', '1.00']])->savedMappingUsed)->toBeFalse();
});
