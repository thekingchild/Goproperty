<?php

declare(strict_types=1);

namespace Modules\Tickets\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\Tickets\Enums\TicketStatus;

/**
 * One move of a ticket's state machine (DATABASE_SCHEMA.md § 5
 * `ticket_status_history`): from (empty when the ticket was created), to,
 * who (empty for the system), the note given (a resolution, a reopening
 * reason) and when. Organization-scoped. Written only by
 * Modules\Tickets\Tickets\TicketService in the move's transaction; never
 * changed or deleted.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $ticket_id
 * @property TicketStatus|null $from_status
 * @property TicketStatus $to_status
 * @property int|null $changed_by_user_id
 * @property string|null $note
 * @property CarbonImmutable $changed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class TicketStatusChange extends Model
{
    use BelongsToOrganization;

    protected $table = 'ticket_status_history';

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('A ticket\'s status history is never changed.'));
        self::deleting(static fn (): never => throw new LogicException('A ticket\'s status history is never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_status' => TicketStatus::class,
            'to_status' => TicketStatus::class,
            'changed_at' => 'immutable_datetime',
        ];
    }
}
