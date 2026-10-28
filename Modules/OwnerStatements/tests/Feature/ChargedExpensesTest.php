<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\Expenses\Models\Expense;
use Modules\Expenses\Recording\ExpenseBook;

require_once __DIR__.'/statement-world.php';

/*
 * P08-05: "charged to owner" on expenses (Modules\Expenses): off by
 * default, set on the expense form, shown in the list, on the expense's
 * page and in the export; only a building's expense can be charged; and a
 * month settled with the owner never changes, so an expense is not charged
 * into it, nor a charged one there changed, until it is unsettled.
 */

beforeEach(function () {
    Notification::fake();
    statementWorld();
    $this->travelTo('2027-02-02 10:00:00');
});

afterEach(fn () => billsFreshRequest());

/** The expense's form input as stored, with changes. */
function chargedExpenseInput(Expense $expense, array $changes = []): array
{
    return $changes + expenseInput([
        'propertyId' => (string) $expense->property_id,
        'unitId' => $expense->unit_id !== null ? (string) $expense->unit_id : '',
        'description' => $expense->description,
        'amount' => str_replace('₦', '', $expense->amount_kobo->format()),
        'spentOn' => $expense->spent_on->toDateString(),
        'chargedToOwner' => $expense->charged_to_owner,
    ]);
}

it('is off by default, set on the form, and shown in the list, on the expense\'s page and in the export', function () {
    expect(storedExpense($this->acme, $this->ownExpense)->charged_to_owner)->toBeFalse()
        ->and(storedExpense($this->acme, $this->chargedExpense)->charged_to_owner)->toBeTrue();

    // The form: record one charged to the owner.
    $create = expensesSnapshot($this->owner, '/create');
    expensesUpdate($this->owner, $create, expenseInput(['propertyId' => (string) $this->marina->id, 'description' => 'Painted the stairwell', 'amount' => '75,000', 'chargedToOwner' => true]), [['save']])->assertOk();
    $painted = expensesOf($this->acme)->firstWhere('description', 'Painted the stairwell');
    expect($painted?->charged_to_owner)->toBeTrue();

    expensesPage($this->owner)->assertOk()->assertSee('data-test="charged-'.$this->chargedExpense->id.'"', false)->assertDontSee('data-test="charged-'.$this->ownExpense->id.'"', false);
    expensesPage($this->owner, "/{$this->chargedExpense->id}")->assertOk()->assertSee('Yes: deducted from what the owner is due');
    expensesPage($this->owner, "/{$this->chargedExpense->id}/edit")->assertOk()->assertSee('data-test="expense-charged-to-owner"', false);

    $rows = exportRows($this->owner, '/expenses', 'expenses');
    $column = array_search('Charged to owner', $rows[0], true);
    $described = array_search('Description', $rows[0], true);
    $byDescription = collect(array_slice($rows, 1))->mapWithKeys(fn ($row) => [$row[$described] => $row[$column]]);
    expect($column)->not->toBeFalse()
        ->and($byDescription['Replaced the bathroom tap'])->toBe('Yes')
        ->and($byDescription['Office printer for the site'])->toBe('No')
        ->and($byDescription['Painted the stairwell'])->toBe('Yes');

    // It counts on February's statement (paid on 2 February).
    expect(statementOf($this->acme, $this->ngoziAtAcme, '2027-02')->statement->totals->expenses->kobo)->toBe(7_500_000);
});

it('charges only a building\'s expense to its owner', function () {
    expect(fn () => recordExpense($this->acme, $this->owner, ['propertyId' => '', 'description' => 'Office rent', 'chargedToOwner' => true]))
        ->toThrow(ValidationException::class);
});

it('refuses an expense charged into a month settled with the owner, and a change to a charged one there, until it is unsettled', function () {
    settleStatement($this->acme, $this->owner, $this->ngoziAtAcme, '2027-01', '2027-02-01');
    $book = app(ExpenseBook::class);

    // A late January expense charged to the owner: refused, explained.
    try {
        recordExpense($this->acme, $this->owner, ['propertyId' => (string) $this->marina->id, 'spentOn' => '2027-01-30', 'chargedToOwner' => true]);
        $this->fail('Charged into a settled month.');
    } catch (ValidationException $refused) {
        expect($refused->errors()['chargedToOwner'][0])->toContain('January 2027 is already settled');
    }

    // Not charged, or charged in February: fine.
    recordExpense($this->acme, $this->owner, ['propertyId' => (string) $this->marina->id, 'spentOn' => '2027-01-30', 'description' => 'Late, not charged']);
    recordExpense($this->acme, $this->owner, ['propertyId' => (string) $this->marina->id, 'spentOn' => '2027-02-01', 'description' => 'February, charged', 'chargedToOwner' => true]);

    // The charged January expense: its amount, date or charge cannot change; its description can.
    $expense = storedExpense($this->acme, $this->chargedExpense);
    foreach ([['amount' => '45,000'], ['spentOn' => '2027-02-01'], ['chargedToOwner' => false]] as $change) {
        expect(fn () => inOrganization($this->acme, fn () => $book->update($this->owner, $expense, chargedExpenseInput($expense, $change))))->toThrow(ValidationException::class);
    }
    inOrganization($this->acme, fn () => $book->update($this->owner, $expense, chargedExpenseInput($expense, ['description' => 'Replaced the bathroom tap (brass)'])));
    expect(storedExpense($this->acme, $expense)->amount_kobo->kobo)->toBe(4_000_000);

    // Unsettled: the change goes through, and January is worked out again.
    unsettleStatement($this->acme, $this->owner, $this->ngoziAtAcme, '2027-01');
    inOrganization($this->acme, fn () => $book->update($this->owner, storedExpense($this->acme, $expense), chargedExpenseInput(storedExpense($this->acme, $expense), ['amount' => '45,000'])));
    expect(statementOf($this->acme, $this->ngoziAtAcme, '2027-01')->statement->totals->expenses->kobo)->toBe(4_500_000);
});
