<?php

declare(strict_types=1);

namespace App\Notifications\Preferences;

/**
 * Panels modules add to the account's Settings → Notifications page
 * (P05-06: the tenant's consent to each agency's WhatsApp messages). Each
 * is a Livewire component, mounted without parameters for the signed-in
 * account, that shows nothing when it has nothing to offer; the page only
 * places it. A module that is switched off registers nothing. A singleton;
 * modules register from their providers (callAfterResolving).
 */
final class NotificationSettingsPanels
{
    /** @var list<string> */
    private array $components = [];

    public function register(string $component): void
    {
        if (! in_array($component, $this->components, true)) {
            $this->components[] = $component;
        }
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->components;
    }
}
