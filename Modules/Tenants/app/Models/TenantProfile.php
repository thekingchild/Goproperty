<?php

declare(strict_types=1);

namespace Modules\Tenants\Models;

use App\Models\User;
use App\Support\PhoneNumber;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;
use Modules\Tenants\Database\Factories\TenantProfileFactory;
use Modules\Tenants\Enums\KycStatus;
use Modules\Tenants\Enums\TenantIdType;

/**
 * A tenant (renter) of the organization (DATABASE_SCHEMA.md § 1; "tenant"
 * in code always means a renter, AGENT.md § 1), distinct from a user: a
 * tenant may not have portal access yet. Organization-scoped. The phone
 * number is how a tenant is recognised (unique per organization, archived
 * tenants included) and is always kept as E.164 through
 * App\Support\PhoneNumber, whoever writes it. The ID number is an encrypted
 * cast, hidden from arrays; screens show it masked (maskedId()). Archived
 * tenants are soft deleted (AGENT.md § 3 rule 7). `import_batch_id` and
 * `external_ref` are import provenance (§ 8A), set only by the importer
 * (Import\TenantsImportTarget).
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $user_id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $email lower case
 * @property string $phone E.164
 * @property string|null $address
 * @property string|null $occupation
 * @property array{name?: string|null, phone?: string|null, relationship?: string|null}|null $emergency_contact
 * @property KycStatus $kyc_status
 * @property TenantIdType $id_type
 * @property string|null $id_value decrypted
 * @property int|null $import_batch_id
 * @property string|null $external_ref
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Hidden(['id_value'])]
final class TenantProfile extends Model
{
    /** @use HasFactory<TenantProfileFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['first_name', 'last_name', 'email', 'phone', 'address', 'occupation', 'emergency_contact', 'id_type', 'id_value'];

    /** @var array<string, mixed> */
    protected $attributes = ['kyc_status' => 'unverified', 'id_type' => 'none'];

    protected static function booted(): void
    {
        self::saving(static function (self $tenant): void {
            // One form for every writer, so matching by phone works (UX_DESIGN.md § 3.1).
            $tenant->phone = PhoneNumber::normalize($tenant->phone)
                ?? throw new InvalidArgumentException('A tenant needs a valid phone number.');

            if ($tenant->email !== null) {
                $tenant->email = mb_strtolower(trim($tenant->email));
            }
        });
    }

    /** "First Last". */
    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /** "NIN ···7890", or null when no number is on file. */
    public function maskedId(): ?string
    {
        if ($this->id_type === TenantIdType::None || $this->id_value === null || $this->id_value === '') {
            return null;
        }

        return $this->id_type->label().' ···'.substr($this->id_value, -4);
    }

    /**
     * The tenant's portal account, once they have one (P03-01b).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): TenantProfileFactory
    {
        return TenantProfileFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'emergency_contact' => 'array',
            'kyc_status' => KycStatus::class,
            'id_type' => TenantIdType::class,
            'id_value' => 'encrypted',
        ];
    }
}
