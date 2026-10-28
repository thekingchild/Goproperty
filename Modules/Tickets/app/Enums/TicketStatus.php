<?php

declare(strict_types=1);

namespace Modules\Tickets\Enums;

use App\Enums\Concerns\HasValues;

/**
 * A maintenance ticket's state (`tickets.status`, DATABASE_SCHEMA.md § 5;
 * ARCHITECTURE.md § 6.6). Changed only by Modules\Tickets\Tickets\
 * TicketService (AGENT.md § 3 rule 5):
 *
 *     draft ────────submit──────▶ submitted        (the tenant sends it; a staff ticket starts here)
 *     submitted ────review──────▶ under_review
 *     submitted ────start work──▶ in_progress
 *     submitted ────resolve─────▶ resolved         (fixed at once)
 *     under_review ─start work──▶ in_progress
 *     under_review ─resolve─────▶ resolved
 *     in_progress ──resolve─────▶ resolved
 *     resolved ─────close───────▶ closed           (the tenant confirms, staff close it, or tickets-auto-close)
 *     resolved ─────reopen──────▶ reopened         (the tenant within tickets.reopen_days of the resolution; staff any time)
 *     closed ───────reopen──────▶ reopened
 *     reopened ─────review / start work / resolve ─▶ under_review / in_progress / resolved
 *
 * Nothing goes back to `draft`, and nothing skips `resolved` on the way to
 * `closed`. Every other move is refused and changes nothing
 * (Tickets\TicketRefused).
 */
enum TicketStatus: string
{
    use HasValues;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Reopened = 'reopened';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::InProgress => 'In progress',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
            self::Reopened => 'Reopened',
        };
    }

    /** Flux badge colour (UX_DESIGN.md § 3: status badges). */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::Submitted => 'sky',
            self::UnderReview => 'amber',
            self::InProgress => 'indigo',
            self::Resolved => 'green',
            self::Closed => 'zinc',
            self::Reopened => 'red',
        };
    }

    /** The moves the state machine allows (see the class comment). */
    public function canMoveTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::UnderReview, self::InProgress, self::Resolved],
            self::UnderReview => [self::InProgress, self::Resolved],
            self::InProgress => [self::Resolved],
            self::Resolved => [self::Closed, self::Reopened],
            self::Closed => [self::Reopened],
            self::Reopened => [self::UnderReview, self::InProgress, self::Resolved],
        }, true);
    }

    /** Waiting for the organization: sent and not yet resolved (an inbox's "open"). */
    public function isOpen(): bool
    {
        return in_array($this, [self::Submitted, self::UnderReview, self::InProgress, self::Reopened], true);
    }

    /**
     * The statuses staff see (every one but the tenant's unsent draft).
     *
     * @return list<self>
     */
    public static function sent(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $status): bool => $status !== self::Draft));
    }

    /** @return list<self> */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $status): bool => $status->isOpen()));
    }
}
