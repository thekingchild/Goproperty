<?php

declare(strict_types=1);

namespace App\Platform\Customers;

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\TransactionTier;
use App\Models\Organization;
use App\Models\User;
use App\Policies\CustomerPolicy;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The console's read model of an organization (ARCHITECTURE.md § 4: cross-
 * organization reads use dedicated read models in the Platform namespace).
 * Read-only: changes go through App\Models\Organization and the services
 * (OrganizationSuspension). Authorized by CustomerPolicy for staff.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property OrganizationType $type
 * @property string|null $state
 * @property OrganizationStatus $status
 * @property TransactionTier $transaction_tier
 * @property bool $platform_covered
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $suspended_at
 * @property OrganizationStatus|null $status_before_suspension
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Hidden(['cac_number', 'branding'])]
#[UsePolicy(CustomerPolicy::class)]
class Customer extends Model
{
    use SoftDeletes;

    protected $table = 'organizations';

    protected static function booted(): void
    {
        static::saving(fn (): never => throw new LogicException('Customer is a read model: change App\Models\Organization through its services.'));
        static::deleting(fn (): never => throw new LogicException('Customer is a read model: organizations are never deleted from the console.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'status' => OrganizationStatus::class,
            'transaction_tier' => TransactionTier::class,
            'platform_covered' => 'boolean',
            'trial_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
            'status_before_suspension' => OrganizationStatus::class,
        ];
    }

    /** @return BelongsToMany<CustomerUser, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(CustomerUser::class, 'organization_user', 'organization_id', 'user_id')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    /**
     * Audit entries about the organization (suspensions, reactivations and
     * every other change logged on it).
     *
     * @return HasMany<AuditEntry, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(AuditEntry::class, 'subject_id')->where('subject_type', (new Organization)->getMorphClass());
    }

    /** The organization itself, for the services that change it. */
    public function organization(): Organization
    {
        return Organization::query()->whereKey($this->getKey())->firstOrFail();
    }

    /**
     * A column of the organization's first org-owner, as a subquery on
     * `organizations.id` (for the customers list).
     */
    public static function ownerColumn(string $column): Builder
    {
        return DB::table('users')
            ->select("users.{$column}")
            ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->where('roles.name', OrganizationRole::Owner->value)
            ->whereNull('roles.organization_id')
            ->whereColumn('model_has_roles.organization_id', 'organizations.id')
            ->orderBy('users.id')
            ->limit(1);
    }

    /**
     * The organizations whose owner's name, email or phone contains the text.
     */
    public static function ownedBy(string $search): Builder
    {
        $like = '%'.addcslashes($search, '%_\\').'%';

        return DB::table('model_has_roles')
            ->select('model_has_roles.organization_id')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->where('roles.name', OrganizationRole::Owner->value)
            ->whereNull('roles.organization_id')
            ->where(fn (Builder $query) => $query
                ->where('users.name', 'like', $like)
                ->orWhere('users.email', 'like', $like)
                ->orWhere('users.phone', 'like', $like));
    }

    /**
     * A member's role names in one organization, comma-separated, as a
     * subquery on `users.id` (for member lists).
     */
    public static function roleNamesColumn(int|string $organizationId, string $userColumn = 'users.id'): Builder
    {
        return DB::table('model_has_roles')
            ->selectRaw("GROUP_CONCAT(roles.name ORDER BY roles.name SEPARATOR ', ')")
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->where('model_has_roles.organization_id', $organizationId)
            ->whereColumn('model_has_roles.model_id', $userColumn);
    }
}
