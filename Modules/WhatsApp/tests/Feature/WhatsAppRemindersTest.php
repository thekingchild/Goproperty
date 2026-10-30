<?php

declare(strict_types=1);

use App\Models\User;
use App\Sms\DeliveryStatus;
use App\Sms\SmsSender;
use Modules\Reminders\Enums\ReminderChannel;
use Modules\Reminders\Enums\ReminderStatus;
use Modules\Reminders\Enums\SkipReason;

require_once __DIR__.'/whatsapp-world.php';

/*
 * P05-06: reminders by the agency's own WhatsApp (ARCHITECTURE.md § 6.7;
 * docs/plans/P05-subtasks.md, defaults taken in P05-06). Acceptance: "A
 * template paused by Meta disables its mapping on next sync and that event
 * falls back to SMS". Also: WhatsApp only to a tenant who opted in, at most
 * one phone channel per rung in the event's channel order, and the next
 * channel when Meta refuses a message or reports it failed.
 */

beforeEach(function () {
    $this->meta = whatsappWorld();
    connectWhatsApp($this->acme, $this->owner);
    syncWhatsAppTemplates($this->acme);
});

function waVerify(User $user): void
{
    $user->forceFill(['phone_verified_at' => now(), 'phone_verification_method' => 'whatsapp_link'])->save();
}

/** @return list<array{to: string, text: string}> */
function waTexts(): array
{
    return app(SmsSender::class)->sent();
}

/** Each day's phone reminders only (SMS and WhatsApp). */
function phoneDays(array $sent): array
{
    return array_filter(array_map(fn (array $lines): array => array_values(array_filter($lines, fn (string $line): bool => str_contains($line, ' sms ') || str_contains($line, ' whatsapp '))), $sent));
}

describe('acceptance: a template paused by Meta disables its mapping on the next sync, and the event falls back to SMS', function () {
    it('sends rent T−7 by WhatsApp, then, once the daily sync finds the template paused, rent T−1 by SMS', function () {
        activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
        waVerify($this->kemi);
        optInToWhatsApp($this->acme, $this->kemiAtAcme, $this->owner);
        mapRentReminder($this->acme, $this->admin, siblings: true);

        liveReminderDays('2026-12-24', '2026-12-26');

        $bill = billsOf($this->acme)->sole();
        $send = $this->meta->sends()[0] ?? null;
        expect($this->meta->sends())->toHaveCount(1)
            ->and($send['to'])->toBe('2348031112222')
            ->and($send['template']['name'])->toBe('rent_reminder')
            ->and($send['template']['language'])->toBe(['code' => 'en_US'])
            ->and($send['template']['components'][0]['parameters'])->toBe([
                ['type' => 'text', 'text' => 'Kemi Adebayo'],
                ['type' => 'text', 'text' => '₦1,500,000'],
                ['type' => 'text', 'text' => 'Flat 3B, Palm Court'],
                ['type' => 'text', 'text' => 'in 7 days (1 Jan)'],
                ['type' => 'text', 'text' => $bill->payment_reference],
            ])
            // The pay button's address already holds /pay/: only the reference is sent.
            ->and($send['template']['components'][1])->toBe(['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $bill->payment_reference]]])
            ->and(waTexts())->toBe([]);

        $whatsapp = remindersOf($this->acme)->firstWhere('channel', ReminderChannel::WhatsApp);
        $delivery = deliveriesOf($this->acme)->sole();
        expect($whatsapp->status)->toBe(ReminderStatus::Sent)
            ->and($whatsapp->offset_days)->toBe(-7)
            ->and($whatsapp->delivered_at)->not->toBeNull()
            ->and($delivery->status)->toBe(DeliveryStatus::Sent)
            ->and($delivery->provider)->toBe('meta')
            ->and($delivery->provider_message_id)->toBe('wamid.TEST1')
            ->and($delivery->notification_type)->toBe('reminder.rent_due.t7')
            ->and($delivery->source_id)->toBe($whatsapp->id)
            ->and($delivery->body)->toContain('Hello Kemi Adebayo, your rent of ₦1,500,000 for Flat 3B, Palm Court is due in 7 days (1 Jan).');

        // Meta pauses the template; the daily sync (05:20 Lagos) switches the mappings off.
        $this->meta->setStatus('1000000001', 'rent_reminder', 'PAUSED');
        $this->travelTo('2026-12-27 04:20:00');
        whatsappSyncScheduledEvent()->run(app());

        expect(mappingOf($this->acme, 'reminder.rent_due.t1'))->active->toBeFalse()
            ->disabled_reason->toBe('template_paused')
            ->and(mappingOf($this->acme, 'reminder.rent_due.t7'))->active->toBeFalse()
            ->and(whatsappTemplate($this->acme, 'rent_reminder')->status->value)->toBe('paused');

        $sent = liveReminderDays('2026-12-30', '2027-01-01');

        expect(phoneDays($sent))->toBe([
            '2026-12-25' => ['rent_due T-7 whatsapp '.RM_KEMI],
            '2026-12-31' => ['rent_due T-1 sms '.RM_KEMI],
        ])
            ->and($this->meta->sends())->toHaveCount(1)
            ->and(collect(waTexts())->pluck('to')->all())->toBe(['+2348031112222']);
    });

    it('falls back to SMS the same day when the template was paused after the reminder was planned', function () {
        activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
        waVerify($this->kemi);
        optInToWhatsApp($this->acme, $this->kemiAtAcme, $this->owner);
        mapRentReminder($this->acme, $this->admin, siblings: true);
        liveReminderDays('2026-12-24', '2026-12-30');

        // Planned for WhatsApp just after midnight; Meta pauses the template before 08:00.
        $this->travelTo('2026-12-31 00:35:00');
        runReminders();
        $planned = remindersOf($this->acme)->where('channel', ReminderChannel::WhatsApp)->firstWhere('offset_days', -1);
        expect($planned->status)->toBe(ReminderStatus::Pending);

        $this->meta->setStatus('1000000001', 'rent_reminder', 'PAUSED');
        $this->travelTo('2026-12-31 04:20:00');
        whatsappSyncScheduledEvent()->run(app());
        $this->travelTo('2026-12-31 08:35:00');
        runReminders();

        $planned = remindersOf($this->acme)->firstWhere('id', $planned->id);
        $sms = remindersOf($this->acme)->where('channel', ReminderChannel::Sms)->firstWhere('offset_days', -1);
        expect($planned->status)->toBe(ReminderStatus::Skipped)
            ->and($planned->skip_reason)->toBe(SkipReason::FellBack)
            ->and($sms->status)->toBe(ReminderStatus::Sent)
            ->and($sms->send_on->toDateString())->toBe('2026-12-31')
            // T−7 and T−3 went by WhatsApp; T−1 did not.
            ->and($this->meta->sends())->toHaveCount(2)
            ->and(waTexts())->toHaveCount(1);
    });
});

it('changes nothing for a tenant who did not opt in: SMS on its rungs, never WhatsApp', function () {
    activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
    waVerify($this->kemi);
    mapRentReminder($this->acme, $this->admin, siblings: true);

    $sent = liveReminderDays('2026-12-24', '2027-01-01');

    expect(phoneDays($sent))->toBe([
        '2026-12-25' => ['rent_due T-7 sms '.RM_KEMI],
        '2026-12-31' => ['rent_due T-1 sms '.RM_KEMI],
    ])->and($this->meta->sends())->toBe([]);
});

it('goes by WhatsApp on a rung without SMS, and in the order the agency chose', function () {
    activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
    waVerify($this->kemi);
    optInToWhatsApp($this->acme, $this->kemiAtAcme, $this->owner);
    mapRentReminder($this->acme, $this->admin, 'reminder.rent_due.t3', ['whatsapp']);
    // SMS first on T−1: WhatsApp only if SMS cannot go.
    mapRentReminder($this->acme, $this->admin, 'reminder.rent_due.t1', ['sms', 'whatsapp']);

    $sent = liveReminderDays('2026-12-24', '2027-01-01');

    expect(phoneDays($sent))->toBe([
        '2026-12-25' => ['rent_due T-7 sms '.RM_KEMI],
        '2026-12-29' => ['rent_due T-3 whatsapp '.RM_KEMI],
        '2026-12-31' => ['rent_due T-1 sms '.RM_KEMI],
    ])->and($this->meta->sends())->toHaveCount(1);
});

it('reaches a phone-only tenant with no account by WhatsApp once the agency recorded their consent', function () {
    activeLease($this->acme, $this->owner, $this->flat4c, $this->phoneOnly);
    optInToWhatsApp($this->acme, $this->phoneOnly, $this->agent);
    mapRentReminder($this->acme, $this->admin, siblings: true);

    $sent = liveReminderDays('2026-12-24', '2027-01-01');

    expect(phoneDays($sent))->toBe([
        '2026-12-25' => ['rent_due T-7 whatsapp tenant:'.$this->phoneOnly->id],
        '2026-12-29' => ['rent_due T-3 whatsapp tenant:'.$this->phoneOnly->id],
        '2026-12-31' => ['rent_due T-1 whatsapp tenant:'.$this->phoneOnly->id],
        '2027-01-01' => ['rent_due T-0 whatsapp tenant:'.$this->phoneOnly->id],
    ])
        ->and(collect($this->meta->sends())->pluck('to')->unique()->all())->toBe(['2348037770000'])
        // Never texted: the number is not a verified portal account's.
        ->and(waTexts())->toBe([]);
});

it('honours an opt-out at once: a WhatsApp reminder already planned goes by SMS instead', function () {
    activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
    waVerify($this->kemi);
    optInToWhatsApp($this->acme, $this->kemiAtAcme, $this->owner);
    mapRentReminder($this->acme, $this->admin);

    $this->travelTo('2026-12-25 00:35:00');
    runBillGeneration();
    runReminders();
    expect(remindersOf($this->acme)->firstWhere('channel', ReminderChannel::WhatsApp)->status)->toBe(ReminderStatus::Pending);

    optOutOfWhatsApp($this->acme, $this->kemiAtAcme);
    $this->travelTo('2026-12-25 08:35:00');
    runReminders();

    expect(remindersOf($this->acme)->firstWhere('channel', ReminderChannel::WhatsApp))->skip_reason->toBe(SkipReason::FellBack)
        ->and(remindersOf($this->acme)->firstWhere('channel', ReminderChannel::Sms)->status)->toBe(ReminderStatus::Sent)
        ->and($this->meta->sends())->toBe([])
        ->and(waTexts())->toHaveCount(1);
});

describe('Meta does not deliver it', function () {
    beforeEach(function () {
        activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
        waVerify($this->kemi);
        optInToWhatsApp($this->acme, $this->kemiAtAcme, $this->owner);
    });

    it('falls back to SMS at once when Meta refuses the message, never sending either twice', function () {
        mapRentReminder($this->acme, $this->admin);
        $this->meta->refuseSends = [400, 131026, 'Message undeliverable'];

        liveReminderDays('2026-12-24', '2026-12-26');

        $delivery = deliveriesOf($this->acme)->sole();
        expect($delivery->status)->toBe(DeliveryStatus::Failed)
            ->and($delivery->error)->toBe('(#131026) Message undeliverable')
            ->and(remindersOf($this->acme)->firstWhere('channel', ReminderChannel::WhatsApp)->status)->toBe(ReminderStatus::Sent)
            ->and(remindersOf($this->acme)->where('channel', ReminderChannel::Sms)->sole()->status)->toBe(ReminderStatus::Sent)
            ->and(count($this->meta->sends()))->toBe(1)
            ->and(waTexts())->toHaveCount(1)
            // A refused message does not mark the connection failing; a refused token does.
            ->and(whatsappConnectionOf($this->acme)->status->value)->toBe('active');
    });

    it('sends nothing more when the agency chose WhatsApp only', function () {
        mapRentReminder($this->acme, $this->admin, order: ['whatsapp']);
        $this->meta->refuseSends = [400, 131026, 'Message undeliverable'];

        liveReminderDays('2026-12-24', '2026-12-26');

        expect(waTexts())->toBe([])->and(remindersOf($this->acme)->where('channel', ReminderChannel::Sms))->toBeEmpty();
    });

    it('marks the connection failing when Meta refuses the token, and falls back to SMS', function () {
        mapRentReminder($this->acme, $this->admin, siblings: true);
        $this->meta->tokens[WA_TOKEN] = false;

        liveReminderDays('2026-12-24', '2026-12-26');

        expect(whatsappConnectionOf($this->acme)->status->value)->toBe('failing')
            ->and(waTexts())->toHaveCount(1);

        // Failing: the next rungs are planned for SMS straight away; nothing is tried with Meta.
        $requests = count($this->meta->requests);
        liveReminderDays('2026-12-30', '2026-12-31');
        expect(count($this->meta->requests))->toBe($requests)->and(waTexts())->toHaveCount(2);
    });

    it('falls back to SMS when Meta later reports the message failed on its day, and not on a later day', function () {
        mapRentReminder($this->acme, $this->admin, siblings: true);
        liveReminderDay('2026-12-25');
        $connection = whatsappConnectionOf($this->acme);

        $this->travelTo('2026-12-25 15:00:00');
        whatsappWebhook($connection, metaStatuses($connection, [metaStatus('wamid.TEST1', 'failed', extra: ['errors' => [['code' => 131049, 'title' => 'This message was not delivered to maintain healthy ecosystem engagement.']]])]), WA_SECRET)->assertOk();

        expect(deliveriesOf($this->acme)->sole()->status)->toBe(DeliveryStatus::Undelivered)
            ->and(waTexts())->toHaveCount(1);

        // The same report again changes nothing.
        whatsappWebhook($connection, metaStatuses($connection, [metaStatus('wamid.TEST1', 'failed')]), WA_SECRET)->assertOk();
        expect(waTexts())->toHaveCount(1);

        // T−3 goes by WhatsApp (no SMS on that rung); its failure the next day changes nothing.
        liveReminderDay('2026-12-29');
        $this->travelTo('2026-12-30 09:00:00');
        whatsappWebhook($connection, metaStatuses($connection, [metaStatus('wamid.TEST2', 'failed')]), WA_SECRET)->assertOk();

        expect(waTexts())->toHaveCount(1)
            ->and(remindersOf($this->acme)->where('channel', ReminderChannel::Sms))->toHaveCount(1);
    });
});

it('never sends by WhatsApp on a plan without it, keeping SMS', function () {
    $this->acme->forceFill(['signup_plan_key' => 'starter'])->save();
    activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
    waVerify($this->kemi);
    optInToWhatsApp($this->acme, $this->kemiAtAcme, $this->owner);
    mapRentReminder($this->acme, $this->admin);

    $sent = liveReminderDays('2026-12-24', '2026-12-26');

    expect(phoneDays($sent))->toBe(['2026-12-25' => ['rent_due T-7 sms '.RM_KEMI]])
        ->and($this->meta->sends())->toBe([]);
});
