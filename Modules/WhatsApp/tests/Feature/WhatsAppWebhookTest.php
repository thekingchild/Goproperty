<?php

declare(strict_types=1);

use App\Models\NotificationDelivery;
use App\Sms\DeliveryStatus;
use App\WhatsApp\SendWhatsAppMessage;
use App\WhatsApp\WhatsAppMessage;
use Illuminate\Support\Facades\Queue;
use Modules\WhatsApp\Connections\WhatsAppConnections;
use Modules\WhatsApp\Jobs\ProcessWhatsAppStatuses;
use Modules\WhatsApp\Mappings\ChannelMappings;

require_once __DIR__.'/whatsapp-world.php';

/*
 * P05-06: Meta's webhook for the organization's own WhatsApp,
 * /webhooks/whatsapp/{ulid} (ARCHITECTURE.md § 6.7). Acceptance: "a status
 * webhook with a bad signature is rejected". Also: the GET verify-token
 * handshake, and statuses that move a delivery forward only, once.
 */

beforeEach(function () {
    $this->meta = whatsappWorld();
    $this->connection = connectWhatsApp($this->acme, $this->owner);
    $this->otherConnection = connectWhatsApp($this->other, $this->otherOwner, '2000000002', '2200000002', WA_OTHER_TOKEN, WA_OTHER_SECRET);
    syncWhatsAppTemplates($this->acme);

    // One invitation-like message sent through the cloud driver: Meta took it as wamid.TEST1.
    inOrganization($this->acme, fn () => app(ChannelMappings::class)->save($this->owner, 'tenant_invitation', whatsappTemplate($this->acme, 'tenant_invite')->id, [
        'body.1' => 'tenant.first_name', 'body.2' => 'organization.name', 'body.3' => 'invitation_link',
    ], ['whatsapp'], true));
    SendWhatsAppMessage::dispatch($this->acme->id, new WhatsAppMessage('+2348031112222', 'tenant_invitation', 'Hi', [
        'tenant.first_name' => 'Kemi', 'organization.name' => 'Adeyemi Realty Ltd', 'invitation_link' => 'https://example.test/i/abc',
    ]));
    $this->delivery = deliveriesOf($this->acme)->sole();
});

function waDelivery(NotificationDelivery $delivery, $test): NotificationDelivery
{
    return inOrganization($test->acme, fn (): NotificationDelivery => NotificationDelivery::query()->findOrFail($delivery->id));
}

describe('acceptance: a status webhook with a bad signature is rejected', function () {
    it('refuses a call signed with the wrong secret, another organization\'s, or not signed, and records nothing', function () {
        $delivered = metaStatuses($this->connection, [metaStatus('wamid.TEST1', 'delivered')]);

        whatsappWebhook($this->connection, $delivered, 'not-the-app-secret')->assertStatus(401);
        whatsappWebhook($this->connection, $delivered, WA_OTHER_SECRET)->assertStatus(401);
        whatsappWebhook($this->connection, $delivered, null)->assertStatus(401);

        // A body changed after signing.
        $raw = (string) json_encode($delivered);
        test()->call('POST', '/webhooks/whatsapp/'.$this->connection->webhook_ulid, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, WA_SECRET),
        ], str_replace('delivered', 'read', $raw))->assertStatus(401);

        expect(waDelivery($this->delivery, $this))->status->toBe(DeliveryStatus::Sent)
            ->provider_status->toBe('accepted')
            ->delivered_at->toBeNull()
            ->and(whatsappConnectionOf($this->acme)->last_event_at)->toBeNull();
    });

    it('accepts the same call signed with the organization\'s app secret', function () {
        whatsappWebhook($this->connection, metaStatuses($this->connection, [metaStatus('wamid.TEST1', 'delivered')]), WA_SECRET)
            ->assertOk()->assertJson(['received' => true]);

        expect(waDelivery($this->delivery, $this))->status->toBe(DeliveryStatus::Delivered)
            ->delivered_at->not->toBeNull()
            ->and(whatsappConnectionOf($this->acme)->last_event_at)->not->toBeNull();
    });
});

it('answers Meta\'s verify handshake only with the organization\'s verify token', function () {
    $url = '/webhooks/whatsapp/'.$this->connection->webhook_ulid;
    $token = whatsappConnectionOf($this->acme)->webhook_verify_token;

    $this->get($url.'?hub.mode=subscribe&hub.verify_token='.$token.'&hub.challenge=1158201444')
        ->assertOk()->assertSeeText('1158201444');
    $this->get($url.'?hub.mode=subscribe&hub.verify_token='.whatsappConnectionOf($this->other)->webhook_verify_token.'&hub.challenge=1158201444')->assertForbidden();
    $this->get($url.'?hub.mode=subscribe&hub.challenge=1158201444')->assertForbidden();
    $this->get('/webhooks/whatsapp/01hzzzzzzzzzzzzzzzzzzzzzzz?hub.mode=subscribe&hub.verify_token='.$token.'&hub.challenge=1')->assertNotFound();
});

it('refuses calls to a disconnected organization\'s address (404) and too large a body (413)', function () {
    whatsappWebhook($this->connection, str_repeat('x', 262_145), WA_SECRET)->assertStatus(413);

    inOrganization($this->acme, fn () => app(WhatsAppConnections::class)->disconnect($this->owner));

    whatsappWebhook($this->connection, metaStatuses($this->connection, [metaStatus('wamid.TEST1', 'delivered')]), WA_SECRET)->assertNotFound();
});

it('moves a delivery forward only — sent, delivered, read — whatever order Meta sends them in, and replays change nothing', function () {
    $post = fn (array $statuses) => whatsappWebhook($this->connection, metaStatuses($this->connection, $statuses), WA_SECRET)->assertOk();

    $post([metaStatus('wamid.TEST1', 'read', 1798189300)]);
    expect(waDelivery($this->delivery, $this))->status->toBe(DeliveryStatus::Delivered)->provider_status->toBe('read');

    // Late "delivered" and "sent", and a "failed" after it was read: nothing moves back.
    $post([metaStatus('wamid.TEST1', 'delivered', 1798189200), metaStatus('wamid.TEST1', 'sent', 1798189100), metaStatus('wamid.TEST1', 'failed')]);
    $post([metaStatus('wamid.TEST1', 'read', 1798189300)]);

    expect(waDelivery($this->delivery, $this))->status->toBe(DeliveryStatus::Delivered)
        ->provider_status->toBe('read')
        ->failed_at->toBeNull()
        ->delivered_at->getTimestamp()->toBe(1798189300);
});

it('applies only statuses for this organization\'s own number and messages', function () {
    // Another organization's message id, and a status for another phone number id, sent to acme's address.
    $other = metaStatuses($this->otherConnection, [metaStatus('wamid.TEST1', 'delivered')]);
    whatsappWebhook($this->connection, $other, WA_SECRET)->assertOk();
    whatsappWebhook($this->connection, metaStatuses($this->connection, [metaStatus('wamid.UNKNOWN', 'delivered')]), WA_SECRET)->assertOk();

    expect(waDelivery($this->delivery, $this)->status)->toBe(DeliveryStatus::Sent);
});

it('queues the statuses on `notifications` and answers at once', function () {
    Queue::fake();

    whatsappWebhook($this->connection, metaStatuses($this->connection, [metaStatus('wamid.TEST1', 'delivered')]), WA_SECRET)->assertOk();

    Queue::assertPushedOn('notifications', ProcessWhatsAppStatuses::class);
    expect(waDelivery($this->delivery, $this)->status)->toBe(DeliveryStatus::Sent);
});
