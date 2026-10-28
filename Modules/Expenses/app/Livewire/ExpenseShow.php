<?php

declare(strict_types=1);

namespace Modules\Expenses\Livewire;

use App\Media\Media;
use App\Media\UploadValidator;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Expenses\Lists\ExpenseRows;
use Modules\Expenses\Models\Expense;
use Modules\Expenses\Models\ExpenseReceipt;
use Modules\Expenses\Recording\ExpenseBook;

/**
 * An expense's page, /o/{organization}/expenses/{id} (P03-12): what was
 * paid, for which building and unit (or the organization's own), to whom,
 * how, who recorded and changed it, and its receipt files — thumbnails and
 * links made only after ExpensePolicy::viewReceiptFile (never while staff
 * impersonate), archived ones listed with who archived them and why.
 * Actions: Edit, add receipts, archive a receipt (with a reason; the file
 * is kept), archive the expense (with a reason) and restore it. An archived
 * expense opens read-only with Restore.
 *
 * Every action checks ExpensePolicy here and again in
 * Recording\ExpenseBook; adding and archiving receipts are refused while
 * staff impersonate.
 */
#[Layout('layouts::app')]
#[Title('Expense')]
final class ExpenseShow extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $organizationId;

    #[Locked]
    public int $expenseId;

    /** @var array<int, mixed> Receipt files chosen (Livewire's temporary uploads). */
    public array $receipts = [];

    public string $archiveReason = '';

    /** @var array<int|string, mixed> Why each receipt is removed, by receipt id (as typed). */
    public array $receiptReasons = [];

    public ?string $notice = null;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, Expense $expense): void
    {
        $this->organizationId = $context->id();
        $this->authorize('view', $expense);
        $this->expenseId = $expense->id;
    }

    #[BlockedWhileImpersonating]
    public function addReceipts(ExpenseBook $book, UploadValidator $validator): void
    {
        $expense = $this->expense();
        $this->authorize('attachReceipt', $expense);
        $this->validate(
            ['receipts' => ['required', 'array', 'max:'.ExpenseBook::FILES_PER_UPLOAD], 'receipts.*' => ['file', $validator->rule(Expense::COLLECTION)]],
            ['receipts.required' => __('Choose the receipt: a photo, a scan or a PDF.'), 'receipts.max' => __('Add at most :count files at a time.', ['count' => ExpenseBook::FILES_PER_UPLOAD])],
        );

        $added = $book->attachReceipts($this->user(), $expense, array_values(array_filter($this->receipts, static fn (mixed $file): bool => $file instanceof UploadedFile)));
        $this->reset('receipts');
        $this->notice = trans_choice('The receipt was added.|:count receipts were added.', count($added), ['count' => count($added)]);
    }

    /** "Remove" a receipt: archived with the reason; its file is kept (evidence). */
    #[BlockedWhileImpersonating]
    public function archiveReceipt(ExpenseBook $book, int $receiptId): void
    {
        $expense = $this->expense();
        $this->authorize('archiveReceipt', $expense);
        $receipt = ExpenseReceipt::query()->where('expense_id', $expense->id)->findOrFail($receiptId);

        $reason = $this->receiptReasons[$receipt->id] ?? '';
        $book->archiveReceipt($this->user(), $receipt, is_string($reason) ? $reason : '');
        $this->reset('receiptReasons');
        $this->notice = __('The receipt is archived. Its file is kept.');
    }

    public function archive(ExpenseBook $book): void
    {
        $expense = $this->expense();
        $this->authorize('delete', $expense);

        $book->archive($this->user(), $expense, $this->archiveReason);
        $this->reset('archiveReason');
        $this->notice = __('The expense is archived. It is kept with its receipts, and you can restore it.');
    }

    public function restore(ExpenseBook $book): void
    {
        $expense = $this->expense();
        $this->authorize('restore', $expense);

        $book->restore($this->user(), $expense);
        $this->notice = __('The expense is restored.');
    }

    public function render(OrganizationContext $context, ExpenseRows $rows): View
    {
        $user = $this->user();
        $expense = $this->expense();
        $this->authorize('view', $expense);
        $receipts = ExpenseReceipt::query()->where('expense_id', $expense->id)->orderBy('id')->get();
        $media = Media::query()->whereKey($receipts->pluck('media_id')->all())->get()->keyBy('id');
        $people = User::query()->whereKey(array_values(array_filter([
            $expense->updated_by_user_id,
            $expense->archived_by_user_id,
            ...$receipts->pluck('uploaded_by_user_id')->all(),
            ...$receipts->pluck('archived_by_user_id')->all(),
        ])))->pluck('name', 'id')->all();

        return view('expenses::livewire.expense-show', [
            'organization' => $context->get(),
            'expense' => $expense,
            'row' => $rows->rows([$expense])[0],
            'kept' => $receipts->filter(static fn (ExpenseReceipt $receipt): bool => ! $receipt->isArchived())->values(),
            'archivedReceipts' => $receipts->filter(static fn (ExpenseReceipt $receipt): bool => $receipt->isArchived())->values(),
            'media' => $media,
            'people' => $people,
            'flash' => session('expense-status'),
            'canEdit' => $user->can('update', $expense),
            'canAttach' => $user->can('attachReceipt', $expense),
            'canArchiveReceipt' => $user->can('archiveReceipt', $expense),
            'canOpenFiles' => $user->can('viewReceiptFile', $expense),
            'canArchive' => $user->can('delete', $expense),
            'canRestore' => $user->can('restore', $expense),
            'maxFiles' => ExpenseBook::FILES_PER_UPLOAD,
        ]);
    }

    private function expense(): Expense
    {
        return Expense::query()->withTrashed()->findOrFail($this->expenseId);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
