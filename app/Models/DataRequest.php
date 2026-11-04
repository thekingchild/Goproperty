<?php

declare(strict_types=1);

namespace App\Models;

use App\Privacy\DataRequestKind;
use App\Privacy\DataRequestScope;
use App\Privacy\DataRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A data protection request (P09-02; NDPA 2023 ss. 34–36): an export of
 * one tenant's or a whole organization's data, or an erasure (a tenant
 * anonymised, an organization purged). Platform-level, like
 * impersonation_sessions: the workspace reads its own organization's
 * requests by `organization_id`. Written only by App\Privacy\DataRequests
 * and its jobs; never deleted (it is the record that the request was
 * carried out) and holding no personal data.
 *
 * @property int $id
 * @property DataRequestKind $kind
 * @property DataRequestScope $scope
 * @property int $organization_id
 * @property int|null $tenant_profile_id
 * @property DataRequestStatus $status
 * @property string $reason
 * @property int|null $requested_by_staff_id
 * @property int|null $requested_by_user_id
 * @property int|null $approval_request_id
 * @property Carbon|null $due_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property string|null $failure
 * @property array<string, mixed>|null $progress
 * @property array<string, mixed>|null $result
 * @property string|null $file_path
 * @property int|null $file_size
 * @property string|null $file_sha256
 * @property Carbon|null $file_expires_at
 * @property Carbon|null $file_deleted_at
 * @property int $downloads
 * @property Carbon|null $last_downloaded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization|null $organization
 * @property-read PlatformStaff|null $requestedByStaff
 * @property-read User|null $requestedByUser
 */
class DataRequest extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('Data requests are kept as the record of what was done; they are never deleted.'));
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        // A purged organization stays as a soft-deleted row.
        return $this->belongsTo(Organization::class)->withTrashed();
    }

    /** @return BelongsTo<PlatformStaff, $this> */
    public function requestedByStaff(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'requested_by_staff_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** An export whose file can be downloaded now. */
    public function isDownloadable(): bool
    {
        return $this->kind === DataRequestKind::Export
            && $this->status === DataRequestStatus::Ready
            && $this->file_path !== null
            && $this->file_deleted_at === null
            && ($this->file_expires_at === null || $this->file_expires_at->isFuture());
    }

    /** "Export · one tenant (#12)". */
    public function summary(): string
    {
        $subject = $this->scope === DataRequestScope::Tenant ? "tenant #{$this->tenant_profile_id}" : 'whole organization';

        return "{$this->kind->label()} · {$subject} of organization #{$this->organization_id}";
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => DataRequestKind::class,
            'scope' => DataRequestScope::class,
            'status' => DataRequestStatus::class,
            'due_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'progress' => 'array',
            'result' => 'array',
            'file_expires_at' => 'datetime',
            'file_deleted_at' => 'datetime',
            'last_downloaded_at' => 'datetime',
        ];
    }
}
