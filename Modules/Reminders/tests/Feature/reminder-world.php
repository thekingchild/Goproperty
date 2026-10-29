<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Reminders\Enums\ReminderStatus;
use Modules\Reminders\Models\Reminder;

/*
 * The Reminders module's test world (P05-01). Bills are made by the
 * scheduled `bills-generate` job and flagged by `bills-flag-overdue`, as in
 * production; reminders by the scheduled `reminders-dispatch` job.
 *
 * Adeyemi Realty (acme-homes): Ada the owner, Seyi an administrator, Bola
 * an agent assigned to Palm Court, Chika an agent with no assignment. Palm
 * Court has Flat 3B (Kemi: portal account and email) and Flat 4C; Oak
 * Lodge has Unit 1 (Femi: email, no portal account) and Unit 2. Bello
 * Homes (other-realty) has its own owner and tenant (Musa).
 *
 * Leases are made per test (none in the world), from 1 January 2027
 * unless a test says otherwise, while the clock stands on 20 November 2026.
 */

require_once __DIR__.'/../../../Billing/tests/Feature/utility-bill-helpers.php';
require_once __DIR__.'/../../../Payments/tests/Feature/helpers.php';
require_once __DIR__.'/../../../Tenants/tests/Feature/Portal/helpers.php';

const RM_KEMI = 'kemi.adebayo@example.com';
const RM_FEMI = 'femi.okon@example.com';
const RM_ADA = 'ada.owner@example.com';
const RM_SEYI = 'seyi.admin@example.com';
const RM_BOLA = 'bola.agent@example.com';
const RM_CHIKA = 'chika.agent@example.com';

function reminderWorld(): void
{
    $test = test();
    (function () {
        seedOrganizationRoles();
        holdReceiptPdfs();
        $this->travelTo('2026-11-20 10:00:00');

        $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
        $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes Ltd']);
        $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner', 'email' => RM_ADA]), $this->acme);
        $this->admin = joinAs(User::factory()->create(['name' => 'Seyi Admin', 'email' => RM_SEYI]), $this->acme, OrganizationRole::Admin);
        $this->agent = joinAs(User::factory()->create(['name' => 'Bola Agent', 'email' => RM_BOLA]), $this->acme, OrganizationRole::Agent);
        $this->freeAgent = joinAs(User::factory()->create(['name' => 'Chika Agent', 'email' => RM_CHIKA]), $this->acme, OrganizationRole::Agent);
        $this->otherOwner = joinAs(User::factory()->create(['name' => 'Chidi Owner', 'email' => 'chidi.owner@example.com']), $this->other);

        $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
        $this->oak = buildingIn($this->acme, ['name' => 'Oak Lodge', 'slug' => 'oak-lodge']);
        assignIn($this->acme, $this->agent, $this->palm);

        $this->flat3b = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 3B']);
        $this->flat4c = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 4C']);
        $this->oakUnit = unitIn($this->acme, $this->oak, ['unit_label' => 'Unit 1']);
        $this->oakUnit2 = unitIn($this->acme, $this->oak, ['unit_label' => 'Unit 2']);

        $this->kemi = User::factory()->create(['name' => 'Kemi Adebayo', 'email' => RM_KEMI, 'phone' => '+2348031112222']);
        $this->kemiAtAcme = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222', 'email' => RM_KEMI, 'user_id' => $this->kemi->id]);
        $this->femi = tenantIn($this->acme, ['first_name' => 'Femi', 'last_name' => 'Okon', 'phone' => '+2348036660000', 'email' => RM_FEMI]);
        $this->phoneOnly = tenantIn($this->acme, ['first_name' => 'Musa', 'last_name' => 'Bello', 'phone' => '+2348037770000', 'email' => null]);

        $foreignBuilding = buildingIn($this->other, ['name' => 'Foreign Court', 'slug' => 'foreign-court']);
        $this->foreignUnit = unitIn($this->other, $foreignBuilding, ['unit_label' => 'Suite 9']);
        $this->foreignTenant = tenantIn($this->other, ['first_name' => 'Foreign', 'last_name' => 'Tenant', 'phone' => '+2348035550000', 'email' => 'foreign.tenant@example.com']);
    })->call($test);
}

/** The scheduled `reminders-dispatch` event, checked against its cron line. */
function remindersScheduledEvent(): CallbackEvent
{
    $event = collect(app(Schedule::class)->events())->first(fn (ScheduledEvent $event): bool => $event->description === 'reminders-dispatch');

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->expression)->toBe('35 * * * *');

    return $event;
}

/** Runs `reminders-dispatch` as the scheduler does: the job, queued on `notifications`, runs inline (sync queue). */
function runReminders(): void
{
    remindersScheduledEvent()->run(app());
}

/**
 * One day (Lagos) as production lives it: bills generated (01:10 Lagos),
 * bills flagged overdue (02:00), reminders planned before the send time
 * (07:35), sent (09:35), and the job running again (10:35 and 23:35)
 * without sending anything more.
 */
function liveReminderDay(string $date): void
{
    test()->travelTo($date.' 00:10:00');
    runBillGeneration();
    test()->travelTo($date.' 01:00:00');
    runOverdueFlagging();

    foreach (['06:35', '08:35', '09:35', '22:35'] as $time) {
        test()->travelTo($date.' '.$time.':00');
        runReminders();
    }
}

/**
 * Lives every day from $from to $to (inclusive) and returns what was sent
 * on each day it sent something: "type offset channel recipient", sorted.
 *
 * @return array<string, list<string>>
 */
function liveReminderDays(string $from, string $to, ?Organization $organization = null): array
{
    for ($day = CarbonImmutable::parse($from); $day->lessThanOrEqualTo(CarbonImmutable::parse($to)); $day = $day->addDay()) {
        liveReminderDay($day->toDateString());
    }

    return sentReminders($organization ?? test()->acme);
}

/**
 * What was sent so far, by Lagos day: "type offset channel recipient", sorted.
 *
 * @return array<string, list<string>>
 */
function sentReminders(Organization $organization): array
{
    $sent = [];

    foreach (remindersOf($organization)->where('status', ReminderStatus::Sent) as $reminder) {
        $sent[$reminder->send_on->toDateString()][] = reminderLabel($reminder);
    }

    ksort($sent);

    return array_map(function (array $lines): array {
        sort($lines);

        return $lines;
    }, $sent);
}

function reminderLabel(Reminder $reminder): string
{
    $offset = $reminder->offset_days === 0 ? 'T-0' : ($reminder->offset_days < 0 ? 'T'.$reminder->offset_days : '+'.$reminder->offset_days);

    return $reminder->type->value.' '.$offset.' '.$reminder->channel->value.' '.($reminder->recipient_email ?? $reminder->recipient);
}

/** Every reminder row of the organization, oldest first. */
function remindersOf(Organization $organization): Collection
{
    return inOrganization($organization, fn () => Reminder::query()->orderBy('id')->get());
}

/**
 * What each address was told while $action ran: `app:{event}` per in-app
 * notification, `mail:{subject}` per email.
 *
 * @return array<string, list<string>>
 */
function remindedBy(Closure $action): array
{
    DB::table('notifications')->delete();
    app('mailer')->getSymfonyTransport()->flush();

    $action();

    $told = [];

    foreach (DB::table('notifications')->orderBy('created_at')->get() as $row) {
        $data = json_decode((string) $row->data, true);
        $told[(string) User::query()->findOrFail($row->notifiable_id)->email][] = 'app:'.$data['event'];
    }

    foreach (app('mailer')->getSymfonyTransport()->messages() as $message) {
        foreach ($message->getOriginalMessage()->getTo() as $address) {
            $told[$address->getAddress()][] = 'mail:'.$message->getOriginalMessage()->getSubject();
        }
    }

    ksort($told);

    return $told;
}

/** Every email sent so far, as [to, subject, text body]. */
function reminderMails(): array
{
    return array_map(fn ($message): array => [
        $message->getOriginalMessage()->getTo()[0]->getAddress(),
        (string) $message->getOriginalMessage()->getSubject(),
        (string) $message->getOriginalMessage()->getTextBody(),
    ], app('mailer')->getSymfonyTransport()->messages()->all());
}
