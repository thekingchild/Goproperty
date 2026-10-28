<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Media\EvidenceFileProtected;
use App\Media\Media;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Expenses\Livewire\ExpenseForm;
use Modules\Expenses\Livewire\ExpenseShow;
use Modules\Expenses\Models\Expense;
use Modules\Expenses\Models\ExpenseReceipt;
use Modules\Expenses\Recording\ExpenseBook;

require_once __DIR__.'/helpers.php';

/*
 * P03-12 AC "receipt upload validated", and receipts as evidence
 * (AGENT.md § 3 rule 7, ARCHITECTURE.md § 9): files go through
 * App\Media\MediaUploads into the expense's own evidence collection
 * `expense_receipts` (JPEG, PNG, WebP or PDF, sniffed from the bytes,
 * never SVG, 10 MB), checked before anything is written; "remove" archives
 * a receipt with a reason and keeps the file; nothing deletes one.
 */

beforeEach(function () {
    Storage::fake('media');
    seedOrganizationRoles();
    $this->travelTo('2027-03-15 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    $this->otherOwner = joinAs(User::factory()->create(), $this->other);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->expense = recordExpense($this->acme, $this->owner, ['propertyId' => (string) $this->palm->id]);
});

afterEach(fn () => expensesFreshRequest());

describe('acceptance: receipt upload validated', function () {
    it('refuses a file that is not a JPEG, PNG, WebP or PDF, whatever its name says, or larger than 10 MB, and stores nothing', function (Closure $file, string $message) {
        foreach ([
            fn () => recordExpense($this->acme, $this->owner, ['propertyId' => (string) $this->palm->id, 'description' => 'With a bad receipt'], [$file()]),
            fn () => inOrganization($this->acme, fn () => app(ExpenseBook::class)->attachReceipts($this->owner, $this->expense, [$file()])),
            // A good file with a bad one: nothing of the upload is stored.
            fn () => inOrganization($this->acme, fn () => app(ExpenseBook::class)->attachReceipts($this->owner, $this->expense, [receiptFile('good.png'), $file()])),
        ] as $upload) {
            try {
                $upload();
                $this->fail('The file was accepted.');
            } catch (ValidationException $refused) {
                expect($refused->errors())->toHaveKey('receipts')
                    ->and($refused->errors()['receipts'][0])->toContain($message);
            }
        }

        expect(expensesOf($this->acme))->toHaveCount(1)
            ->and(expenseReceiptsOf($this->acme, $this->expense))->toHaveCount(0)
            ->and(mediaCount($this->acme))->toBe(0)
            ->and(Storage::disk('media')->allFiles())->toBe([]);
    })->with([
        'a PHP script renamed .png' => [fn (): UploadedFile => UploadedFile::fake()->createWithContent('receipt.png', "<?php system(\$_GET['c']); ?>"), 'whatever its name says'],
        'a PHP script claiming to be a JPEG' => [fn (): UploadedFile => UploadedFile::fake()->createWithContent('receipt.jpg', "<?php echo 'x';")->mimeType('image/jpeg'), 'whatever its name says'],
        'an SVG' => [fn (): UploadedFile => UploadedFile::fake()->createWithContent('receipt.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'SVG files are not accepted'],
        'an HTML page renamed .pdf' => [fn (): UploadedFile => UploadedFile::fake()->createWithContent('invoice.pdf', '<html><body><script>alert(1)</script></body></html>'), 'whatever its name says'],
        'a spreadsheet' => [fn (): UploadedFile => UploadedFile::fake()->createWithContent('costs.csv', "date,amount\n2027-03-01,25000\n"), 'whatever its name says'],
        'an empty file' => [fn (): UploadedFile => UploadedFile::fake()->createWithContent('receipt.png', ''), 'empty'],
        'a file over 10 MB' => [fn (): UploadedFile => UploadedFile::fake()->createWithContent('receipt.png', receiptImageBytes().str_repeat("\0", 10 * 1024 * 1024)), 'larger than 10 MB'],
    ]);

    it('takes a JPEG, a PNG, a WebP and a PDF into the expense_receipts collection, typed as sniffed, the uploader\'s name only a label', function () {
        $added = inOrganization($this->acme, fn () => app(ExpenseBook::class)->attachReceipts($this->owner, $this->expense, [
            mediaImage('../../etc/IMG 2041.jpg', 80, 60),
            receiptFile('scan.png'),
            mediaImage('photo.webp', 80, 60, 'webp'),
            mediaPdf('Musa invoice.pdf'),
        ]));

        $files = inOrganization($this->acme, fn () => array_map(fn (ExpenseReceipt $receipt): Media => Media::query()->findOrFail($receipt->media_id), $added));

        expect(array_map(fn (Media $media): string => $media->mime_type, $files))->toBe(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
            ->and(array_map(fn (Media $media): string => $media->collection_name, $files))->toBe(array_fill(0, 4, 'expense_receipts'))
            ->and(array_map(fn (Media $media): string => $media->model_type, $files))->toBe(array_fill(0, 4, Expense::class))
            ->and($files[0]->name)->toBe('IMG 2041')
            ->and($files[0]->file_name)->toEndWith('.jpg')->not->toContain('IMG')
            ->and($files[3]->file_name)->toEndWith('.pdf')
            ->and($added[1]->file_sha256)->toBe(hash('sha256', receiptImageBytes()))
            ->and($added[1]->uploaded_by_user_id)->toBe($this->owner->id)
            ->and(Storage::disk('media')->exists($files[1]->getPathRelativeToRoot()))->toBeTrue()
            ->and(AuditEntry::query()->where('event', 'expense.receipt_added')->count())->toBe(4);
    });

    it('keeps receipts sent with a new expense, in one go', function () {
        $expense = recordExpense($this->acme, $this->owner, ['propertyId' => (string) $this->palm->id, 'description' => 'Diesel, 200 litres'], [receiptFile('pump.png', 2), mediaPdf('invoice.pdf')]);

        expect(expenseReceiptsOf($this->acme, $expense))->toHaveCount(2)
            ->and(expenseReceiptsOf($this->acme, $expense)->pluck('expense_id')->unique()->all())->toBe([$expense->id]);
    });

    it('takes at most 5 files an upload and keeps at most 10 receipts an expense', function () {
        $files = fn (int $count, int $from = 1): array => array_map(fn (int $seed): UploadedFile => receiptFile("r{$seed}.png", $seed), range($from, $from + $count - 1));

        expect(fn () => inOrganization($this->acme, fn () => app(ExpenseBook::class)->attachReceipts($this->owner, $this->expense, $files(6))))
            ->toThrow(ValidationException::class, 'at most 5 files');

        inOrganization($this->acme, fn () => app(ExpenseBook::class)->attachReceipts($this->owner, $this->expense, $files(5)));
        inOrganization($this->acme, fn () => app(ExpenseBook::class)->attachReceipts($this->owner, $this->expense, $files(5, 6)));

        expect(fn () => inOrganization($this->acme, fn () => app(ExpenseBook::class)->attachReceipts($this->owner, $this->expense, $files(1, 11))))
            ->toThrow(ValidationException::class, 'at most 10 receipt files');

        // An archived receipt makes room; it is still kept.
        $first = expenseReceiptsOf($this->acme, $this->expense)->first();
        inOrganization($this->acme, fn () => app(ExpenseBook::class)->archiveReceipt($this->owner, $first, 'Blurred photo'));
        inOrganization($this->acme, fn () => app(ExpenseBook::class)->attachReceipts($this->owner, $this->expense, $files(1, 11)));

        expect(expenseReceiptsOf($this->acme, $this->expense))->toHaveCount(11)
            ->and(mediaCount($this->acme))->toBe(11);
    });

    it('checks the files on the pages too, and shows why', function () {
        $page = chooseReceiptFiles(
            expenseComponent($this->owner, $this->acme, ExpenseForm::class)
                ->set('propertyId', (string) $this->palm->id)->set('category', 'repairs')->set('description', 'Gate motor')->set('amount', '40,000'),
            [UploadedFile::fake()->createWithContent('receipt.png', "<?php system('id'); ?>")],
        )->call('save')->assertHasErrors('receipts.0')->assertNoRedirect();
        expect($page->errors()->first('receipts.0'))->toContain('whatever its name says');

        $show = chooseReceiptFiles(
            expenseComponent($this->owner, $this->acme, ExpenseShow::class, ['expense' => $this->expense]),
            [UploadedFile::fake()->createWithContent('receipt.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')],
        )->call('addReceipts')->assertHasErrors('receipts.0');
        expect($show->errors()->first('receipts.0'))->toContain('SVG');

        expect(expensesOf($this->acme))->toHaveCount(1)
            ->and(expenseReceiptsOf($this->acme, $this->expense))->toHaveCount(0)
            ->and(mediaCount($this->acme))->toBe(0);

        // A good file through the page.
        chooseReceiptFiles(
            expenseComponent($this->owner, $this->acme, ExpenseShow::class, ['expense' => $this->expense]),
            [receiptFile('receipt.png')],
        )->call('addReceipts')->assertHasNoErrors()->assertSee('The receipt was added.');
        expect(expenseReceiptsOf($this->acme, $this->expense))->toHaveCount(1);
    });
});

describe('acceptance: receipts are evidence (cannot be deleted; archive only)', function () {
    it('refuses to delete a receipt or its file, whoever asks, and archives it with a reason, keeping the file', function () {
        [$receipt] = inOrganization($this->acme, fn () => app(ExpenseBook::class)->attachReceipts($this->owner, $this->expense, [receiptFile()]));
        $media = inOrganization($this->acme, fn () => Media::query()->findOrFail($receipt->media_id));
        $path = $media->getPathRelativeToRoot();

        expect(fn () => inOrganization($this->acme, fn () => ExpenseReceipt::query()->findOrFail($receipt->id)->delete()))->toThrow(LogicException::class)
            ->and(fn () => inOrganization($this->acme, fn () => Media::query()->findOrFail($media->id)->delete()))->toThrow(EvidenceFileProtected::class)
            ->and(fn () => inOrganization($this->acme, fn () => ExpenseReceipt::query()->findOrFail($receipt->id)->forceFill(['media_id' => $media->id + 1])->save()))->toThrow(LogicException::class)
            ->and(fn () => inOrganization($this->acme, fn () => storedExpense($this->acme, $this->expense)->clearMediaCollection(Expense::COLLECTION)))->toThrow(EvidenceFileProtected::class)
            ->and(inOrganization($this->acme, fn () => Gate::forUser($this->owner)->allows('forceDelete', storedExpense($this->acme, $this->expense))))->toBeFalse();

        // No route deletes anything here: every expense route is a GET or a Livewire page.
        $methods = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'o/{organization}/expenses'))->flatMap->methods()->unique()->values()->all();
        expect($methods)->toEqualCanonicalizing(['GET', 'HEAD']);

        // "Remove" from the page needs a reason, then archives.
        $page = expenseComponent($this->owner, $this->acme, ExpenseShow::class, ['expense' => $this->expense])
            ->assertSee('data-test="archive-receipt-'.$receipt->id.'"', false);
        $page->call('archiveReceipt', $receipt->id)->assertHasErrors('reason');
        $page->set('receiptReasons.'.$receipt->id, 'Wrong photo, the diesel receipt')->call('archiveReceipt', $receipt->id)->assertHasNoErrors()
            ->assertSee('The receipt is archived. Its file is kept.')
            ->assertSee('data-archived-receipt="'.$receipt->id.'"', false)
            ->assertSee('Wrong photo, the diesel receipt')
            ->assertDontSee('data-test="archive-receipt-'.$receipt->id.'"', false);

        $stored = inOrganization($this->acme, fn () => ExpenseReceipt::query()->findOrFail($receipt->id));
        expect($stored->isArchived())->toBeTrue()
            ->and($stored->archived_by_user_id)->toBe($this->owner->id)
            ->and($stored->archive_reason)->toBe('Wrong photo, the diesel receipt')
            ->and(inOrganization($this->acme, fn () => Media::query()->whereKey($media->id)->exists()))->toBeTrue()
            ->and(Storage::disk('media')->exists($path))->toBeTrue();

        $audit = AuditEntry::query()->where('event', 'expense.receipt_archived')->sole();
        expect($audit->subject_id)->toBe($this->expense->id)
            ->and($audit->properties['receipt_id'])->toBe($receipt->id)
            ->and($audit->properties['file_sha256'])->toBe($receipt->file_sha256)
            ->and($audit->properties['reason'])->toBe('Wrong photo, the diesel receipt');

        // Archived once; the file still opens for whoever sees the expense.
        expect(fn () => inOrganization($this->acme, fn () => app(ExpenseBook::class)->archiveReceipt($this->owner, $stored, 'Again')))->toThrow(ValidationException::class)
            ->and(fn () => inOrganization($this->acme, fn () => $stored->forceFill(['archive_reason' => 'Changed'])->save()))->toThrow(LogicException::class);
        expensesPage($this->owner, "/{$this->expense->id}/receipts/{$receipt->id}")->assertRedirectContains('/media/');
    });

    it('keeps the receipts of an archived expense, and lets them be opened', function () {
        [$receipt] = inOrganization($this->acme, fn () => app(ExpenseBook::class)->attachReceipts($this->owner, $this->expense, [receiptFile()]));
        inOrganization($this->acme, fn () => app(ExpenseBook::class)->archive($this->owner, $this->expense, 'Duplicate entry'));

        expect(expenseReceiptsOf($this->acme, $this->expense))->toHaveCount(1)
            ->and(mediaCount($this->acme))->toBe(1);
        expensesPage($this->owner, "/{$this->expense->id}")->assertOk()->assertSee('This expense is archived')->assertSee('data-receipt="'.$receipt->id.'"', false)->assertDontSee('data-test="add-receipts"', false);
        expensesPage($this->owner, "/{$this->expense->id}/receipts/{$receipt->id}")->assertRedirectContains('/media/');

        // No receipt is added to an archived expense.
        expect(fn () => inOrganization($this->acme, fn () => app(ExpenseBook::class)->attachReceipts($this->owner, storedExpense($this->acme, $this->expense), [receiptFile('late.png', 3)])))->toThrow(AuthorizationException::class);
    });

    it('lets agents add receipts to their buildings\' expenses but only owners and administrators archive one', function () {
        $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
        $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
        [$receipt] = inOrganization($this->acme, fn () => app(ExpenseBook::class)->attachReceipts($agent, $this->expense, [receiptFile()]));

        expect($receipt->uploaded_by_user_id)->toBe($agent->id)
            ->and(fn () => inOrganization($this->acme, fn () => app(ExpenseBook::class)->archiveReceipt($agent, $receipt, 'Not needed')))->toThrow(AuthorizationException::class);
        expensesPage($agent, "/{$this->expense->id}")->assertOk()->assertSee('data-test="add-receipts"', false)->assertDontSee('data-test="archive-receipt-'.$receipt->id.'"', false);

        inOrganization($this->acme, fn () => app(ExpenseBook::class)->archiveReceipt($admin, $receipt, 'Not needed'));
        expect(expenseReceiptsOf($this->acme, $this->expense)->first()->archived_by_user_id)->toBe($admin->id);
    });
});

it('opens a receipt only through a short-lived signed link, and 404 for another expense\'s or organization\'s receipt', function () {
    [$receipt] = inOrganization($this->acme, fn () => app(ExpenseBook::class)->attachReceipts($this->owner, $this->expense, [receiptFile()]));
    $second = recordExpense($this->acme, $this->owner, ['propertyId' => (string) $this->palm->id, 'description' => 'Second job']);
    $foreignExpense = recordExpense($this->other, $this->otherOwner, ['propertyId' => (string) buildingIn($this->other)->id]);
    [$foreignReceipt] = inOrganization($this->other, fn () => app(ExpenseBook::class)->attachReceipts($this->otherOwner, $foreignExpense, [receiptFile('foreign.png', 9)]));

    $url = (string) expensesPage($this->owner, "/{$this->expense->id}/receipts/{$receipt->id}")->assertRedirect()->headers->get('Location');
    expect($url)->toContain('/media/'.$this->acme->id.'/')->toContain('signature=')->toContain('expires=');
    expensesFreshRequest();
    $served = $this->get($url)->assertOk();
    expect($served->streamedContent())->toBe(receiptImageBytes());
    expensesPage($this->owner, "/{$this->expense->id}/receipts/{$receipt->id}/thumb")->assertRedirectContains('/media/');

    // The receipt under another expense, another organization's, unknown ids.
    expensesPage($this->owner, "/{$second->id}/receipts/{$receipt->id}")->assertNotFound();
    expensesPage($this->owner, "/{$foreignExpense->id}/receipts/{$foreignReceipt->id}")->assertNotFound();
    expensesPage($this->owner, "/{$this->expense->id}/receipts/{$foreignReceipt->id}")->assertNotFound();
    expensesPage($this->owner, "/{$this->expense->id}/receipts/999999")->assertNotFound();
    expensesPage($this->otherOwner, "/{$this->expense->id}/receipts/{$receipt->id}", 'other-realty')->assertNotFound();
});
