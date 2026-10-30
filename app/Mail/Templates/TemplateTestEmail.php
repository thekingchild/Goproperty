<?php

declare(strict_types=1);

namespace App\Mail\Templates;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Send test to me" in the template editor (PLATFORM_CONSOLE.md § 5): the
 * wording on screen, filled in with sample values, in the same layout and
 * sample branding as the preview, to the staff member's own address only.
 * The subject says it is a test.
 */
final class TemplateTestEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly RenderedEmail $email,
        public readonly EmailBrand $brand,
        public readonly ?string $url,
    ) {
        $this->onQueue('notifications');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = new RenderedEmail('[Test] '.$this->email->subject, $this->email->html, $this->email->text, $this->email->actionLabel);

        return app(BrandedMail::class)->message($email, $this->brand, $this->url);
    }
}
