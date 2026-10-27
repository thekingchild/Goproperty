<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\ChecklistItem;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\OnboardingChecklistItem;
use App\Models\Organization;
use App\Models\User;
use App\Onboarding\OnboardingChecklist;
use App\Platform\Impersonation\ImpersonationMode;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\OnlinePayments\Connections\ConnectionRefused;
use Modules\OnlinePayments\Connections\PaystackConnections;
use Modules\OnlinePayments\Enums\ConnectionStatus;
use Modules\OnlinePayments\Models\PaystackConnection;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../../tests/Support/organization-settings.php';

/*
 * P03-08: Settings → Online payments, the organization's own Paystack
 * account (ONBOARDING_COMPLIANCE.md § 4.2; ARCHITECTURE.md § 10; AGENT.md
 * § 3 rule 9): org-owners only; keys encrypted at rest and never sent back
 * to the browser (masked); "test connection" against Paystack; rotate the
 * webhook address; disconnect; the online-payment cap; never seen or
 * changed by impersonating staff; read-only while suspended.
 */

beforeEach(function () {
    onlinePaymentsWorld($this);
    inOrganization($this->acme, fn () => app(OnboardingChecklist::class)->start());
    [$this->public, $this->secret] = paystackKeysOf('acme-homes');
    $this->paystack->know($this->secret);
});

afterEach(fn () => billsFreshRequest());

describe('connecting', function () {
    it('tries the secret key with Paystack, stores it encrypted, shows both keys masked and never sends the secret back to the browser', function () {
        $snapshot = paystackSettingsSnapshot($this->owner);
        $response = paystackSettingsUpdate($this->owner, $snapshot, ['publicKey' => $this->public, 'secretKey' => $this->secret], [['connect']])->assertOk();

        $raw = DB::table('organization_paystack_connections')->where('organization_id', $this->acme->id)->first();
        $connection = paystackConnectionOf($this->acme);
        $test = $this->paystack->requestsTo('/transaction')[0];

        expect($test['secret'])->toBe($this->secret)
            ->and($test['method'])->toBe('GET')
            ->and($raw->secret_key)->not->toContain($this->secret)
            ->and(Crypt::decryptString($raw->secret_key))->toBe($this->secret)
            ->and($raw->secret_key_last4)->toBe(substr($this->secret, -4))
            ->and($connection->secret_key)->toBe($this->secret)
            ->and($connection->status)->toBe(ConnectionStatus::Active)
            ->and($connection->mode?->value)->toBe('live')
            ->and($connection->webhook_token)->toMatch('/^[a-z0-9]{48}$/')
            ->and($connection->connected_by_user_id)->toBe($this->owner->id)
            ->and($connection->maskedSecretKey())->toBe('sk_live_····'.substr($this->secret, -4))
            ->and($connection->maskedPublicKey())->toBe('pk_live_····'.substr($this->public, -4))
            // Neither the Livewire reply (HTML and snapshot) nor the model's array or JSON carry a key or the token.
            ->and($response->getContent())->not->toContain($this->secret)->not->toContain($this->public)
            ->and($connection->toArray())->not->toHaveKeys(['secret_key', 'public_key', 'secret_key_last4', 'webhook_token'])
            ->and($connection->toJson())->not->toContain($this->secret)->not->toContain($connection->webhook_token);

        $page = test()->actingAs($this->owner)->get('/o/acme-homes/settings/paystack')->assertOk()
            ->assertSee('sk_live_····'.substr($this->secret, -4))
            ->assertSee(url('/webhooks/paystack/'.$connection->webhook_token))
            ->assertSee('Connected');
        expect($page->getContent())->not->toContain($this->secret)->not->toContain($this->public);

        // Audited with the keys masked; ticks the setup checklist's collections item.
        $audit = AuditEntry::query()->where('event', 'paystack.connected')->sole();
        expect($audit->log_name)->toBe('payment_settings')
            ->and($audit->causer_id)->toBe($this->owner->id)
            ->and($audit->properties['secret_key'])->toBe('sk_live_····'.substr($this->secret, -4))
            ->and(AuditEntry::query()->where('properties', 'like', '%'.$this->secret.'%')->exists())->toBeFalse()
            ->and(inOrganization($this->acme, fn () => OnboardingChecklistItem::query()->where('item', ChecklistItem::Collections->value)->sole())->details)->toHaveKey('paystack', true);
    });

    it('refuses a key Paystack does not accept, keys of the wrong shape and mixed modes, saving nothing', function () {
        $snapshot = paystackSettingsSnapshot($this->owner);
        [$public, $unknown] = paystackKeysOf('someone-else');

        $refused = paystackSettingsUpdate($this->owner, $snapshot, ['publicKey' => $public, 'secretKey' => $unknown], [['connect']])->assertOk();
        expect($refused->getContent())->toContain('Paystack did not accept this secret key')->not->toContain($unknown);

        foreach ([
            ['pk_live_short', $this->secret, 'publicKey'],
            [$this->public, 'sk_live_short', 'secretKey'],
            [$this->public, 'not a key at all '.str_repeat('x', 30), 'secretKey'],
            [$this->public, paystackKeysOf('acme-homes', 'test')[1], 'secretKey'],
        ] as [$publicKey, $secretKey, $field]) {
            expect(fn () => inOrganization($this->acme, fn () => app(PaystackConnections::class)->connect($this->owner, $publicKey, $secretKey)))
                ->toThrow(fn (ConnectionRefused $refused) => expect($refused->field)->toBe($field));
        }

        expect(paystackConnectionOf($this->acme))->toBeNull()
            ->and(AuditEntry::query()->where('log_name', 'payment_settings')->where('event', 'like', 'paystack.%')->exists())->toBeFalse();
    });

    it('refuses test keys on a live site, and takes them elsewhere', function () {
        [$public, $secret] = paystackKeysOf('acme-homes', 'test');
        $this->paystack->know($secret, 'test');

        app()->detectEnvironment(fn () => 'production');
        expect(fn () => inOrganization($this->acme, fn () => app(PaystackConnections::class)->connect($this->owner, $public, $secret)))
            ->toThrow(ConnectionRefused::class, 'Use your live keys');
        app()->detectEnvironment(fn () => 'testing');

        $connection = inOrganization($this->acme, fn () => app(PaystackConnections::class)->connect($this->owner, $public, $secret));
        expect($connection->mode?->value)->toBe('test')->and($connection->active())->toBeTrue();
    });

    it('saves the keys untested when Paystack cannot be reached, and tests them again later', function () {
        $this->paystack->down = true;
        $connection = connectPaystack($this->paystack, $this->acme, $this->owner);
        expect($connection->status)->toBe(ConnectionStatus::PendingTest)
            // Not counted as set up until Paystack has accepted the keys.
            ->and(inOrganization($this->acme, fn () => OnboardingChecklistItem::query()->where('item', ChecklistItem::Collections->value)->sole())->details)->not->toHaveKey('paystack');

        $snapshot = paystackSettingsSnapshot($this->owner);
        paystackSettingsUpdate($this->owner, $snapshot, [], [['test']])->assertOk()->assertSee('Paystack could not be reached');
        expect(paystackConnectionOf($this->acme)->status)->toBe(ConnectionStatus::PendingTest);

        $this->paystack->down = false;
        paystackSettingsUpdate($this->owner, $snapshot, [], [['test']])->assertOk()->assertSee('Paystack accepted your keys.');
        expect(paystackConnectionOf($this->acme)->status)->toBe(ConnectionStatus::Active)
            ->and(paystackConnectionOf($this->acme)->last_tested_at)->not->toBeNull();

        // Revoked at Paystack: failing, online payment stops.
        $this->paystack->forget($this->secret);
        paystackSettingsUpdate($this->owner, $snapshot, [], [['test']])->assertOk()->assertSee('Paystack refused your secret key');
        expect(paystackConnectionOf($this->acme)->status)->toBe(ConnectionStatus::Failing)
            ->and(AuditEntry::query()->where('event', 'paystack.tested')->count())->toBe(3);
    });

    it('replaces the keys, keeping the webhook address; rotating changes it; disconnecting forgets the keys', function () {
        $first = connectPaystack($this->paystack, $this->acme, $this->owner);
        [$public, $secret] = paystackKeysOf('acme-homes-rotated');
        $second = connectPaystack($this->paystack, $this->acme, $this->owner, [$public, $secret]);

        expect($second->id)->toBe($first->id)
            ->and($second->webhook_token)->toBe($first->webhook_token)
            ->and(paystackConnectionOf($this->acme)->secret_key)->toBe($secret)
            ->and(AuditEntry::query()->where('event', 'paystack.keys_replaced')->count())->toBe(1);

        $snapshot = paystackSettingsSnapshot($this->owner);
        paystackSettingsUpdate($this->owner, $snapshot, [], [['rotate']])->assertOk()->assertSee('The webhook address changed');
        $rotated = paystackConnectionOf($this->acme);
        expect($rotated->webhook_token)->not->toBe($first->webhook_token)->toMatch('/^[a-z0-9]{48}$/');

        paystackSettingsUpdate($this->owner, $snapshot, [], [['disconnect']])->assertOk()->assertSee('Paystack disconnected');
        $raw = DB::table('organization_paystack_connections')->where('organization_id', $this->acme->id)->first();
        expect($raw->secret_key)->toBeNull()
            ->and($raw->public_key)->toBeNull()
            ->and($raw->status)->toBe('disconnected')
            ->and(AuditEntry::query()->whereIn('event', ['paystack.webhook_rotated', 'paystack.disconnected'])->count())->toBe(2);
        test()->actingAs($this->owner)->get('/o/acme-homes/settings/paystack')->assertOk()->assertSee('Not connected.')->assertDontSee('/webhooks/paystack/');
    });
});

describe('the online-payment cap', function () {
    it('saves the largest bill payable online, refuses nonsense, and goes back to the standard', function () {
        $snapshot = paystackSettingsSnapshot($this->owner);
        $save = fn (string $cap): string => LivewireHttp::html(paystackSettingsUpdate($this->owner, $snapshot, ['cap' => $cap], [['saveCap']])->assertOk());

        expect($save('750,000'))->toContain('Bills owing up to ₦750,000 can now be paid online')
            ->and($this->acme->fresh()->online_payment_cap_kobo?->kobo)->toBe(75_000_000)
            ->and($save('lots'))->toContain('Enter an amount in naira')
            ->and($save('500'))->toContain('Enter a limit between ₦1,000 and ₦100,000,000')
            ->and($save('200,000,000'))->toContain('Enter a limit between')
            ->and($this->acme->fresh()->online_payment_cap_kobo?->kobo)->toBe(75_000_000)
            ->and($save(''))->toContain('set back to the standard ₦1,000,000')
            ->and($this->acme->fresh()->online_payment_cap_kobo)->toBeNull();

        $audits = AuditEntry::query()->where('event', 'paystack.online_cap_changed')->orderBy('id')->get();
        expect($audits)->toHaveCount(2)
            ->and($audits[0]->properties['after_kobo'])->toBe(75_000_000)
            ->and($audits[1]->properties['before_kobo'])->toBe(75_000_000)
            ->and($audits[1]->properties['after_kobo'])->toBeNull()
            // One entry per change: the organization model's own "updated" entry is not written too.
            ->and(AuditEntry::query()->where('log_name', 'organization')->where('subject_id', $this->acme->id)->where('properties', 'like', '%online_payment_cap%')->exists())->toBeFalse();
    });
});

describe('acceptance: connection settings are org-owner only', function () {
    it('forbids the page and every change to administrators and agents', function (OrganizationRole $role) {
        connectPaystack($this->paystack, $this->acme, $this->owner);
        $member = joinAs(User::factory()->create(), $this->acme, $role);
        $snapshot = paystackSettingsSnapshot($this->owner);

        test()->actingAs($member)->get('/o/acme-homes/settings/paystack')->assertForbidden();
        foreach ([['connect'], ['test'], ['rotate'], ['disconnect'], ['saveCap']] as $call) {
            paystackSettingsUpdate($member, $snapshot, ['publicKey' => $this->public, 'secretKey' => $this->secret, 'cap' => '5,000'], [$call])->assertForbidden();
        }

        inOrganization($this->acme, fn () => expect(Gate::forUser($member)->allows('viewAny', PaystackConnection::class))->toBeFalse()
            ->and(Gate::forUser($member)->allows('manage', PaystackConnection::class))->toBeFalse());
        expect(fn () => inOrganization($this->acme, fn () => app(PaystackConnections::class)->connect($member, $this->public, $this->secret)))->toThrow(AuthorizationException::class)
            ->and(fn () => inOrganization($this->acme, fn () => app(PaystackConnections::class)->setCap($member, Money::naira(5_000))))->toThrow(AuthorizationException::class)
            ->and(paystackConnectionOf($this->acme)->status)->toBe(ConnectionStatus::Active)
            ->and($this->acme->fresh()->online_payment_cap_kobo)->toBeNull();

        // The Settings tabs do not offer it.
        test()->actingAs($member)->get('/o/acme-homes/settings/profile')->assertDontSee('Online payments');
    })->with([
        'administrator' => OrganizationRole::Admin,
        'agent' => OrganizationRole::Agent,
    ]);

    it('answers 404 to tenants, property owners and strangers, and to another organization\'s owner', function (?OrganizationRole $role) {
        $user = User::factory()->create();

        if ($role !== null) {
            joinAs($user, $this->acme, $role);
        }

        test()->actingAs($user)->get('/o/acme-homes/settings/paystack')->assertNotFound();
        test()->actingAs($this->otherOwner)->get('/o/acme-homes/settings/paystack')->assertNotFound();
    })->with([
        'tenant' => OrganizationRole::Tenant,
        'property owner' => OrganizationRole::PropertyOwner,
        'stranger' => null,
    ]);

    it('shows the owner the tab, and each owner only their own connection', function () {
        connectPaystack($this->paystack, $this->acme, $this->owner);
        $theirs = connectPaystack($this->paystack, $this->other, $this->otherOwner);

        test()->actingAs($this->owner)->get('/o/acme-homes/settings/profile')->assertOk()->assertSee('Online payments');
        test()->actingAs($this->owner)->get('/o/acme-homes/settings/paystack')->assertOk()
            ->assertSee(substr($this->secret, -4))
            ->assertDontSee($theirs->webhook_token)
            ->assertDontSee(substr(paystackKeysOf('other-realty')[1], -4));

        // Our page's actions never touch their connection, even with a tampered snapshot.
        config(['app.debug' => false]);
        $data = json_decode(paystackSettingsSnapshot($this->owner), true);
        $data['data']['organizationId'] = $this->other->id;
        paystackSettingsUpdate($this->owner, (string) json_encode($data), [], [['disconnect']])->assertStatus(419);
        expect(paystackConnectionOf($this->other)->status)->toBe(ConnectionStatus::Active);
    });
});

describe('acceptance: keys hidden during impersonation', function () {
    it('never shows the keys or the webhook address to impersonating staff and refuses every change, in both modes', function (ImpersonationMode $mode) {
        seedStaffRoles();
        $connection = connectPaystack($this->paystack, $this->acme, $this->owner);
        $snapshot = settingsPage($this->owner, 'paystack');

        enterSupport(startSupport(staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']), $this->owner, $this->acme, $mode))->assertRedirect('/o/acme-homes');

        $page = asSupport()->get('/o/acme-homes/settings/paystack')->assertForbidden();
        expect($page->getContent())->not->toContain(substr($this->secret, -4))->not->toContain($connection->webhook_token)->not->toContain('pk_live_');

        foreach ([['test'], ['rotate'], ['disconnect'], ['saveCap']] as $call) {
            freshRequest();
            $reply = LivewireHttp::update($this, $snapshot, ['cap' => '5,000'], [$call])->assertForbidden();
            expect($reply->getContent())->not->toContain(substr($this->secret, -4));
        }
        freshRequest();
        LivewireHttp::update($this, $snapshot, ['publicKey' => 'pk_live_'.str_repeat('b', 40), 'secretKey' => 'sk_live_'.str_repeat('b', 40)], [['connect']])->assertForbidden();

        // Neither the Settings tabs nor the signup checklist link offer the page; the policy says no too.
        freshRequest();
        asSupport()->get('/o/acme-homes/settings/profile')->assertOk()->assertDontSee('Online payments');

        $stored = paystackConnectionOf($this->acme);
        expect($stored->secret_key)->toBe($this->secret)
            ->and($stored->webhook_token)->toBe($connection->webhook_token)
            ->and($stored->status)->toBe(ConnectionStatus::Active)
            ->and($this->acme->fresh()->online_payment_cap_kobo)->toBeNull()
            ->and(AuditEntry::query()->where('event', 'impersonation.blocked')->count())->toBeGreaterThanOrEqual(1);
    })->with([
        'read-only' => ImpersonationMode::ReadOnly,
        'write' => ImpersonationMode::Write,
    ]);

    it('keeps the keys out of every array and JSON form of the connection', function () {
        $connection = connectPaystack($this->paystack, $this->acme, $this->owner);

        foreach ([$connection->toArray(), json_decode($connection->toJson(), true), paystackConnectionOf($this->acme)->attributesToArray()] as $form) {
            expect($form)->not->toHaveKeys(['secret_key', 'public_key', 'secret_key_last4', 'webhook_token'])
                ->and((string) json_encode($form))->not->toContain(substr($this->secret, 8));
        }
    });
});

it('shows the connection to the owner of a suspended organization, but refuses every change', function () {
    connectPaystack($this->paystack, $this->acme, $this->owner);
    $snapshot = paystackSettingsSnapshot($this->owner);
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    test()->actingAs($this->owner)->get('/o/acme-homes/settings/paystack')->assertOk()
        ->assertSee('sk_live_····')->assertDontSee('Check and replace keys')->assertDontSee('data-test="disconnect"', false);
    foreach ([['test'], ['rotate'], ['disconnect'], ['saveCap']] as $call) {
        paystackSettingsUpdate($this->owner, $snapshot, ['cap' => '5,000'], [$call])->assertForbidden();
    }

    expect(paystackConnectionOf($this->acme)->status)->toBe(ConnectionStatus::Active)
        ->and(fn () => inOrganization($this->acme, fn () => app(PaystackConnections::class)->disconnect($this->owner)))->toThrow(AuthorizationException::class);
});

it('offers the Paystack connection in the signup wizard\'s collections step, and continues once it is connected', function () {
    freshRequest();
    app('auth')->forgetGuards();
    $owner = signUpAndVerify('ada@adeyemi.ng');
    $organization = signupOrganization();
    signupStep($organization, 'saveTier', ['annualVolume' => '10,000,000', 'tier' => 1])->assertOk();
    signupStep($organization, 'savePlan', ['plan' => 'growth'])->assertOk();

    $wizard = test()->actingAs($owner)->get("/o/{$organization->slug}/setup")->assertOk()
        ->assertSee('Your own Paystack account')
        ->assertSee('data-test="setup-paystack"', false)
        ->assertSee("/o/{$organization->slug}/settings/paystack")
        ->assertSee('Set up later');

    test()->actingAs($owner)->get("/o/{$organization->slug}/settings/paystack")->assertOk()->assertSee('data-test="back-to-setup"', false);
    [$public, $secret] = paystackKeysOf($organization->slug);
    connectPaystack($this->paystack, $organization, $owner, [$public, $secret]);

    test()->actingAs($owner)->get("/o/{$organization->slug}/setup")->assertOk()
        ->assertSee('data-test="collections-done"', false)
        ->assertSee('Continue');
    expect($wizard->getContent())->toContain('Add a bank account');
});
