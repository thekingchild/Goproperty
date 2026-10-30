<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Mail\Suppression\EmailWebhookDelivery;
use App\Mail\Suppression\SuppressionReason;
use App\Mail\Suppression\Suppressions;
use App\Models\EmailSuppression;
use App\Models\User;
use App\Notifications\EmailAddressSuppressed;
use App\Platform\Filament\Resources\EmailSuppressions\Pages\ListEmailSuppressions;
use Filament\Facades\Filament;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
 * P05-04: bounce and complaint suppression (PLATFORM_CONSOLE.md § 5
 * "Delivery"). Acceptance: "suppressed addresses are skipped" — by every
 * email the application sends (reminders: ReminderTemplatesTest).
 */

const WEBHOOK_TOKEN = 'test-webhook-token-0123456789abcdef';

beforeEach(function () {
    config(['mail.webhooks.token' => WEBHOOK_TOKEN, 'mail.default' => 'array']);
    $this->suppressions = app(Suppressions::class);
});

final class PlainNotice extends BaseNotification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Plain notice')->line('Hello');
    }
}

/** @return list<string> every address that received mail */
function mailedTo(): array
{
    $to = [];

    foreach (app('mailer')->getSymfonyTransport()->messages() as $message) {
        foreach (['getTo', 'getCc', 'getBcc'] as $kind) {
            foreach ($message->getOriginalMessage()->{$kind}() as $address) {
                $to[] = $address->getAddress();
            }
        }
    }

    sort($to);

    return $to;
}

function postmark(array $payload, string $token = WEBHOOK_TOKEN): TestResponse
{
    return test()->postJson('/webhooks/email/postmark/'.$token, $payload);
}

describe('acceptance: suppressed addresses are skipped', function () {
    it('sends no notification email to a suppressed address, on demand or to an account, and still sends the others', function () {
        $this->suppressions->suppress('Gone@Example.com', SuppressionReason::Bounce, 'postmark');
        $gone = User::factory()->create(['email' => 'gone@example.com']);
        $fine = User::factory()->create(['email' => 'fine@example.com']);

        Notification::route('mail', 'gone@example.com')->notify(new PlainNotice);
        Notification::route('mail', ['GONE@example.com' => 'Gone'])->notify(new PlainNotice);
        $gone->notify(new PlainNotice);
        $fine->notify(new PlainNotice);
        Notification::route('mail', 'other@example.com')->notify(new PlainNotice);

        expect(mailedTo())->toBe(['fine@example.com', 'other@example.com']);
    });

    it('drops suppressed To, Cc and Bcc addresses from any email, and sends nothing when none is left', function () {
        $this->suppressions->suppress('gone@example.com', SuppressionReason::Complaint, 'mailgun');

        Mail::raw('Hi', fn ($message) => $message->to(['gone@example.com', 'kept@example.com'])->cc('GONE@example.com')->bcc(['gone@example.com', 'audit@example.com'])->subject('Mixed'));
        Mail::raw('Hi', fn ($message) => $message->to('gone@example.com')->subject('Only gone'));

        $subjects = collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($m) => $m->getOriginalMessage()->getSubject())->all();
        expect(mailedTo())->toBe(['audit@example.com', 'kept@example.com'])
            ->and($subjects)->toBe(['Mixed']);
    });

    it('mails an address again once staff lift its suppression, and suppresses it again on the next bounce', function () {
        $this->suppressions->suppress('back@example.com', SuppressionReason::Bounce, 'postmark');
        $row = EmailSuppression::query()->sole();

        expect($this->suppressions->lift($row, staffMember()))->toBeTrue();
        Notification::route('mail', 'back@example.com')->notify(new PlainNotice);
        expect(mailedTo())->toBe(['back@example.com']);

        expect($this->suppressions->suppress('back@example.com', SuppressionReason::Complaint, 'mailgun'))->toBeTrue()
            ->and(EmailSuppression::query()->sole()->only(['reason', 'provider', 'lifted_at']))->toEqual(['reason' => SuppressionReason::Complaint, 'provider' => 'mailgun', 'lifted_at' => null])
            ->and($this->suppressions->isSuppressed('BACK@example.com'))->toBeTrue();
    });
});

describe('the provider webhook', function () {
    it('suppresses a hard bounce and a spam complaint from Postmark, and keeps every call', function () {
        postmark(['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => 'Hard@Example.com', 'Description' => 'The server was unable to deliver your message'])
            ->assertOk()->assertJson(['suppressed' => 1]);
        postmark(['RecordType' => 'SpamComplaint', 'Type' => 'SpamComplaint', 'Email' => 'spam@example.com'])->assertOk()->assertJson(['suppressed' => 1]);
        postmark(['RecordType' => 'Bounce', 'Type' => 'SoftBounce', 'Email' => 'soft@example.com'])->assertOk()->assertJson(['suppressed' => 0]);
        postmark(['RecordType' => 'Delivery', 'Email' => 'ok@example.com'])->assertOk()->assertJson(['suppressed' => 0]);
        postmark(['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => 'hard@example.com'])->assertOk()->assertJson(['suppressed' => 0]);

        expect(EmailSuppression::query()->orderBy('email')->get()->map(fn (EmailSuppression $s) => [$s->email, $s->reason->value, $s->provider])->all())
            ->toBe([['hard@example.com', 'bounce', 'postmark'], ['spam@example.com', 'complaint', 'postmark']])
            ->and(EmailWebhookDelivery::query()->orderBy('id')->pluck('outcome')->all())->toBe(['suppressed', 'suppressed', 'ignored', 'ignored', 'duplicate'])
            ->and(EmailWebhookDelivery::query()->first()->only(['provider', 'event', 'signature_valid']))->toBe(['provider' => 'postmark', 'event' => 'Bounce HardBounce', 'signature_valid' => true])
            ->and(DB::table('webhook_log')->whereNull('organization_id')->count())->toBe(5)
            ->and(AuditEntry::query()->where('event', 'email.suppressed')->count())->toBe(2);
    });

    it('answers 404 to a wrong token, and to every token while none is configured, keeping nothing', function () {
        postmark(['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => 'a@example.com'], 'wrong-token-0123456789abcdefgh')->assertNotFound();
        $this->postJson('/webhooks/email/sendgrid/'.WEBHOOK_TOKEN, [])->assertNotFound();

        config(['mail.webhooks.token' => null]);
        postmark(['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => 'a@example.com'])->assertNotFound();

        expect(EmailSuppression::query()->count())->toBe(0)
            ->and(DB::table('webhook_log')->count())->toBe(0);
    });

    it('checks Mailgun\'s signature when its signing key is set', function () {
        config(['services.mailgun.webhook_signing_key' => 'mg-signing-key']);
        $signed = function (array $event, string $key = 'mg-signing-key'): array {
            $timestamp = (string) time();
            $token = 'tok'.random_int(1000, 9999);

            return ['signature' => ['timestamp' => $timestamp, 'token' => $token, 'signature' => hash_hmac('sha256', $timestamp.$token, $key)], 'event-data' => $event];
        };

        $this->postJson('/webhooks/email/mailgun/'.WEBHOOK_TOKEN, $signed(['event' => 'failed', 'severity' => 'permanent', 'recipient' => 'bounced@example.com', 'delivery-status' => ['description' => 'No such mailbox']]))
            ->assertOk()->assertJson(['suppressed' => 1]);
        $this->postJson('/webhooks/email/mailgun/'.WEBHOOK_TOKEN, $signed(['event' => 'complained', 'recipient' => 'angry@example.com']))->assertOk();
        $this->postJson('/webhooks/email/mailgun/'.WEBHOOK_TOKEN, $signed(['event' => 'failed', 'severity' => 'temporary', 'recipient' => 'later@example.com']))->assertOk()->assertJson(['suppressed' => 0]);
        $this->postJson('/webhooks/email/mailgun/'.WEBHOOK_TOKEN, $signed(['event' => 'complained', 'recipient' => 'forged@example.com'], 'wrong-key'))->assertStatus(401);

        expect(EmailSuppression::query()->orderBy('email')->pluck('reason', 'email')->map->value->all())->toBe(['angry@example.com' => 'complaint', 'bounced@example.com' => 'bounce'])
            ->and(EmailSuppression::query()->where('email', 'bounced@example.com')->value('detail'))->toBe('No such mailbox')
            ->and(EmailWebhookDelivery::query()->where('signature_valid', false)->sole()->outcome)->toBe('rejected');
    });

    it('tells the people whose account uses a newly suppressed address in the app, once', function () {
        $user = User::factory()->create(['email' => 'kemi@example.com']);

        postmark(['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => 'KEMI@example.com'])->assertOk();
        postmark(['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => 'kemi@example.com'])->assertOk();

        $notices = DB::table('notifications')->where('notifiable_id', $user->id)->get();
        expect($notices)->toHaveCount(1)
            ->and($notices[0]->type)->toBe(EmailAddressSuppressed::class)
            ->and(json_decode((string) $notices[0]->data, true))->toMatchArray(['event' => 'email.suppressed', 'reason' => 'bounce', 'url' => route('profile.edit')])
            ->and(mailedTo())->toBe([]);
    });
});

describe('the console', function () {
    beforeEach(function () {
        Filament::setCurrentPanel(Filament::getPanel('console'));
        seedStaffRoles();
        $this->console = '/'.config('app.admin_path');
        $this->suppressions->suppress('gone@example.com', SuppressionReason::Bounce, 'postmark', 'Mailbox does not exist');
        $this->row = EmailSuppression::query()->sole();
    });

    it('shows suppressed addresses to support and technical admins, and to nobody else', function () {
        foreach (['support-agent', 'technical-admin', 'super-admin'] as $role) {
            $this->flushSession();
            $this->actingAs(staffNamed(ucfirst(str_replace('-', '', $role)), [$role]), 'staff');
            $this->get($this->console.'/email-suppressions')->assertOk()->assertSee('gone@example.com');
        }

        foreach (['content-manager', 'analyst', 'finance-admin'] as $role) {
            $this->flushSession();
            $this->actingAs(staffNamed(ucfirst(str_replace('-', '', $role)), [$role]), 'staff');
            $this->get($this->console.'/email-suppressions')->assertForbidden();
        }
    });

    it('lets a technical admin allow an address again and add one, audited; support only looks', function () {
        $tech = staffNamed('Tobi', ['technical-admin']);
        $support = staffNamed('Sade', ['support-agent']);

        $this->actingAs($support, 'staff');
        Livewire::test(ListEmailSuppressions::class)
            ->assertTableActionHidden('lift', $this->row)
            ->assertActionHidden('suppress');

        $this->actingAs($tech, 'staff');
        Livewire::test(ListEmailSuppressions::class)
            ->callTableAction('lift', $this->row)
            ->assertNotified('Email goes to gone@example.com again')
            ->callAction('suppress', data: ['email' => 'Manual@Example.com', 'detail' => 'Asked by the owner'])
            ->assertNotified('No email goes to Manual@Example.com from now on');

        expect($this->row->refresh()->lifted_by_staff_id)->toBe($tech->id)
            ->and($this->suppressions->isSuppressed('gone@example.com'))->toBeFalse()
            ->and(EmailSuppression::query()->where('email', 'manual@example.com')->sole()->reason)->toBe(SuppressionReason::Manual)
            ->and(AuditEntry::query()->where('event', 'email.suppression_lifted')->sole()->causer_id)->toBe($tech->id)
            ->and(AuditEntry::query()->where('event', 'email.suppressed')->latest('id')->first()->causer_id)->toBe($tech->id)
            ->and(fn () => $this->row->delete())->toThrow(LogicException::class);
    });
});
