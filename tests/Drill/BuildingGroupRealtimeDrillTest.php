<?php

declare(strict_types=1);

use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\BuildingGroup\Enums\AuthorKind;
use Modules\BuildingGroup\Enums\Reaction;
use Tests\Support\WebSocketClient;

require_once __DIR__.'/../../Modules/BuildingGroup/tests/Feature/group-world.php';
require_once __DIR__.'/../Support/realtime.php';

/*
 * P07-03 acceptance, VPS profile: "two browser sessions see a post,
 * comment or reaction within 1 s via Reverb". A real Reverb server
 * (`php artisan reverb:start`) runs on a free port; Tunde's "browser" is a
 * WebSocket client speaking the Pusher protocol, as pusher-js does: it
 * connects, gets its channel authorized by the app (/broadcasting/auth,
 * which checks he may open the group) and subscribes to the group's private
 * channel. Kemi then posts, comments and reacts through the writer (her
 * session), and each nudge must reach Tunde within a second. A forged
 * subscription is refused by Reverb itself. The page's reaction to a nudge
 * is refreshFeed, proven in Modules/BuildingGroup/tests/Feature/LiveUpdatesTest.php.
 */

beforeEach(function () {
    // DatabaseTruncation empties every table between drills, the audit chain's head row too (the migration seeds it).
    DB::table('audit_chain_head')->insertOrIgnore(['id' => 1, 'last_hash' => null, 'updated_at' => now()]);
    Storage::fake('media');
    groupWorld();

    $this->port = freeTcpPort();
    $this->reverb = startReverb($this->port);
    useReverb(port: $this->port);
});

afterEach(function () {
    $this->browser?->close();
    $this->reverb?->stop();
    app(OrganizationContext::class)->clear();
    $this->truncateTablesForAllConnections();
    DB::table('audit_chain_head')->insertOrIgnore(['id' => 1, 'last_hash' => null, 'updated_at' => now()]);
});

/** A browser's connection to Reverb: [client, socket id]. */
function reverbBrowser(int $port): array
{
    $client = WebSocketClient::connect('127.0.0.1', $port, '/app/'.REVERB_TEST_APP['key'].'?protocol=7&client=js&version=8.4.0&flash=false', 'http://127.0.0.1');
    $hello = $client->await('pusher:connection_established', 5) ?? throw new RuntimeException('Reverb did not greet the browser.');
    $socket = json_decode((string) $hello['data'], true)['socket_id'] ?? null;

    return [$client, is_string($socket) ? $socket : throw new RuntimeException('No socket id from Reverb.')];
}

/** Seconds until the group's `feed.changed` nudge reaches the browser after $write, or null. */
function secondsToHear(WebSocketClient $browser, string $channel, Closure $write): ?float
{
    $start = microtime(true);
    $write();
    $nudge = $browser->await('feed.changed', 3);

    if ($nudge === null || ($nudge['channel'] ?? null) !== $channel) {
        return null;
    }

    expect(array_keys(json_decode((string) $nudge['data'], true)))->toBe(['version']);

    return microtime(true) - $start;
}

it('acceptance (VPS): a member\'s browser hears a post, a comment and a reaction within 1 s through Reverb', function () {
    $channel = "private-group.{$this->palmGroup->id}";
    [$this->browser, $socket] = reverbBrowser($this->port);

    // The app authorizes Tunde's browser for the group's channel, as Echo asks it to.
    groupsFreshRequest();
    $auth = $this->actingAs($this->tunde)->post('/broadcasting/auth', ['socket_id' => $socket, 'channel_name' => $channel])->assertOk()->json('auth');
    $this->browser->send(['event' => 'pusher:subscribe', 'data' => ['channel' => $channel, 'auth' => $auth]]);
    expect($this->browser->await('pusher_internal:subscription_succeeded', 5))->not->toBeNull();

    $post = null;
    $heard = [
        'post' => secondsToHear($this->browser, $channel, function () use (&$post): void {
            $post = postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'Water tanks are being cleaned today.');
        }),
        'comment' => secondsToHear($this->browser, $channel, fn () => commentIn($this->acme, $this->kemi, AuthorKind::Tenant, $post, 'Until 4 pm.')),
        'reaction' => secondsToHear($this->browser, $channel, fn () => reactIn($this->acme, $this->kemi, AuthorKind::Tenant, $post, Reaction::ThumbsUp)),
    ];

    foreach ($heard as $what => $seconds) {
        expect($seconds)->not->toBeNull("no nudge for the {$what}")->toBeLessThan(1.0, "the {$what} took {$seconds} s");
    }

    // Another group's writes are not heard on this channel.
    postIn($this->acme, $this->owner, AuthorKind::Staff, $this->oakGroup, 'Oak Lodge only.');
    expect($this->browser->await('feed.changed', 1))->toBeNull();
});

it('is refused by Reverb when a browser subscribes without the app\'s signature', function () {
    $channel = "private-group.{$this->palmGroup->id}";
    [$this->browser, $socket] = reverbBrowser($this->port);

    // Grace, of another agency, gets no signature from the app ...
    groupsFreshRequest();
    $this->actingAs($this->grace)->post('/broadcasting/auth', ['socket_id' => $socket, 'channel_name' => $channel])->assertForbidden();

    // ... and a made-up one does not get her in.
    $this->browser->send(['event' => 'pusher:subscribe', 'data' => ['channel' => $channel, 'auth' => REVERB_TEST_APP['key'].':'.hash_hmac('sha256', "{$socket}:{$channel}", 'not-the-secret')]]);
    expect($this->browser->await('pusher:error', 5))->not->toBeNull();

    postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'Members only.');
    expect($this->browser->await('feed.changed', 1))->toBeNull();
});
