<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Expenses\Lists\ExpenseList;
use Modules\Expenses\Models\Expense;
use Modules\Expenses\Recording\ExpenseBook;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';

/*
 * P03-12: who may see and change expenses (ARCHITECTURE.md § 5
 * "Expenses", ExpensePolicy). AC "expenses scoped to org": another
 * organization's expense guessed by ID answers 404 everywhere; narrowed
 * agents see only their buildings' expenses (decision 9, BuildingAccess);
 * the organization's own expenses (no building) only owners and
 * administrators see; a suspended organization is read-only; impersonating
 * staff change nothing in a read-only session and, in write mode, record
 * and edit but never archive, add or archive receipts, or open a file.
 */

beforeEach(function () {
    Storage::fake('media');
    seedOrganizationRoles();
    $this->travelTo('2027-03-15 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    $this->otherOwner = joinAs(User::factory()->create(['name' => 'Bola Owner']), $this->other);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->oak = buildingIn($this->acme, ['name' => 'Oak Lodge', 'slug' => 'oak-lodge']);
    $this->palmExpense = recordExpense($this->acme, $this->owner, ['propertyId' => (string) $this->palm->id, 'description' => 'Palm gate motor repair', 'vendorName' => 'Gatefix Ltd'], [receiptFile()]);
    $this->oakExpense = recordExpense($this->acme, $this->owner, ['propertyId' => (string) $this->oak->id, 'description' => 'Oak borehole servicing', 'vendorName' => 'Aqua Drillers']);
    $this->office = recordExpense($this->acme, $this->owner, ['category' => 'staff', 'description' => 'Office salaries for March', 'vendorName' => 'Staff payroll', 'amount' => '900,000']);
    $this->foreign = recordExpense($this->other, $this->otherOwner, ['propertyId' => (string) buildingIn($this->other, ['name' => 'Foreign Court'])->id, 'description' => 'Foreign roof work', 'vendorName' => 'Foreign Roofers'], [receiptFile('foreign.png', 7)]);
    $this->foreignOffice = recordExpense($this->other, $this->otherOwner, ['category' => 'agency_ops', 'description' => 'Foreign office rent', 'vendorName' => 'Foreign Landlord']);
    $this->palmReceipt = expenseReceiptsOf($this->acme, $this->palmExpense)->sole();
    $this->foreignReceipt = expenseReceiptsOf($this->other, $this->foreign)->sole();
});

afterEach(fn () => expensesFreshRequest());

/** @return array<string, bool> ability => allowed, as ExpensePolicy answers inside the organization */
function expenseAbilitiesOf(User $user, Organization $organization, Expense $building, Expense $own): array
{
    return inOrganization($organization, fn (): array => [
        'viewAny' => Gate::forUser($user)->allows('viewAny', Expense::class),
        'export' => Gate::forUser($user)->allows('export', Expense::class),
        'view' => Gate::forUser($user)->allows('view', $building),
        'viewReceiptFile' => Gate::forUser($user)->allows('viewReceiptFile', $building),
        'create' => Gate::forUser($user)->allows('create', Expense::class),
        'update' => Gate::forUser($user)->allows('update', $building),
        'attachReceipt' => Gate::forUser($user)->allows('attachReceipt', $building),
        'viewOrganizationExpenses' => Gate::forUser($user)->allows('viewOrganizationExpenses', Expense::class),
        'viewOwn' => Gate::forUser($user)->allows('view', $own),
        'createUnlinked' => Gate::forUser($user)->allows('createUnlinked', Expense::class),
        'archiveReceipt' => Gate::forUser($user)->allows('archiveReceipt', $building),
        'delete' => Gate::forUser($user)->allows('delete', $building),
        'forceDelete' => Gate::forUser($user)->allows('forceDelete', $building),
    ]);
}

it('enforces the role rules in ExpensePolicy: agents record their buildings\' expenses, owners and administrators also keep the organization\'s own and archive', function (OrganizationRole $role, array $allowed) {
    $user = joinAs(User::factory()->create(), $this->acme, $role);
    $abilities = ['viewAny', 'export', 'view', 'viewReceiptFile', 'create', 'update', 'attachReceipt', 'viewOrganizationExpenses', 'viewOwn', 'createUnlinked', 'archiveReceipt', 'delete', 'forceDelete'];

    expect(expenseAbilitiesOf($user, $this->acme, $this->palmExpense, $this->office))
        ->toBe(array_combine($abilities, array_map(fn (string $ability): bool => in_array($ability, $allowed, true), $abilities)));
})->with([
    'org-owner' => [OrganizationRole::Owner, ['viewAny', 'export', 'view', 'viewReceiptFile', 'create', 'update', 'attachReceipt', 'viewOrganizationExpenses', 'viewOwn', 'createUnlinked', 'archiveReceipt', 'delete']],
    'org-admin' => [OrganizationRole::Admin, ['viewAny', 'export', 'view', 'viewReceiptFile', 'create', 'update', 'attachReceipt', 'viewOrganizationExpenses', 'viewOwn', 'createUnlinked', 'archiveReceipt', 'delete']],
    'agent' => [OrganizationRole::Agent, ['viewAny', 'export', 'view', 'viewReceiptFile', 'create', 'update', 'attachReceipt']],
    'property-owner' => [OrganizationRole::PropertyOwner, []],
    'tenant' => [OrganizationRole::Tenant, []],
]);

it('opens the expense pages for owners, administrators and agents, and 404 for property owners, tenants and strangers', function () {
    $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);

    foreach ([$this->owner, $admin, $agent] as $user) {
        expensesPage($user)->assertOk()->assertSee('Palm gate motor repair')->assertSee('data-test="add-expense"', false)->assertSee('data-test="export-expenses-csv"', false)->assertSee('data-nav="expenses"', false);
        expensesPage($user, '/create')->assertOk();
        expensesPage($user, "/{$this->palmExpense->id}")->assertOk()->assertSee('data-test="edit-expense"', false)->assertSee('data-test="add-receipts"', false);
        expensesPage($user, "/{$this->palmExpense->id}/edit")->assertOk();
        expensesPage($user, "/{$this->palmExpense->id}/receipts/{$this->palmReceipt->id}")->assertRedirectContains('/media/');
    }

    foreach ([joinAs(User::factory()->create(), $this->acme, OrganizationRole::PropertyOwner), joinAs(User::factory()->create(), $this->acme, OrganizationRole::Tenant), User::factory()->create()] as $user) {
        foreach (['', '/create', "/{$this->palmExpense->id}", "/{$this->palmExpense->id}/edit", "/{$this->palmExpense->id}/receipts/{$this->palmReceipt->id}"] as $path) {
            expensesPage($user, $path)->assertNotFound();
        }
    }
});

describe('acceptance: expenses scoped to the organization', function () {
    it('answers 404 for another organization\'s expense guessed by ID, on every page and file, and never lists it', function () {
        foreach ([$this->foreign, $this->foreignOffice] as $expense) {
            expensesPage($this->owner, "/{$expense->id}")->assertNotFound();
            expensesPage($this->owner, "/{$expense->id}/edit")->assertNotFound();
        }

        expensesPage($this->owner, "/{$this->foreign->id}/receipts/{$this->foreignReceipt->id}")->assertNotFound();
        expensesPage($this->owner, "/{$this->foreign->id}/receipts/{$this->foreignReceipt->id}/thumb")->assertNotFound();
        expensesPage($this->owner, '/999999')->assertNotFound();
        expensesPage($this->owner, '/abc')->assertNotFound();

        // Never listed, searched, filtered or exported into view.
        foreach (['', '?q=Foreign', '?archived=1', '?building=none', '?category=agency_ops'] as $query) {
            expensesPage($this->owner, $query)->assertOk()->assertDontSee('Foreign roof work')->assertDontSee('Foreign office rent');
        }

        $rows = exportRows($this->owner, '/expenses', 'expenses');
        expect(exportColumn($rows, 'Description'))->toEqualCanonicalizing(['Palm gate motor repair', 'Oak borehole servicing', 'Office salaries for March'])
            ->and(inOrganization($this->acme, fn () => Expense::query()->find($this->foreign->id)))->toBeNull();

        // Their owner sees none of ours either; a member of both sees each one's own.
        expensesPage($this->otherOwner, "/{$this->palmExpense->id}", 'other-realty')->assertNotFound();
        joinAs($this->owner, $this->other);
        expect(listedExpenses(expensesPage($this->owner)))->toEqualCanonicalizing([$this->palmExpense->id, $this->oakExpense->id, $this->office->id])
            ->and(listedExpenses(expensesPage($this->owner, '', 'other-realty')))->toEqualCanonicalizing([$this->foreign->id, $this->foreignOffice->id]);
    });

    it('refuses another organization\'s expense, building or unit sent straight to the server', function () {
        // Livewire answers a tampered snapshot with 419 in production (debug off).
        config(['app.debug' => false]);
        $tamper = function (string $snapshot, string $property, int $id): string {
            $data = json_decode($snapshot, true);
            $data['data'][$property] = $id;

            return (string) json_encode($data);
        };

        expensesUpdate($this->owner, $tamper(expensesSnapshot($this->owner, "/{$this->palmExpense->id}"), 'expenseId', $this->foreign->id), ['archiveReason' => 'Not ours'], [['archive']])->assertStatus(419);
        expensesUpdate($this->owner, $tamper(expensesSnapshot($this->owner, "/{$this->palmExpense->id}/edit"), 'expenseId', $this->foreign->id), [], [['save']])->assertStatus(419);

        // A foreign building or unit typed into the form is refused, nothing recorded.
        $foreignBuilding = buildingIn($this->other, ['name' => 'Foreign Plaza']);
        $foreignUnit = unitIn($this->other, $foreignBuilding);
        expensesUpdate($this->owner, expensesSnapshot($this->owner, '/create'), ['propertyId' => (string) $foreignBuilding->id, 'category' => 'repairs', 'description' => 'Sneaky', 'amount' => '1,000'], [['save']])->assertOk();
        expect(expensesOf($this->acme)->pluck('description')->all())->not->toContain('Sneaky');

        expect(fn () => recordExpense($this->acme, $this->owner, ['propertyId' => (string) $this->palm->id, 'unitId' => (string) $foreignUnit->id]))->toThrow(ValidationException::class)
            ->and(fn () => inOrganization($this->acme, fn () => app(ExpenseBook::class)->update($this->owner, $this->foreign, expenseInput())))->toThrow(AuthorizationException::class)
            ->and(storedExpense($this->other, $this->foreign)->description)->toBe('Foreign roof work')
            ->and(expensesOf($this->other))->toHaveCount(2);
    });
});

it('shows a narrowed agent only their buildings\' expenses, never the organization\'s own, and 404 for the others', function () {
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    assignIn($this->acme, $agent, $this->palm);
    $oakReceipt = inOrganization($this->acme, fn () => app(ExpenseBook::class)->attachReceipts($this->owner, $this->oakExpense, [receiptFile('oak.png', 4)]))[0];

    expect(listedExpenses(expensesPage($agent)))->toBe([$this->palmExpense->id])
        ->and(listedExpenses(expensesPage($agent, '?building='.$this->oak->id)))->toBe([])
        ->and(listedExpenses(expensesPage($agent, '?building=none')))->toBe([])
        ->and(listedExpenses(expensesPage($agent, '?q=salaries')))->toBe([]);
    expensesPage($agent)->assertDontSee('Oak Lodge')->assertDontSee('No building (the organization');

    foreach ([$this->oakExpense, $this->office] as $hidden) {
        expensesPage($agent, "/{$hidden->id}")->assertNotFound();
        expensesPage($agent, "/{$hidden->id}/edit")->assertNotFound();
    }
    expensesPage($agent, "/{$this->oakExpense->id}/receipts/{$oakReceipt->id}")->assertNotFound();

    $book = app(ExpenseBook::class);
    $refusedAs404 = fn (Closure $action) => expect(fn () => inOrganization($this->acme, $action))
        ->toThrow(fn (AuthorizationException $e) => expect($e->status())->toBe(404));
    $refusedAs404(fn () => $book->update($agent, $this->oakExpense, expenseInput(['propertyId' => (string) $this->oak->id])));
    $refusedAs404(fn () => $book->attachReceipts($agent, $this->oakExpense, [receiptFile('x.png', 5)]));
    $refusedAs404(fn () => $book->record($agent, expenseInput(['propertyId' => (string) $this->oak->id])));
    // Moving their own expense to another building is refused too.
    $refusedAs404(fn () => $book->update($agent, $this->palmExpense, expenseInput(['propertyId' => (string) $this->oak->id])));

    // The export holds the same rows as the page.
    expect(exportColumn(exportRows($agent, '/expenses', 'expenses'), 'Description'))->toBe(['Palm gate motor repair']);

    // The form offers their buildings only, and no "no building".
    expensesPage($agent, '/create')->assertOk()->assertSee('Palm Court')->assertDontSee('Oak Lodge')->assertDontSee('No building (the organization');

    // An agent with no assignment sees every building's expenses, still not the organization's own.
    $free = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    expect(listedExpenses(expensesPage($free)))->toEqualCanonicalizing([$this->palmExpense->id, $this->oakExpense->id]);
    expensesPage($free, "/{$this->office->id}")->assertNotFound();

    // Owners see everything.
    expect(listedExpenses(expensesPage($this->owner)))->toHaveCount(3);
});

it('keeps expenses read-only while the organization is suspended, and still exports them', function () {
    $show = expensesSnapshot($this->owner, "/{$this->palmExpense->id}");
    $edit = expensesSnapshot($this->owner, "/{$this->palmExpense->id}/edit");
    $create = expensesSnapshot($this->owner, '/create');
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    expensesPage($this->owner)->assertOk()->assertSee('Palm gate motor repair')->assertSee('Office salaries for March')->assertDontSee('data-test="add-expense"', false)->assertSee('data-test="export-expenses-csv"', false);
    expensesPage($this->owner, "/{$this->palmExpense->id}")->assertOk()->assertDontSee('data-test="edit-expense"', false)->assertDontSee('data-test="archive-expense"', false)->assertDontSee('data-test="add-receipts"', false);
    expensesPage($this->owner, "/{$this->office->id}")->assertOk();
    expensesPage($this->owner, "/{$this->palmExpense->id}/receipts/{$this->palmReceipt->id}")->assertRedirectContains('/media/');
    expensesPage($this->owner, '/create')->assertForbidden();
    expensesPage($this->owner, "/{$this->palmExpense->id}/edit")->assertForbidden();

    expensesUpdate($this->owner, $show, ['archiveReason' => 'Duplicate'], [['archive']])->assertForbidden();
    expensesUpdate($this->owner, $show, ['receiptReasons' => [$this->palmReceipt->id => 'Blurred']], [['archiveReceipt', [$this->palmReceipt->id]]])->assertForbidden();
    expensesUpdate($this->owner, $edit, ['amount' => '99,000'], [['save']])->assertForbidden();
    expensesUpdate($this->owner, $create, expenseInput(['propertyId' => (string) $this->palm->id]), [['save']])->assertForbidden();

    $book = app(ExpenseBook::class);
    expect(fn () => recordExpense($this->acme, $this->owner, ['propertyId' => (string) $this->palm->id]))->toThrow(AuthorizationException::class)
        ->and(fn () => inOrganization($this->acme, fn () => $book->attachReceipts($this->owner, $this->palmExpense, [receiptFile('late.png', 2)])))->toThrow(AuthorizationException::class)
        ->and(fn () => inOrganization($this->acme, fn () => $book->archive($this->owner, $this->palmExpense, 'Duplicate')))->toThrow(AuthorizationException::class)
        ->and(expensesOf($this->acme))->toHaveCount(3)
        ->and(storedExpense($this->acme, $this->palmExpense)->amount_kobo->kobo)->toBe(2_500_000)
        ->and(storedExpense($this->acme, $this->palmExpense)->isArchived())->toBeFalse()
        ->and(expenseReceiptsOf($this->acme, $this->palmExpense)->sole()->isArchived())->toBeFalse();

    $pressed = expensesUpdate($this->owner, expensesSnapshot($this->owner), [], [['export', ['expenses', 'csv']]])->assertOk();
    expect(LivewireHttp::redirect($pressed))->toContain('/exports/');
});

it('shows impersonating staff the expenses read-only in a read-only session, without receipt files, and lets them export the list', function () {
    $staff = staffMember(['name' => 'Kemi Support', 'email' => 'kemi@goproperty.ng']);
    enterSupport(startSupport($staff, $this->owner, $this->acme))->assertRedirect('/o/acme-homes');

    $list = asSupport()->get('/o/acme-homes/expenses')->assertOk()->assertSee('Palm gate motor repair')->assertSee('Office salaries for March')->assertDontSee('data-test="add-expense"', false)->assertSee('data-test="export-expenses-csv"', false);
    $show = LivewireHttp::snapshot(asSupport()->get("/o/acme-homes/expenses/{$this->palmExpense->id}")->assertOk()
        ->assertSee('data-test="receipt-hidden-'.$this->palmReceipt->id.'"', false)
        ->assertDontSee('data-test="open-receipt-'.$this->palmReceipt->id.'"', false)
        ->assertDontSee('data-test="edit-expense"', false));
    asSupport()->get('/o/acme-homes/expenses/create')->assertForbidden();
    asSupport()->get("/o/acme-homes/expenses/{$this->palmExpense->id}/receipts/{$this->palmReceipt->id}")->assertForbidden();

    freshRequest();
    LivewireHttp::update(test(), $show, ['archiveReason' => 'Duplicate'], [['archive']])->assertForbidden();
    freshRequest();
    $pressed = LivewireHttp::update(test(), LivewireHttp::snapshot($list), [], [['export', ['expenses', 'csv']]])->assertOk();

    expect(LivewireHttp::redirect($pressed))->toContain('/exports/')
        ->and(storedExpense($this->acme, $this->palmExpense)->isArchived())->toBeFalse();
});

it('lets impersonating staff in write mode record and edit expenses, audited with both identities, but never archive one, touch receipts or open a file', function () {
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    $handOff = startSupport($staff, $this->owner, $this->acme, ImpersonationMode::Write);
    enterSupport($handOff)->assertRedirect('/o/acme-homes');

    // Record and edit: the organization's own bookkeeping, not tenant money.
    $create = LivewireHttp::snapshot(asSupport()->get('/o/acme-homes/expenses/create')->assertOk()->assertDontSee('data-test="expense-receipts"', false));
    freshRequest();
    LivewireHttp::update(test(), $create, expenseInput(['propertyId' => (string) $this->palm->id, 'description' => 'Recorded by support']), [['save']])->assertOk();
    $edit = LivewireHttp::snapshot(asSupport()->get("/o/acme-homes/expenses/{$this->oakExpense->id}/edit")->assertOk());
    freshRequest();
    LivewireHttp::update(test(), $edit, ['amount' => '26,000'], [['save']])->assertOk();

    // Never archive, add or archive a receipt, or open a file.
    $show = LivewireHttp::snapshot(asSupport()->get("/o/acme-homes/expenses/{$this->palmExpense->id}")->assertOk()
        ->assertDontSee('data-test="archive-expense"', false)
        ->assertDontSee('data-test="add-receipts"', false)
        ->assertDontSee('data-test="archive-receipt-'.$this->palmReceipt->id.'"', false));
    freshRequest();
    LivewireHttp::update(test(), $show, ['archiveReason' => 'Duplicate'], [['archive']])->assertForbidden();
    freshRequest();
    LivewireHttp::update(test(), $show, ['receiptReasons' => [$this->palmReceipt->id => 'Blurred']], [['archiveReceipt', [$this->palmReceipt->id]]])->assertForbidden();
    freshRequest();
    LivewireHttp::update(test(), $show, [], [['addReceipts']])->assertForbidden();
    asSupport()->get("/o/acme-homes/expenses/{$this->palmExpense->id}/receipts/{$this->palmReceipt->id}")->assertForbidden();

    $recorded = AuditEntry::query()->where('event', 'expense.recorded')->latest('id')->first();
    $changed = AuditEntry::query()->where('event', 'expense.updated')->sole();
    expect(expensesOf($this->acme)->pluck('description')->all())->toContain('Recorded by support')
        ->and($recorded->causer_id)->toBe($this->owner->id)
        ->and($recorded->impersonator_staff_id)->toBe($staff->id)
        ->and($changed->impersonator_staff_id)->toBe($staff->id)
        ->and(storedExpense($this->acme, $this->oakExpense)->amount_kobo->kobo)->toBe(2_600_000)
        ->and(storedExpense($this->acme, $this->palmExpense)->isArchived())->toBeFalse()
        ->and(expenseReceiptsOf($this->acme, $this->palmExpense)->sole()->isArchived())->toBeFalse()
        ->and(AuditEntry::query()->where('event', 'impersonation.blocked')->where('subject_id', $handOff->session->id)->exists())->toBeTrue();
});

it('exports only what the member sees, with the page\'s filters', function () {
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);

    expect(inOrganization($this->acme, fn () => app(ExpenseList::class)->count($agent, [])))->toBe(2)
        ->and(inOrganization($this->acme, fn () => app(ExpenseList::class)->count($this->owner, [])))->toBe(3)
        ->and(inOrganization($this->acme, fn () => app(ExpenseList::class)->count($this->owner, app(ExpenseList::class)->filters(['building' => 'none']))))->toBe(1)
        ->and(inOrganization($this->acme, fn () => app(ExpenseList::class)->count($agent, app(ExpenseList::class)->filters(['building' => 'none']))))->toBe(0);
});
