<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Media\Media;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Leasing\Jobs\RenderLeaseContract;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Models\TicketMessage;
use Modules\Tickets\Tickets\TicketService;

/*
 * The world of the tickets that move with their building (P04-05;
 * BILLING_PAYMENTS.md § 7.4; docs/plans/P04-subtasks.md "Settled by the
 * product owner"). Every name, phone and email is fixed; the real
 * notification pipeline runs (sync queue, the `notifications` table, the
 * array mailer), so "told once" is counted where people would see it.
 *
 * Eze Properties (`eze-properties`, a property owner's organization): Ngozi
 * Eze its owner, Emeka Eze an administrator, Sade Caretaker an agent of
 * Palm Court. Its tickets, numbered TKT-000001 to TKT-000007 in this order
 * of sending: kemiOpen, tundeResolved, gatehouse, kemiClosed, cedarOpen,
 * musaReopened, bisiOpen.
 * - Palm Court, Flat 1 (Kemi Adebayo, portal account):
 *   `kemiOpen` TKT-000001, sent by Kemi, in progress, assigned to Sade; its
 *   thread: Kemi's reply with leak.jpg, Ngozi's reply with
 *   plumber-quote.pdf, and Ngozi's internal note (INTERNAL_NOTE) with
 *   internal-quote.pdf, which must never cross;
 *   `kemiClosed` TKT-000004, resolved and confirmed (stays);
 *   `kemiDraft`, Kemi's unsent draft (moves as a draft).
 * - Palm Court, Flat 2 (Tunde Bakare, portal account): `tundeResolved`
 *   TKT-000002, raised by Ngozi, resolved on 2 January 2027.
 * - Palm Court, Gatehouse (no tenant): `gatehouse` TKT-000003, under review.
 * - Lagoon View, Suite 1 (Musa Danjuma: email, no portal account):
 *   `musaReopened` TKT-000006, resolved then reopened by Ngozi. Suite 2
 *   (Bisi Ade, phone only): `bisiOpen` TKT-000007, submitted.
 * - Cedar House, Unit A (Femi Okafor): `cedarOpen` TKT-000005, submitted
 *   (stays: its building is not handed over).
 *
 * Adeyemi Realty Ltd (`acme-homes`, an agency on Starter): Ada Owner, Seyi
 * Admin, Bola Agent (assigned to Office Block). Its own Office Block has
 * two tickets, TKT-000001 and TKT-000002, so the moved ones take
 * TKT-000003 onwards.
 *
 * The handover offers Palm Court and Lagoon View; it is accepted on
 * 4 January 2027 at 09:00 (Lagos 10:00).
 */

require_once __DIR__.'/message-world.php';
require_once __DIR__.'/../../../Handovers/tests/Feature/helpers.php';

function ticketMoveWorld(): void
{
    $test = test();
    (function () {
        Queue::fake([RenderLeaseContract::class]);
        Storage::fake('media');
        seedOrganizationRoles();
        $this->travelTo('2026-12-20 09:00:00');

        $this->landlord = Organization::factory()->create(['slug' => 'eze-properties', 'name' => 'Eze Properties', 'type' => OrganizationType::PropertyOwner, 'platform_covered' => true]);
        $this->ngozi = joinAs(User::factory()->create(['name' => 'Ngozi Eze', 'email' => 'ngozi.eze@example.com', 'phone' => '+2348051112222']), $this->landlord);
        $this->emeka = joinAs(User::factory()->create(['name' => 'Emeka Eze', 'email' => 'emeka.eze@example.com', 'phone' => '+2348051113333']), $this->landlord, OrganizationRole::Admin);
        $this->sade = joinAs(User::factory()->create(['name' => 'Sade Caretaker', 'email' => 'sade.caretaker@example.com', 'phone' => '+2348052223333']), $this->landlord, OrganizationRole::Agent);

        $this->agency = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd', 'type' => OrganizationType::Agency, 'signup_plan_key' => 'starter']);
        $this->ada = joinAs(User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada.owner@example.com', 'phone' => '+2348020000001']), $this->agency);
        $this->seyi = joinAs(User::factory()->create(['name' => 'Seyi Admin', 'email' => 'seyi.admin@example.com', 'phone' => '+2348020000002']), $this->agency, OrganizationRole::Admin);
        $this->bola = joinAs(User::factory()->create(['name' => 'Bola Agent', 'email' => 'bola.agent@example.com', 'phone' => '+2348020000003']), $this->agency, OrganizationRole::Agent);

        $this->palm = buildingIn($this->landlord, ['name' => 'Palm Court', 'slug' => 'palm-court', 'address' => '22 Fola Osibo Road, Lekki Phase 1']);
        $this->flat1 = unitIn($this->landlord, $this->palm, ['unit_label' => 'Flat 1']);
        $this->flat2 = unitIn($this->landlord, $this->palm, ['unit_label' => 'Flat 2']);
        $this->gate = unitIn($this->landlord, $this->palm, ['unit_label' => 'Gatehouse']);
        assignIn($this->landlord, $this->sade, $this->palm);
        $this->lagoon = buildingIn($this->landlord, ['name' => 'Lagoon View', 'slug' => 'lagoon-view', 'address' => '4 Ozumba Mbadiwe Avenue, Victoria Island']);
        $this->suite1 = unitIn($this->landlord, $this->lagoon, ['unit_label' => 'Suite 1']);
        $this->suite2 = unitIn($this->landlord, $this->lagoon, ['unit_label' => 'Suite 2']);
        $this->cedar = buildingIn($this->landlord, ['name' => 'Cedar House', 'slug' => 'cedar-house', 'address' => '9 Bourdillon Road, Ikoyi']);
        $this->unitA = unitIn($this->landlord, $this->cedar, ['unit_label' => 'Unit A']);

        $this->kemiUser = User::factory()->create(['name' => 'Kemi Adebayo', 'email' => 'kemi.adebayo@example.com', 'phone' => '+2348031112222']);
        $this->tundeUser = User::factory()->create(['name' => 'Tunde Bakare', 'email' => 'tunde.bakare@example.com', 'phone' => '+2348034445555']);
        $this->kemi = tenantIn($this->landlord, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222', 'email' => 'kemi.adebayo@example.com', 'user_id' => $this->kemiUser->id]);
        $this->tunde = tenantIn($this->landlord, ['first_name' => 'Tunde', 'last_name' => 'Bakare', 'phone' => '+2348034445555', 'email' => 'tunde.bakare@example.com', 'user_id' => $this->tundeUser->id]);
        $this->musa = tenantIn($this->landlord, ['first_name' => 'Musa', 'last_name' => 'Danjuma', 'phone' => '+2348036667777', 'email' => 'musa.danjuma@example.com']);
        $this->bisi = tenantIn($this->landlord, ['first_name' => 'Bisi', 'last_name' => 'Ade', 'phone' => '+2348037778888', 'email' => null]);
        $this->femi = tenantIn($this->landlord, ['first_name' => 'Femi', 'last_name' => 'Okafor', 'phone' => '+2348039990000', 'email' => 'femi.okafor@example.com']);

        $this->kemiLease = activeLease($this->landlord, $this->ngozi, $this->flat1, $this->kemi);
        $this->tundeLease = activeLease($this->landlord, $this->ngozi, $this->flat2, $this->tunde, ['rent' => '1,200,000']);
        $this->musaLease = activeLease($this->landlord, $this->ngozi, $this->suite1, $this->musa, ['rent' => '900,000']);
        $this->bisiLease = activeLease($this->landlord, $this->ngozi, $this->suite2, $this->bisi, ['rent' => '800,000']);
        $this->femiLease = activeLease($this->landlord, $this->ngozi, $this->unitA, $this->femi, ['rent' => '2,000,000']);

        // The agency's own building and tickets.
        $this->office = buildingIn($this->agency, ['name' => 'Office Block', 'slug' => 'office-block', 'address' => '1 Marina Road, Lagos Island']);
        $this->office1 = unitIn($this->agency, $this->office, ['unit_label' => 'Office 1']);
        unitIn($this->agency, $this->office, ['unit_label' => 'Office 2']);
        assignIn($this->agency, $this->bola, $this->office);
        $this->agencyOwn = [
            raiseTicket($this->agency, $this->ada, $this->office1, null, ['title' => 'Lift stuck on 2nd floor']),
            raiseTicket($this->agency, $this->ada, $this->office1, null, ['title' => 'Broken window']),
        ];

        // The landlord's tickets.
        $this->travelTo('2026-12-28 10:00:00');
        $this->kemiOpen = tenantTicket($this->landlord, $this->kemiUser, $this->kemiLease, ['title' => 'Kitchen sink leaking']);
        ticketsDo($this->landlord, fn (TicketService $s) => $s->assign($this->ngozi, $this->kemiOpen, $this->sade->id));
        ticketsDo($this->landlord, fn (TicketService $s) => $s->startWork($this->sade, $this->kemiOpen));
        tenantReply($this->landlord, $this->kemiUser, $this->kemiOpen, 'Here is a photo of the leak.', [ticketPhoto('leak.jpg')]);
        $this->travelTo('2026-12-29 11:00:00');
        staffReply($this->landlord, $this->ngozi, $this->kemiOpen, 'The plumber comes on Monday; his quote is attached.', [ticketPdf('plumber-quote.pdf')]);
        staffReply($this->landlord, $this->ngozi, $this->kemiOpen, INTERNAL_NOTE, [ticketPdf('internal-quote.pdf')], internal: true);

        $this->tundeResolved = raiseTicket($this->landlord, $this->ngozi, $this->flat2, $this->tunde, ['title' => 'Bathroom light flickers', 'category' => 'electrical', 'priority' => 'medium']);
        $this->gatehouse = raiseTicket($this->landlord, $this->ngozi, $this->gate, null, ['title' => 'Gate motor noisy', 'category' => 'security']);
        ticketsDo($this->landlord, fn (TicketService $s) => $s->startReview($this->ngozi, $this->gatehouse));

        $this->kemiClosed = tenantTicket($this->landlord, $this->kemiUser, $this->kemiLease, ['title' => 'Door handle loose']);
        ticketsDo($this->landlord, fn (TicketService $s) => $s->resolve($this->ngozi, $this->kemiClosed, 'Tightened.'));
        ticketsDo($this->landlord, fn (TicketService $s) => $s->confirmResolution($this->kemiUser, $this->kemiClosed));

        $this->cedarOpen = raiseTicket($this->landlord, $this->ngozi, $this->unitA, $this->femi, ['title' => 'Air conditioner leaking']);
        $this->musaReopened = raiseTicket($this->landlord, $this->ngozi, $this->suite1, $this->musa, ['title' => 'Water heater cold']);
        ticketsDo($this->landlord, fn (TicketService $s) => $s->resolve($this->ngozi, $this->musaReopened));
        ticketsDo($this->landlord, fn (TicketService $s) => $s->reopen($this->ngozi, $this->musaReopened, 'Still cold in the morning.'));
        $this->bisiOpen = raiseTicket($this->landlord, $this->ngozi, $this->suite2, $this->bisi, ['title' => 'Window will not close']);
        $this->kemiDraft = draftTicket($this->landlord, $this->kemiUser, $this->kemiLease, ['title' => 'Wardrobe door off its hinge', 'description' => null]);

        $this->travelTo('2027-01-02 15:00:00');
        ticketsDo($this->landlord, fn (TicketService $s) => $s->resolve($this->ngozi, $this->tundeResolved, 'Replaced the starter.'));

        $this->travelTo('2027-01-04 09:00:00');
    })->call($test);
}

/** The world's handover of Palm Court and Lagoon View, offered by Ngozi and accepted by Ada now. */
function acceptTicketHandover(): void
{
    $test = test();
    $test->handover = offerHandover($test->ngozi, $test->landlord, $test->agency, [$test->palm, $test->lagoon]);
    acceptHandover($test->ada, $test->agency, incomingHandover($test->agency, $test->handover));
}

/** The receiving organization's ticket that came from $original (null when none did). */
function movedCopy(Organization $to, Ticket $original): ?Ticket
{
    return inOrganization($to, fn (): ?Ticket => Ticket::query()->withTrashed()->where('moved_from_ticket_id', $original->id)->first());
}

/** The ticket's messages as [author kind, author, body, internal], oldest first, with the times they say they were written. */
function movedThread(Organization $organization, Ticket $ticket): array
{
    return inOrganization($organization, fn (): array => TicketMessage::query()->where('ticket_id', $ticket->id)->orderBy('id')->get()
        ->map(fn (TicketMessage $message): array => [$message->author_kind->value, $message->author_user_id, $message->body, $message->is_internal, $message->created_at?->toDateTimeString(), $message->attachment_count])->all());
}

/**
 * The files of every message of the ticket, as [name, mime, sha256, organization, file on the disk].
 *
 * @return list<array{string, string, string, int, bool}>
 */
function movedFiles(Organization $organization, Ticket $ticket): array
{
    return inOrganization($organization, fn (): array => Media::query()
        ->where('model_type', (new TicketMessage)->getMorphClass())
        ->whereIn('model_id', TicketMessage::query()->where('ticket_id', $ticket->id)->select('id'))
        ->orderBy('id')->get()
        ->map(fn (Media $media): array => [(string) $media->name, (string) $media->mime_type, (string) $media->getCustomProperty('sha256'), (int) $media->organization_id, Storage::disk($media->disk)->exists($media->getPathRelativeToRoot())])
        ->all());
}

/**
 * What each address was told about tickets that moved while $action ran:
 * `app` per in-app notification and `mail` per email of the move
 * (`ticket.moved_in`), and `other:{event}` for any other ticket notice.
 *
 * @return array<string, list<string>>
 */
function ticketMoveTold(Closure $action): array
{
    DB::table('notifications')->delete();
    app('mailer')->getSymfonyTransport()->flush();

    $action();

    $told = [];

    foreach (DB::table('notifications')->orderBy('created_at')->get() as $row) {
        $data = json_decode((string) $row->data, true);

        if (! str_starts_with((string) ($data['event'] ?? ''), 'ticket.')) {
            continue;
        }

        $told[(string) User::query()->findOrFail($row->notifiable_id)->email][] = $data['event'] === 'ticket.moved_in' ? 'app' : 'other:'.$data['event'];
    }

    foreach (app('mailer')->getSymfonyTransport()->messages() as $message) {
        $subject = (string) $message->getOriginalMessage()->getSubject();

        if (! preg_match('/now handled by|moved here from|TKT-/', $subject)) {
            continue;
        }

        foreach ($message->getOriginalMessage()->getTo() as $address) {
            $told[$address->getAddress()][] = preg_match('/now handled by|moved here from/', $subject) ? 'mail' : 'other-mail';
        }
    }

    ksort($told);

    return array_map(function (array $lines): array {
        sort($lines);

        return $lines;
    }, $told);
}
