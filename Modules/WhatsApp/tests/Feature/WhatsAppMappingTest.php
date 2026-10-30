<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Notifications\Channels\WhatsAppEvents;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\WhatsApp\Mappings\ChannelMappings;
use Modules\WhatsApp\Mappings\MappingRefused;
use Modules\WhatsApp\Models\WhatsAppTemplate;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/whatsapp-world.php';

/*
 * P05-06: templates synced from Meta and the event → template mapping
 * (ARCHITECTURE.md § 6.7): approved utility templates only, every variable
 * mapped to one of the event's whitelisted placeholders (its email and SMS
 * templates' own), a preview with sample values, the channel order each
 * event may hold.
 */

beforeEach(function () {
    $this->meta = whatsappWorld();
    connectWhatsApp($this->acme, $this->owner);
    syncWhatsAppTemplates($this->acme);
});

function waSave(string $event, ?WhatsAppTemplate $template, array $map, array $order = ['whatsapp'], bool $active = true): void
{
    $test = test();
    inOrganization($test->acme, fn () => app(ChannelMappings::class)->save($test->admin, $event, $template?->id, $map, $order, $active));
}

it('keeps the templates as Meta describes them, with their variables, and their status from every sync', function () {
    $rent = whatsappTemplate($this->acme, 'rent_reminder');

    expect($rent->status->value)->toBe('approved')
        ->and($rent->category->value)->toBe('utility')
        ->and($rent->variable_count)->toBe(6)
        ->and($rent->layout()->variables())->toBe(['body.1', 'body.2', 'body.3', 'body.4', 'body.5', 'button.0'])
        ->and(whatsappTemplate($this->acme, 'summer_offer')->category->value)->toBe('marketing')
        ->and($this->meta->requests[1]['path'])->toBe('/v21.0/1000000001/message_templates');

    // Rejected at Meta, then deleted there: kept, never removed.
    $this->meta->setStatus('1000000001', 'tenant_invite', 'REJECTED');
    syncWhatsAppTemplates($this->acme);
    expect(whatsappTemplate($this->acme, 'tenant_invite')->status->value)->toBe('rejected');

    $this->meta->templates['1000000001'] = [rentReminderTemplate()];
    syncWhatsAppTemplates($this->acme);
    expect(whatsappTemplate($this->acme, 'tenant_invite')->status->value)->toBe('disabled')
        ->and(inOrganization($this->acme, fn () => WhatsAppTemplate::query()->count()))->toBe(3);
});

it('maps an approved utility template, and previews it filled with sample values', function () {
    mapRentReminder($this->acme, $this->admin);
    $events = app(WhatsAppEvents::class);

    $preview = inOrganization($this->acme, fn () => app(ChannelMappings::class)->preview($events->find('reminder.rent_due.t7'), whatsappTemplate($this->acme, 'rent_reminder'), mappingOf($this->acme, 'reminder.rent_due.t7')->variable_map));

    expect(mappingOf($this->acme, 'reminder.rent_due.t7'))->active->toBeTrue()->channel_order->toBe(['whatsapp', 'sms'])
        ->and($preview)->toBe("Hello Femi Okon, your rent of ₦1,500,000 for Flat 3B, Palm Court is due in 7 days (1 Jan). Use reference GP-7K3M9Q when you pay.\n\nSent by your agency\n\n[Pay now] ".rtrim((string) config('app.url'), '/').'/pay/GP-7K3M9Q');
});

it('refuses marketing templates, unknown placeholders, missing variables and channels the event cannot use', function (Closure $save, string $field) {
    try {
        $save();
        $this->fail('Not refused');
    } catch (MappingRefused $refused) {
        expect($refused->field)->toStartWith($field);
    }

    expect(mappingOf($this->acme, 'reminder.rent_due.t7'))->toBeNull()
        ->and(mappingOf($this->acme, 'tenant_invitation'))->toBeNull();
})->with([
    'a marketing template' => [fn () => waSave('reminder.rent_due.t7', whatsappTemplate(test()->acme, 'summer_offer'), ['body.1' => 'recipient.name']), 'templateId'],
    'a placeholder the event does not have' => [fn () => waSave('tenant_invitation', whatsappTemplate(test()->acme, 'tenant_invite'), ['body.1' => 'tenant.first_name', 'body.2' => 'bill.owed', 'body.3' => 'invitation_link']), 'variableMap'],
    'a variable left out' => [fn () => waSave('tenant_invitation', whatsappTemplate(test()->acme, 'tenant_invite'), ['body.1' => 'tenant.first_name', 'body.2' => 'organization.name']), 'variableMap'],
    'SMS for an invitation' => [fn () => waSave('tenant_invitation', whatsappTemplate(test()->acme, 'tenant_invite'), ['body.1' => 'tenant.first_name', 'body.2' => 'organization.name', 'body.3' => 'invitation_link'], ['whatsapp', 'sms']), 'channelOrder'],
    'SMS on a rung without SMS' => [fn () => waSave('reminder.rent_due.t30', null, [], ['whatsapp', 'sms'], false), 'channelOrder'],
    'switched on without a template' => [fn () => waSave('tenant_invitation', null, [], ['whatsapp']), 'templateId'],
    'an unknown event' => [fn () => waSave('reminder.made_up.t7', null, [], ['whatsapp'], false), 'event'],
]);

it('refuses another organization\'s template', function () {
    connectWhatsApp($this->other, $this->otherOwner, '2000000002', '2200000002', WA_OTHER_TOKEN, WA_OTHER_SECRET);
    syncWhatsAppTemplates($this->other);
    $foreign = whatsappTemplate($this->other, 'other_rent');

    expect(fn () => inOrganization($this->acme, fn () => app(ChannelMappings::class)->save($this->admin, 'reminder.rent_due.t7', $foreign->id, ['body.1' => 'bill.owed'], ['whatsapp'], true)))
        ->toThrow(MappingRefused::class, 'Choose one of your synced templates');
});

it('lists every message that can go by WhatsApp: the reminder rungs and the existing invitations and notices', function () {
    $keys = array_map(fn ($event) => $event->key, app(WhatsAppEvents::class)->all());

    expect($keys)->toContain('reminder.rent_due.t7', 'reminder.rent_upcoming.t30', 'reminder.utility_due.t0', 'reminder.bill_overdue.p14', 'tenant_invitation', 'tenant_sign_in_link', 'owner_invitation', 'receipt_issued', 'payment_rejected')
        ->and($keys)->not->toContain('reminder.lease_renewal.t60')
        ->and(app(WhatsAppEvents::class)->find('reminder.rent_due.t7')->placeholderNames())->toContain('recipient.name', 'bill.owed', 'bill.payment_reference', 'pay_link', 'bill.due_short')
        ->and(app(WhatsAppEvents::class)->find('reminder.rent_due.t7')->channels())->toBe(['whatsapp', 'sms'])
        ->and(app(WhatsAppEvents::class)->find('reminder.rent_due.t3')->channels())->toBe(['whatsapp']);
});

it('lets an administrator map a message on the page, with a live preview, and copy it to the reminder\'s other days', function () {
    $template = whatsappTemplate($this->acme, 'rent_reminder');
    $snapshot = whatsappPageSnapshot($this->admin, '/messages');

    $opened = whatsappPageUpdate($this->admin, $snapshot, [], [['edit', ['reminder.rent_due.t7']]])->assertOk();
    $chosen = whatsappPageUpdate($this->admin, LivewireHttp::next($opened), ['templateId' => $template->id], [])->assertOk();
    expect(LivewireHttp::html($chosen))->toContain('data-variable="body.1"')->toContain('data-variable="button.0"');

    $filled = whatsappPageUpdate($this->admin, LivewireHttp::next($chosen), [
        'placeholders.0' => 'recipient.name', 'placeholders.1' => 'bill.owed', 'placeholders.2' => 'unit.place',
        'placeholders.3' => 'bill.due_short', 'placeholders.4' => 'bill.payment_reference', 'placeholders.5' => 'pay_link',
        'channelOrder' => 'whatsapp,sms', 'active' => true,
    ], [])->assertOk();
    expect(LivewireHttp::html($filled))->toContain('data-test="preview"')->toContain('Hello Femi Okon, your rent of ₦1,500,000');

    whatsappPageUpdate($this->admin, LivewireHttp::next($filled), [], [['copyToRungs']])->assertOk()->assertSee('copied to the 5 other days');

    foreach (['t30', 't14', 't7', 't3', 't1', 't0'] as $rung) {
        expect(mappingOf($this->acme, 'reminder.rent_due.'.$rung))->active->toBeTrue()->whatsapp_template_id->toBe($template->id);
    }

    expect(mappingOf($this->acme, 'reminder.rent_due.t3')->channel_order)->toBe(['whatsapp'])
        ->and(mappingOf($this->acme, 'reminder.rent_due.t1')->channel_order)->toBe(['whatsapp', 'sms'])
        ->and(mappingOf($this->acme, 'reminder.rent_upcoming.t30'))->toBeNull();
});

it('shows a mapping a sync switched off, and why', function () {
    mapRentReminder($this->acme, $this->admin);
    $this->meta->setStatus('1000000001', 'rent_reminder', 'REJECTED');
    syncWhatsAppTemplates($this->acme);

    $this->actingAs($this->admin)->get('/o/acme-homes/settings/operations/whatsapp/messages')->assertOk()
        ->assertSee('data-test="disabled-reason"', false)->assertSee('the template was rejected at Meta');
    expect(mappingOf($this->acme, 'reminder.rent_due.t7'))->active->toBeFalse()->disabled_reason->toBe('template_rejected');
});

it('switches a mapping off when Meta changed the template\'s variables', function () {
    mapRentReminder($this->acme, $this->admin);
    $this->meta->templates['1000000001'][0] = metaTemplate('rent_reminder', 'Hello {{1}}, rent of {{2}} is due.');
    syncWhatsAppTemplates($this->acme);

    expect(mappingOf($this->acme, 'reminder.rent_due.t7'))->active->toBeFalse()->disabled_reason->toBe('template_changed');
});

it('refuses mapping changes to agents and a suspended organization', function () {
    expect(fn () => inOrganization($this->acme, fn () => app(ChannelMappings::class)->save($this->agent, 'tenant_invitation', null, [], ['whatsapp'], false)))
        ->toThrow(AuthorizationException::class);

    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();
    $this->actingAs($this->admin)->get('/o/acme-homes/settings/operations/whatsapp/messages')->assertOk()->assertDontSee('data-test="edit-message"', false);
});
