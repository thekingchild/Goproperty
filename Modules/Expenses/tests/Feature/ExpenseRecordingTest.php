<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Expenses\Enums\ExpenseCategory;
use Modules\Expenses\Enums\ExpensePaymentMethod;
use Modules\Expenses\Models\Expense;
use Modules\Expenses\Recording\ExpenseBook;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;

require_once __DIR__.'/helpers.php';

/*
 * P03-12: recording expenses through the only writer
 * (Recording\ExpenseBook): building and unit through the Properties
 * contracts, amounts as exact kobo, dates in Lagos, the organization's own
 * expenses (no building) for owners and administrators only, every change
 * audited, archive (soft delete with a reason) and restore, never deleted
 * for good.
 */

beforeEach(function () {
    Storage::fake('media');
    seedOrganizationRoles();
    $this->travelTo('2027-03-15 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->flat = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 2']);
});

afterEach(fn () => expensesFreshRequest());

/** Runs the writer inside the organization and returns the validation errors it raised. */
function expenseErrors(Organization $organization, Closure $action): array
{
    try {
        inOrganization($organization, $action);
    } catch (ValidationException $refused) {
        return $refused->errors();
    }

    test()->fail('The writer accepted it.');
}

it('records an expense of a building and unit, keeping the names, the amount as exact kobo and who recorded it', function () {
    $expense = recordExpense($this->acme, $this->owner, [
        'propertyId' => (string) $this->palm->id,
        'unitId' => (string) $this->flat->id,
        'amount' => '₦1,500,000.50',
        'spentOn' => '2027-03-14',
        'paymentMethod' => 'cash',
        'note' => "Pipe burst on Sunday.\nTenant paid nothing.",
    ]);

    $stored = storedExpense($this->acme, $expense);
    expect($stored->organization_id)->toBe($this->acme->id)
        ->and($stored->property_id)->toBe($this->palm->id)
        ->and($stored->unit_id)->toBe($this->flat->id)
        ->and($stored->property_name)->toBe('Palm Court')
        ->and($stored->unit_label)->toBe('Flat 2')
        ->and($stored->category)->toBe(ExpenseCategory::Repairs)
        ->and($stored->amount_kobo->kobo)->toBe(150_000_050)
        ->and($stored->spent_on->toDateString())->toBe('2027-03-14')
        ->and($stored->payment_method)->toBe(ExpensePaymentMethod::Cash)
        ->and($stored->vendor_name)->toBe('Musa Plumbing Works')
        ->and($stored->note)->toBe("Pipe burst on Sunday.\nTenant paid nothing.")
        ->and($stored->recorded_by_user_id)->toBe($this->owner->id)
        ->and($stored->ticket_id)->toBeNull()
        ->and($stored->bill_id)->toBeNull();

    $audit = AuditEntry::query()->where('event', 'expense.recorded')->sole();
    expect($audit->log_name)->toBe('expenses')
        ->and($audit->subject_id)->toBe($expense->id)
        ->and($audit->causer_id)->toBe($this->owner->id)
        ->and($audit->properties['amount_kobo'])->toBe(150_000_050)
        ->and($audit->properties['category'])->toBe('repairs')
        ->and($audit->properties['property_id'])->toBe($this->palm->id)
        ->and($audit->properties)->not->toHaveKey('note')
        ->and($audit->properties)->not->toHaveKey('description');
});

it('records the organization\'s own expense (no building) for owners and administrators only', function () {
    $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);

    $office = recordExpense($this->acme, $admin, ['category' => 'agency_ops', 'description' => 'Office rent for March', 'amount' => '350,000']);
    expect(storedExpense($this->acme, $office)->property_id)->toBeNull()
        ->and(storedExpense($this->acme, $office)->property_name)->toBeNull();

    expect(fn () => recordExpense($this->acme, $agent, ['category' => 'staff', 'description' => 'Salaries']))
        ->toThrow(AuthorizationException::class);
    expect(fn () => recordExpense($this->acme, $agent, ['unitId' => (string) $this->flat->id]))
        ->toThrow(ValidationException::class);

    // An agent records their building's expense.
    expect(storedExpense($this->acme, recordExpense($this->acme, $agent, ['propertyId' => (string) $this->palm->id]))->recorded_by_user_id)->toBe($agent->id)
        ->and(expensesOf($this->acme))->toHaveCount(2);
});

it('checks what is typed and refuses anything that is not an expense', function (array $changes, string $field, string $message) {
    $errors = expenseErrors($this->acme, fn () => app(ExpenseBook::class)->record($this->owner, expenseInput(['propertyId' => (string) $this->palm->id] + $changes)));

    expect($errors)->toHaveKey($field)
        ->and($errors[$field][0])->toContain($message)
        ->and(expensesOf($this->acme))->toHaveCount(0);
})->with([
    'no amount' => [['amount' => ''], 'amount', 'Type the amount paid'],
    'a word for an amount' => [['amount' => 'twenty thousand'], 'amount', 'Type the amount in naira'],
    'three decimals' => [['amount' => '25,000.505'], 'amount', 'Type the amount in naira'],
    'a negative amount' => [['amount' => '-25,000'], 'amount', 'Type the amount in naira'],
    'zero' => [['amount' => '0'], 'amount', 'more than zero'],
    'too much' => [['amount' => '10,000,000,001'], 'amount', 'too large'],
    'a date in the future (Lagos)' => [['spentOn' => '2027-03-16'], 'spentOn', 'cannot be in the future'],
    'not a date' => [['spentOn' => '31/02/2027'], 'spentOn', 'Choose the date it was paid'],
    'before 2000' => [['spentOn' => '1999-12-31'], 'spentOn', 'from 2000 on'],
    'no category' => [['category' => ''], 'category', 'Choose what the money was spent on'],
    'an unknown category' => [['category' => 'bribes'], 'category', 'Choose what the money was spent on'],
    'no description' => [['description' => '  '], 'description', 'Say what was paid for'],
    'an unknown payment method' => [['paymentMethod' => 'crypto'], 'paymentMethod', 'Choose how it was paid'],
    'a description too long' => [['description' => str_repeat('a', 501)], 'description', '500'],
]);

it('takes only a building of this organization, active, and a unit of that building', function () {
    $oak = buildingIn($this->acme, ['name' => 'Oak Lodge', 'slug' => 'oak-lodge']);
    $oakUnit = unitIn($this->acme, $oak, ['unit_label' => 'Oak 1']);
    $archived = inOrganization($this->acme, fn () => Property::factory()->archived()->create(['name' => 'Old Mill']));
    $foreign = buildingIn($this->other, ['name' => 'Foreign Court']);

    expect(expenseErrors($this->acme, fn () => app(ExpenseBook::class)->record($this->owner, expenseInput(['propertyId' => (string) $foreign->id]))))->toHaveKey('propertyId')
        ->and(expenseErrors($this->acme, fn () => app(ExpenseBook::class)->record($this->owner, expenseInput(['propertyId' => '999999']))))->toHaveKey('propertyId')
        ->and(expenseErrors($this->acme, fn () => app(ExpenseBook::class)->record($this->owner, expenseInput(['propertyId' => 'abc']))))->toHaveKey('propertyId')
        ->and(expenseErrors($this->acme, fn () => app(ExpenseBook::class)->record($this->owner, expenseInput(['propertyId' => (string) $archived->id])))['propertyId'][0])->toContain('Old Mill is archived')
        ->and(expenseErrors($this->acme, fn () => app(ExpenseBook::class)->record($this->owner, expenseInput(['propertyId' => (string) $this->palm->id, 'unitId' => (string) $oakUnit->id]))))->toHaveKey('unitId')
        ->and(expensesOf($this->acme))->toHaveCount(0)
        ->and(expensesOf($this->other))->toHaveCount(0);

    // The whole building: no unit.
    $expense = recordExpense($this->acme, $this->owner, ['propertyId' => (string) $oak->id]);
    expect(storedExpense($this->acme, $expense)->unit_id)->toBeNull();
});

it('keeps an expense\'s building and unit, as recorded, once the unit is archived or the building is', function () {
    $expense = recordExpense($this->acme, $this->owner, ['propertyId' => (string) $this->palm->id, 'unitId' => (string) $this->flat->id]);
    inOrganization($this->acme, function (): void {
        Unit::query()->findOrFail($this->flat->id)->delete();
        Property::query()->findOrFail($this->palm->id)->forceFill(['status' => 'archived'])->save();
    });

    // Editing other details keeps both.
    inOrganization($this->acme, fn () => app(ExpenseBook::class)->update($this->owner, storedExpense($this->acme, $expense), expenseInput([
        'propertyId' => (string) $this->palm->id,
        'unitId' => (string) $this->flat->id,
        'amount' => '30,000',
    ])));

    $stored = storedExpense($this->acme, $expense);
    expect($stored->property_id)->toBe($this->palm->id)
        ->and($stored->unit_id)->toBe($this->flat->id)
        ->and($stored->unit_label)->toBe('Flat 2')
        ->and($stored->amount_kobo->kobo)->toBe(3_000_000);
});

it('audits an edit with the fields that changed and the amounts, and nothing when nothing changed', function () {
    $oak = buildingIn($this->acme, ['name' => 'Oak Lodge', 'slug' => 'oak-lodge']);
    $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    $expense = recordExpense($this->acme, $this->owner, ['propertyId' => (string) $this->palm->id]);

    inOrganization($this->acme, fn () => app(ExpenseBook::class)->update($admin, storedExpense($this->acme, $expense), expenseInput(['propertyId' => (string) $this->palm->id])));
    expect(AuditEntry::query()->where('event', 'expense.updated')->count())->toBe(0);

    inOrganization($this->acme, fn () => app(ExpenseBook::class)->update($admin, storedExpense($this->acme, $expense), expenseInput([
        'propertyId' => (string) $oak->id,
        'amount' => '27,500',
        'description' => 'Fixed the leaking kitchen pipe and the tap',
    ])));

    $audit = AuditEntry::query()->where('event', 'expense.updated')->sole();
    $stored = storedExpense($this->acme, $expense);
    expect($audit->causer_id)->toBe($admin->id)
        ->and($audit->properties['changed'])->toEqualCanonicalizing(['property_id', 'description', 'amount_kobo'])
        ->and($audit->properties['amount_from_kobo'])->toBe(2_500_000)
        ->and($audit->properties['amount_to_kobo'])->toBe(2_750_000)
        ->and($audit->properties['property_from'])->toBe($this->palm->id)
        ->and($audit->properties['property_to'])->toBe($oak->id)
        ->and($stored->property_name)->toBe('Oak Lodge')
        ->and($stored->updated_by_user_id)->toBe($admin->id)
        ->and($stored->recorded_by_user_id)->toBe($this->owner->id);
});

it('archives an expense with a reason and restores it, audited, and never deletes it for good', function () {
    $expense = recordExpense($this->acme, $this->owner, ['propertyId' => (string) $this->palm->id]);

    expect(expenseErrors($this->acme, fn () => app(ExpenseBook::class)->archive($this->owner, $expense, ' ')))->toHaveKey('reason');

    inOrganization($this->acme, fn () => app(ExpenseBook::class)->archive($this->owner, $expense, 'Recorded twice'));
    $stored = storedExpense($this->acme, $expense);
    expect($stored->isArchived())->toBeTrue()
        ->and($stored->archived_by_user_id)->toBe($this->owner->id)
        ->and($stored->archive_reason)->toBe('Recorded twice')
        ->and(inOrganization($this->acme, fn () => Expense::query()->count()))->toBe(0)
        ->and(AuditEntry::query()->where('event', 'expense.archived')->sole()->properties['reason'])->toBe('Recorded twice');

    // Archived: not changed, not archived again.
    expect(fn () => inOrganization($this->acme, fn () => app(ExpenseBook::class)->update($this->owner, $stored, expenseInput(['propertyId' => (string) $this->palm->id]))))->toThrow(AuthorizationException::class)
        ->and(fn () => inOrganization($this->acme, fn () => app(ExpenseBook::class)->archive($this->owner, $stored, 'Again')))->toThrow(AuthorizationException::class)
        ->and(fn () => inOrganization($this->acme, fn () => $stored->forceDelete()))->toThrow(LogicException::class);

    inOrganization($this->acme, fn () => app(ExpenseBook::class)->restore($this->owner, $stored));
    $restored = storedExpense($this->acme, $expense);
    expect($restored->isArchived())->toBeFalse()
        ->and($restored->archive_reason)->toBeNull()
        ->and(AuditEntry::query()->where('event', 'expense.restored')->count())->toBe(1)
        ->and(expensesOf($this->acme))->toHaveCount(1);
});
