<?php

declare(strict_types=1);

namespace Modules\Tickets\Http\Controllers;

use App\Media\Media;
use App\Media\MediaUrls;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Tickets\Messages\ThreadViewer;
use Modules\Tickets\Messages\TicketThread;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Owner\OwnerTickets;
use Modules\Tickets\Portal\TenantTickets;

/**
 * A file of a ticket's thread (P04-02; evidence, ARCHITECTURE.md § 9): a
 * redirect to a short-lived signed link (App\Media\MediaUrls), made only
 * after the policy check, for each surface:
 *
 * - the workspace (TicketPolicy::downloadAttachment: whoever sees the
 *   ticket; internal notes' files too);
 * - the tenant portal (the user's own ticket, TenantTickets;
 *   downloadAttachmentAsTenant);
 * - the owner portal (a ticket of the user's buildings, OwnerTickets;
 *   downloadAttachmentAsOwner).
 *
 * The file is looked up among the messages the reader may see
 * (Messages\TicketThread::attachment), so an internal note's file answers
 * 404 to the tenant and the owner, whatever id they send; so do another
 * ticket's file and anything else. Every one is refused while GoProperty
 * staff impersonate (PLATFORM_CONSOLE.md § 6: staff see the thread, not
 * the files). thumb() gives an image's thumbnail once the `media` queue
 * made it, else the original.
 */
final class TicketFileController
{
    #[BlockedWhileImpersonating]
    public function workspace(Organization $organization, Ticket $ticket, int $media, TicketThread $thread, MediaUrls $urls): RedirectResponse
    {
        Gate::authorize('downloadAttachment', $ticket);

        return $this->send($urls, $thread->attachment($ticket, $media, ThreadViewer::Staff), false);
    }

    #[BlockedWhileImpersonating]
    public function workspaceThumb(Organization $organization, Ticket $ticket, int $media, TicketThread $thread, MediaUrls $urls): RedirectResponse
    {
        Gate::authorize('downloadAttachment', $ticket);

        return $this->send($urls, $thread->attachment($ticket, $media, ThreadViewer::Staff), true);
    }

    #[BlockedWhileImpersonating]
    public function portal(Request $request, int $ticket, int $media, TenantTickets $tickets, TicketThread $thread, MediaUrls $urls): RedirectResponse
    {
        return $this->send($urls, $this->tenantFile($request, $ticket, $media, $tickets, $thread), false);
    }

    #[BlockedWhileImpersonating]
    public function portalThumb(Request $request, int $ticket, int $media, TenantTickets $tickets, TicketThread $thread, MediaUrls $urls): RedirectResponse
    {
        return $this->send($urls, $this->tenantFile($request, $ticket, $media, $tickets, $thread), true);
    }

    #[BlockedWhileImpersonating]
    public function owner(Request $request, int $ticket, int $media, OwnerTickets $tickets, TicketThread $thread, MediaUrls $urls): RedirectResponse
    {
        return $this->send($urls, $this->ownerFile($request, $ticket, $media, $tickets, $thread), false);
    }

    #[BlockedWhileImpersonating]
    public function ownerThumb(Request $request, int $ticket, int $media, OwnerTickets $tickets, TicketThread $thread, MediaUrls $urls): RedirectResponse
    {
        return $this->send($urls, $this->ownerFile($request, $ticket, $media, $tickets, $thread), true);
    }

    private function tenantFile(Request $request, int $ticketId, int $mediaId, TenantTickets $tickets, TicketThread $thread): ?Media
    {
        $user = $this->user($request);
        $found = $tickets->find($user, $ticketId);
        abort_if($found === null, 404);
        [$tenancy, $ticket] = $found;

        return $tickets->within($tenancy, static function () use ($user, $ticket, $mediaId, $thread): ?Media {
            Gate::forUser($user)->authorize('downloadAttachmentAsTenant', $ticket);

            return $thread->attachment($ticket, $mediaId, ThreadViewer::Tenant);
        });
    }

    private function ownerFile(Request $request, int $ticketId, int $mediaId, OwnerTickets $tickets, TicketThread $thread): ?Media
    {
        $user = $this->user($request);
        $found = $tickets->find($user, $ticketId);
        abort_if($found === null, 404);
        [$owner, $ticket] = $found;

        return $tickets->within($user, $owner, static function () use ($user, $ticket, $mediaId, $thread): ?Media {
            Gate::forUser($user)->authorize('downloadAttachmentAsOwner', $ticket);

            return $thread->attachment($ticket, $mediaId, ThreadViewer::Owner);
        });
    }

    private function send(MediaUrls $urls, ?Media $media, bool $thumb): RedirectResponse
    {
        abort_if($media === null, 404);

        if ($thumb) {
            abort_unless(str_starts_with((string) $media->mime_type, 'image/'), 404);
        }

        return redirect()->away($thumb ? $urls->preview($media, 'thumb') : $urls->temporary($media))->header('Cache-Control', 'no-store, private');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
