<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ComplianceDocumentStatus;
use App\Enums\ComplianceDocumentType;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A document uploaded for a Transaction Tier application
 * (ONBOARDING_COMPLIANCE.md § 5.3, DATABASE_SCHEMA.md § 9). Organization-
 * scoped. The file itself is encrypted on the private compliance disk
 * (App\Compliance\ComplianceFiles); only compliance staff open it, through
 * the console's signed and audited route. Document numbers, holder names
 * and the original file name are encrypted. Removed uploads are soft
 * deleted (their file is kept).
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $tier_application_id
 * @property ComplianceDocumentType $type
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size_bytes
 * @property string $sha256
 * @property string|null $document_number
 * @property string|null $holder_name
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $expires_at
 * @property ComplianceDocumentStatus $status
 * @property int|null $uploaded_by_user_id
 * @property int|null $reviewed_by_staff_id
 * @property string|null $review_note
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable|null $expiring_notified_at
 * @property CarbonImmutable|null $expired_notified_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Hidden(['disk', 'path', 'document_number', 'holder_name', 'original_name'])]
final class ComplianceDocument extends Model
{
    use BelongsToOrganization, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['tier_application_id', 'type', 'disk', 'path', 'original_name', 'mime', 'size_bytes', 'sha256', 'document_number', 'holder_name', 'issued_at', 'expires_at', 'uploaded_by_user_id'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ComplianceDocumentType::class,
            'status' => ComplianceDocumentStatus::class,
            'original_name' => 'encrypted',
            'document_number' => 'encrypted',
            'holder_name' => 'encrypted',
            'size_bytes' => 'integer',
            'issued_at' => 'date',
            'expires_at' => 'date',
            'reviewed_at' => 'datetime',
            'expiring_notified_at' => 'datetime',
            'expired_notified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TierApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(TierApplication::class, 'tier_application_id');
    }

    /** Days until the expiry date (negative once past), or null without one. */
    public function daysUntilExpiry(): ?int
    {
        return $this->expires_at === null ? null : (int) now()->startOfDay()->diffInDays($this->expires_at->startOfDay(), false);
    }
}
