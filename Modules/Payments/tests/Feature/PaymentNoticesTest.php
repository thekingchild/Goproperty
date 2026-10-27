<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use App\WhatsApp\LogWhatsAppSender;
use App\WhatsApp\WhatsAppMessage;
use App\WhatsApp\WhatsAppSender;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\Payments\Contracts\PaymentDraft;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Contracts\PaymentRecorder;
use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Events\PaymentConfirmed;
use Modules\Payments\Events\PaymentRecorded;
use Modules\Payments\Events\PaymentRejected;
use Modules\Payments\Events\ReceiptVoided;
use Modules\Payments\Listeners\TellTenantAboutPayment;
use Modules\Payments\Models\Payment;
use Modules\Payments\Notifications\TenantPaymentNotice;
use Modules\Payments\Payments\PaymentService;
use Modules\Tenants\Models\TenantProfile;

require_once __DIR__.'/receipt-helpers.php';

/*
 * P03-10b: the tenant is told about every payment transition — recorded,
 * confirmed (with the receipt), rejected (with the reason), receipt voided —
 * exactly once each (ARCHITECTURE.md § 6.4), after the step commits: in the
 * app with a portal account and by email (an address on record without an
 * account gets the email alone); a tenant with no email address gets the
 * agency's WhatsApp (the stand-in sender until P05-06, P03 decision 3).
 * Never SMS.
 */

beforeEach(function () {
    holdReceiptPdfs();
    seedOrganizationRoles();
    $this->travelTo('2026-12-25 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Tunde Bakare']), $this->acme);
    $this->admin = joinAs(User::factory()->create(['name' => 'Ngozi Eze']), $this->acme, OrganizationRole::Admin);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348030000001', 'email' => 'kemi@example.test']);
    // Kemi uses the tenant portal (P03-01b): her profile is linked to her account, no workspace role.
    $this->portal = User::factory()->create(['name' => 'Kemi Adebayo', 'email' => 'kemi@example.test']);
    inOrganization($this->acme, fn () => TenantProfile::query()->findOrFail($this->kemi->id)->forceFill(['user_id' => $this->portal->id])->save());
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']), $this->kemi, ['billingCycle' => 'monthly', 'rent' => '100,000', 'deposit' => '0']);
    runBillGeneration();
});

afterEach(fn () => billsFreshRequest());

/**
 * The payment notices sent to the user, as [event, payment id, channels].
 *
 * @return list<array{string, int, list<string>}>
 */
function paymentNoticesTo(User $user): array
{
    return Notification::sent($user, TenantPaymentNotice::class)
        ->map(fn (TenantPaymentNotice $notice): array => [$notice->event->value, $notice->paymentId, $notice->via($user)])
        ->values()->all();
}

/** @return list<WhatsAppMessage> */
function paymentWhatsApps(): array
{
    $sender = app(WhatsAppSender::class);
    assert($sender instanceof LogWhatsAppSender);

    return array_map(fn (array $sent): WhatsAppMessage => $sent['message'], $sender->sent());
}

describe('sub: each transition notifies the tenant exactly once (mail + database; no SMS)', function () {
    it('tells the tenant once each when a payment is recorded, confirmed, another rejected, and the receipt voided', function () {
        Notification::fake();

        $first = recordPayment($this->acme, $this->owner, $this->kemi, '100,000');
        confirmPayment($this->acme, $this->owner, $first);
        $second = recordPayment($this->acme, $this->owner, $this->kemi, '40,000');
        inOrganization($this->acme, fn () => app(PaymentService::class)->reject($this->owner, $second, 'The transfer never arrived'));
        $receipt = receiptOf($this->acme, $first);
        requestReceiptVoid($this->acme, $this->admin, $receipt, 'Recorded against the wrong tenant');
        approveReceiptVoid($this->acme, $this->owner, $receipt);

        $both = ['database', 'mail'];
        expect(paymentNoticesTo($this->portal))->toBe([
            ['payment.recorded', $first->id, $both],
            ['payment.confirmed', $first->id, $both],
            ['payment.recorded', $second->id, $both],
            ['payment.rejected', $second->id, $both],
            ['receipt.voided', $first->id, $both],
        ])
            ->and(noticesOf($this->acme, $first))->toBe([['payment.recorded', ['database', 'mail']], ['payment.confirmed', ['database', 'mail']], ['receipt.voided', ['database', 'mail']]])
            ->and(noticesOf($this->acme, $second))->toBe([['payment.recorded', ['database', 'mail']], ['payment.rejected', ['database', 'mail']]])
            ->and(paymentWhatsApps())->toBe([]);

        // Each notice says what happened, with the receipt or the reason.
        $sent = Notification::sent($this->portal, TenantPaymentNotice::class)->values();
        $confirmed = $sent[1]->toMail($this->portal);
        expect($confirmed->subject)->toBe("Receipt {$receipt->number} for your payment of ₦100,000")
            ->and(implode(' ', $confirmed->introLines))->toContain('Adeyemi Realty Ltd confirmed your bank transfer payment of ₦100,000 (paid on 25/12/2026).', $receipt->number)
            ->and($confirmed->actionUrl)->toContain('/verify/'.$receipt->verification_code)
            ->and($sent[3]->toArray($this->portal))->toMatchArray(['event' => 'payment.rejected', 'reason' => 'The transfer never arrived', 'amount' => '₦40,000'])
            ->and($sent[4]->toArray($this->portal))->toMatchArray(['event' => 'receipt.voided', 'receipt_number' => $receipt->number, 'reason' => 'Recorded against the wrong tenant']);

        // Every transition announced again (a replayed event, a retried listener): nobody is told twice.
        $voided = storedReceipt($this->acme, $receipt);
        event(new PaymentRecorded($first->id, $this->acme->id, $this->kemi->id, 10_000_000, 'bank_transfer', 'manual', $this->owner->id));
        event(new PaymentConfirmed($first->id, $this->acme->id, $this->kemi->id, 10_000_000, 'bank_transfer', 'manual', $this->owner->id, [], 0));
        event(new PaymentRejected($second->id, $this->acme->id, $this->kemi->id, 4_000_000, 'bank_transfer', 'manual', $this->owner->id, 'The transfer never arrived'));
        event(new ReceiptVoided($voided->id, $first->id, $this->acme->id, $this->kemi->id, $voided->number, 10_000_000, 'Recorded against the wrong tenant', $this->admin->id, $this->owner->id, false));
        app(TellTenantAboutPayment::class)->handle(new PaymentRecorded($second->id, $this->acme->id, $this->kemi->id, 4_000_000, 'bank_transfer', 'manual', $this->owner->id));

        expect(Notification::sent($this->portal, TenantPaymentNotice::class))->toHaveCount(5)
            ->and(DB::table('payment_notices')->count())->toBe(5);
        Notification::assertNothingSentTo(new AnonymousNotifiable);

        // Never SMS: every channel a notice used is in the app, email or WhatsApp.
        expect(DB::table('payment_notices')->pluck('channels')->flatMap(fn (string $channels): array => json_decode($channels, true))->unique()->sort()->values()->all())->toBe(['database', 'mail']);
    });

    it('keeps the in-app copies and sends the emails for real (database notifications rows, mails)', function () {
        $payment = confirmedPayment($this->acme, $this->owner, $this->kemi, '100,000');

        $rows = DB::table('notifications')->where('notifiable_id', $this->portal->id)->orderBy('created_at')->orderBy('id')->get();
        $mails = collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($message) => $message->getOriginalMessage()->getSubject())->values()->all();

        expect($rows)->toHaveCount(2)
            ->and($rows->map(fn ($row) => json_decode($row->data, true)['event'])->sort()->values()->all())->toBe(['payment.confirmed', 'payment.recorded'])
            ->and($mails)->toContain('Adeyemi Realty Ltd recorded your payment of ₦100,000', 'Receipt '.receiptOf($this->acme, $payment)->number.' for your payment of ₦100,000')
            ->and(count($mails))->toBe(2);
    });

    it('tells the tenant once when a payment is recorded and confirmed in one step: the confirmation', function () {
        Notification::fake();

        $cash = inOrganization($this->acme, fn () => app(PaymentService::class)->recordAndConfirm($this->owner, paymentInput($this->kemi, '5,000', ['method' => 'cash', 'note' => 'At the office'])));
        $paystack = inOrganization($this->acme, fn () => app(PaymentRecorder::class)->recordConfirmed(receiptDraftFor($this->kemi->id, 'paystack:T-9', 7_000)));
        $statement = inOrganization($this->acme, fn () => app(PaymentRecorder::class)->record(receiptDraftFor($this->kemi->id, 'statement-line:3', 9_000)));
        // The same statement line again: recorded once, told once.
        inOrganization($this->acme, fn () => app(PaymentRecorder::class)->record(receiptDraftFor($this->kemi->id, 'statement-line:3', 9_000)));

        expect(paymentNoticesTo($this->portal))->toBe([
            ['payment.confirmed', $cash->id, ['database', 'mail']],
            ['payment.confirmed', $paystack->paymentId, ['database', 'mail']],
            ['payment.recorded', $statement->paymentId, ['database', 'mail']],
        ]);
    });

    it('tells nobody about a step that is refused or rolled back', function () {
        $pending = recordPayment($this->acme, $this->owner, $this->kemi, '100,000');
        Notification::fake();

        expect(fn () => inOrganization($this->acme, fn () => DB::transaction(function () use ($pending): void {
            app(PaymentService::class)->confirm($this->owner, $pending);

            throw new RuntimeException('Rolled back after confirming.');
        })))->toThrow(RuntimeException::class);
        expect(fn () => inOrganization($this->acme, fn () => app(PaymentService::class)->reject($this->owner, $pending, '')))->toThrow(ValidationException::class);

        Notification::assertNothingSent();
        expect(noticesOf($this->acme, $pending))->toBe([['payment.recorded', ['database', 'mail']]]);
    });
});

it('emails a tenant without a portal account at the address on record, with no in-app copy', function () {
    Notification::fake();
    $tola = tenantIn($this->acme, ['first_name' => 'Tola', 'last_name' => 'Ade', 'phone' => '+2348030000002', 'email' => 'tola@example.test']);
    $payment = confirmedPayment($this->acme, $this->owner, $tola, '10,000');

    Notification::assertSentOnDemandTimes(TenantPaymentNotice::class, 2);
    Notification::assertSentOnDemand(TenantPaymentNotice::class, fn (TenantPaymentNotice $notice, array $channels, AnonymousNotifiable $notifiable): bool => $notice->event->value === 'payment.confirmed'
        && $notice->paymentId === $payment->id
        && $channels === ['mail']
        && ($notifiable->routes['mail'] ?? null) === 'tola@example.test');
    expect(noticesOf($this->acme, $payment))->toBe([['payment.recorded', ['mail']], ['payment.confirmed', ['mail']]])
        ->and(receiptOf($this->acme, $payment)->details['sent_via'])->toBe(['email'])
        ->and(paymentWhatsApps())->toBe([]);
});

it('sends a phone-only tenant the agency\'s WhatsApp (the stand-in sender), never an SMS or an email', function () {
    Notification::fake();
    $musa = tenantIn($this->acme, ['first_name' => 'Musa', 'last_name' => 'Bello', 'phone' => '+2348030000004', 'email' => null]);
    $payment = confirmedPayment($this->acme, $this->owner, $musa, '10,000');
    $receipt = receiptOf($this->acme, $payment);

    $messages = paymentWhatsApps();

    expect(array_map(fn (WhatsAppMessage $message): array => [$message->to, $message->event], $messages))->toBe([
        ['+2348030000004', 'payment_recorded'],
        ['+2348030000004', 'receipt_issued'],
    ])
        ->and($messages[1]->text)->toContain($receipt->number, '₦10,000', 'Adeyemi Realty Ltd')
        ->and($messages[1]->variables)->toMatchArray(['receipt.number' => $receipt->number, 'payment.amount' => '₦10,000'])
        ->and(noticesOf($this->acme, $payment))->toBe([['payment.recorded', ['whatsapp']], ['payment.confirmed', ['whatsapp']]])
        ->and($receipt->details['sent_via'])->toBe(['WhatsApp']);
    Notification::assertNothingSent();

    // Where WhatsApp is not available (production until P05-06), the phone-only tenant gets nothing outside the app.
    app()->detectEnvironment(fn () => 'production');
    $quiet = confirmedPayment($this->acme, $this->owner, $musa, '5,000');
    app()->detectEnvironment(fn () => 'testing');
    expect(noticesOf($this->acme, $quiet))->toBe([['payment.recorded', []], ['payment.confirmed', []]])
        ->and(paymentWhatsApps())->toHaveCount(2);
});

/** A recorder draft (Collections, OnlinePayments). */
function receiptDraftFor(int $tenantId, string $key, int $naira): PaymentDraft
{
    $paystack = str_starts_with($key, 'paystack:');

    return new PaymentDraft(
        tenantProfileId: $tenantId,
        amount: Money::naira($naira),
        method: $paystack ? PaymentMethod::Paystack : PaymentMethod::BankTransfer,
        source: $paystack ? PaymentSource::PaystackWebhook : PaymentSource::StatementMatch,
        idempotencyKey: $key,
        paidAt: CarbonImmutable::parse('2026-12-24 15:00:00'),
        paystackReference: $paystack ? substr($key, 9) : null,
    );
}
