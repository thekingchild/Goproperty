<?php

declare(strict_types=1);

namespace Modules\Expenses\Livewire;

use App\Media\UploadValidator;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Expenses\Enums\ExpenseCategory;
use Modules\Expenses\Enums\ExpensePaymentMethod;
use Modules\Expenses\Models\Expense;
use Modules\Expenses\Recording\ExpenseBook;
use Modules\Expenses\Recording\ExpenseInput;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\UnitDirectory;

/**
 * Record an expense (/o/{organization}/expenses/create, optionally
 * `?building={id}`) or change one (/expenses/{id}/edit; P03-12): the
 * building and unit (or none: the organization's own, for owners and
 * administrators), category, what was paid for, amount in naira, date
 * paid, who was paid, how, a reference and a note; when recording, the
 * receipt files too (JPEG, PNG, WebP or PDF, checked from their bytes here
 * and again before they are stored).
 *
 * ExpensePolicy::create / update, checked again by Recording\ExpenseBook,
 * which also checks the building against the member's (an assigned agent:
 * theirs only).
 */
#[Layout('layouts::app')]
#[Title('Expense')]
final class ExpenseForm extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $organizationId;

    #[Locked]
    public ?int $expenseId = null;

    #[Url(as: 'building', except: '')]
    public string $propertyId = '';

    public string $unitId = '';

    public string $category = '';

    public string $description = '';

    /** Naira as typed. */
    public string $amount = '';

    public string $spentOn = '';

    public string $vendorName = '';

    public string $paymentMethod = '';

    public string $reference = '';

    public string $note = '';

    /** @var array<int, mixed> Receipt files chosen (Livewire's temporary uploads), when recording. */
    public array $receipts = [];

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, PropertyDirectory $buildings, ?Expense $expense = null): void
    {
        $this->organizationId = $context->id();

        if ($expense === null) {
            $this->authorize('create', Expense::class);
            $this->spentOn = ExpenseInput::today()->toDateString();
            $this->paymentMethod = ExpensePaymentMethod::BankTransfer->value;

            // ?building= from a building's page: only one this member may choose.
            if (! ctype_digit($this->propertyId) || ! array_key_exists((int) $this->propertyId, $buildings->activeOptions($this->user()))) {
                $this->propertyId = '';
            }

            return;
        }

        $this->authorize('update', $expense);
        $this->expenseId = $expense->id;
        $this->propertyId = $expense->property_id !== null ? (string) $expense->property_id : '';
        $this->unitId = $expense->unit_id !== null ? (string) $expense->unit_id : '';
        $this->category = $expense->category->value;
        $this->description = $expense->description;
        $this->amount = ExpenseInput::showAmount($expense->amount_kobo);
        $this->spentOn = $expense->spent_on->toDateString();
        $this->vendorName = (string) $expense->vendor_name;
        $this->paymentMethod = $expense->payment_method->value;
        $this->reference = (string) $expense->reference;
        $this->note = (string) $expense->note;
    }

    /** Another building chosen: its units, not the last one's. */
    public function updatedPropertyId(): void
    {
        $this->unitId = '';
    }

    public function save(ExpenseBook $book, UploadValidator $validator): void
    {
        $user = $this->user();
        $input = [
            'propertyId' => $this->propertyId,
            'unitId' => $this->unitId,
            'category' => $this->category,
            'description' => $this->description,
            'amount' => $this->amount,
            'spentOn' => $this->spentOn,
            'vendorName' => $this->vendorName,
            'paymentMethod' => $this->paymentMethod,
            'reference' => $this->reference,
            'note' => $this->note,
        ];

        if (trim($this->propertyId) === '' && ! $user->can('createUnlinked', Expense::class)) {
            throw ValidationException::withMessages(['propertyId' => __('Choose the building the money was spent on.')]);
        }

        if ($this->expenseId !== null) {
            $expense = $this->expense();
            $this->authorize('update', $expense);
            $book->update($user, $expense, $input);

            session()->flash('expense-status', __('The expense was saved.'));
            $this->redirectRoute('workspace.expenses.show', ['organization' => $this->organizationSlug(), 'expense' => $expense->id], navigate: true);

            return;
        }

        $this->authorize('create', Expense::class);

        if ($this->receipts !== []) {
            $this->validate(
                ['receipts' => ['array', 'max:'.ExpenseBook::FILES_PER_UPLOAD], 'receipts.*' => ['file', $validator->rule(Expense::COLLECTION)]],
                ['receipts.max' => __('Add at most :count files at a time.', ['count' => ExpenseBook::FILES_PER_UPLOAD])],
            );
        }

        $expense = $book->record($user, $input, array_values(array_filter($this->receipts, static fn (mixed $file): bool => $file instanceof UploadedFile)));

        session()->flash('expense-status', __('The expense was recorded.'));
        $this->redirectRoute('workspace.expenses.show', ['organization' => $this->organizationSlug(), 'expense' => $expense->id], navigate: true);
    }

    public function render(OrganizationContext $context, PropertyDirectory $buildings, UnitDirectory $units): View
    {
        $user = $this->user();
        $options = $buildings->activeOptions($user);
        $expense = $this->expenseId !== null ? $this->expense() : null;

        // An expense kept on a building no longer offered (archived, handed over) keeps it.
        if ($expense !== null && $expense->property_id !== null && ! array_key_exists($expense->property_id, $options)) {
            $options = [$expense->property_id => (string) ($buildings->find($expense->property_id)->name ?? $expense->property_name)] + $options;
        }

        $chosen = ctype_digit($this->propertyId) && array_key_exists((int) $this->propertyId, $options) ? (int) $this->propertyId : null;
        $unitOptions = $chosen !== null ? array_map(static fn ($unit): string => $unit->label, $units->inProperty($chosen)) : [];

        if ($expense !== null && $expense->unit_id !== null && $chosen === $expense->property_id && ! array_key_exists($expense->unit_id, $unitOptions)) {
            $unitOptions = [$expense->unit_id => (string) $expense->unit_label] + $unitOptions;
        }

        $probe = new Expense;
        $probe->forceFill(['organization_id' => $context->id(), 'property_id' => $chosen]);

        return view('expenses::livewire.expense-form', [
            'organization' => $context->get(),
            'expense' => $expense,
            'buildings' => $options,
            'units' => $unitOptions,
            'canUnlinked' => $user->can('createUnlinked', Expense::class),
            'canAttach' => $expense === null && Gate::forUser($user)->allows('attachReceipt', $probe),
            'categories' => ExpenseCategory::cases(),
            'methods' => ExpensePaymentMethod::cases(),
            'maxFiles' => ExpenseBook::FILES_PER_UPLOAD,
        ]);
    }

    private function expense(): Expense
    {
        return Expense::query()->findOrFail($this->expenseId);
    }

    private function organizationSlug(): string
    {
        return (string) app(OrganizationContext::class)->get()->slug;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
