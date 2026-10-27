<?php

declare(strict_types=1);

namespace App\Platform\Compliance;

use App\Enums\ComplianceDocumentStatus;
use App\Enums\ComplianceDocumentType;
use App\Models\ComplianceDocument;
use App\Models\Organization;
use App\Platform\Customers\Customer;
use App\Policies\ReviewDocumentPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * The console's read model of a compliance document, across organizations.
 * Read-only; its file opens only through the signed, audited route
 * (DocumentLinks, ComplianceDocumentController) for staff holding
 * `compliance.view_documents`. Audit entries name it as the document itself.
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
 * @property string|null $review_note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Customer $customer
 * @property-read ReviewApplication|null $application
 */
#[Hidden(['disk', 'path', 'document_number', 'holder_name', 'original_name'])]
#[UsePolicy(ReviewDocumentPolicy::class)]
class ReviewDocument extends Model
{
    use SoftDeletes;

    protected $table = 'compliance_documents';

    protected static function booted(): void
    {
        static::saving(fn (): never => throw new LogicException('ReviewDocument is a read model: review through App\Platform\Compliance\TierReview.'));
        static::deleting(fn (): never => throw new LogicException('Compliance documents are never deleted from the console.'));
    }

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
        ];
    }

    public function getMorphClass(): string
    {
        return (new ComplianceDocument)->getMorphClass();
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'organization_id');
    }

    /** @return BelongsTo<ReviewApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(ReviewApplication::class, 'tier_application_id');
    }

    public function organization(): Organization
    {
        return Organization::query()->whereKey($this->organization_id)->firstOrFail();
    }
}
