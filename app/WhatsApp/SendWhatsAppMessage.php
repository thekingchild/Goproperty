<?php

declare(strict_types=1);

namespace App\WhatsApp;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SensitiveParameter;

/**
 * Sends one WhatsApp message on the `notifications` queue, after the
 * commit that asked for it (AGENT.md § 4: everything slow is queued). The
 * payload is encrypted: the message may carry a one-time link. A retry
 * sends the same message again; P05-06 adds delivery tracking
 * (`notification_deliveries`).
 */
final class SendWhatsAppMessage implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $timeout = 30;

    public function __construct(
        public readonly int $organizationId,
        #[SensitiveParameter] public readonly WhatsAppMessage $message,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    public function handle(WhatsAppSender $sender): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $sender->send($organization, $this->message);
    }
}
