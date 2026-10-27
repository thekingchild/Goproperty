<?php

declare(strict_types=1);

namespace Modules\Leasing\Models;

use App\Media\HasOrganizationMedia;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Spatie\MediaLibrary\HasMedia;

/**
 * A lease contract as issued (P03-03b; DATABASE_SCHEMA.md § 3
 * `lease_contracts`, BRANDING_DOCUMENTS.md § 4–5): everything the printed
 * contract shows that could change after the lease was activated — the
 * branding snapshot, the parties and the rent schedule (`details`) and the
 * agency's clause template — kept
 * as it was at issue, with the contract's verification code. Written once
 * by Modules\Leasing\Documents\LeaseContracts; its PDF is rendered once
 * (App\Documents\DocumentRenderer::stored(), key `lease-contract`) into
 * this row's `issued_documents` media, an evidence file.
 *
 * History: never changed (only `document_media_id` is filled in once the
 * PDF exists) and never deleted (AGENT.md § 3 rule 7).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $lease_id
 * @property string $contract_number
 * @property string $verification_code
 * @property array<string, mixed> $branding_snapshot
 * @property array<string, mixed> $details
 * @property string $clauses
 * @property CarbonImmutable $issued_at
 * @property int|null $document_media_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class LeaseContract extends Model implements HasMedia
{
    use BelongsToOrganization, HasOrganizationMedia;

    protected static function booted(): void
    {
        self::updating(static function (self $contract): void {
            // The PDF's media id is recorded once it exists; nothing else changes.
            if (array_diff(array_keys($contract->getDirty()), ['document_media_id', 'updated_at']) !== []) {
                throw new LogicException('An issued lease contract is never changed.');
            }

            if ($contract->getRawOriginal('document_media_id') !== null) {
                throw new LogicException('An issued lease contract keeps the PDF it was first printed as.');
            }
        });
        self::deleting(static fn (): never => throw new LogicException('Issued lease contracts are never deleted.'));
    }

    /** @return BelongsTo<Lease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class)->withTrashed();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'branding_snapshot' => 'array',
            'details' => 'array',
            'issued_at' => 'immutable_datetime',
            'document_media_id' => 'integer',
        ];
    }
}
