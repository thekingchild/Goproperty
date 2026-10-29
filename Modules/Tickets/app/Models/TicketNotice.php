<?php

declare(strict_types=1);

namespace Modules\Tickets\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One thing one person was told about a ticket (DATABASE_SCHEMA.md § 5
 * `ticket_notices`; P04-03): the announcement's key (a status move, an
 * assignment), the recipient (`user:{id}`, or `tenant:{id}` for a tenant
 * told by email only), their side (`tenant`, `staff`, `owner`: the
 * building's owner about a ticket shared with them, P04-06), the event, the
 * channels and the in-app notification's id. Unique per ticket, key and
 * recipient, written before the notification is queued, so each party is
 * told once. Written only by Modules\Tickets\Notices\TicketNotifier; only
 * `mailed_at` is ever filled in afterwards (TicketNotifier::markMailed),
 * and rows are never deleted.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $ticket_id
 * @property string $key
 * @property string $recipient
 * @property int|null $user_id
 * @property string $side
 * @property string $event
 * @property list<string> $channels
 * @property string $notification_id
 * @property CarbonImmutable|null $mailed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class TicketNotice extends Model
{
    use BelongsToOrganization;

    protected static function booted(): void
    {
        self::updating(static function (TicketNotice $notice): void {
            if (array_diff(array_keys($notice->getDirty()), ['mailed_at', 'updated_at']) !== []) {
                throw new LogicException('What a ticket\'s parties were told is never changed.');
            }
        });
        self::deleting(static fn (): never => throw new LogicException('What a ticket\'s parties were told is never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'mailed_at' => 'immutable_datetime',
        ];
    }
}
