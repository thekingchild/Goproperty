<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Compliance\Monitoring\MonitoringRules;
use App\Compliance\Monitoring\TierMonitoringTemplates;
use App\Enums\ComplianceFlagType;
use App\Mail\Templates\BrandedMail;
use App\Mail\Templates\EmailBrand;
use App\Mail\Templates\EmailTemplates;
use App\Mail\Templates\RenderedEmail;
use App\Models\ComplianceFlag;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use App\Theme\ThemeContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * What the Transaction Tier monitoring tells an organization's owners
 * (P06-09; ONBOARDING_COMPLIANCE.md § 5.4–5.5, default 94): approaching or
 * over the yearly limit, a payment over the single-payment limit or at the
 * cash threshold, restricted, restriction lifted. In the app, and by email
 * when the account has an address, in GoProperty's branding (the words
 * are TierMonitoringTemplates), linked to the owner's Transaction Tier page
 * (`/o/{slug}/compliance/tier`, where they apply to upgrade). Queued on
 * `notifications` after the commit of the change that raised it.
 */
final class TierMonitoringNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @param  array<string, string|null>  $values  the template's placeholders but the recipient's name */
    public function __construct(
        public readonly int $organizationId,
        public readonly string $template,
        public readonly array $values,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** The owners' notice for a new flag, or null when § 5.4 tells only the compliance queue. */
    public static function forFlag(Organization $organization, ComplianceFlag $flag): ?self
    {
        $values = ['organization.name' => $organization->name, 'tier.name' => $organization->transaction_tier->label()];
        $amount = static fn (?int $kobo): ?string => $kobo !== null ? Money::kobo($kobo)->format() : null;
        $percent = $flag->details['percent'] ?? null;

        return match ($flag->type) {
            ComplianceFlagType::Volume80 => new self($organization->id, TierMonitoringTemplates::VOLUME_80, [...$values,
                'volume.amount' => $amount($flag->metric_value_kobo),
                'volume.limit' => $amount($flag->limit_kobo),
                'volume.percent' => is_int($percent) ? "{$percent} %" : null,
            ]),
            ComplianceFlagType::ForecastOver => new self($organization->id, TierMonitoringTemplates::FORECAST_OVER, [...$values,
                'forecast.amount' => $amount($flag->metric_value_kobo),
                'volume.limit' => $amount($flag->limit_kobo),
            ]),
            ComplianceFlagType::Volume100 => new self($organization->id, TierMonitoringTemplates::VOLUME_100, [...$values,
                'volume.amount' => $amount($flag->metric_value_kobo),
                'volume.limit' => $amount($flag->limit_kobo),
                'volume.percent' => is_int($percent) ? "{$percent} %" : null,
                'grace.date' => $flag->grace_ends_at?->setTimezone('Africa/Lagos')->format('j F Y'),
                'limit.note' => is_string($flag->details['limit_lowered_on'] ?? null)
                    ? 'GoProperty lowered this limit on '.CarbonImmutable::parse($flag->details['limit_lowered_on'])->format('j F Y').', so you have longer than usual to apply.'
                    : '',
            ]),
            ComplianceFlagType::SingleOver => ($flag->details['review'] ?? false) === true ? null : new self($organization->id, TierMonitoringTemplates::SINGLE_OVER, [...$values,
                'payment.amount' => $amount($flag->metric_value_kobo),
                'payment.limit' => $amount($flag->limit_kobo),
                'payment.date' => self::paidOn($flag),
            ]),
            ComplianceFlagType::CashThreshold => new self($organization->id, TierMonitoringTemplates::CASH_THRESHOLD, [...$values,
                'payment.amount' => $amount($flag->metric_value_kobo),
                'payment.limit' => $amount($flag->limit_kobo),
                'payment.date' => self::paidOn($flag),
            ]),
            default => null,
        };
    }

    public static function restricted(Organization $organization, bool $byStaff): self
    {
        return new self($organization->id, TierMonitoringTemplates::RESTRICTED, [
            'organization.name' => $organization->name,
            'restriction.reason' => $byStaff
                ? "GoProperty's compliance team has restricted {$organization->name}. Contact support to find out what they need."
                : "{$organization->name}'s collections are over its Transaction Tier limit, and the grace period ended without an upgrade application.",
        ]);
    }

    public static function restrictionLifted(Organization $organization): self
    {
        return new self($organization->id, TierMonitoringTemplates::RESTRICTION_LIFTED, ['organization.name' => $organization->name]);
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
            'event' => $this->template,
            'organization_id' => $this->organizationId,
            'organization' => $this->values['organization.name'] ?? null,
            'message' => $this->email($notifiable)->subject,
            'url' => $this->url(),
        ];
    }

    private function email(object $notifiable): RenderedEmail
    {
        return app(EmailTemplates::class)->render($this->template, [
            ...$this->values,
            'recipient.name' => $notifiable instanceof User ? $notifiable->name : null,
        ]);
    }

    private function url(): ?string
    {
        $slug = Organization::query()->whereKey($this->organizationId)->value('slug');

        return is_string($slug) && Route::has('workspace.compliance.tier')
            ? route('workspace.compliance.tier', ['organization' => $slug])
            : null;
    }

    private static function paidOn(ComplianceFlag $flag): ?string
    {
        $day = $flag->details['paid_on'] ?? null;

        return is_string($day) ? CarbonImmutable::parse($day, MonitoringRules::day()->getTimezone())->format('j F Y') : null;
    }
}
