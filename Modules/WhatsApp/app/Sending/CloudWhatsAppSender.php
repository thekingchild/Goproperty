<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Sending;

use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Sms\DeliveryStatus;
use App\Tenancy\OrganizationContext;
use App\WhatsApp\WhatsAppMessage;
use App\WhatsApp\WhatsAppRefused;
use App\WhatsApp\WhatsAppSender;
use App\WhatsApp\WhatsAppSent;
use App\WhatsApp\WhatsAppUnavailable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\WhatsApp\Connections\WhatsAppConnections;
use Modules\WhatsApp\Mappings\ChannelMappings;
use Modules\WhatsApp\Meta\CloudApi;
use Modules\WhatsApp\Meta\MetaRefused;
use Modules\WhatsApp\Meta\MetaUnavailable;
use Modules\WhatsApp\Meta\MetaUnreachable;
use Modules\WhatsApp\Models\ChannelMapping;
use Modules\WhatsApp\Models\WhatsAppConnection;
use Modules\WhatsApp\Models\WhatsAppTemplate;
use Modules\WhatsApp\OptIns\WhatsAppOptIns;
use Modules\WhatsApp\Templates\TemplateSync;

/**
 * The `cloud` WhatsApp driver (P05-06; ARCHITECTURE.md § 6.7): the
 * organization's own connection, the approved template mapped to the
 * message's event, its variables filled from the message's placeholder
 * values, sent through Meta's Cloud API (CloudApi: Meta only in production,
 * the log stand-in elsewhere).
 *
 * - available(): the plan allows WhatsApp, the connection works, and (with
 *   an event) an active mapping uses an approved utility template.
 * - send(): refuses (WhatsAppUnavailable, never retried) a number that
 *   opted out, an event without a usable template, or a value missing for
 *   one of its variables. Otherwise it writes the `notification_deliveries`
 *   row (channel `whatsapp`, unique dedupe key), claims it (`sending`) and
 *   calls Meta: taken → `sent` with Meta's message id and WhatsAppSent;
 *   refused → `failed` (WhatsAppRefused; a refused token also marks the
 *   connection failing); 5xx → back to `queued` and the job retries; the
 *   call broke off → `failed`, never sent again (at most once: a row found
 *   still `sending` is failed too). Status webhooks move it on from `sent`.
 */
final readonly class CloudWhatsAppSender implements WhatsAppSender
{
    public function __construct(
        private OrganizationContext $context,
        private WhatsAppConnections $connections,
        private ChannelMappings $mappings,
        private WhatsAppOptIns $optIns,
        private CloudApi $meta,
    ) {}

    public function available(Organization $organization, ?string $event = null): bool
    {
        return $this->context->run($organization, function () use ($organization, $event): bool {
            if ($this->connections->working($organization) === null) {
                return false;
            }

            return $event === null || $this->usableMapping($event) !== null;
        });
    }

    public function send(Organization $organization, WhatsAppMessage $message): void
    {
        $this->context->run($organization, function () use ($organization, $message): void {
            $connection = $this->connections->working($organization) ?? throw new WhatsAppUnavailable('WhatsApp is not connected for this organization.');

            if (preg_match('/^\+[1-9]\d{7,14}$/', $message->to) !== 1) {
                throw new WhatsAppUnavailable('A WhatsApp message goes to an E.164 number.');
            }

            if ($this->optIns->optedOut($message->to)) {
                throw new WhatsAppUnavailable('The tenant opted out of WhatsApp messages.');
            }

            $mapping = $this->usableMapping($message->event) ?? throw new WhatsAppUnavailable('No approved WhatsApp template is set for '.$message->event.'.');
            $template = $mapping->template;
            assert($template instanceof WhatsAppTemplate);
            $layout = $template->layout();
            $values = ChannelMappings::fill($layout, $mapping->variable_map ?? [], $message->variables)
                ?? throw new WhatsAppUnavailable('A value the template needs is missing from this message.');

            $delivery = $this->claim($this->delivery($message, $template, $layout->render($values)));

            if ($delivery === null) {
                return;
            }

            $this->deliver($connection, $delivery, $template, $layout->components($values), $message);
        });
    }

    public function gaveUp(Organization $organization, WhatsAppMessage $message): ?int
    {
        $key = $message->deliveryKey();

        if ($key === null) {
            return null;
        }

        return $this->context->run($organization, static fn (): ?int => DB::transaction(static function () use ($key): ?int {
            $delivery = NotificationDelivery::query()->where('dedupe_key', $key)->lockForUpdate()->first();

            if ($delivery === null || ! in_array($delivery->status, [DeliveryStatus::Queued, DeliveryStatus::Sending], true)) {
                return null;
            }

            $delivery->forceFill([
                'status' => DeliveryStatus::Failed,
                'failed_at' => now(),
                'error' => $delivery->error ?? 'Meta could not be reached.',
            ])->save();

            return $delivery->id;
        }, 3));
    }

    /** @param  list<array<string, mixed>>  $components */
    private function deliver(WhatsAppConnection $connection, NotificationDelivery $delivery, WhatsAppTemplate $template, array $components, WhatsAppMessage $message): void
    {
        try {
            $messageId = $this->meta->send($connection, $delivery->recipient_contact, $template->name, $template->language, $components);
        } catch (MetaRefused $refused) {
            $this->fail($delivery, $refused->getMessage());

            if ($refused->tokenRefused) {
                $this->connections->markFailing($connection, $refused->getMessage());
            }

            throw new WhatsAppRefused($refused->getMessage(), $delivery->id);
        } catch (MetaUnreachable $unreachable) {
            $this->fail($delivery, 'The call to Meta broke off, so it is not known whether Meta took the message. It is not sent again.');

            throw new WhatsAppUnavailable($unreachable->getMessage(), $delivery->id);
        } catch (MetaUnavailable $unavailable) {
            // Meta did not take it: tried again by the job's next attempt.
            $delivery->forceFill(['status' => DeliveryStatus::Queued, 'error' => mb_substr($unavailable->getMessage(), 0, 500)])->save();

            throw $unavailable;
        }

        $delivery->forceFill([
            'status' => DeliveryStatus::Sent,
            'provider_message_id' => $messageId,
            'provider_status' => 'accepted',
            'error' => null,
            'sent_at' => now(),
        ])->save();

        WhatsAppSent::dispatch($delivery->organization_id, $delivery->id, $message->sourceType, $message->sourceId);
    }

    /** The event's active mapping when its template can be sent, else null. */
    private function usableMapping(string $event): ?ChannelMapping
    {
        $mapping = $this->mappings->for($event);

        return $mapping instanceof ChannelMapping && $mapping->active && TemplateSync::unusable($mapping) === null ? $mapping : null;
    }

    /** The message's delivery row, written once (its dedupe key). */
    private function delivery(WhatsAppMessage $message, WhatsAppTemplate $template, string $body): NotificationDelivery
    {
        $key = $message->deliveryKey() ?? throw new WhatsAppUnavailable('A WhatsApp message needs a dedupe key.');
        $existing = NotificationDelivery::query()->where('dedupe_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        $delivery = new NotificationDelivery;
        $delivery->forceFill([
            'notification_type' => mb_substr($message->event, 0, 60),
            'channel' => 'whatsapp',
            'recipient_user_id' => $message->userId,
            'recipient_contact' => $message->to,
            'subject' => mb_substr($template->label(), 0, 200),
            'body' => $body,
            'status' => DeliveryStatus::Queued,
            'provider' => $this->meta->name(),
            'source_type' => $message->sourceType,
            'source_id' => $message->sourceId,
            'dedupe_key' => $key,
            'queued_at' => now(),
        ]);

        try {
            $delivery->save();
        } catch (UniqueConstraintViolationException) {
            return NotificationDelivery::query()->where('dedupe_key', $key)->firstOrFail();
        }

        return $delivery;
    }

    /**
     * The delivery claimed for this attempt (`sending`), or null when it
     * went already.
     *
     * @throws WhatsAppUnavailable when an earlier attempt stopped mid-call (marked failed, never sent again)
     */
    private function claim(NotificationDelivery $delivery): ?NotificationDelivery
    {
        [$claimed, $stale] = DB::transaction(static function () use ($delivery): array {
            $locked = NotificationDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === DeliveryStatus::Sending) {
                $locked->forceFill([
                    'status' => DeliveryStatus::Failed,
                    'failed_at' => now(),
                    'error' => 'An earlier attempt stopped while sending, so it is not known whether Meta took the message. It is not sent again.',
                ])->save();

                return [null, true];
            }

            if ($locked->status !== DeliveryStatus::Queued) {
                return [null, false];
            }

            $locked->forceFill(['status' => DeliveryStatus::Sending, 'attempts' => $locked->attempts + 1])->save();

            return [$locked, false];
        }, 3);

        if ($stale) {
            throw new WhatsAppUnavailable('An earlier attempt stopped while sending.', $delivery->id);
        }

        return $claimed;
    }

    private function fail(NotificationDelivery $delivery, string $error): void
    {
        $delivery->forceFill(['status' => DeliveryStatus::Failed, 'failed_at' => now(), 'error' => mb_substr($error, 0, 500)])->save();
    }
}
