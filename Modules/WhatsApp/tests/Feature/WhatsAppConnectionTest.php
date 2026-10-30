<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationStatus;
use App\Platform\Impersonation\ImpersonationMode;
use Illuminate\Support\Facades\DB;
use Modules\WhatsApp\Connections\ConnectionRefused;
use Modules\WhatsApp\Meta\CloudApi;
use Modules\WhatsApp\Meta\LogCloudApi;
use Modules\WhatsApp\Models\WhatsAppConnection;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/whatsapp-world.php';

/*
 * P05-06: Settings → WhatsApp, the organization's own WhatsApp Business
 * account (ARCHITECTURE.md § 6.7): connected by the org-owner only, the
 * token and app secret encrypted and never shown back, checked with Meta;
 * Growth plan and above; Meta called only in production.
 */

beforeEach(function () {
    $this->meta = whatsappWorld();
});

it('lets the owner connect from the page: checked with Meta, stored encrypted, never shown back', function () {
    $snapshot = whatsappPageSnapshot($this->owner);
    $response = whatsappPageUpdate($this->owner, $snapshot, [
        'wabaId' => '1000000001', 'phoneNumberId' => '1100000001', 'accessToken' => WA_TOKEN, 'appSecret' => WA_SECRET,
    ], [['connect']])->assertOk();

    $connection = whatsappConnectionOf($this->acme);
    $html = LivewireHttp::html($response);
    expect($connection->active())->toBeTrue()
        ->and($connection->display_phone_number)->toBe('+234 801 234 0001')
        ->and($connection->connected_by_user_id)->toBe($this->owner->id)
        ->and($html)->toContain('Meta accepted the connection')
        ->and($html)->toContain(route('whatsapp.webhook', ['ulid' => $connection->webhook_ulid]))
        ->and($html)->toContain('EAA····0001')
        ->and($html)->not->toContain(WA_TOKEN)
        ->and($html)->not->toContain(WA_SECRET)
        ->and(LivewireHttp::next($response))->not->toContain(WA_TOKEN)
        ->and(LivewireHttp::next($response))->not->toContain(WA_SECRET);

    // Encrypted at rest, never in JSON.
    $row = DB::table('organization_whatsapp_connections')->where('id', $connection->id)->first();
    expect($row->access_token)->not->toContain(WA_TOKEN)
        ->and($row->app_secret)->not->toContain(WA_SECRET)
        ->and(json_encode($connection))->not->toContain(WA_TOKEN)
        ->and($this->meta->requests[0]['path'])->toBe('/v21.0/1100000001')
        ->and($this->meta->requests[0]['token'])->toBe(WA_TOKEN);

    // The page as it opens again: masked token, verify token for the owner.
    $page = $this->actingAs($this->owner)->get('/o/acme-homes/settings/operations/whatsapp')->assertOk();
    $page->assertSee('data-test="verify-token"', false)->assertSee($connection->webhook_verify_token)->assertDontSee(WA_TOKEN)->assertDontSee(WA_SECRET);
});

it('refuses a token Meta does not accept, and saves nothing', function () {
    expect(fn () => connectWhatsApp($this->acme, $this->owner, token: 'EAAGunknownToken00000000000000000009'))
        ->toThrow(ConnectionRefused::class, 'Meta did not accept this access token');
    expect(whatsappConnectionOf($this->acme))->toBeNull();
});

it('refuses details of the wrong shape before calling Meta', function (array $details, string $field) {
    [$waba, $phone, $token, $secret] = $details + ['1000000001', '1100000001', WA_TOKEN, WA_SECRET];

    try {
        connectWhatsApp($this->acme, $this->owner, $waba, $phone, $token, $secret);
        $this->fail('Not refused');
    } catch (ConnectionRefused $refused) {
        expect($refused->field)->toBe($field);
    }

    expect($this->meta->requests)->toBe([]);
})->with([
    'account id with letters' => [[0 => 'waba-1'], 'wabaId'],
    'phone number instead of its id' => [[1 => '+2348012345678'], 'phoneNumberId'],
    'short token' => [[2 => 'EAA1'], 'accessToken'],
    'secret with spaces' => [[3 => 'not a secret'], 'appSecret'],
]);

it('keeps the saved token and secret when they are left empty on replacing, and checks again', function () {
    connectWhatsApp($this->acme, $this->owner);
    $replaced = connectWhatsApp($this->acme, $this->owner, phoneId: '1100000009', token: '', secret: '');

    expect($replaced->phone_number_id)->toBe('1100000009')
        ->and($replaced->access_token)->toBe(WA_TOKEN)
        ->and($replaced->app_secret)->toBe(WA_SECRET)
        ->and($replaced->display_phone_number)->toBe('+234 801 234 0009');
});

it('marks the connection failing when a later check is refused, and active again once it passes', function () {
    connectWhatsApp($this->acme, $this->owner);
    $this->meta->tokens[WA_TOKEN] = false;
    whatsappPageUpdate($this->owner, whatsappPageSnapshot($this->owner), [], [['test']])->assertOk()->assertSee('Meta refused the access token');
    expect(whatsappConnectionOf($this->acme)->status->value)->toBe('failing');

    $this->meta->tokens[WA_TOKEN] = true;
    whatsappPageUpdate($this->owner, whatsappPageSnapshot($this->owner), [], [['test']])->assertOk();
    expect(whatsappConnectionOf($this->acme)->status->value)->toBe('active');

    $this->meta->down = true;
    whatsappPageUpdate($this->owner, whatsappPageSnapshot($this->owner), [], [['test']])->assertOk()->assertSee('could not be reached');
    expect(whatsappConnectionOf($this->acme)->status->value)->toBe('active');
});

it('forgets the token and secret on disconnect, keeping the history; a new verify token for the owner', function () {
    connectWhatsApp($this->acme, $this->owner);
    $before = whatsappConnectionOf($this->acme)->webhook_verify_token;
    whatsappPageUpdate($this->owner, whatsappPageSnapshot($this->owner), [], [['newVerifyToken']])->assertOk();
    expect(whatsappConnectionOf($this->acme)->webhook_verify_token)->not->toBe($before);

    whatsappPageUpdate($this->owner, whatsappPageSnapshot($this->owner), [], [['disconnect']])->assertOk();

    $connection = whatsappConnectionOf($this->acme);
    expect($connection->status->value)->toBe('disconnected')
        ->and($connection->access_token)->toBeNull()
        ->and($connection->app_secret)->toBeNull()
        ->and(AuditEntry::query()->where('log_name', 'whatsapp')->orderBy('id')->pluck('event')->all())
        ->toBe(['whatsapp.connected', 'whatsapp.verify_token_changed', 'whatsapp.disconnected']);
});

it('lets administrators see the connection and sync, but only owners change the credentials; agents see nothing', function () {
    connectWhatsApp($this->acme, $this->owner);

    $page = $this->actingAs($this->admin)->get('/o/acme-homes/settings/operations/whatsapp')->assertOk();
    $page->assertSee('data-test="sync-templates"', false)
        ->assertDontSee('data-test="credentials-form"', false)
        ->assertDontSee('data-test="verify-token"', false)
        ->assertDontSee('EAA····');

    whatsappPageUpdate($this->admin, whatsappPageSnapshot($this->admin), ['wabaId' => '1000000001', 'phoneNumberId' => '1100000002', 'accessToken' => WA_TOKEN, 'appSecret' => WA_SECRET], [['connect']])->assertForbidden();
    whatsappPageUpdate($this->admin, whatsappPageSnapshot($this->admin), [], [['disconnect']])->assertForbidden();
    expect(whatsappConnectionOf($this->acme)->phone_number_id)->toBe('1100000001');

    freshRequest();
    $this->actingAs($this->agent)->get('/o/acme-homes/settings/operations/whatsapp')->assertForbidden();
    $this->actingAs($this->agent)->get('/o/acme-homes/settings/operations/whatsapp/messages')->assertForbidden();
});

it('never shows or changes another organization\'s connection', function () {
    connectWhatsApp($this->other, $this->otherOwner, '2000000002', '2200000002', WA_OTHER_TOKEN, WA_OTHER_SECRET);

    $this->actingAs($this->owner)->get('/o/acme-homes/settings/operations/whatsapp')->assertOk()->assertSee('data-test="not-connected"', false)->assertDontSee('2200000002');
    $this->actingAs($this->owner)->get('/o/other-realty/settings/operations/whatsapp')->assertNotFound();
});

it('never shows the credentials to GoProperty staff impersonating the owner, and refuses every change', function (ImpersonationMode $mode) {
    seedStaffRoles();
    connectWhatsApp($this->acme, $this->owner);
    $connection = whatsappConnectionOf($this->acme);
    $snapshot = whatsappPageSnapshot($this->owner);

    enterSupport(startSupport(staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']), $this->owner, $this->acme, $mode))->assertRedirect('/o/acme-homes');

    $page = asSupport()->get('/o/acme-homes/settings/operations/whatsapp')->assertOk();
    $page->assertDontSee('data-test="credentials-form"', false)->assertDontSee($connection->webhook_verify_token)->assertDontSee('EAA····');

    foreach ([['test'], ['newVerifyToken'], ['disconnect']] as $call) {
        freshRequest();
        LivewireHttp::update($this, $snapshot, [], [$call])->assertForbidden();
    }
    freshRequest();
    LivewireHttp::update($this, $snapshot, ['wabaId' => '1000000001', 'phoneNumberId' => '1100000009', 'accessToken' => WA_TOKEN, 'appSecret' => WA_SECRET], [['connect']])->assertForbidden();

    $stored = whatsappConnectionOf($this->acme);
    expect($stored->phone_number_id)->toBe('1100000001')
        ->and($stored->status->value)->toBe('active')
        ->and($stored->webhook_verify_token)->toBe($connection->webhook_verify_token);
})->with([
    'read-only' => ImpersonationMode::ReadOnly,
    'write' => ImpersonationMode::Write,
]);

it('offers no connection on a plan without WhatsApp, and refuses connecting', function () {
    $this->acme->forceFill(['signup_plan_key' => 'starter'])->save();

    $this->actingAs($this->owner)->get('/o/acme-homes/settings/operations/whatsapp')->assertOk()
        ->assertSee('data-test="plan-notice"', false)->assertDontSee('data-test="credentials-form"', false);

    expect(fn () => connectWhatsApp($this->acme, $this->owner))->toThrow(ConnectionRefused::class, 'Growth plan');
});

it('is read-only while the organization is suspended', function () {
    connectWhatsApp($this->acme, $this->owner);
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    $this->actingAs($this->owner)->get('/o/acme-homes/settings/operations/whatsapp')->assertOk()
        ->assertDontSee('data-test="disconnect"', false)->assertDontSee('data-test="sync-templates"', false);
});

it('calls Meta only in production: elsewhere the log stand-in answers', function () {
    app()->forgetInstance(CloudApi::class);
    config(['services.whatsapp.driver' => 'cloud']);

    expect(app(CloudApi::class))->toBeInstanceOf(LogCloudApi::class);

    $connection = new WhatsAppConnection;
    $connection->forceFill(['organization_id' => $this->acme->id, 'phone_number_id' => '1', 'waba_id' => '1', 'access_token' => WA_TOKEN]);
    expect(app(CloudApi::class)->templates($connection))->toBeNull()
        ->and(app(CloudApi::class)->send($connection, '+2348031112222', 'x', 'en', []))->toStartWith('log-')
        ->and($this->meta->requests)->toBe([]);
});
