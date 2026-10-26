<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\TransactionTier;
use App\Support\Money;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A customer: an agency or a property owner's business (DATABASE_SCHEMA.md
 * § 1), and the unit of tenancy (ARCHITECTURE.md § 4). Not itself scoped:
 * customer code reaches it through OrganizationContext, staff code through
 * the Platform namespace.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property list<string>|null $previous_slugs
 * @property OrganizationType $type
 * @property string|null $cac_number
 * @property string|null $address
 * @property string|null $state
 * @property string|null $lga
 * @property int|null $logo_media_id
 * @property OrganizationStatus $status
 * @property TransactionTier $transaction_tier
 * @property bool $allow_support_access
 * @property bool $platform_covered
 * @property array<string, mixed>|null $branding
 * @property Money|null $online_payment_cap_kobo
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Hidden(['cac_number'])]
#[Fillable(['name', 'slug', 'type', 'address', 'state', 'lga', 'status', 'transaction_tier', 'allow_support_access', 'platform_covered', 'branding', 'online_payment_cap_kobo', 'trial_ends_at'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * The table's defaults, so a new model knows them before it is reloaded
     * (otherwise the activity log reports them as changed from null).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'onboarding',
        'transaction_tier' => 1,
        'allow_support_access' => true,
        'platform_covered' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'previous_slugs' => 'array',
            'type' => OrganizationType::class,
            'cac_number' => 'encrypted',
            'status' => OrganizationStatus::class,
            'transaction_tier' => TransactionTier::class,
            'allow_support_access' => 'boolean',
            'platform_covered' => 'boolean',
            'branding' => 'array',
            'online_payment_cap_kobo' => MoneyCast::class,
            'trial_ends_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('is_default')->withTimestamps();
    }

    /** Audit trail (PLATFORM_CONSOLE.md § 7); the CAC number is never logged. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('organization')
            ->logFillable()
            ->logExcept(['cac_number'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
