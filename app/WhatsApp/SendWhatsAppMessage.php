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
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

/**
 * Sends one WhatsApp message on the `notifications` queue, after the
 * commit that asked for it (AGENT.md § 4: everything slow is queued). The
 * payload is encrypted: the message may carry a one-time link.
 *
 * The message always has a dedupe key (one is made here when the caller
 * gave none), so the cloud driver writes its delivery row once and its
 * retries reuse it (P05-06). A message that cannot go (WhatsAppUnavailable,
 * Meta's refusal included) is not retried: WhatsAppFailed tells whoever
 * asked for it, once. Meta out of reach is retried after 30 seconds and 2
 * minutes; after the last try the driver marks its delivery row failed
 * (gaveUp()) and WhatsAppFailed goes out.
 */
final class SendWhatsAppMessage implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $timeout = 30;

    public readonly WhatsAppMessage $message;

    public function __construct(
        public readonly int $organizationId,
        #[SensitiveParameter] WhatsAppMessage $message,
    ) {
        $this->message = $message->dedupeKey === null ? $message->withDedupeKey((string) Str::uuid()) : $message;
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    public function handle(WhatsAppSender $sender): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        try {
            $sender->send($organization, $this->message);
        } catch (WhatsAppUnavailable $unavailable) {
            WhatsAppFailed::dispatch($this->organizationId, $unavailable->deliveryId, $this->message->sourceType, $this->message->sourceId, mb_substr($unavailable->getMessage(), 0, 300));
        }
    }

    /** Called by the queue once the last try has failed (Meta out of reach every time). */
    public function failed(?Throwable $exception): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $deliveryId = app(WhatsAppSender::class)->gaveUp($organization, $this->message);

        if ($deliveryId !== null) {
            WhatsAppFailed::dispatch($this->organizationId, $deliveryId, $this->message->sourceType, $this->message->sourceId, 'Meta could not be reached.');
        }
    }
}
