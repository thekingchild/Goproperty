<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

/**
 * Whether a reminder goes by the agency's WhatsApp, and what comes after
 * it (P05-06; ARCHITECTURE.md § 6.7), for the current organization. The
 * reminder engine asks this when it plans and again when it sends, so a
 * template Meta paused, a connection that stopped working or a tenant who
 * opted out moves that reminder to the next channel.
 *
 * Bound by default to NoWhatsAppRouting (WhatsApp never used, SMS as
 * before); the WhatsApp module binds its own.
 */
interface WhatsAppRouting
{
    /**
     * The event's phone channels in order, e.g. ['whatsapp', 'sms']; ['sms']
     * when the agency set none. Email and in-app reminders are not in it:
     * they go wherever they go anyway.
     *
     * @return list<string>
     */
    public function order(string $event): array;

    /** WhatsApp can carry this event now: the plan allows it, the connection works, and an active mapping uses an approved template. */
    public function usable(string $event): bool;

    /**
     * The WhatsApp numbers of these tenants: an active opt-in (Meta policy)
     * at a number that is still the tenant's. Tenants without are left out.
     *
     * @param  list<int>  $tenantIds
     * @return array<int, string> tenant id => E.164 number
     */
    public function optedIn(array $tenantIds): array;
}
