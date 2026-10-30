<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use InvalidArgumentException;

/**
 * Every message GoProperty may send by the agency's WhatsApp (P05-06), as
 * the code knows it: Core and modules add theirs from their providers
 * (callAfterResolving), like TemplateCatalog. The WhatsApp settings list
 * them for the agency to map each one to an approved template.
 */
final class WhatsAppEvents
{
    /** @var array<string, WhatsAppEvent> */
    private array $events = [];

    public function add(WhatsAppEvent ...$events): void
    {
        foreach ($events as $event) {
            if (isset($this->events[$event->key])) {
                throw new InvalidArgumentException("WhatsApp event [{$event->key}] is registered twice.");
            }

            $this->events[$event->key] = $event;
        }
    }

    public function find(string $key): ?WhatsAppEvent
    {
        return $this->events[$key] ?? null;
    }

    /** @return list<WhatsAppEvent> by group order, then as registered */
    public function all(): array
    {
        $events = array_values($this->events);
        $position = array_flip(array_keys($this->events));
        usort($events, static fn (WhatsAppEvent $a, WhatsAppEvent $b): int => [$a->order, $position[$a->key]] <=> [$b->order, $position[$b->key]]);

        return $events;
    }
}
