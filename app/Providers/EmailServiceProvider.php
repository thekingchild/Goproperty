<?php

declare(strict_types=1);

namespace App\Providers;

use App\Mail\Suppression\DropSuppressedRecipients;
use App\Mail\Templates\TemplateCatalog;
use App\Sms\Credits\SmsCreditTemplates;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Email templates and the suppression list (P05-04; PLATFORM_CONSOLE.md
 * § 5): one catalog of templates that Core and modules add to, and the
 * check that keeps every email away from suppressed addresses.
 */
final class EmailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TemplateCatalog::class);

        // Core's own templates: the SMS credit notices (P05-03).
        $this->callAfterResolving(TemplateCatalog::class, static function (TemplateCatalog $catalog): void {
            $catalog->add(...SmsCreditTemplates::definitions());
        });
    }

    public function boot(): void
    {
        Event::listen(NotificationSending::class, [DropSuppressedRecipients::class, 'notification']);
        Event::listen(MessageSending::class, [DropSuppressedRecipients::class, 'message']);
    }
}
