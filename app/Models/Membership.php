<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's membership of the current organization (`organization_user`,
 * DATABASE_SCHEMA.md § 1), scoped like every customer-domain model: listing
 * members can only ever show the current organization's. The user's own
 * list of organizations (switcher, landing) goes through
 * User::organizations(), which is the user's view across them.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
final class Membership extends Model
{
    use BelongsToOrganization;

    protected $table = 'organization_user';

    /** @var list<string> */
    protected $fillable = ['user_id', 'is_default'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
