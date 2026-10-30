<?php

declare(strict_types=1);

namespace App\Mail\Suppression;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSending;
use Symfony\Component\Mime\Address;

/**
 * No email reaches a suppressed address, whatever sends it (P05-04;
 * PLATFORM_CONSOLE.md § 5 "Delivery"):
 *
 * - a notification's mail channel is not used at all when every address it
 *   would go to is suppressed (no NotificationSent, so a reminder or ticket
 *   notice is not marked as mailed);
 * - every message about to leave (mailables too) loses its suppressed
 *   To, Cc and Bcc addresses, and is not sent when none is left.
 */
final readonly class DropSuppressedRecipients
{
    public function __construct(private Suppressions $suppressions) {}

    public function notification(NotificationSending $event): ?bool
    {
        if ($event->channel !== 'mail') {
            return null;
        }

        $notifiable = $event->notifiable;
        $route = match (true) {
            $notifiable instanceof AnonymousNotifiable => $notifiable->routeNotificationFor('mail'),
            is_object($notifiable) && method_exists($notifiable, 'routeNotificationFor') => $notifiable->routeNotificationFor('mail', $event->notification),
            default => null,
        };

        $emails = self::addresses($route);

        if ($emails === []) {
            return null;
        }

        return count($this->suppressions->among($emails)) === count(array_unique(array_map(Suppressions::normalize(...), $emails))) ? false : null;
    }

    public function message(MessageSending $event): ?bool
    {
        $message = $event->message;
        $remaining = 0;

        foreach (['To', 'Cc', 'Bcc'] as $kind) {
            $addresses = $message->{'get'.$kind}();
            $kept = array_values(array_filter($addresses, fn (Address $address): bool => ! $this->suppressions->isSuppressed($address->getAddress())));

            if (count($kept) !== count($addresses)) {
                $message->getHeaders()->remove($kind);

                if ($kept !== []) {
                    $message->{strtolower($kind)}(...$kept);
                }
            }

            $remaining += count($kept);
        }

        return $remaining === 0 ? false : null;
    }

    /** @return list<string> */
    private static function addresses(mixed $route): array
    {
        if (is_string($route)) {
            return [$route];
        }

        if (! is_array($route)) {
            return [];
        }

        $emails = [];

        foreach ($route as $key => $value) {
            // ['a@x.ng' => 'Name'] or ['a@x.ng', …]
            $emails[] = is_string($key) ? $key : (string) $value;
        }

        return array_values(array_filter($emails, static fn (string $email): bool => $email !== ''));
    }
}
