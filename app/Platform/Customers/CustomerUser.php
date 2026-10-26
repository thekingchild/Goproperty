<?php

declare(strict_types=1);

namespace App\Platform\Customers;

use App\Models\User;
use App\Policies\CustomerUserPolicy;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The console's read model of a customer user (the users lookup), across
 * organizations. Read-only; authorized by CustomerUserPolicy for staff.
 *
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
#[UsePolicy(CustomerUserPolicy::class)]
class CustomerUser extends Model
{
    protected $table = 'users';

    protected static function booted(): void
    {
        static::saving(fn (): never => throw new LogicException('CustomerUser is a read model.'));
        static::deleting(fn (): never => throw new LogicException('CustomerUser is a read model.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<Customer, $this> */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'organization_user', 'user_id', 'organization_id')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    /**
     * The user's role names in each organization row, comma-separated, as a
     * subquery on `organizations.id` (for the user's memberships).
     */
    public function roleNamesPerOrganization(): Builder
    {
        return DB::table('model_has_roles')
            ->selectRaw("GROUP_CONCAT(roles.name ORDER BY roles.name SEPARATOR ', ')")
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->where('model_has_roles.model_id', $this->getKey())
            ->whereColumn('model_has_roles.organization_id', 'organizations.id');
    }
}
