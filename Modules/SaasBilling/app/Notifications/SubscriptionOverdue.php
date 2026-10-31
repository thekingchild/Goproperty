<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Notifications;

use App\Mail\Templates\BrandedMail;
use App\Mail\Templates\EmailBrand;
use App\Mail\Templates\EmailTemplates;
use App\Mail\Templates\RenderedEmail;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use App\Theme\ThemeContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;
use Modules\SaasBilling\Models\DunningNotice;

/**
 * A notice of the dunning ladder (P06-05; BILLING_PAYMENTS.md § 6) to an
 * owner of the organization (or of its payer): past due, a grace warning,
 * suspended, a reminder while suspended, cancelled. In the app, and by
 * email when the account has an address, in GoProperty's branding (the
 * step's template, `billing.past_due` …), linked to Settings → Subscription
 * where an owner pays. Sent by Dunning\DunningLadder after the commit that
 * wrote the `dunning_notices` row; the SMS goes through the SMS outbox.
 */
final class SubscriptionOverdue extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $noticeId,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && $notifiable->email !== null ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return app(BrandedMail::class)->message($this->email($notifiable), EmailBrand::platform(app(ThemeContext::class)->current()), $this->url());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $notice = $this->notice();

        return [
            'event' => $notice->step->template(),
            'organization_id' => $this->organizationId,
            'organization' => $this->organization()?->name,
            'dunning_notice_id' => $this->noticeId,
            'message' => $this->email($notifiable)->subject,
            'url' => $this->url(),
        ];
    }

    private function email(object $notifiable): RenderedEmail
    {
        $notice = $this->notice();
        $numbers = $notice->invoice_numbers;

        return app(EmailTemplates::class)->render($notice->step->template(), [
            'recipient.name' => $notifiable instanceof User ? $notifiable->name : null,
            'organization.name' => $this->organization()?->name,
            'invoice.numbers' => implode(', ', $numbers),
            'invoice.count' => (string) count($numbers),
            'amount.overdue' => Money::kobo($notice->overdue_kobo)->format(),
            'suspension.date' => $notice->next_step_on?->format('j F Y'),
            'closing.date' => $notice->next_step_on?->format('j F Y'),
            'days.left' => $notice->next_step_on !== null
                ? trans_choice(':count day|:count days', (int) $notice->notice_date->diffInDays($notice->next_step_on), ['count' => (int) $notice->notice_date->diffInDays($notice->next_step_on)])
                : null,
        ]);
    }

    /** The notice as written, read in its organization. */
    private function notice(): DunningNotice
    {
        $organization = $this->organization() ?? Organization::query()->findOrFail($this->organizationId);

        return app(OrganizationContext::class)->run($organization, fn (): DunningNotice => DunningNotice::query()->findOrFail($this->noticeId));
    }

    private function url(): ?string
    {
        $organization = $this->organization();

        return $organization !== null && Route::has('workspace.settings.subscription')
            ? route('workspace.settings.subscription', ['organization' => $organization->slug])
            : null;
    }

    private function organization(): ?Organization
    {
        return Organization::query()->find($this->organizationId);
    }
}
