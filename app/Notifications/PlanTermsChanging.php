<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Mail\Templates\BrandedMail;
use App\Mail\Templates\EmailBrand;
use App\Mail\Templates\EmailTemplates;
use App\Mail\Templates\RenderedEmail;
use App\Marketing\PlanCatalogue;
use App\Models\Organization;
use App\Models\PlanVersion;
use App\Models\User;
use App\Plans\PlanChanges;
use App\Plans\PlanTemplates;
use App\Tenancy\OrganizationContext;
use App\Theme\ThemeContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;
use Modules\Properties\Contracts\UnitDirectory;

/**
 * The 30 days' notice of a plan change (P06-01a; BILLING_PAYMENTS.md § 1.3)
 * to an organization's owners and administrators, in the app and by email
 * (when the account has an address), in GoProperty's branding (template
 * `plans.terms_changing`), linked to Settings → Subscription. Sent once per
 * organization and version by App\Platform\Plans\SendPlanChangeNotices,
 * queued on `notifications` after the commit of its notice row.
 */
final class PlanTermsChanging extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $planVersionId,
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
            'event' => PlanTemplates::TERMS_CHANGING,
            'organization_id' => $this->organizationId,
            'organization' => $this->organization()?->name,
            'plan_version_id' => $this->planVersionId,
            'message' => $this->email($notifiable)->subject,
            'url' => $this->url(),
        ];
    }

    private function email(object $notifiable): RenderedEmail
    {
        $version = PlanVersion::query()->findOrFail($this->planVersionId);
        $catalogue = app(PlanCatalogue::class);
        $after = $catalogue->withVersion($version);
        $date = CarbonImmutable::parse($version->effective_from->toDateString(), 'Africa/Lagos');
        $before = $catalogue->findById($version->plan_id, $date->subDay()) ?? $after;
        $units = $this->units();

        return app(EmailTemplates::class)->render(PlanTemplates::TERMS_CHANGING, [
            'recipient.name' => $notifiable instanceof User ? $notifiable->name : null,
            'organization.name' => $this->organization()?->name,
            'plan.name' => $after->name,
            'change.date' => $date->format('j F Y'),
            'change.first_bill' => $date->format('j F Y'),
            'change.summary' => implode("\n", PlanChanges::lines($before, $after)),
            'bill.before' => $before->priceFor($units)->format(),
            'bill.after' => $after->priceFor($units)->format(),
        ]);
    }

    /** The organization's billable units today (the Properties module's count). */
    private function units(): int
    {
        $organization = $this->organization();

        if ($organization === null || ! app()->bound(UnitDirectory::class)) {
            return 0;
        }

        return app(OrganizationContext::class)->run($organization, static fn (): int => app(UnitDirectory::class)->countBillable());
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
