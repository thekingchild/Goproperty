<?php

declare(strict_types=1);

namespace Modules\Tickets\Tickets;

use Illuminate\Validation\ValidationException;

/**
 * A ticket step that cannot happen: a move the state machine does not
 * allow (TicketStatus), a reopening after the tenant's window, an
 * assignment to a closed ticket. Nothing was changed. A
 * ValidationException (key `ticket` unless said otherwise), so the pages
 * show the message where the action is.
 */
final class TicketRefused extends ValidationException
{
    public static function because(string $message, string $key = 'ticket'): self
    {
        return self::withMessages([$key => $message]);
    }
}
