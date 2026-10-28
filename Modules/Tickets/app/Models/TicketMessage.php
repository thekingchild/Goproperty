<?php

declare(strict_types=1);

namespace Modules\Tickets\Models;

use App\Media\HasOrganizationMedia;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Tickets\Enums\MessageAuthor;
use Spatie\MediaLibrary\HasMedia;

/**
 * One message of a ticket's thread (DATABASE_SCHEMA.md § 5
 * `ticket_messages`; P04-02): a reply by the tenant, a member or the
 * building's owner, or a member's internal note (`is_internal`), which
 * only staff ever see. Organization-scoped. Written only by
 * Modules\Tickets\Messages\TicketMessages; the media owner of its
 * attachments (collection `ticket_attachments`, evidence).
 *
 * Evidence (AGENT.md § 3 rule 7): never changed and never deleted.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $ticket_id
 * @property MessageAuthor $author_kind
 * @property int|null $author_user_id
 * @property string|null $body
 * @property bool $is_internal
 * @property int $attachment_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class TicketMessage extends Model implements HasMedia
{
    use BelongsToOrganization, HasOrganizationMedia;

    /** The media collection of ticket attachments (config `media.collections`, added by TicketsServiceProvider). */
    public const string COLLECTION = 'ticket_attachments';

    /** @var array<string, mixed> */
    protected $attributes = ['is_internal' => false, 'attachment_count' => 0];

    protected static function booted(): void
    {
        self::updating(static function (self $message): void {
            // Only the count of files kept with it is set, once, in the posting transaction.
            if (array_diff(array_keys($message->getDirty()), ['attachment_count', 'updated_at']) !== []) {
                throw new LogicException('A ticket message is evidence: it is never changed.');
            }
        });
        self::deleting(static fn (): never => throw new LogicException('A ticket message is evidence and is never deleted.'));
    }

    /**
     * Messages the tenant and the owner may see: never an internal note.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeShared(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class)->withTrashed();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ticket_id' => 'integer',
            'author_kind' => MessageAuthor::class,
            'author_user_id' => 'integer',
            'is_internal' => 'boolean',
            'attachment_count' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
