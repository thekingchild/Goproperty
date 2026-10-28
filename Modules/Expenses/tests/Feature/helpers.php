<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Modules\Expenses\Models\Expense;
use Modules\Expenses\Models\ExpenseReceipt;
use Modules\Expenses\Recording\ExpenseBook;
use Tests\Support\LivewireHttp;

/*
 * Helpers for the Expenses module's tests (P03-12). Expenses are made
 * through the real writer (Recording\ExpenseBook), pages through HTTP and
 * Livewire updates (uploads through Livewire's test client). Buildings,
 * units and assignments come from the Properties module's helpers, list
 * exports from tests/Feature/Exports, image files from tests/Support/media.
 */

require_once __DIR__.'/../../../Properties/tests/Feature/Assignments/helpers.php';
require_once __DIR__.'/../../../../tests/Support/impersonation.php';
require_once __DIR__.'/../../../../tests/Support/media.php';
require_once __DIR__.'/../../../../tests/Feature/Exports/helpers.php';

/** What the expense form sends for a ₦25,000 repair paid by transfer, with changes. */
function expenseInput(array $changes = []): array
{
    return $changes + [
        'propertyId' => '',
        'unitId' => '',
        'category' => 'repairs',
        'description' => 'Fixed the leaking kitchen pipe',
        'amount' => '25,000',
        'spentOn' => now('Africa/Lagos')->toDateString(),
        'vendorName' => 'Musa Plumbing Works',
        'paymentMethod' => 'bank_transfer',
        'reference' => 'TRF-0001',
        'note' => '',
    ];
}

/** An expense recorded by the member through ExpenseBook, inside the organization. */
function recordExpense(Organization $organization, User $by, array $changes = [], array $receipts = []): Expense
{
    return inOrganization($organization, fn (): Expense => app(ExpenseBook::class)->record($by, expenseInput($changes), $receipts));
}

/** The expense as stored now, archived or not. */
function storedExpense(Organization $organization, Expense|int $expense): Expense
{
    return inOrganization($organization, fn (): Expense => Expense::query()->withTrashed()->findOrFail($expense instanceof Expense ? $expense->id : $expense));
}

/** Every expense of the organization, archived ones too, oldest first. */
function expensesOf(Organization $organization): Collection
{
    return inOrganization($organization, fn () => Expense::query()->withTrashed()->orderBy('id')->get());
}

/** The expense's receipt rows, oldest first. */
function expenseReceiptsOf(Organization $organization, Expense $expense): Collection
{
    return inOrganization($organization, fn () => ExpenseReceipt::query()->where('expense_id', $expense->id)->orderBy('id')->get());
}

/** A real PNG photo of a receipt: the same bytes for the same seed. */
function receiptImageBytes(int $seed = 1): string
{
    $image = imagecreatetruecolor(60, 80);
    imagefilledrectangle($image, 0, 0, 59, 79, (int) imagecolorallocate($image, ($seed * 53) % 256, ($seed * 89) % 256, ($seed * 131) % 256));
    imagestring($image, 2, 2, 2, 'RCPT '.$seed, (int) imagecolorallocate($image, 255, 255, 255));

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/** The receipt photo of $seed as an upload, under any name. */
function receiptFile(string $name = 'receipt.png', int $seed = 1): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, receiptImageBytes($seed));
}

/** A fresh request, as production starts every one. */
function expensesFreshRequest(): void
{
    config(['auth.defaults.guard' => 'web']);
    app('auth')->forgetGuards();
    app()->forgetScopedInstances();
    app('livewire')->flushState();
    app(OrganizationContext::class)->clear();
}

/** A page of the expenses area, as the user ($path after /expenses). */
function expensesPage(User $user, string $path = '', string $slug = 'acme-homes'): TestResponse
{
    expensesFreshRequest();

    return test()->actingAs($user)->get("/o/{$slug}/expenses{$path}");
}

/** The Livewire snapshot of an expenses page the user can open. */
function expensesSnapshot(User $user, string $path = '', string $slug = 'acme-homes'): string
{
    $snapshot = LivewireHttp::snapshot(expensesPage($user, $path, $slug)->assertOk());
    expensesFreshRequest();
    test()->actingAs($user);

    return $snapshot;
}

/** A Livewire update on a snapshot, as a fresh request by the same user. */
function expensesUpdate(User $user, string $snapshot, array $updates = [], array $calls = []): TestResponse
{
    expensesFreshRequest();
    test()->actingAs($user);

    return LivewireHttp::update(test(), $snapshot, $updates, $calls);
}

/** The expense ids a rendered expenses list shows, in order. */
function listedExpenses(TestResponse|string $page): array
{
    preg_match_all('/data-expense="(\d+)"/', htmlOf($page), $matches);

    return array_map('intval', $matches[1]);
}

/**
 * A Livewire component of the module as a Livewire test, inside the
 * organization (uploads need Livewire's test client).
 *
 * @param  class-string  $component
 */
function expenseComponent(User $user, Organization $organization, string $component, array $parameters = [], array $query = []): mixed
{
    test()->actingAs($user);
    app(OrganizationContext::class)->set($organization);

    return Livewire::withQueryParams($query)->test($component, $parameters);
}

/**
 * Chooses files on a Livewire page. Livewire's test uploader clears
 * temporary uploads it thinks are a day old, judged by the (travelled)
 * clock, so the upload itself happens at the real time.
 *
 * @param  list<UploadedFile>  $files
 */
function chooseReceiptFiles(mixed $page, array $files, string $property = 'receipts'): mixed
{
    $now = now();
    test()->travelBack();
    $page->set($property, $files);
    test()->travelTo($now);

    return $page;
}
