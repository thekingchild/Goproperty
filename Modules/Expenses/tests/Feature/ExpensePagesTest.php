<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Modules\Expenses\Livewire\ExpenseForm;
use Modules\Expenses\Livewire\ExpenseShow;
use Modules\Expenses\Recording\ExpenseBook;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';

/*
 * P03-12: the expense pages — the list (search, filters in the URL, total,
 * Active / Archived) and its export (P02-07: the page's rows, Excel and
 * CSV), recording with receipts, editing, archiving and restoring. Rows that
 * must not match a search have fixed descriptions, payees and references.
 */

beforeEach(function () {
    Storage::fake('media');
    seedOrganizationRoles();
    $this->travelTo('2027-03-15 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    $this->agent = joinAs(User::factory()->create(['name' => 'Bola Agent']), $this->acme, OrganizationRole::Agent);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->oak = buildingIn($this->acme, ['name' => 'Oak Lodge', 'slug' => 'oak-lodge']);
    $this->flat = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 2']);

    $this->pipe = recordExpense($this->acme, $this->agent, ['propertyId' => (string) $this->palm->id, 'unitId' => (string) $this->flat->id, 'description' => 'Kitchen pipe replaced', 'vendorName' => 'Musa Plumbing Works', 'reference' => 'TRF-1001', 'amount' => '25,000.50', 'spentOn' => '2027-03-10', 'paymentMethod' => 'cash'], [receiptFile()]);
    $this->diesel = recordExpense($this->acme, $this->owner, ['propertyId' => (string) $this->oak->id, 'category' => 'utilities', 'description' => 'Diesel for the generator', 'vendorName' => 'Total Energies Ikeja', 'reference' => 'INV-77', 'amount' => '180,000', 'spentOn' => '2027-02-20']);
    $this->rates = recordExpense($this->acme, $this->owner, ['propertyId' => (string) $this->oak->id, 'category' => 'taxes', 'description' => 'Land Use Charge 2027', 'vendorName' => 'Lagos State', 'reference' => 'LUC-2027', 'amount' => '95,000', 'spentOn' => '2027-01-05', 'paymentMethod' => 'pos']);
    $this->office = recordExpense($this->acme, $this->owner, ['category' => 'agency_ops', 'description' => 'Office rent for March', 'vendorName' => 'Ikoyi Estates', 'reference' => 'RENT-03', 'amount' => '350,000', 'spentOn' => '2027-03-01']);
});

afterEach(fn () => expensesFreshRequest());

it('lists expenses latest date paid first, with building, unit, payee, a "No receipt" mark and the total', function () {
    $page = expensesPage($this->owner)->assertOk();

    expect(listedExpenses($page))->toBe([$this->pipe->id, $this->office->id, $this->diesel->id, $this->rates->id]);
    $page->assertSee('Flat 2 · Palm Court')
        ->assertSee('paid to Musa Plumbing Works')
        ->assertSee('The organization&#039;s own', false)
        ->assertSee('₦25,000.50')
        ->assertSee('Total: ₦650,000.50')
        ->assertDontSee('data-test="no-receipt-'.$this->pipe->id.'"', false)
        ->assertSee('data-test="no-receipt-'.$this->diesel->id.'"', false);
});

it('searches what was paid for, who was paid and the reference, and filters by category, building, dates and archive, all in the URL', function () {
    expect(listedExpenses(expensesPage($this->owner, '?q=diesel')))->toBe([$this->diesel->id])
        ->and(listedExpenses(expensesPage($this->owner, '?q=musa')))->toBe([$this->pipe->id])
        ->and(listedExpenses(expensesPage($this->owner, '?q=LUC-2027')))->toBe([$this->rates->id])
        ->and(listedExpenses(expensesPage($this->owner, '?q=%25')))->toBe([])
        ->and(listedExpenses(expensesPage($this->owner, '?category=taxes')))->toBe([$this->rates->id])
        ->and(listedExpenses(expensesPage($this->owner, '?building='.$this->oak->id)))->toBe([$this->diesel->id, $this->rates->id])
        ->and(listedExpenses(expensesPage($this->owner, '?building=none')))->toBe([$this->office->id])
        ->and(listedExpenses(expensesPage($this->owner, '?from=2027-02-01&to=2027-03-05')))->toBe([$this->office->id, $this->diesel->id])
        ->and(listedExpenses(expensesPage($this->owner, '?category=bogus&building=abc&from=31-12-2026')))->toHaveCount(4);

    expensesPage($this->owner, '?building='.$this->oak->id)->assertSee('Total: ₦275,000');
    expensesPage($this->owner, '?q=nothing-like-this')->assertSee('No expense matches')->assertSee('data-test="clear-filters"', false);

    // A filter typed on the page goes into the URL and back to page one.
    $typed = expensesUpdate($this->owner, expensesSnapshot($this->owner), ['search' => 'generator'])->assertOk();
    expect(listedExpenses($typed))->toBe([$this->diesel->id]);

    // Archived ones only on the Archived tab.
    inOrganization($this->acme, fn () => app(ExpenseBook::class)->archive($this->owner, $this->rates, 'Paid by the landlord'));
    expect(listedExpenses(expensesPage($this->owner)))->not->toContain($this->rates->id)
        ->and(listedExpenses(expensesPage($this->owner, '?archived=1')))->toBe([$this->rates->id]);
});

it('records an expense from the form with its receipts, the building offered from a building page', function () {
    expensesPage($this->owner, '/create?building='.$this->palm->id)->assertOk()
        ->assertSee('No building (the organization&#039;s own)', false)
        ->assertSee('Repairs and maintenance')
        ->assertSee('Tenement rate, Land Use Charge');

    $page = expenseComponent($this->owner, $this->acme, ExpenseForm::class, [], ['building' => (string) $this->palm->id])
        ->assertSet('propertyId', (string) $this->palm->id)
        ->assertSet('spentOn', '2027-03-15')
        ->assertSet('paymentMethod', 'bank_transfer')
        ->set('unitId', (string) $this->flat->id)
        ->set('category', 'repairs')
        ->set('description', 'Door lock changed')
        ->set('amount', '12,500')
        ->set('vendorName', 'Chidi Locksmith');
    chooseReceiptFiles($page, [receiptFile('lock.png', 3), mediaPdf('invoice.pdf')])
        ->call('save')
        ->assertHasNoErrors();

    $expense = expensesOf($this->acme)->last();
    $page->assertRedirect(route('workspace.expenses.show', [$this->acme, $expense->id]));
    expect($expense->description)->toBe('Door lock changed')
        ->and($expense->unit_id)->toBe($this->flat->id)
        ->and($expense->amount_kobo->kobo)->toBe(1_250_000)
        ->and(expenseReceiptsOf($this->acme, $expense))->toHaveCount(2);

    // A building of another organization, or a missing one, is not offered.
    expenseComponent($this->owner, $this->acme, ExpenseForm::class, [], ['building' => '999999'])->assertSet('propertyId', '');

    // An agent must choose a building.
    expenseComponent($this->agent, $this->acme, ExpenseForm::class)
        ->set('category', 'repairs')->set('description', 'No building')->set('amount', '1,000')
        ->call('save')
        ->assertHasErrors('propertyId');
});

it('edits an expense from its page, filled in as recorded', function () {
    $page = expenseComponent($this->owner, $this->acme, ExpenseForm::class, ['expense' => $this->pipe])
        ->assertSet('propertyId', (string) $this->palm->id)
        ->assertSet('unitId', (string) $this->flat->id)
        ->assertSet('amount', '25,000.50')
        ->assertSet('spentOn', '2027-03-10')
        ->assertSet('paymentMethod', 'cash')
        ->assertDontSee('data-test="expense-receipts"', false)
        ->set('amount', '26,000')
        ->set('propertyId', (string) $this->oak->id)
        ->assertSet('unitId', '')
        ->call('save')
        ->assertHasNoErrors();

    $page->assertRedirect(route('workspace.expenses.show', [$this->acme, $this->pipe->id]));
    $stored = storedExpense($this->acme, $this->pipe);
    expect($stored->amount_kobo->kobo)->toBe(2_600_000)
        ->and($stored->property_id)->toBe($this->oak->id)
        ->and($stored->unit_id)->toBeNull()
        ->and($stored->updated_by_user_id)->toBe($this->owner->id);

    expensesPage($this->owner, "/{$this->pipe->id}")->assertOk()->assertSee('Last changed')->assertSee('Oak Lodge')->assertSee('Bola Agent');
});

it('archives an expense from its page with a reason and restores it', function () {
    $page = expenseComponent($this->owner, $this->acme, ExpenseShow::class, ['expense' => $this->diesel])
        ->call('archive')->assertHasErrors('reason')
        ->set('archiveReason', 'Recorded twice')
        ->call('archive')->assertHasNoErrors()
        ->assertSee('The expense is archived.')
        ->assertSee('This expense is archived')
        ->assertSee('Recorded twice')
        ->assertSee('data-test="restore-expense"', false)
        ->assertDontSee('data-test="edit-expense"', false);

    expect(storedExpense($this->acme, $this->diesel)->isArchived())->toBeTrue();
    expensesPage($this->owner, "/{$this->diesel->id}")->assertOk()->assertSee('All expenses');
    expensesPage($this->owner, "/{$this->diesel->id}/edit")->assertNotFound();

    $page->call('restore')->assertSee('The expense is restored.');
    expect(storedExpense($this->acme, $this->diesel)->isArchived())->toBeFalse();

    // Agents neither archive nor restore.
    expensesPage($this->agent, "/{$this->pipe->id}")->assertOk()->assertDontSee('data-test="archive-expense"', false);
});

describe('list exports', function () {
    it('exports the expenses list in Excel and CSV with exact columns and the page\'s rows', function (string $format) {
        $rows = exportRows($this->owner, '/expenses', 'expenses', $format);

        expect($rows[0])->toBe(['Date paid', 'Category', 'Description', 'Amount', 'Building', 'Unit', 'Paid to', 'Payment method', 'Reference', 'Note', 'Receipt files', 'Recorded by', 'Recorded on', 'Archived on', 'Reason archived'])
            ->and(array_slice($rows, 1))->toHaveCount(4)
            ->and(array_slice($rows[1], 0, 13))->toBe(['2027-03-10', 'Repairs and maintenance', 'Kitchen pipe replaced', '25000.50', 'Palm Court', 'Flat 2', 'Musa Plumbing Works', 'Cash', 'TRF-1001', '', '1', 'Bola Agent', '2027-03-15 11:00'])
            ->and(exportColumn($rows, 'Description'))->toBe(['Kitchen pipe replaced', 'Office rent for March', 'Diesel for the generator', 'Land Use Charge 2027'])
            ->and(exportColumn($rows, 'Building'))->toBe(['Palm Court', '', 'Oak Lodge', 'Oak Lodge'])
            ->and(exportColumn($rows, 'Amount'))->toBe(['25000.50', '350000.00', '180000.00', '95000.00'])
            ->and(exportColumn($rows, 'Payment method'))->toBe(['Cash', 'Bank transfer', 'Bank transfer', 'Card (POS)']);
    })->with(['xlsx', 'csv']);

    it('exports with the page\'s filters, search and archive tab, and audits the download', function () {
        inOrganization($this->acme, fn () => app(ExpenseBook::class)->archive($this->owner, $this->rates, 'Paid by the landlord'));

        expect(exportColumn(exportRows($this->owner, '/expenses?building='.$this->oak->id, 'expenses'), 'Description'))->toBe(['Diesel for the generator'])
            ->and(exportColumn(exportRows($this->owner, '/expenses?q=musa', 'expenses'), 'Description'))->toBe(['Kitchen pipe replaced'])
            ->and(exportColumn(exportRows($this->owner, '/expenses?building=none', 'expenses'), 'Description'))->toBe(['Office rent for March'])
            ->and(exportColumn(exportRows($this->owner, '/expenses?from=2027-03-01', 'expenses'), 'Description'))->toBe(['Kitchen pipe replaced', 'Office rent for March']);

        $archived = exportRows($this->owner, '/expenses?archived=1', 'expenses');
        expect(exportColumn($archived, 'Description'))->toBe(['Land Use Charge 2027'])
            ->and(exportColumn($archived, 'Reason archived'))->toBe(['Paid by the landlord'])
            ->and(exportColumn($archived, 'Archived on'))->toBe(['2027-03-15 11:00']);

        $audit = AuditEntry::query()->where('event', 'list.exported')->latest('id')->first();
        expect($audit->properties['list'])->toBe('expenses')
            ->and($audit->properties['filters'])->toEqual(['archived' => true]);
    });

    it('offers the Export button to everyone who sees the list, and nobody else', function () {
        $pressed = expensesUpdate($this->agent, expensesSnapshot($this->agent), [], [['export', ['expenses', 'xlsx']]])->assertOk();
        expect(LivewireHttp::redirect($pressed))->toContain('/exports/');

        expensesPage(joinAs(User::factory()->create(), $this->acme, OrganizationRole::PropertyOwner))->assertNotFound();
    });
});
