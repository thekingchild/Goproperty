<?php

declare(strict_types=1);

namespace Modules\Properties\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Properties\Database\Factories\OwnerProfileFactory;
use Modules\Properties\Enums\OwnerIdType;
use Modules\Properties\Enums\OwnerType;

/**
 * A property owner's person or company details (DATABASE_SCHEMA.md § 1),
 * distinct from a user: an owner may never sign in. Organization-scoped.
 * The ID number (NIN or CAC number) is an encrypted cast, hidden from
 * arrays, and screens show it masked (maskedId()); it never goes back to
 * the browser (ARCHITECTURE.md § 10). Written only through
 * Modules\Properties\Owners\OwnerProfiles, which checks the policy and
 * audits every change. Archived owners are soft deleted (AGENT.md § 3
 * rule 7). `import_batch_id` and `external_ref` are import provenance
 * (§ 8A), set only by the importer.
 *
 * @property int $id
 * @property int $organization_id
 * @property OwnerType $type
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $company_name
 * @property string $display_name company name, or first and last name
 * @property string|null $email
 * @property string|null $phone E.164 (App\Support\PhoneNumber)
 * @property string|null $address
 * @property string|null $state
 * @property string|null $lga
 * @property OwnerIdType $id_type
 * @property string|null $id_value decrypted
 * @property string|null $notes
 * @property int|null $import_batch_id
 * @property string|null $external_ref
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Hidden(['id_value'])]
final class OwnerProfile extends Model
{
    /** @use HasFactory<OwnerProfileFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['type', 'first_name', 'last_name', 'company_name', 'email', 'phone', 'address', 'state', 'lga', 'id_type', 'id_value', 'notes'];

    /** @var array<string, mixed> */
    protected $attributes = ['id_type' => 'none'];

    protected static function booted(): void
    {
        self::saving(static function (self $owner): void {
            $owner->display_name = self::nameFor($owner->type, $owner->first_name, $owner->last_name, $owner->company_name);
        });
    }

    /** The name lists show: the company name, or "First Last". */
    public static function nameFor(OwnerType $type, ?string $firstName, ?string $lastName, ?string $companyName): string
    {
        return $type === OwnerType::Company
            ? trim((string) $companyName)
            : trim(trim((string) $firstName).' '.trim((string) $lastName));
    }

    /** "NIN ···7890", or null when no number is on file. */
    public function maskedId(): ?string
    {
        if ($this->id_type === OwnerIdType::None || $this->id_value === null || $this->id_value === '') {
            return null;
        }

        return $this->id_type->label().' ···'.substr($this->id_value, -4);
    }

    /**
     * The buildings this owner owns (P02-01b), archived buildings included.
     *
     * @return HasMany<Property, $this>
     */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class, 'owner_profile_id');
    }

    protected static function newFactory(): OwnerProfileFactory
    {
        return OwnerProfileFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => OwnerType::class,
            'id_type' => OwnerIdType::class,
            'id_value' => 'encrypted',
        ];
    }
}
