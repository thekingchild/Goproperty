<?php

declare(strict_types=1);

namespace App\Models;

use App\Mail\Suppression\SuppressionReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An address no email goes to while `lifted_at` is empty (P05-04;
 * PLATFORM_CONSOLE.md § 5 "Delivery"). Platform-level, no organization.
 * Written only by App\Mail\Suppression\Suppressions; never deleted.
 *
 * @property int $id
 * @property string $email
 * @property SuppressionReason $reason
 * @property string|null $provider
 * @property string|null $detail
 * @property Carbon $suppressed_at
 * @property Carbon|null $lifted_at
 * @property int|null $lifted_by_staff_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PlatformStaff|null $liftedBy
 */
class EmailSuppression extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('Suppressions are lifted, never deleted.'));
    }

    public function isActive(): bool
    {
        return $this->lifted_at === null;
    }

    /** @return BelongsTo<PlatformStaff, $this> */
    public function liftedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'lifted_by_staff_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reason' => SuppressionReason::class,
            'suppressed_at' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }
}
