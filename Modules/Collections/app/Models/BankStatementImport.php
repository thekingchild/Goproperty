<?php

declare(strict_types=1);

namespace Modules\Collections\Models;

use App\Media\HasOrganizationMedia;
use App\Media\Media;
use App\Models\OrganizationBankAccount;
use App\Models\User;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Modules\Collections\Enums\StatementFormat;
use Modules\Collections\Enums\StatementStatus;
use Modules\Collections\Statements\StatementMapping;
use Spatie\MediaLibrary\HasMedia;

/**
 * One uploaded bank statement of one of the organization's bank accounts
 * (DATABASE_SCHEMA.md § 4 `bank_statement_imports`, ONBOARDING_COMPLIANCE.md
 * § 4.1). Organization-scoped. The file is the import's media in the
 * `statements` collection: evidence, never deleted by a customer action
 * (AGENT.md § 3 rule 7). Written only by Statements\StatementImports and
 * Statements\StatementReader (the processing job); nothing is mass
 * assignable, and an uploaded statement is never deleted (the model
 * refuses), since its lines may become payments.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $organization_bank_account_id
 * @property int|null $media_id
 * @property string $file_name the uploader's file name, a label only
 * @property StatementFormat $format
 * @property array<string, mixed>|null $column_mapping StatementMapping::toArray()
 * @property CarbonImmutable|null $period_start the first transaction date read
 * @property CarbonImmutable|null $period_end the last transaction date read
 * @property int $lines_total transaction rows read
 * @property int $lines_added new lines (credits and debits)
 * @property int $lines_credit credits among the new lines
 * @property int $lines_debit debits among the new lines (kept, never matched)
 * @property int $lines_duplicate rows an earlier statement had already brought in
 * @property int $lines_unreadable rows with a problem (the first ones in `report`)
 * @property int $lines_matched lines matched to bills (P03-07b)
 * @property array<string, mixed>|null $report {unreadable: list<{row, reason}> (the first ones), not_transactions: int}
 * @property StatementStatus $status
 * @property string|null $failure_reason
 * @property int|null $uploaded_by_user_id
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read OrganizationBankAccount|null $bankAccount
 * @property-read User|null $uploadedBy
 */
final class BankStatementImport extends Model implements HasMedia
{
    use BelongsToOrganization, HasOrganizationMedia;

    /** The media collection of uploaded statements (config/media.php: evidence). */
    public const string COLLECTION = 'statements';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'processing',
        'lines_total' => 0,
        'lines_added' => 0,
        'lines_credit' => 0,
        'lines_debit' => 0,
        'lines_duplicate' => 0,
        'lines_unreadable' => 0,
        'lines_matched' => 0,
    ];

    protected static function boot(): void
    {
        // Before the media library's own `deleting` listener (registered
        // while the traits boot), so the refusal is this model's.
        self::deleting(static function (): void {
            throw new LogicException('An uploaded bank statement is never deleted: it is evidence, and its lines may become payments.');
        });

        parent::boot();
    }

    /**
     * The bank account, also once it has been removed from the settings.
     *
     * @return BelongsTo<OrganizationBankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(OrganizationBankAccount::class, 'organization_bank_account_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** @return HasMany<BankStatementLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    /** The uploaded file (evidence, kept). */
    public function file(): ?Media
    {
        $media = $this->getFirstMedia(self::COLLECTION);

        return $media instanceof Media ? $media : null;
    }

    public function mapping(): ?StatementMapping
    {
        return is_array($this->column_mapping) ? StatementMapping::fromArray($this->column_mapping) : null;
    }

    /**
     * The unreadable rows the report keeps (the first ones), with the reason.
     *
     * @return list<array{row: int, reason: string}>
     */
    public function unreadableRows(): array
    {
        $rows = $this->report['unreadable'] ?? [];

        return array_values(array_filter(array_map(
            static fn (mixed $row): ?array => is_array($row) && is_int($row['row'] ?? null) && is_string($row['reason'] ?? null) ? ['row' => $row['row'], 'reason' => $row['reason']] : null,
            is_array($rows) ? $rows : [],
        )));
    }

    /** "GTBank ···6789", also for a removed account. */
    public function accountLabel(): string
    {
        $account = $this->bankAccount;

        return $account instanceof OrganizationBankAccount ? $account->bank_name.' '.$account->masked() : __('Removed account');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'format' => StatementFormat::class,
            'status' => StatementStatus::class,
            'column_mapping' => 'array',
            'report' => 'array',
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'processed_at' => 'immutable_datetime',
            'lines_total' => 'integer',
            'lines_added' => 'integer',
            'lines_credit' => 'integer',
            'lines_debit' => 'integer',
            'lines_duplicate' => 'integer',
            'lines_unreadable' => 'integer',
            'lines_matched' => 'integer',
        ];
    }
}
