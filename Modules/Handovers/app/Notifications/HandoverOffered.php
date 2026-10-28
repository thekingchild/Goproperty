<?php

declare(strict_types=1);

namespace Modules\Handovers\Notifications;

use App\Theme\ThemeContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * To each owner of the agency buildings are offered to (BILLING_PAYMENTS.md
 * § 7.7 rule 3): who offers which buildings, and the link to the accept
 * screen (/handovers/{token}, which opens it in their workspace after
 * sign-in). By email and in the app (the stored copy links to the
 * workspace page, never the token); never SMS or WhatsApp. Queued on
 * `notifications`, after the commit.
 */
final class HandoverOffered extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        #[SensitiveParameter] private readonly string $token,
        public readonly int $handoverId,
        public readonly string $fromOrganization,
        public readonly string $toOrganization,
        public readonly string $toSlug,
        public readonly string $offeredBy,
        public readonly int $buildings,
        public readonly int $units,
        public readonly CarbonImmutable $expiresAt,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function url(): string
    {
        return route('handovers.open', ['token' => $this->token]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = app(ThemeContext::class)->current()->name();
        $what = trans_choice('{1} :count building|[2,*] :count buildings', $this->buildings, ['count' => $this->buildings]).' ('.trans_choice('{1} :count unit|[2,*] :count units', $this->units, ['count' => $this->units]).')';

        return (new MailMessage)
            ->subject("{$this->fromOrganization} offers {$this->toOrganization} the management of {$what}")
            ->line("{$this->offeredBy} of {$this->fromOrganization} asks {$this->toOrganization} to manage {$what} on {$brand}.")
            ->line("Nothing moves unless an owner of {$this->toOrganization} accepts. The accept screen shows the buildings, who collects rent, the notice period and how your {$brand} bill changes.")
            ->action('Review the offer', $this->url())
            ->line('The offer expires on '.$this->expiresAt->timezone('Africa/Lagos')->format('j F Y').'. If you did not expect it, you can ignore this email.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'handover.offered',
            'handover_id' => $this->handoverId,
            'from_organization' => $this->fromOrganization,
            'to_organization' => $this->toOrganization,
            'buildings' => $this->buildings,
            'units' => $this->units,
            'expires_at' => $this->expiresAt->toIso8601String(),
            'url' => route('workspace.handovers.show', ['organization' => $this->toSlug, 'handover' => $this->handoverId]),
        ];
    }
}
