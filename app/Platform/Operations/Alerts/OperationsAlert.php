<?php

declare(strict_types=1);

namespace App\Platform\Operations\Alerts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One incident of an operations alert rule for one subject
 * (`operations_alerts`, DATABASE_SCHEMA.md): open from the check that
 * found it failing until the check that found it cleared. Written only by
 * AlertMonitor; never deleted.
 *
 * @property int $id
 * @property AlertRule $rule
 * @property string $subject
 * @property string|null $open_key "rule|subject" while open, null once resolved
 * @property string $summary
 * @property array<string, scalar|null>|null $details
 * @property string $raised_by scheduler|web|uptime|console
 * @property Carbon|null $started_at
 * @property Carbon|null $last_notified_at
 * @property int $notifications
 * @property Carbon|null $resolved_at
 */
final class OperationsAlert extends Model
{
    protected $table = 'operations_alerts';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::deleting(fn (): never => throw new LogicException('Operations alerts are never deleted.'));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotNull('open_key');
    }

    public function isOpen(): bool
    {
        return $this->open_key !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rule' => AlertRule::class,
            'details' => 'array',
            'started_at' => 'datetime',
            'last_notified_at' => 'datetime',
            'resolved_at' => 'datetime',
            'notifications' => 'integer',
        ];
    }
}
