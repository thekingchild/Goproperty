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
use Modules\Payments\Enums\ReceiptStatus;
use Spatie\MediaLibrary\HasMedia;

/**
 * A receipt as issued (P03-10b; DATABASE_SCHEMA.md § 4 `receipts`,
 * BRANDING_DOCUMENTS.md § 3): one per confirmed payment, numbered per
 * organization, with everything the printed receipt shows that could change
 * afterwards — the branding snapshot and the parties, payment and balances
 * (`details`) — as they were at issue, and its verification code. Written by
 * Modules\Payments\Receipts\Receipts (issue) and ReceiptVoids (the void
 * request and its approval); its PDF is rendered once into this row's
 * `issued_documents` media (key `receipt`), and a voided receipt's VOID copy
 * is a second file (key `receipt-void`): the original is evidence and stays.
 *
 * History (AGENT.md § 3 rule 7): never deleted, and what was issued never
 * changes. Only the PDFs' media ids are filled in (once each), a void may be
 * asked for and the request dismissed while the receipt is valid, and the
 * void itself is recorded once; after that only the VOID copy's media id may
 * still be filled in.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $payment_id
 * @property int $tenant_profile_id
 * @property string $number
 * @property string $verification_code
 * @property Money $amount_kobo
 * @property CarbonImmutable $issued_at
 * @property int|null $issued_by_user_id
 * @property array<array-key, mixed> $branding_snapshot
 * @property array<array-key, mixed> $details
 * @property int|null $document_media_id
 * @property CarbonImmutable|null $void_requested_at
 * @property int|null $void_requested_by_user_id
 * @property string|null $void_request_reason
 * @property CarbonImmutable|null $voided_at
 * @property int|null $voided_by_user_id
 * @property int|null $void_approved_by_user_id
 * @property string|null $void_reason
 * @property bool $void_single_approver
 * @property int|null $void_document_media_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Receipt extends Model implements HasMedia
{
    use BelongsToOrganization, HasOrganizationMedia;

    /** Columns of a valid receipt that may change: the PDF's id (once) and the void request. */
    private const array CHANGEABLE_WHILE_VALID = [
        'document_media_id', 'void_requested_at', 'void_requested_by_user_id', 'void_request_reason',
        'voided_at', 'voided_by_user_id', 'void_approved_by_user_id', 'void_reason', 'void_single_approver', 'updated_at',
    ];

    /** Once voided, only the media ids may still be filled in (once each). */
    private const array CHANGEABLE_ONCE_VOIDED = ['document_media_id', 'void_document_media_id', 'updated_at'];

    /** @var array<string, mixed> */
    protected $attributes = ['void_single_approver' => false];

    protected static function booted(): void
    {
        self::updating(static function (self $receipt): void {
            $dirty = array_keys($receipt->getDirty());
            $allowed = $receipt->getRawOriginal('voided_at') === null ? self::CHANGEABLE_WHILE_VALID : self::CHANGEABLE_ONCE_VOIDED;

            if (array_diff($dirty, $allowed) !== []) {
                throw new LogicException('An issued receipt is never changed; void it instead.');
            }

            foreach (['document_media_id', 'void_document_media_id'] as $column) {
                if (in_array($column, $dirty, true) && $receipt->getRawOriginal($column) !== null) {
                    throw new LogicException('An issued receipt keeps the PDF it was first printed as.');
                }
            }

            if (in_array('void_document_media_id', $dirty, true) && $receipt->voided_at === null) {
                throw new LogicException('Only a voided receipt has a VOID copy.');
            }
        });
        self::deleting(static fn (): never => throw new LogicException('Receipts are never deleted; void them instead.'));
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class)->withTrashed();
    }

    public function status(): ReceiptStatus
    {
        return match (true) {
            $this->voided_at !== null => ReceiptStatus::Voided,
            $this->void_requested_at !== null => ReceiptStatus::VoidRequested,
            default => ReceiptStatus::Valid,
        };
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function voidPending(): bool
    {
        return $this->voided_at === null && $this->void_requested_at !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_kobo' => MoneyCast::class,
            'issued_at' => 'immutable_datetime',
            'branding_snapshot' => 'array',
            'details' => 'array',
            'document_media_id' => 'integer',
            'void_requested_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'void_single_approver' => 'boolean',
            'void_document_media_id' => 'integer',
        ];
    }
}
