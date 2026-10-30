<?php

declare(strict_types=1);

use App\Models\User;
use App\WhatsApp\WhatsAppSender;
use Illuminate\Support\Collection;
use Modules\WhatsApp\Connections\WhatsAppConnections;
use Modules\WhatsApp\Mappings\ChannelMappings;
use Modules\WhatsApp\Models\WhatsAppOptIn;
use Modules\WhatsApp\OptIns\OptInRefused;
use Modules\WhatsApp\OptIns\WhatsAppOptIns;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/whatsapp-world.php';

/*
 * P05-06: tenants' consent to the agency's WhatsApp messages (Meta policy;
 * ARCHITECTURE.md § 6.7): given in the portal (Settings → Notifications)
 * at a verified number, or recorded by the agency in person on the
 * tenant's record; taken back at once; and invitations through the cloud
 * driver once their template is mapped.
 */

beforeEach(function () {
    $this->meta = whatsappWorld();
    connectWhatsApp($this->acme, $this->owner);
    syncWhatsAppTemplates($this->acme);
});

function optInsOf($organization): Collection
{
    return inOrganization($organization, fn () => WhatsAppOptIn::query()->orderBy('id')->get());
}

/** The WhatsApp panel on a tenant's record, as $user. */
function tenantWhatsAppPanel(User $user, $tenant): string
{
    freshRequest();
    $page = test()->actingAs($user)->get('/o/acme-homes/tenants/'.$tenant->id)->assertOk();
    $snapshot = waComponentSnapshot($page, 'whatsapp::tenant-opt-in');
    freshRequest();
    test()->actingAs($user);

    return $snapshot;
}

function portalWhatsAppPanel(User $user): string
{
    freshRequest();
    $page = test()->actingAs($user)->get('/settings/notifications')->assertOk();
    $snapshot = waComponentSnapshot($page, 'whatsapp::portal-opt-ins');
    freshRequest();
    test()->actingAs($user);

    return $snapshot;
}

describe('the agency records consent given in person', function () {
    it('records the tenant\'s consent at their number, and an opt-out at once', function () {
        $snapshot = tenantWhatsAppPanel($this->agent, $this->kemiAtAcme);

        LivewireHttp::update($this, $snapshot, [], [['optIn']])->assertOk()->assertSee('Tick the box');
        expect(optInsOf($this->acme))->toBeEmpty();

        freshRequest();
        $this->actingAs($this->agent);
        LivewireHttp::update($this, $snapshot, ['confirmed' => true], [['optIn']])->assertOk()->assertSee('WhatsApp messages go to +2348031112222');

        $optIn = optInsOf($this->acme)->sole();
        expect($optIn->phone)->toBe('+2348031112222')
            ->and($optIn->source->value)->toBe('agent_recorded')
            ->and($optIn->recorded_by_user_id)->toBe($this->agent->id);

        $this->actingAs($this->agent)->get('/o/acme-homes/tenants/'.$this->kemiAtAcme->id)->assertSee('Agreed to WhatsApp messages at +2348031112222');

        LivewireHttp::update($this, tenantWhatsAppPanel($this->agent, $this->kemiAtAcme), [], [['optOut']])->assertOk();
        expect(optInsOf($this->acme)->sole()->opted_out_at)->not->toBeNull()
            ->and(inOrganization($this->acme, fn () => app(WhatsAppOptIns::class)->optedIn([$this->kemiAtAcme->id])))->toBe([]);
    });

    it('needs consent again once the tenant\'s number changes', function () {
        optInToWhatsApp($this->acme, $this->femi, $this->owner);
        inOrganization($this->acme, fn () => $this->femi->forceFill(['phone' => '+2348036669999'])->save());

        expect(inOrganization($this->acme, fn () => app(WhatsAppOptIns::class)->optedIn([$this->femi->id])))->toBe([]);

        optInToWhatsApp($this->acme, $this->femi, $this->owner);
        expect(optInsOf($this->acme)->map(fn ($row) => [$row->phone, $row->opt_out_source?->value])->all())->toBe([
            ['+2348036660000', 'replaced'],
            ['+2348036669999', null],
        ]);
    });

    it('shows nothing until WhatsApp is connected', function () {
        $this->actingAs($this->owner)->get('/o/acme-homes/tenants/'.$this->kemiAtAcme->id)->assertOk()->assertSee('data-test="tenant-whatsapp"', false);

        inOrganization($this->acme, fn () => app(WhatsAppConnections::class)->disconnect($this->owner));
        freshRequest();
        $this->actingAs($this->owner)->get('/o/acme-homes/tenants/'.$this->kemiAtAcme->id)->assertOk()->assertDontSee('data-test="tenant-whatsapp"', false);
    });

    it('never records consent for another organization\'s tenant', function () {
        expect(fn () => optInToWhatsApp($this->acme, $this->foreignTenant, $this->owner))->toThrow(OptInRefused::class);
        expect(optInsOf($this->acme))->toBeEmpty()->and(optInsOf($this->other))->toBeEmpty();
    });
});

describe('the tenant in the portal', function () {
    it('lets a tenant with a verified number turn WhatsApp messages on and off for their agency', function () {
        $this->kemi->forceFill(['phone_verified_at' => now(), 'phone_verification_method' => 'whatsapp_link'])->save();
        $snapshot = portalWhatsAppPanel($this->kemi);

        LivewireHttp::update($this, $snapshot, [], [['optIn', [$this->acme->id]]])->assertOk()->assertSee('may now send you reminders and notices by WhatsApp');
        expect(optInsOf($this->acme)->sole())->source->value->toBe('portal')->phone->toBe('+2348031112222');

        LivewireHttp::update($this, portalWhatsAppPanel($this->kemi), [], [['optOut', [$this->acme->id]]])->assertOk()->assertSee('no more WhatsApp messages');
        expect(optInsOf($this->acme)->sole()->opted_out_at)->not->toBeNull();
    });

    it('asks for a verified number first, and offers only the agencies the tenant rents from', function () {
        $snapshot = portalWhatsAppPanel($this->kemi);
        $this->actingAs($this->kemi)->get('/settings/notifications')->assertSee('data-test="portal-whatsapp-unverified"', false)->assertDontSee('data-test="portal-opt-in"', false);

        LivewireHttp::update($this, $snapshot, [], [['optIn', [$this->acme->id]]])->assertOk()->assertSee('must be verified first');
        freshRequest();
        $this->actingAs($this->kemi);
        LivewireHttp::update($this, $snapshot, [], [['optIn', [$this->other->id]]])->assertNotFound();

        expect(optInsOf($this->acme))->toBeEmpty()->and(optInsOf($this->other))->toBeEmpty();
    });

    it('shows no WhatsApp panel when no agency of the account has WhatsApp', function () {
        inOrganization($this->acme, fn () => app(WhatsAppConnections::class)->disconnect($this->owner));

        $this->actingAs($this->kemi)->get('/settings/notifications')->assertOk()->assertDontSee('data-test="portal-whatsapp"', false);
    });
});

describe('invitations by the cloud driver', function () {
    beforeEach(function () {
        inOrganization($this->acme, fn () => app(ChannelMappings::class)->save($this->owner, 'tenant_invitation', whatsappTemplate($this->acme, 'tenant_invite')->id, [
            'body.1' => 'tenant.first_name', 'body.2' => 'organization.name', 'body.3' => 'invitation_link',
        ], ['whatsapp'], true));
    });

    it('offers WhatsApp for an invitation only once its template is mapped, and sends it with that template', function () {
        $sender = app(WhatsAppSender::class);
        expect($sender->available($this->acme, 'tenant_invitation'))->toBeTrue()
            ->and($sender->available($this->acme, 'owner_invitation'))->toBeFalse()
            ->and($sender->available($this->other, 'tenant_invitation'))->toBeFalse();

        portalCall($this->owner, $this->phoneOnly, 'invite', ['whatsapp'])->assertOk()->assertSee('sent to');

        $send = $this->meta->sends()[0];
        expect($send['to'])->toBe('2348037770000')
            ->and($send['template']['name'])->toBe('tenant_invite')
            ->and($send['template']['components'][0]['parameters'][0]['text'])->toBe('Musa')
            ->and($send['template']['components'][0]['parameters'][2]['text'])->toContain('/portal/invitations/')
            ->and(deliveriesOf($this->acme)->sole())->notification_type->toBe('tenant_invitation')->source_type->toBeNull();
    });

    it('sends nothing by WhatsApp to a number that opted out', function () {
        optInToWhatsApp($this->acme, $this->phoneOnly, $this->owner);
        optOutOfWhatsApp($this->acme, $this->phoneOnly);

        portalCall($this->owner, $this->phoneOnly, 'invite', ['whatsapp'])->assertOk();

        expect($this->meta->sends())->toBe([])->and(deliveriesOf($this->acme))->toBeEmpty();
    });
});
