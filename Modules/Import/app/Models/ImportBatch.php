<?php

declare(strict_types=1);

namespace Modules\Import\Models;

use App\Media\HasOrganizationMedia;
use App\Media\Media;
use App\Models\User;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Import\Enums\ImportFileType;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Mapping\SheetMapping;
use Spatie\MediaLibrary\HasMedia;

/**
 * One uploaded file and what became of it (DATABASE_SCHEMA.md § 8A,
 * DATA_IMPORT.md § 5). Organization-scoped. The file is the batch's media
 * in the `imports` collection until the 30-day purge. Written only by
 * Modules\Import\Batches\ImportBatches and the commit job.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $kind `workbook` or a target key
 * @property ImportFileType $file_type
 * @property string $file_name the uploader's file name, a label only
 * @property int|null $media_id
 * @property array<string, mixed>|null $column_mapping {sheets: list<SheetMapping as array>}
 * @property ImportStatus $status
 * @property array<string, mixed>|null $dry_run_report
 * @property CarbonImmutable|null $dry_run_at
 * @property int $rows_total
 * @property int $rows_done
 * @property array{target: string, row: int}|null $commit_cursor
 * @property array{targets?: array<string, array<string, int>>, skipped?: list<array{target: string, row: int, reason: string}>}|null $result_counts per target, action => count; the first skipped rows with the reason
 * @property string|null $failure_reason
 * @property int|null $created_by_user_id
 * @property int|null $committed_by_user_id
 * @property CarbonImmutable|null $committed_at
 * @property int|null $rolled_back_by_user_id
 * @property CarbonImmutable|null $rolled_back_at
 * @property CarbonImmutable|null $file_purged_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ImportBatch extends Model implements HasMedia
{
    use BelongsToOrganization, HasOrganizationMedia;

    public const string COLLECTION = 'imports';

    public const string WORKBOOK = 'workbook';

    /** @var list<string> */
    protected $fillable = ['kind', 'file_type', 'file_name', 'status', 'created_by_user_id'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'uploaded', 'rows_total' => 0, 'rows_done' => 0];

    /** @return list<SheetMapping> */
    public function sheets(): array
    {
        $sheets = $this->column_mapping['sheets'] ?? [];

        return array_values(array_map(
            static fn (mixed $sheet): SheetMapping => SheetMapping::fromArray(is_array($sheet) ? $sheet : []),
            is_array($sheets) ? $sheets : [],
        ));
    }

    /** @param  list<SheetMapping>  $sheets */
    public function setSheets(array $sheets): void
    {
        $this->column_mapping = ['sheets' => array_map(static fn (SheetMapping $sheet): array => $sheet->toArray(), $sheets)];
    }

    /** The uploaded file, until it is purged. */
    public function file(): ?Media
    {
        $media = $this->media_id !== null ? Media::query()->whereKey($this->media_id)->first() : null;

        return $media instanceof Media ? $media : null;
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function committedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by_user_id');
    }

    /** The end of the rollback window, for a committed batch. */
    public function rollbackEndsAt(): ?CarbonImmutable
    {
        $days = config('import.rollback_days', 7);

        return $this->committed_at?->addDays(is_int($days) ? $days : 7);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'file_type' => ImportFileType::class,
            'status' => ImportStatus::class,
            'column_mapping' => 'array',
            'dry_run_report' => 'array',
            'commit_cursor' => 'array',
            'result_counts' => 'array',
            'dry_run_at' => 'datetime',
            'committed_at' => 'datetime',
            'rolled_back_at' => 'datetime',
            'file_purged_at' => 'datetime',
        ];
    }
}
