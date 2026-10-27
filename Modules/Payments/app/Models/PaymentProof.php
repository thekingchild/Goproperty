<?php

declare(strict_types=1);

namespace Modules\Payments\Models;

use App\Casts\MoneyCast;
use App\Media\HasOrganizationMedia;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Payments\Contracts\PaymentProofs;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Enums\ProofStatus;
use Modules\Payments\Enums\ProofSubmitter;
use Spatie\MediaLibrary\HasMedia;

/**
 * A proof of payment (P03-09; DATABASE_SCHEMA.md § 4 `payment_proofs`,
 * ONBOARDING_COMPLIANCE.md § 4.1): what the tenant says they paid, with the
 * file in this record's `payment_proofs` media collection (evidence).
 * Organization-scoped. Written only by Proofs\ProofSubmissions; each proof
 * records one payment waiting for confirmation (source `proof_upload`),
 * reviewed by confirming or rejecting that payment (PaymentService).
 *
 * Evidence (AGENT.md § 3 rule 7, ARCHITECTURE.md § 9): never deleted, and
 * what was submitted never changes. Only the file's media id may be filled
 * in once, and the proof archived once ("remove" archives; the file stays).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $payment_id
 * @property int $tenant_profile_id
 * @property int|null $bill_id
 * @property Money $amount_kobo
 * @property CarbonImmutable $paid_on
 * @property string $sender_name
 * @property string $bank_name
 * @property string|null $reference
 * @property string|null $note
 * @property int|null $media_id
 * @property string $file_sha256
 * @property string|null $first_upload_sha256
 * @property int|null $duplicate_of_proof_id
 * @property ProofSubmitter $submitted_by
 * @property int|null $submitted_by_user_id
 * @property CarbonImmutable $submitted_at
 * @property CarbonImmutable|null $archived_at
 * @property int|null $archived_by_user_id
 * @property string|null $archive_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PaymentProof extends Model implements HasMedia
{
    use BelongsToOrganization, HasOrganizationMedia;

    public const string COLLECTION = PaymentProofs::COLLECTION;

    /** What may change once submitted: the file's id (once) and the archive (once). */
    private const array CHANGEABLE = ['media_id', 'archived_at', 'archived_by_user_id', 'archive_reason', 'updated_at'];

    protected static function booted(): void
    {
        self::updating(static function (self $proof): void {
            $dirty = array_keys($proof->getDirty());

            if (array_diff($dirty, self::CHANGEABLE) !== []) {
                throw new LogicException('A proof of payment is evidence: what was submitted never changes.');
            }

            if (in_array('media_id', $dirty, true) && $proof->getRawOriginal('media_id') !== null) {
                throw new LogicException('A proof of payment keeps the file it was submitted with.');
            }

            if (array_intersect($dirty, ['archived_at', 'archived_by_user_id', 'archive_reason']) !== [] && $proof->getRawOriginal('archived_at') !== null) {
                throw new LogicException('This proof of payment is archived already.');
            }
        });
        self::deleting(static fn (): never => throw new LogicException('A proof of payment is evidence and is never deleted; archive it instead.'));
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class)->withTrashed();
    }

    /** The review (the payment's status), or archived. */
    public function status(?Payment $payment = null): ProofStatus
    {
        if ($this->archived_at !== null) {
            return ProofStatus::Archived;
        }

        $status = ($payment ?? $this->payment)?->status;

        return match ($status) {
            PaymentStatus::Confirmed, PaymentStatus::Refunded => ProofStatus::Accepted,
            PaymentStatus::Rejected => ProofStatus::Rejected,
            default => ProofStatus::Waiting,
        };
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_kobo' => MoneyCast::class,
            'paid_on' => 'immutable_date',
            'media_id' => 'integer',
            'duplicate_of_proof_id' => 'integer',
            'submitted_by' => ProofSubmitter::class,
            'submitted_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }
}
