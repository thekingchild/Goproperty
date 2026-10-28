<?php

declare(strict_types=1);

namespace Modules\Expenses\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A receipt file kept for an expense (P03-12; DATABASE_SCHEMA.md § 4
 * `expense_receipts`): the media row (the expense's `expense_receipts`
 * collection) and who uploaded it. Organization-scoped. Written only by
 * Modules\Expenses\Recording\ExpenseBook.
 *
 * Evidence (AGENT.md § 3 rule 7, ARCHITECTURE.md § 9): never deleted, and
 * which file it is never changes; "remove" archives it once (who, when,
 * why) and the file stays.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $expense_id
 * @property int $media_id
 * @property string $file_sha256
 * @property int|null $uploaded_by_user_id
 * @property CarbonImmutable $uploaded_at
 * @property CarbonImmutable|null $archived_at
 * @property int|null $archived_by_user_id
 * @property string|null $archive_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ExpenseReceipt extends Model
{
    use BelongsToOrganization;

    /** What may change once uploaded: the archive (once). */
    private const array CHANGEABLE = ['archived_at', 'archived_by_user_id', 'archive_reason', 'updated_at'];

    protected static function booted(): void
    {
        self::updating(static function (self $receipt): void {
            if (array_diff(array_keys($receipt->getDirty()), self::CHANGEABLE) !== []) {
                throw new LogicException('An expense receipt is evidence: its file never changes.');
            }

            if ($receipt->getRawOriginal('archived_at') !== null) {
                throw new LogicException('This expense receipt is archived already.');
            }
        });
        self::deleting(static fn (): never => throw new LogicException('An expense receipt is evidence and is never deleted; archive it instead.'));
    }

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class)->withTrashed();
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expense_id' => 'integer',
            'media_id' => 'integer',
            'uploaded_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }
}
