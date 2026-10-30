<?php

declare(strict_types=1);

use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\User;
use App\WhatsApp\WhatsAppSender;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Modules\Tenants\Models\TenantProfile;
use Modules\WhatsApp\Connections\WhatsAppConnections;
use Modules\WhatsApp\Enums\OptInSource;
use Modules\WhatsApp\Mappings\ChannelMappings;
use Modules\WhatsApp\Meta\CloudApi;
use Modules\WhatsApp\Meta\HttpCloudApi;
use Modules\WhatsApp\Models\ChannelMapping;
use Modules\WhatsApp\Models\WhatsAppConnection;
use Modules\WhatsApp\Models\WhatsAppTemplate;
use Modules\WhatsApp\OptIns\WhatsAppOptIns;
use Modules\WhatsApp\Sending\CloudWhatsAppSender;
use Modules\WhatsApp\Templates\TemplateSync;
use Tests\Support\LivewireHttp;

/*
 * The WhatsApp module's test world (P05-06), on top of the Reminders
 * module's (reminderWorld(): Adeyemi Realty `acme-homes` and Bello Homes
 * `other-realty`, Kemi with a portal account at Palm Court Flat 3B, Femi
 * with email only, Musa phone-only). Both organizations are on the Growth
 * plan. Meta is FakeMeta behind Http::fake(): no test reaches the network.
 * The `cloud` driver is put in place with the real HTTP client (the
 * provider binds it only in production) so the real requests are checked.
 */

require_once __DIR__.'/../../../Reminders/tests/Feature/reminder-world.php';
require_once __DIR__.'/../../../../tests/Support/impersonation.php';

const WA_TOKEN = 'EAAGacmeToken000000000000000000001';
const WA_SECRET = 'acmeAppSecret0000000000000000001';
const WA_OTHER_TOKEN = 'EAAGotherToken00000000000000000002';
const WA_OTHER_SECRET = 'otherAppSecret000000000000000002';

/**
 * Meta as the tests see it: the tokens it accepts, the templates of each
 * WhatsApp Business Account, how it answers sends, and every request.
 */
final class FakeMeta
{
    /** @var array<string, bool> token => accepted */
    public array $tokens = [WA_TOKEN => true, WA_OTHER_TOKEN => true];

    /** @var array<string, list<array<string, mixed>>> waba id => templates */
    public array $templates = [];

    /** @var array{0: int, 1: int, 2: string}|null [HTTP status, Meta error code, message] for every send */
    public ?array $refuseSends = null;

    public bool $down = false;

    /** @var list<array{method: string, path: string, token: string, body: array<string, mixed>}> */
    public array $requests = [];

    public int $messages = 0;

    public function install(): self
    {
        Http::fake(fn (Request $request) => $this->answer($request));

        return $this;
    }

    /** @return list<array<string, mixed>> the sends' bodies */
    public function sends(): array
    {
        return array_values(array_map(fn (array $request): array => $request['body'], array_filter($this->requests, fn (array $request): bool => str_ends_with($request['path'], '/messages'))));
    }

    public function setStatus(string $waba, string $name, string $status): void
    {
        foreach ($this->templates[$waba] as $index => $template) {
            if ($template['name'] === $name) {
                $this->templates[$waba][$index]['status'] = $status;
            }
        }
    }

    private function answer(Request $request): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $token = trim(str_replace('Bearer', '', $request->header('Authorization')[0] ?? ''));
        $this->requests[] = ['method' => $request->method(), 'path' => $path, 'token' => $token, 'body' => $request->method() === 'POST' ? $request->data() : []];

        if ($this->down) {
            return Http::response(['error' => ['message' => 'Service temporarily unavailable', 'code' => 2]], 503);
        }

        if (! ($this->tokens[$token] ?? false)) {
            return Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'type' => 'OAuthException', 'code' => 190]], 401);
        }

        if (preg_match('#^/v21\.0/(\d+)/message_templates$#', $path, $match) === 1) {
            return Http::response(['data' => $this->templates[$match[1]] ?? [], 'paging' => ['cursors' => ['before' => 'b', 'after' => 'a']]]);
        }

        if (preg_match('#^/v21\.0/(\d+)/messages$#', $path) === 1) {
            if ($this->refuseSends !== null) {
                return Http::response(['error' => ['message' => $this->refuseSends[2], 'code' => $this->refuseSends[1]]], $this->refuseSends[0]);
            }

            $this->messages++;

            return Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => ltrim((string) $request->data()['to'], '+')]], 'messages' => [['id' => 'wamid.TEST'.$this->messages]]]);
        }

        if (preg_match('#^/v21\.0/(\d+)$#', $path, $match) === 1) {
            return Http::response(['display_phone_number' => '+234 801 234 '.substr($match[1], -4), 'verified_name' => 'Adeyemi Realty', 'id' => $match[1]]);
        }

        return Http::response(['error' => ['message' => 'Unknown path '.$path, 'code' => 100]], 400);
    }
}

/** A utility template as Meta describes it. */
function metaTemplate(string $name, string $body, array $extra = [], string $status = 'APPROVED', string $category = 'UTILITY'): array
{
    return [
        'id' => (string) crc32($name),
        'name' => $name,
        'language' => 'en_US',
        'category' => $category,
        'status' => $status,
        'components' => [['type' => 'BODY', 'text' => $body], ...$extra],
    ];
}

/** The rent reminder template: five body variables and a pay button. */
function rentReminderTemplate(string $status = 'APPROVED'): array
{
    return metaTemplate('rent_reminder', 'Hello {{1}}, your rent of {{2}} for {{3}} is due {{4}}. Use reference {{5}} when you pay.', [
        ['type' => 'FOOTER', 'text' => 'Sent by your agency'],
        ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Pay now', 'url' => rtrim((string) config('app.url'), '/').'/pay/{{1}}']]],
    ], $status);
}

/**
 * The world plus WhatsApp: both organizations on Growth, Meta faked, the
 * `cloud` driver in place. Returns the fake Meta.
 */
function whatsappWorld(): FakeMeta
{
    reminderWorld();
    $test = test();
    $test->acme->forceFill(['signup_plan_key' => 'growth'])->save();
    $test->other->forceFill(['signup_plan_key' => 'growth'])->save();

    $meta = (new FakeMeta)->install();
    $meta->templates['1000000001'] = [
        rentReminderTemplate(),
        metaTemplate('tenant_invite', '{{1}}, {{2}} invites you to its tenant portal: {{3}}'),
        metaTemplate('summer_offer', 'Hi {{1}}, enjoy 10% off this summer!', category: 'MARKETING'),
    ];
    $meta->templates['2000000002'] = [metaTemplate('other_rent', 'Rent of {{1}} due.')];

    useCloudWhatsApp();

    return $meta;
}

/** The `cloud` driver with Meta's real HTTP client (behind Http::fake()). */
function useCloudWhatsApp(): void
{
    config(['services.whatsapp.driver' => 'cloud']);
    app()->instance(CloudApi::class, new HttpCloudApi('https://graph.facebook.com', 'v21.0'));
    app()->forgetInstance(CloudWhatsAppSender::class);
    app()->instance(WhatsAppSender::class, app(CloudWhatsAppSender::class));
}

/** Connects the organization's WhatsApp through the service, as its owner does. */
function connectWhatsApp(Organization $organization, User $owner, string $waba = '1000000001', string $phoneId = '1100000001', string $token = WA_TOKEN, string $secret = WA_SECRET): WhatsAppConnection
{
    return inOrganization($organization, fn (): WhatsAppConnection => app(WhatsAppConnections::class)->connect($owner, $waba, $phoneId, $token, $secret));
}

function whatsappConnectionOf(Organization $organization): ?WhatsAppConnection
{
    return inOrganization($organization, fn (): ?WhatsAppConnection => WhatsAppConnection::query()->first());
}

function syncWhatsAppTemplates(Organization $organization): void
{
    inOrganization($organization, fn () => app(TemplateSync::class)->sync(WhatsAppConnection::query()->firstOrFail()));
}

function whatsappTemplate(Organization $organization, string $name): WhatsAppTemplate
{
    return inOrganization($organization, fn (): WhatsAppTemplate => WhatsAppTemplate::query()->where('name', $name)->firstOrFail());
}

/** The rent reminder template mapped to an event (rent due by default), switched on. */
function mapRentReminder(Organization $organization, User $by, string $event = 'reminder.rent_due.t7', array $order = ['whatsapp', 'sms'], bool $siblings = false): ChannelMapping
{
    $template = whatsappTemplate($organization, 'rent_reminder');

    return inOrganization($organization, function () use ($by, $event, $order, $template, $siblings): ChannelMapping {
        $mappings = app(ChannelMappings::class);
        $mapping = $mappings->save($by, $event, $template->id, [
            'body.1' => 'recipient.name',
            'body.2' => 'bill.owed',
            'body.3' => 'unit.place',
            'body.4' => 'bill.due_short',
            'body.5' => 'bill.payment_reference',
            'button.0' => 'pay_link',
        ], $order, true);

        if ($siblings) {
            $mappings->copyToSiblings($by, $event);
        }

        return $mapping;
    });
}

function mappingOf(Organization $organization, string $event): ?ChannelMapping
{
    return inOrganization($organization, fn (): ?ChannelMapping => ChannelMapping::query()->where('event_key', $event)->first());
}

/** The tenant agrees to WhatsApp messages (as the agency records it in person). */
function optInToWhatsApp(Organization $organization, TenantProfile $tenant, ?User $by = null, OptInSource $source = OptInSource::AgentRecorded): void
{
    inOrganization($organization, fn () => app(WhatsAppOptIns::class)->optIn($tenant->id, $source, $by));
}

function optOutOfWhatsApp(Organization $organization, TenantProfile $tenant): void
{
    inOrganization($organization, fn () => app(WhatsAppOptIns::class)->optOut($tenant->id, OptInSource::Portal, null));
}

/** Every delivery of a channel, oldest first. */
function deliveriesOf(Organization $organization, string $channel = 'whatsapp'): Collection
{
    return inOrganization($organization, fn () => NotificationDelivery::query()->where('channel', $channel)->orderBy('id')->get());
}

/** POSTs a status call as Meta does: the raw JSON body, signed with HMAC-SHA256 of exactly those bytes (null: unsigned). */
function whatsappWebhook(WhatsAppConnection $connection, array|string $payload, ?string $secret): TestResponse
{
    $raw = is_string($payload) ? $payload : (string) json_encode($payload);
    $server = ['CONTENT_TYPE' => 'application/json'];

    if ($secret !== null) {
        $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $raw, $secret);
    }

    return test()->call('POST', '/webhooks/whatsapp/'.$connection->webhook_ulid, [], [], [], $server, $raw);
}

/** Meta's status call for messages of the connection's number. */
function metaStatuses(WhatsAppConnection $connection, array $statuses): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => $connection->waba_id,
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['display_phone_number' => '2348012340001', 'phone_number_id' => $connection->phone_number_id],
                    'statuses' => $statuses,
                ],
            ]],
        ]],
    ];
}

function metaStatus(string $id, string $status, int $timestamp = 1798189200, array $extra = []): array
{
    return ['id' => $id, 'status' => $status, 'timestamp' => (string) $timestamp, 'recipient_id' => '2348031112222', ...$extra];
}

/** The scheduled `whatsapp-templates-sync` event, checked against its cron line. */
function whatsappSyncScheduledEvent(): CallbackEvent
{
    $event = collect(app(Schedule::class)->events())->first(fn (ScheduledEvent $event): bool => $event->description === 'whatsapp-templates-sync');

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->expression)->toBe('20 4 * * *');

    return $event;
}

/** A WhatsApp settings page's Livewire snapshot, as $user (a fresh request). */
function whatsappPageSnapshot(User $user, string $path = '', string $slug = 'acme-homes'): string
{
    freshRequest();
    $snapshot = LivewireHttp::snapshot(test()->actingAs($user)->get("/o/{$slug}/settings/operations/whatsapp{$path}")->assertOk());
    freshRequest();
    test()->actingAs($user);

    return $snapshot;
}

/** A Livewire update on a WhatsApp page, as a fresh request by the same user. */
function whatsappPageUpdate(User $user, string $snapshot, array $updates = [], array $calls = []): TestResponse
{
    freshRequest();
    test()->actingAs($user);

    return LivewireHttp::update(test(), $snapshot, $updates, $calls);
}

/** The snapshot of a named component on a page (several components on one page). */
function waComponentSnapshot(TestResponse $page, string $name): string
{
    preg_match_all('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $matches);

    foreach ($matches[1] as $raw) {
        $snapshot = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $name) {
            return $snapshot;
        }
    }

    throw new LogicException("No {$name} on the page.");
}
