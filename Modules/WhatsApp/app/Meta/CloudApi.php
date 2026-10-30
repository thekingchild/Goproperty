<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Meta;

use Modules\WhatsApp\Models\WhatsAppConnection;

/**
 * Meta's WhatsApp Cloud API with the ORGANIZATION's own access token
 * (ARCHITECTURE.md § 6.7). HttpCloudApi calls Meta, and only in production
 * with `WHATSAPP_DRIVER=cloud` (AGENT.md § 7); everywhere else
 * LogCloudApi stands in and nothing leaves the server. Tokens are never
 * logged and never part of an error message.
 */
interface CloudApi
{
    /** `meta` or `log`: the delivery rows' `provider`. */
    public function name(): string;

    /** Tries the token on the phone number (GET /{phone-number-id}). */
    public function check(WhatsAppConnection $connection): ConnectionCheck;

    /**
     * The account's message templates (GET /{waba-id}/message_templates,
     * every page), as Meta describes them; null when nothing was fetched
     * (the log stand-in).
     *
     * @return list<array<string, mixed>>|null
     *
     * @throws MetaRefused when Meta refuses the token or the account
     * @throws MetaUnavailable when Meta cannot be reached or answers oddly
     */
    public function templates(WhatsAppConnection $connection): ?array;

    /**
     * Sends a template message (POST /{phone-number-id}/messages) and
     * returns Meta's message id (`wamid.…`).
     *
     * @param  list<array<string, mixed>>  $components
     *
     * @throws MetaRefused when Meta refuses it (4xx): never sent again
     * @throws MetaUnavailable when Meta answers 5xx: it did not take it, so it may be tried again
     * @throws MetaUnreachable when the call broke off: Meta may or may not have taken it
     */
    public function send(WhatsAppConnection $connection, string $to, string $template, string $language, array $components): string;
}
