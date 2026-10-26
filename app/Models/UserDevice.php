<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A browser a customer has signed in from (DATABASE_SCHEMA.md § 1).
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string|null $user_agent
 * @property string|null $ip_address
 * @property Carbon|null $two_factor_trusted_until
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'token_hash', 'user_agent', 'ip_address', 'two_factor_trusted_until', 'last_seen_at'])]
class UserDevice extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'two_factor_trusted_until' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
