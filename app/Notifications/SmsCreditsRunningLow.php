<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Mail\Templates\BrandedMail;
use App\Mail\Templates\EmailBrand;
use App\Mail\Templates\EmailTemplates;
use App\Mail\Templates\RenderedEmail;
use App\Models\Organization;
use App\Models\User;
use App\Sms\Credits\SmsCredits;
use App\Sms\Credits\SmsCreditTemplates;
use App\Theme\ThemeContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * To an organization's owners and administrators when its SMS credits fall
 * to 20 % of the month's allowance, or reach zero (P05-03; sent at most
 * once a month per threshold by App\Sms\Credits\SmsCredits). In the app and
 * by email (when the account has an address), in GoProperty's branding
 * (the words are the `sms_credits.low` / `sms_credits.empty` templates),
 * linked to Settings → SMS credits. Queued on `notifications` after the
 * commit of the SMS charge that crossed the threshold.
 */
final class SmsCreditsRunningLow extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $organizationId,
        public readonly bool $empty,
        public readonly int $balance,
        public readonly int $allowance,
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
        return [
            'event' => $this->empty ? 'sms_credits.empty' : 'sms_credits.low',
            'organization_id' => $this->organizationId,
            'organization' => $this->organization()?->name,
            'message' => $this->email($notifiable)->subject,
            'url' => $this->url(),
        ];
    }

    private function email(object $notifiable): RenderedEmail
    {
        return app(EmailTemplates::class)->render($this->empty ? SmsCreditTemplates::EMPTY : SmsCreditTemplates::LOW, [
            'recipient.name' => $notifiable instanceof User ? $notifiable->name : null,
            'organization.name' => $this->organization()?->name,
            'credits.balance' => number_format($this->balance),
            'credits.allowance' => number_format($this->allowance),
            'credits.reset_date' => SmsCredits::nextReset()->format('j F Y'),
        ]);
    }

    private function url(): ?string
    {
        $organization = $this->organization();

        return $organization !== null && Route::has('workspace.settings.sms-credits')
            ? route('workspace.settings.sms-credits', ['organization' => $organization->slug])
            : null;
    }

    private function organization(): ?Organization
    {
        return Organization::query()->find($this->organizationId);
    }
}
