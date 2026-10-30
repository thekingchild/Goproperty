<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Sending;

use App\Notifications\Channels\WhatsAppRouting;
use App\Tenancy\OrganizationContext;
use App\WhatsApp\WhatsAppSender;
use Modules\WhatsApp\Connections\WhatsAppConnections;
use Modules\WhatsApp\Mappings\ChannelMappings;
use Modules\WhatsApp\Models\ChannelMapping;
use Modules\WhatsApp\OptIns\WhatsAppOptIns;
use Modules\WhatsApp\Templates\TemplateSync;

/**
 * Core's WhatsAppRouting for reminders (P05-06; ARCHITECTURE.md § 6.7), in
 * the current organization:
 *
 * - order(): the event's channel order as the agency set it; `["sms"]`
 *   when it set none, so an organization without WhatsApp is reminded
 *   exactly as before;
 * - usable(): the WhatsApp driver can send (Core's WhatsAppSender), the
 *   plan allows WhatsApp, the connection works and the event's mapping is
 *   active with an approved utility template;
 * - optedIn(): tenants' open opt-ins at a number still theirs.
 *
 * Bound anew for each class that asks for it (the reminder planner and
 * sender are built once per dispatch run), so its answers are remembered
 * for one run at most.
 */
final class WhatsAppRoutes implements WhatsAppRouting
{
    /** @var array<string, bool> organization:event => usable */
    private array $usable = [];

    /** @var array<int, bool> organization => has a working connection */
    private array $working = [];

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly WhatsAppSender $sender,
        private readonly WhatsAppConnections $connections,
        private readonly ChannelMappings $mappings,
        private readonly WhatsAppOptIns $optIns,
    ) {}

    public function order(string $event): array
    {
        $order = $this->mappings->for($event)?->channel_order;

        return is_array($order) && $order !== [] ? $order : ['sms'];
    }

    public function usable(string $event): bool
    {
        $key = $this->context->id().':'.$event;

        return $this->usable[$key] ??= $this->working() && $this->sender->available($this->context->get(), $event) && $this->mapped($event);
    }

    public function optedIn(array $tenantIds): array
    {
        return $this->working() ? $this->optIns->optedIn($tenantIds) : [];
    }

    private function working(): bool
    {
        return $this->working[$this->context->id()] ??= $this->connections->working($this->context->get()) !== null;
    }

    private function mapped(string $event): bool
    {
        $mapping = $this->mappings->for($event);

        return $mapping instanceof ChannelMapping && $mapping->active && TemplateSync::unusable($mapping) === null;
    }
}
