<?php

declare(strict_types=1);

namespace Modules\Expenses\Models;

use App\Casts\MoneyCast;
use App\Media\HasOrganizationMedia;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use Modules\Expenses\Enums\ExpenseCategory;
use Modules\Expenses\Enums\ExpensePaymentMethod;
use Spatie\MediaLibrary\HasMedia;

/**
 * Money the organization spent (P03-12; DATABASE_SCHEMA.md § 4
 * `expenses`). Organization-scoped. Written only by
 * Modules\Expenses\Recording\ExpenseBook (nothing here is mass
 * assignable), which checks ExpensePolicy and audits every change.
 *
 * The building and unit belong to the Properties module: the expense keeps
 * their ids, and the names as recorded, and reads them through
 * Modules\Properties\Contracts (ARCHITECTURE.md § 8 rule 3). Its receipt
 * files are media of this record in the `expense_receipts` collection
 * (evidence), each with an ExpenseReceipt row.
 *
 * Archiving is a soft delete with who and why; an expense is never
 * deleted for good (AGENT.md § 3 rule 7): forceDelete() throws.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $property_id
 * @property int|null $unit_id
 * @property string|null $property_name
 * @property string|null $unit_label
 * @property ExpenseCategory $category
 * @property string $description
 * @property Money $amount_kobo
 * @property CarbonImmutable $spent_on
 * @property string|null $vendor_name
 * @property ExpensePaymentMethod $payment_method
 * @property string|null $reference
 * @property bool $charged_to_owner deducted from what the building's owner is due (P08-05)
 * @property string|null $note
 * @property int|null $ticket_id
 * @property int|null $bill_id
 * @property int|null $recorded_by_user_id
 * @property int|null $updated_by_user_id
 * @property int|null $archived_by_user_id
 * @property string|null $archive_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class Expense extends Model implements HasMedia
{
    use BelongsToOrganization, HasOrganizationMedia, SoftDeletes;

    public const string COLLECTION = 'expense_receipts';

    protected static function booted(): void
    {
        self::forceDeleting(static fn (): never => throw new LogicException('An expense is never deleted for good; archive it instead.'));
    }

    /** @return HasMany<ExpenseReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(ExpenseReceipt::class);
    }

    public function isArchived(): bool
    {
        return $this->deleted_at !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'property_id' => 'integer',
            'unit_id' => 'integer',
            'category' => ExpenseCategory::class,
            'amount_kobo' => MoneyCast::class,
            'spent_on' => 'immutable_date',
            'payment_method' => ExpensePaymentMethod::class,
            'charged_to_owner' => 'boolean',
            'ticket_id' => 'integer',
            'bill_id' => 'integer',
        ];
    }
}
