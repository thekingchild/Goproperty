<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\UserDevice;
use App\Theme\ThemeContext;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "We email you whenever a new device signs in" (UX_DESIGN.md § 3.1).
 * Email only: no SMS is ever sent for sign-in.
 */
final class NewDeviceSignIn extends Notification
{
    use Queueable;

    public function __construct(private readonly UserDevice $device) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = app(ThemeContext::class)->current()->name();
        $when = ($this->device->last_seen_at ?? now())->timezone('Africa/Lagos')->format('j M Y, g:i a').' (Lagos time)';

        return (new MailMessage)
            ->subject("New sign-in to your {$brand} account")
            ->line("Your {$brand} account was just signed in from a browser we haven't seen before.")
            ->line('When: '.$when)
            ->line('Browser: '.($this->device->user_agent ?: 'unknown'))
            ->line('IP address: '.($this->device->ip_address ?: 'unknown'))
            ->line('If this was you, there is nothing to do.')
            ->action('This wasn\'t me: reset my password', route('password.request'));
    }
}
