<?php

declare(strict_types=1);

namespace Modules\Expenses\Recording;

use App\Media\MediaUploads;
use App\Media\UploadRefused;
use App\Media\UploadValidator;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Expenses\Enums\ExpenseCategory;
use Modules\Expenses\Enums\ExpensePaymentMethod;
use Modules\Expenses\Models\Expense;
use Modules\Expenses\Models\ExpenseReceipt;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\UnitDirectory;

/**
 * The only writer of expenses and their receipts (P03-12;
 * DATABASE_SCHEMA.md § 4 `expenses`, `expense_receipts`): record, edit,
 * archive (a soft delete with a reason) and restore an expense; add receipt
 * files and archive one. Every method checks ExpensePolicy for the acting
 * member (so the suspension and impersonation guards apply) and writes the
 * audit log (log `expenses`, the expense as subject; ids, amounts and
 * reasons, never the description or note).
 *
 * Building and unit come from the Properties module's contracts
 * (PropertyDirectory, UnitDirectory, BuildingAccess; ARCHITECTURE.md § 8
 * rule 3): a building of this organization the member works on (another
 * one answers 404 to a narrowed agent), not archived for a new link; a
 * unit of that building. No building at all is the organization's own
 * expense (`createUnlinked`: owners and administrators). The names are
 * kept with the expense as recorded (history, P03-14).
 *
 * Receipts are evidence (AGENT.md § 3 rule 7, ARCHITECTURE.md § 9): each
 * file is checked by App\Media\UploadValidator (type sniffed from the
 * bytes: JPEG, PNG, WebP or PDF; never SVG; 10 MB) before anything is
 * written, stored through App\Media\MediaUploads in the expense's
 * `expense_receipts` collection with an ExpenseReceipt row, and never
 * deleted: "remove" archives the row with a reason and keeps the file.
 */
final readonly class ExpenseBook
{
    public const string LOG = 'expenses';

    /** Receipt files one upload may add. */
    public const int FILES_PER_UPLOAD = 5;

    /** Receipt files an expense keeps, not counting archived ones. */
    public const int MAX_RECEIPTS = 10;

    /** Columns compared for the audit entry of an edit. */
    private const array AUDITED = ['property_id', 'unit_id', 'category', 'description', 'amount_kobo', 'spent_on', 'vendor_name', 'payment_method', 'reference', 'note'];

    public function __construct(
        private OrganizationContext $context,
        private PropertyDirectory $buildings,
        private UnitDirectory $units,
        private BuildingAccess $access,
        private UploadValidator $validator,
        private MediaUploads $uploads,
    ) {}

    /**
     * @param  array<string, mixed>  $input  propertyId, unitId, category, description, amount (naira), spentOn (Y-m-d), vendorName, paymentMethod, reference, note
     * @param  list<UploadedFile>  $receipts  receipt files to keep with it
     *
     * @throws ValidationException
     */
    public function record(User $by, array $input, array $receipts = []): Expense
    {
        Gate::forUser($by)->authorize('create', Expense::class);

        $values = $this->validated($input);
        $place = $this->place($by, null, $values['propertyId'], $values['unitId']);
        $expense = new Expense;
        $expense->forceFill(['organization_id' => $this->context->id(), 'property_id' => $place['property_id']]);

        if ($receipts !== []) {
            Gate::forUser($by)->authorize('attachReceipt', $expense);
            $this->checkedFiles($receipts, 0);
        }

        return DB::transaction(function () use ($by, $values, $place, $expense, $receipts): Expense {
            $this->fill($expense, $values, $place);
            $expense->recorded_by_user_id = $by->id;
            $expense->save();

            $this->audit('expense.recorded', $expense, $by, 'Expense recorded', [
                'category' => $expense->category->value,
                'amount_kobo' => $expense->amount_kobo->kobo,
                'spent_on' => $expense->spent_on->toDateString(),
                'property_id' => $expense->property_id,
                'unit_id' => $expense->unit_id,
            ]);

            foreach ($receipts as $file) {
                $this->store($by, $expense, $file);
            }

            return $expense;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $input  as for record()
     *
     * @throws ValidationException
     */
    public function update(User $by, Expense $expense, array $input): Expense
    {
        Gate::forUser($by)->authorize('update', $expense);

        $values = $this->validated($input);

        return DB::transaction(function () use ($by, $expense, $values): Expense {
            $expense = $this->locked($expense);
            Gate::forUser($by)->authorize('update', $expense);
            $before = ['amount_kobo' => $expense->amount_kobo->kobo, 'property_id' => $expense->property_id];
            $place = $this->place($by, $expense, $values['propertyId'], $values['unitId']);

            $this->fill($expense, $values, $place);
            $changed = array_values(array_intersect(self::AUDITED, array_keys($expense->getDirty())));

            if ($changed === []) {
                return $expense;
            }

            $expense->updated_by_user_id = $by->id;
            $expense->save();

            $this->audit('expense.updated', $expense, $by, 'Expense changed', ['changed' => $changed]
                + (in_array('amount_kobo', $changed, true) ? ['amount_from_kobo' => $before['amount_kobo'], 'amount_to_kobo' => $expense->amount_kobo->kobo] : [])
                + (in_array('property_id', $changed, true) ? ['property_from' => $before['property_id'], 'property_to' => $expense->property_id] : []));

            return $expense;
        }, 3);
    }

    /**
     * Archive the expense: a soft delete with who and why; its receipts
     * stay (AGENT.md § 3 rule 7).
     *
     * @throws ValidationException
     */
    public function archive(User $by, Expense $expense, string $reason): Expense
    {
        Gate::forUser($by)->authorize('delete', $expense);
        $reason = $this->reason($reason, __('Say why the expense is archived.'));

        return DB::transaction(function () use ($by, $expense, $reason): Expense {
            $expense = $this->locked($expense);

            if ($expense->isArchived()) {
                throw ValidationException::withMessages(['reason' => __('This expense is archived already.')]);
            }

            Gate::forUser($by)->authorize('delete', $expense);
            $expense->archived_by_user_id = $by->id;
            $expense->archive_reason = $reason;
            $expense->save();
            $expense->delete();

            $this->audit('expense.archived', $expense, $by, 'Expense archived', ['amount_kobo' => $expense->amount_kobo->kobo, 'reason' => $reason]);

            return $expense;
        }, 3);
    }

    public function restore(User $by, Expense $expense): Expense
    {
        Gate::forUser($by)->authorize('restore', $expense);

        return DB::transaction(function () use ($by, $expense): Expense {
            $expense = $this->locked($expense);

            if (! $expense->isArchived()) {
                throw ValidationException::withMessages(['restore' => __('This expense is not archived.')]);
            }

            Gate::forUser($by)->authorize('restore', $expense);
            $expense->archived_by_user_id = null;
            $expense->archive_reason = null;
            $expense->restore();

            $this->audit('expense.restored', $expense, $by, 'Expense restored', ['amount_kobo' => $expense->amount_kobo->kobo]);

            return $expense;
        }, 3);
    }

    /**
     * Add receipt files to the expense (every file checked before the first
     * is stored).
     *
     * @param  list<UploadedFile>  $files
     * @return list<ExpenseReceipt>
     *
     * @throws ValidationException (key `receipts`)
     */
    public function attachReceipts(User $by, Expense $expense, array $files): array
    {
        Gate::forUser($by)->authorize('attachReceipt', $expense);

        if ($files === []) {
            throw ValidationException::withMessages(['receipts' => __('Choose the receipt: a photo, a scan or a PDF.')]);
        }

        return DB::transaction(function () use ($by, $expense, $files): array {
            $expense = $this->locked($expense);
            Gate::forUser($by)->authorize('attachReceipt', $expense);
            $kept = ExpenseReceipt::query()->where('expense_id', $expense->id)->whereNull('archived_at')->count();
            $this->checkedFiles($files, $kept);

            return array_map(fn (UploadedFile $file): ExpenseReceipt => $this->store($by, $expense, $file), $files);
        }, 3);
    }

    /**
     * "Remove" a receipt: archived with a reason; the file and its media
     * row stay (evidence).
     *
     * @throws ValidationException
     */
    public function archiveReceipt(User $by, ExpenseReceipt $receipt, string $reason): ExpenseReceipt
    {
        $expense = Expense::query()->withTrashed()->findOrFail($receipt->expense_id);
        Gate::forUser($by)->authorize('archiveReceipt', $expense);
        $reason = $this->reason($reason, __('Say why the receipt is archived.'));

        return DB::transaction(function () use ($by, $receipt, $expense, $reason): ExpenseReceipt {
            $receipt = ExpenseReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();

            if ($receipt->isArchived()) {
                throw ValidationException::withMessages(['reason' => __('This receipt is archived already.')]);
            }

            $receipt->archived_at = CarbonImmutable::now();
            $receipt->archived_by_user_id = $by->id;
            $receipt->archive_reason = $reason;
            $receipt->save();

            $this->audit('expense.receipt_archived', $expense, $by, 'Expense receipt archived', [
                'receipt_id' => $receipt->id,
                'media_id' => $receipt->media_id,
                'file_sha256' => $receipt->file_sha256,
                'reason' => $reason,
            ]);

            return $receipt;
        }, 3);
    }

    private function store(User $by, Expense $expense, UploadedFile $file): ExpenseReceipt
    {
        $media = $this->uploads->add($expense, Expense::COLLECTION, $file, null, ['expense_id' => $expense->id]);

        $receipt = new ExpenseReceipt;
        $receipt->forceFill([
            'expense_id' => $expense->id,
            'media_id' => $media->id,
            'file_sha256' => (string) $media->checksum(),
            'uploaded_by_user_id' => $by->id,
            'uploaded_at' => CarbonImmutable::now(),
        ])->save();

        $this->audit('expense.receipt_added', $expense, $by, 'Expense receipt added', [
            'receipt_id' => $receipt->id,
            'media_id' => $media->id,
            'file_sha256' => $receipt->file_sha256,
        ]);

        return $receipt;
    }

    /**
     * Every file checked before anything is stored, and the limits.
     *
     * @param  list<UploadedFile>  $files
     *
     * @throws ValidationException (key `receipts`)
     */
    private function checkedFiles(array $files, int $kept): void
    {
        if (count($files) > self::FILES_PER_UPLOAD) {
            throw ValidationException::withMessages(['receipts' => __('Add at most :count files at a time.', ['count' => self::FILES_PER_UPLOAD])]);
        }

        if ($kept + count($files) > self::MAX_RECEIPTS) {
            throw ValidationException::withMessages(['receipts' => __('An expense keeps at most :count receipt files. Archive one it does not need first.', ['count' => self::MAX_RECEIPTS])]);
        }

        foreach ($files as $file) {
            try {
                $this->validator->check(Expense::COLLECTION, $file);
            } catch (UploadRefused $refused) {
                throw ValidationException::withMessages(['receipts' => count($files) > 1
                    ? MediaUploads::label($file->getClientOriginalName()).': '.$refused->getMessage()
                    : $refused->getMessage()]);
            }
        }
    }

    /**
     * The building and unit the expense is for, as ids and names, checked
     * for the member (see the class comment).
     *
     * @return array{property_id: int|null, property_name: string|null, unit_id: int|null, unit_label: string|null}
     *
     * @throws ValidationException
     */
    private function place(User $by, ?Expense $existing, ?int $propertyId, ?int $unitId): array
    {
        if ($propertyId === null) {
            if ($unitId !== null) {
                throw ValidationException::withMessages(['unitId' => __('Choose the building first.')]);
            }

            Gate::forUser($by)->authorize('createUnlinked', Expense::class);

            return ['property_id' => null, 'property_name' => null, 'unit_id' => null, 'unit_label' => null];
        }

        $kept = $existing !== null && $existing->property_id === $propertyId;
        $building = $this->buildings->find($propertyId);

        if ($building === null && ! $kept) {
            throw ValidationException::withMessages(['propertyId' => __('Choose one of your buildings.')]);
        }

        if (! $this->access->allows($by, $propertyId)) {
            Response::denyAsNotFound()->authorize();
        }

        if ($building !== null && $building->archived && ! $kept) {
            throw ValidationException::withMessages(['propertyId' => __(':name is archived: choose an active building.', ['name' => $building->name])]);
        }

        $place = [
            'property_id' => $propertyId,
            'property_name' => $building !== null ? $building->name : $existing->property_name,
            'unit_id' => null,
            'unit_label' => null,
        ];

        if ($unitId === null) {
            return $place;
        }

        $unit = $this->units->find($unitId);

        if ($unit !== null && $unit->propertyId === $propertyId) {
            return ['unit_id' => $unit->id, 'unit_label' => $unit->label] + $place;
        }

        if ($kept && $existing->unit_id === $unitId) {
            // An archived unit, or a building no longer here: kept as recorded.
            return ['unit_id' => $unitId, 'unit_label' => $existing->unit_label] + $place;
        }

        throw ValidationException::withMessages(['unitId' => __('Choose a unit of this building, or none.')]);
    }

    /**
     * @param  array{propertyId: int|null, unitId: int|null, category: ExpenseCategory, description: string, amount: Money, spentOn: CarbonImmutable, vendorName: string|null, paymentMethod: ExpensePaymentMethod, reference: string|null, note: string|null}  $values
     * @param  array{property_id: int|null, property_name: string|null, unit_id: int|null, unit_label: string|null}  $place
     */
    private function fill(Expense $expense, array $values, array $place): void
    {
        $expense->forceFill($place + [
            'category' => $values['category'],
            'description' => $values['description'],
            'amount_kobo' => $values['amount'],
            'spent_on' => $values['spentOn'],
            'vendor_name' => $values['vendorName'],
            'payment_method' => $values['paymentMethod'],
            'reference' => $values['reference'],
            'note' => $values['note'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{propertyId: int|null, unitId: int|null, category: ExpenseCategory, description: string, amount: Money, spentOn: CarbonImmutable, vendorName: string|null, paymentMethod: ExpensePaymentMethod, reference: string|null, note: string|null}
     *
     * @throws ValidationException
     */
    private function validated(array $input): array
    {
        $values = [
            'propertyId' => self::text($input['propertyId'] ?? null),
            'unitId' => self::text($input['unitId'] ?? null),
            'category' => self::text($input['category'] ?? null),
            'description' => self::text($input['description'] ?? null),
            'amount' => self::text($input['amount'] ?? null),
            'spentOn' => self::text($input['spentOn'] ?? null),
            'vendorName' => self::text($input['vendorName'] ?? null),
            'paymentMethod' => self::text($input['paymentMethod'] ?? null),
            'reference' => self::text($input['reference'] ?? null),
            'note' => self::text($input['note'] ?? null, multiline: true),
        ];
        $amount = $values['amount'] !== null ? ExpenseInput::amount($values['amount']) : null;
        $spentOn = ExpenseInput::date($values['spentOn']);
        $today = ExpenseInput::today();

        Validator::make($values, [
            'propertyId' => ['nullable', 'regex:/^\d{1,18}$/'],
            'unitId' => ['nullable', 'regex:/^\d{1,18}$/'],
            'category' => ['required', Rule::in(ExpenseCategory::values())],
            'description' => ['required', 'string', 'min:3', 'max:500'],
            'amount' => ['required', static function (string $attribute, mixed $value, \Closure $fail) use ($amount): void {
                if ($amount === null) {
                    $fail(__('Type the amount in naira, such as 25,000 or 25,000.50.'));
                } elseif (! $amount->isPositive()) {
                    $fail(__('The amount must be more than zero.'));
                } elseif ($amount->greaterThan(Money::naira(ExpenseInput::MAX_NAIRA))) {
                    $fail(__('That amount is too large for one expense.'));
                }
            }],
            'spentOn' => ['required', static function (string $attribute, mixed $value, \Closure $fail) use ($spentOn, $today): void {
                if ($spentOn === null) {
                    $fail(__('Choose the date it was paid.'));
                } elseif ($spentOn->greaterThan($today)) {
                    $fail(__('The date it was paid cannot be in the future.'));
                } elseif ($spentOn->year < 2000) {
                    $fail(__('Choose a date from 2000 on.'));
                }
            }],
            'vendorName' => ['nullable', 'string', 'max:150'],
            'paymentMethod' => ['required', Rule::in(ExpensePaymentMethod::values())],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'propertyId.regex' => __('Choose one of your buildings.'),
            'unitId.regex' => __('Choose a unit of this building, or none.'),
            'category.required' => __('Choose what the money was spent on.'),
            'category.in' => __('Choose what the money was spent on.'),
            'description.required' => __('Say what was paid for.'),
            'amount.required' => __('Type the amount paid.'),
            'spentOn.required' => __('Choose the date it was paid.'),
            'paymentMethod.required' => __('Choose how it was paid.'),
            'paymentMethod.in' => __('Choose how it was paid.'),
        ], [
            'description' => __('description'),
            'vendorName' => __('paid to'),
            'reference' => __('reference'),
            'note' => __('note'),
        ])->validate();

        assert($amount instanceof Money && $spentOn instanceof CarbonImmutable);

        return [
            'propertyId' => $values['propertyId'] !== null ? (int) $values['propertyId'] : null,
            'unitId' => $values['unitId'] !== null ? (int) $values['unitId'] : null,
            'category' => ExpenseCategory::from((string) $values['category']),
            'description' => (string) $values['description'],
            'amount' => $amount,
            'spentOn' => $spentOn,
            'vendorName' => $values['vendorName'],
            'paymentMethod' => ExpensePaymentMethod::from((string) $values['paymentMethod']),
            'reference' => $values['reference'],
            'note' => $values['note'],
        ];
    }

    /** @throws ValidationException */
    private function reason(string $reason, string $required): string
    {
        $reason = (string) self::text($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:500']], ['reason.required' => $required])->validate();

        return $reason;
    }

    private function locked(Expense $expense): Expense
    {
        return Expense::query()->withTrashed()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
    }

    /** Trimmed text with inner spaces collapsed (lines kept for a note), or null when empty. */
    private static function text(mixed $value, bool $multiline = false): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) preg_replace($multiline ? '/[^\S\n]+/u' : '/\s+/u', ' ', str_replace("\r\n", "\n", (string) $value)));

        return $value === '' ? null : $value;
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(string $event, Expense $expense, User $by, string $description, array $properties): void
    {
        activity(self::LOG)
            ->event($event)
            ->performedOn($expense)
            ->causedBy($by)
            ->withProperties($properties)
            ->log($description);
    }
}
