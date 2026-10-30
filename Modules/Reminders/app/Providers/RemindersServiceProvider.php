<?php

declare(strict_types=1);

namespace Modules\Reminders\Providers;

use App\Mail\Templates\TemplateCatalog;
use App\Notifications\Channels\WhatsAppEvents;
use App\Sms\SmsSent;
use App\WhatsApp\WhatsAppFailed;
use App\WhatsApp\WhatsAppSent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Modules\Reminders\Jobs\DispatchReminders;
use Modules\Reminders\Listeners\FallBackFromWhatsApp;
use Modules\Reminders\Listeners\MarkReminderDelivered;
use Modules\Reminders\Listeners\MarkReminderSmsSent;
use Modules\Reminders\Listeners\MarkReminderWhatsAppSent;
use Modules\Reminders\Reminders\ReminderEvents;
use Modules\Reminders\Reminders\ReminderTemplates;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The Reminders module (ARCHITECTURE.md § 6.7, § 8; P05-01): the reminder
 * engine. The base provider loads the module's migrations (`reminders`);
 * this one schedules `reminders-dispatch` hourly at :35 on the
 * `notifications` queue (Schedule::job, ARCHITECTURE.md § 7) and records
 * when each reminder went (Listeners\MarkReminderDelivered). Its emails'
 * words are GoProperty's templates (Reminders\ReminderTemplates, P05-04).
 *
 * It reads bills (Billing's Contracts\RemindableBills), leases (Leasing's
 * LeaseBillingSchedule, EndingLeases and CycleCalculator), tenants
 * (Tenants' TenantDirectory and TenantReach) and buildings (Properties'
 * UnitDirectory and BuildingAccess) through their contracts only; no other
 * module reads its table.
 */
final class RemindersServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Reminders';

    protected string $nameLower = 'reminders';

    public function boot(): void
    {
        parent::boot();

        Event::listen(NotificationSent::class, MarkReminderDelivered::class);
        Event::listen(SmsSent::class, MarkReminderSmsSent::class);
        // WhatsApp (P05-06): marked delivered when Meta took it; the next channel when it did not reach the tenant.
        Event::listen(WhatsAppSent::class, MarkReminderWhatsAppSent::class);
        Event::listen(WhatsAppFailed::class, FallBackFromWhatsApp::class);

        // Each rung of the tenant ladders as a WhatsApp event the agency maps to a template (P05-06).
        $this->callAfterResolving(WhatsAppEvents::class, static function (WhatsAppEvents $events): void {
            $events->add(...ReminderEvents::definitions());
        });

        // Its email templates, edited in the console (P05-04, PLATFORM_CONSOLE.md § 5).
        $this->callAfterResolving(TemplateCatalog::class, static function (TemplateCatalog $catalog): void {
            $catalog->add(...ReminderTemplates::definitions());
        });

        // Plans the day's reminders and sends them from 08:00 Lagos (ARCHITECTURE.md § 7).
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->job(new DispatchReminders, 'notifications')->name('reminders-dispatch')->hourlyAt(35);
        });
    }
}
