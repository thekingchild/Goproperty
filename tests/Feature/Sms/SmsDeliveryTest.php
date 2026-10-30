<?php

declare(strict_types=1);

use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Platform\Filament\Resources\DeliveryLog\DeliveryLogResource;
use App\Platform\Filament\Resources\DeliveryLog\Pages\ListDeliveryLog;
use App\Sms\CheckSmsStatus;
use App\Sms\DeliveryStatus;
use App\Sms\SendSms;
use App\Sms\SmsOutbox;
use App\Sms\SmsSent;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

require_once __DIR__.'/fake-sms.php';

/*
 * P05-02: the `notification_deliveries` log and the SMS jobs. Acceptance:
 * "failed sends retry with backoff then surface in the health dashboard"
 * (the console's delivery log; P05-05 builds the dashboard on these rows).
 */

beforeEach(function () {
    $this->acme = Organization::factory()->create(['name' => 'Adeyemi Realty Ltd']);
});

function queueSms(Organization $organization, string $key = 'reminder:1', string $text = 'Adeyemi Realty: rent of NGN 1,500,000 is due tomorrow (1 Jan).'): NotificationDelivery
{
    return inOrganization($organization, fn (): NotificationDelivery => app(SmsOutbox::class)->queue('reminder.rent_due', '+2348031112222', $text, $key, sourceType: 'reminder', sourceId: 1));
}

function delivery(Organization $organization): NotificationDelivery
{
    return inOrganization($organization, fn (): NotificationDelivery => NotificationDelivery::query()->sole());
}

/** Runs the next job on the database queue, as the shared host's cron worker does. */
function workOnce(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'notifications', '--stop-when-empty' => true]);
}

describe('acceptance: failed sends retry with backoff, then surface', function () {
    it('retries after 1, 5 and 30 minutes, then marks the delivery failed and shows it in the console delivery log', function () {
        config(['queue.default' => 'database']);
        $sms = scriptedSms(failures: 10);
        queueSms($this->acme);

        workOnce();
        expect(delivery($this->acme)->only(['status', 'attempts', 'error']))->toEqual(['status' => DeliveryStatus::Queued, 'attempts' => 1, 'error' => 'GoText refused the request (HTTP 503).']);

        // Not before its backoff.
        $this->travel(59)->seconds();
        workOnce();
        expect($sms->calls)->toBe(1);

        foreach ([2 => 2, 3 => 300, 4 => 1800] as $attempt => $wait) {
            $this->travel($wait)->seconds();
            workOnce();
            expect($sms->calls)->toBe($attempt);
        }

        $failed = delivery($this->acme);
        expect($failed->status)->toBe(DeliveryStatus::Failed)
            ->and($failed->attempts)->toBe(4)
            ->and($failed->failed_at)->not->toBeNull()
            ->and($failed->error)->toBe('GoText refused the request (HTTP 503).')
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and((new SendSms(1, 1))->backoff())->toBe([60, 300, 1800]);

        // Surfaces for support: on the menu, and in the log with the number masked and no text.
        Filament::setCurrentPanel(Filament::getPanel('console'));
        seedStaffRoles();
        $this->actingAs(staffNamed('Sade', ['support-agent']), 'staff');

        expect(DeliveryLogResource::getNavigationBadge())->toBe('1');
        $this->get('/'.config('app.admin_path').'/delivery-log')->assertOk()
            ->assertSee('Adeyemi Realty Ltd')
            ->assertSee('+234 ••• 2222')
            ->assertSee('Failed')
            ->assertSee('GoText refused the request (HTTP 503).')
            ->assertDontSee('+2348031112222')
            ->assertDontSee('rent of NGN 1,500,000');
    });

    it('sends after a failed attempt once GoText takes it, and checks what became of it', function () {
        config(['queue.default' => 'database']);
        Event::fake([SmsSent::class]);
        $sms = scriptedSms(failures: 1);
        queueSms($this->acme);

        workOnce();
        $this->travel(61)->seconds();
        workOnce();

        $sent = delivery($this->acme);
        expect($sent->only(['status', 'attempts', 'provider_message_id', 'provider_status', 'pages', 'cost_kobo', 'error']))
            ->toEqual(['status' => DeliveryStatus::Sent, 'attempts' => 2, 'provider_message_id' => 'uid-1', 'provider_status' => 'Sent', 'pages' => 1, 'cost_kobo' => 250, 'error' => null])
            ->and($sms->sent)->toBe([['to' => '+2348031112222', 'text' => 'Adeyemi Realty: rent of NGN 1,500,000 is due tomorrow (1 Jan).']]);
        Event::assertDispatched(SmsSent::class, fn (SmsSent $event): bool => $event->deliveryId === $sent->id && $event->sourceType === 'reminder' && $event->sourceId === 1);

        // Checked again five minutes later: delivered.
        $this->travel(CheckSmsStatus::DELAYS[0] + 1)->seconds();
        workOnce();
        expect(delivery($this->acme)->only(['status', 'provider_status']))->toEqual(['status' => DeliveryStatus::Delivered, 'provider_status' => 'delivered'])
            ->and(delivery($this->acme)->delivered_at)->not->toBeNull();
    });
});

it('queues a message once per dedupe key, after the commit, and refuses what is not one GSM page to an E.164 number', function (string $to, string $text) {
    Queue::fake();
    $first = queueSms($this->acme);
    $again = queueSms($this->acme);

    expect($again->id)->toBe($first->id)
        ->and($first->only(['status', 'channel', 'provider', 'recipient_contact', 'notification_type']))->toEqual(['status' => DeliveryStatus::Queued, 'channel' => 'sms', 'provider' => 'log', 'recipient_contact' => '+2348031112222', 'notification_type' => 'reminder.rent_due']);
    Queue::assertPushed(SendSms::class, 1);

    expect(fn () => inOrganization($this->acme, fn () => app(SmsOutbox::class)->queue('reminder.rent_due', $to, $text, 'reminder:2')))->toThrow(InvalidArgumentException::class);
})->with([
    'no plus' => ['08031112222', 'Hi'],
    'too long' => ['+2348031112222', str_repeat('a', 161)],
    'naira sign' => ['+2348031112222', 'Rent of ₦1,500,000'],
    'empty' => ['+2348031112222', ''],
]);

it('never sends a message twice: an attempt that died mid-send is marked failed, not retried', function () {
    $sms = scriptedSms();
    Queue::fake();
    $delivery = queueSms($this->acme);
    inOrganization($this->acme, fn () => $delivery->forceFill(['status' => DeliveryStatus::Sending, 'attempts' => 1])->save());

    app()->call([new SendSms($this->acme->id, $delivery->id), 'handle']);
    app()->call([new SendSms($this->acme->id, $delivery->id), 'handle']);

    expect($sms->calls)->toBe(0)
        ->and(delivery($this->acme)->status)->toBe(DeliveryStatus::Failed)
        ->and(delivery($this->acme)->error)->toContain('not known whether GoText took the message');

    // A sent one is not sent again either.
    $other = queueSms($this->acme, 'reminder:9');
    inOrganization($this->acme, fn () => $other->forceFill(['status' => DeliveryStatus::Sent])->save());
    app()->call([new SendSms($this->acme->id, $other->id), 'handle']);
    expect($sms->calls)->toBe(0)
        ->and(fn () => inOrganization($this->acme, fn () => $other->forceFill(['body' => 'changed'])->save()))->toThrow(LogicException::class)
        ->and(fn () => inOrganization($this->acme, fn () => $other->delete()))->toThrow(LogicException::class);
});

it('keeps checking an unknown status at 5 minutes, 30 minutes, 2 and 12 hours, then leaves it sent', function () {
    config(['queue.default' => 'database']);
    $sms = scriptedSms(report: null);
    queueSms($this->acme);
    workOnce();

    foreach (CheckSmsStatus::DELAYS as $delay) {
        $this->travel($delay + 1)->seconds();
        workOnce();
    }
    $this->travel(2)->days();
    workOnce();

    expect($sms->checks)->toBe(4)
        ->and(delivery($this->acme)->status)->toBe(DeliveryStatus::Sent)
        ->and(delivery($this->acme)->status_checked_at)->not->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('records a message GoText could not deliver', function () {
    scriptedSms(report: DeliveryStatus::Undelivered);
    queueSms($this->acme);

    expect(delivery($this->acme)->only(['status', 'provider_status']))->toEqual(['status' => DeliveryStatus::Undelivered, 'provider_status' => 'undelivered'])
        ->and(delivery($this->acme)->failed_at)->not->toBeNull();
});

it('shows the delivery log to support only, filtered to problems', function () {
    scriptedSms(report: DeliveryStatus::Undelivered);
    queueSms($this->acme, 'reminder:1');
    scriptedSms();
    queueSms($this->acme, 'reminder:2');
    Filament::setCurrentPanel(Filament::getPanel('console'));
    seedStaffRoles();
    $console = '/'.config('app.admin_path');

    foreach (['content-manager', 'analyst', 'finance-admin'] as $role) {
        $this->flushSession();
        $this->actingAs(staffNamed(ucfirst(str_replace('-', '', $role)), [$role]), 'staff');
        $this->get($console.'/delivery-log')->assertForbidden();
    }

    $this->flushSession();
    $this->actingAs(staffNamed('Opeyemi', ['operations-manager']), 'staff');
    $rows = DB::table('notification_deliveries')->orderBy('id')->pluck('id')->all();

    Livewire::test(ListDeliveryLog::class)
        ->assertCanSeeTableRecords($rows)
        ->filterTable('problems', 'problems')
        ->assertCanSeeTableRecords([$rows[0]])
        ->assertCanNotSeeTableRecords([$rows[1]]);
});
