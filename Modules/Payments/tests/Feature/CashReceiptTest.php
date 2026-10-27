<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\WhatsApp\LogWhatsAppSender;
use App\WhatsApp\WhatsAppMessage;
use App\WhatsApp\WhatsAppSender;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Enums\BillStatus;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Events\PaymentConfirmed;
use Modules\Payments\Listeners\TellTenantAboutPayment;
use Modules\Payments\Notices\PaymentNoticeEvent;
use Modules\Payments\Notifications\TenantPaymentNotice;
use Modules\Payments\Payments\PaymentService;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/proof-helpers.php';

/*
 * P03-09: cash and POS (ONBOARDING_COMPLIANCE.md § 4.3): recorded by a
 * member with a mandatory note and confirmed as they are recorded (the
 * member took the money), so the receipt goes straight to the tenant, who
 * can check it: in the portal and by email with the receipt PDF attached,
 * or by the agency's WhatsApp (the stand-in sender, P03 decision 3) when no
 * email is known. Never SMS. Told once, whatever is replayed.
 */

beforeEach(function () {
    Storage::fake('media');
    holdInvoicePdfs();
    holdReceiptPdfs();
    seedOrganizationRoles();
    $this->travelTo('2026-12-25 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Tunde Bakare']), $this->acme);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348030000001', 'email' => 'kemi@example.test']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']), $this->kemi, ['billingCycle' => 'monthly', 'rent' => '100,000', 'deposit' => '0']);
    runBillGeneration();
    $this->bill = billsOfTenant($this->acme, $this->kemi)->sole();
    $this->kemiUser = proofPortalAccount($this->acme, $this->kemi, User::factory()->create(['name' => 'Kemi Adebayo', 'email' => 'kemi@example.test']));
});

afterEach(fn () => billsFreshRequest());

/** @return list<WhatsAppMessage> */
function cashWhatsApps(): array
{
    $sender = app(WhatsAppSender::class);
    assert($sender instanceof LogWhatsAppSender);

    return array_map(fn (array $sent): WhatsAppMessage => $sent['message'], $sender->sent());
}

describe('acceptance: a cash payment sends the receipt to the tenant', function () {
    it('confirms cash as it is recorded from the form and sends the tenant the receipt once, the PDF attached', function () {
        Notification::fake();

        // The form offers cash only as "Record and send the receipt".
        $form = paymentsSnapshot($this->owner, '/create?bill='.$this->bill->id);
        $cash = paymentsUpdate($this->owner, $form, ['method' => 'cash'])->assertOk();
        expect(LivewireHttp::html($cash))->toContain('Record and send the receipt')->not->toContain('data-test="save-payment"');

        // A note is mandatory; nothing is recorded or told without it.
        $refused = paymentsUpdate($this->owner, LivewireHttp::next($cash), ['amount' => '100,000', 'note' => ''], [['save']])->assertOk();
        expect(LivewireHttp::errors($refused))->toHaveKey('note')
            ->and(paymentsOf($this->acme))->toHaveCount(0);
        Notification::assertNothingSent();

        // Pressing Enter (save) or the button: recorded and confirmed at once.
        $saved = paymentsUpdate($this->owner, LivewireHttp::next($cash), ['amount' => '100,000', 'note' => 'Paid at the estate office to Musa'], [['save']])->assertOk();
        $payment = paymentsOf($this->acme)->sole();
        expect(LivewireHttp::redirect($saved))->toEndWith("/o/acme-homes/payments/{$payment->id}")
            ->and($payment->method)->toBe(PaymentMethod::Cash)
            ->and($payment->status)->toBe(PaymentStatus::Confirmed)
            ->and($payment->note)->toBe('Paid at the estate office to Musa')
            ->and(storedBill($this->acme, $this->bill)->status)->toBe(BillStatus::Paid);
        paymentsPage($this->owner, "/{$payment->id}")->assertOk()->assertSee('Its receipt is on its way to the tenant.');

        $receipt = receiptOf($this->acme, $payment);
        $sent = Notification::sent($this->kemiUser, TenantPaymentNotice::class)->values();

        // Told once, of the confirmation only (it never waited), in the app and by email.
        expect($sent)->toHaveCount(1)
            ->and($sent[0]->event)->toBe(PaymentNoticeEvent::Confirmed)
            ->and($sent[0]->via($this->kemiUser))->toBe(['database', 'mail'])
            ->and($sent[0]->attachReceiptId)->toBe($receipt->id)
            ->and(noticesOf($this->acme, $payment))->toBe([['payment.confirmed', ['database', 'mail']]])
            ->and($receipt->details['sent_via'])->toBe(['email']);

        // The email is the receipt: its PDF as issued, attached, with the number and the verify link.
        $mail = $sent[0]->toMail($this->kemiUser);
        expect($mail->subject)->toBe("Receipt {$receipt->number} for your payment of ₦100,000")
            ->and(implode(' ', $mail->introLines))->toContain('confirmed your cash payment of ₦100,000', $receipt->number, 'Your receipt is attached.')
            ->and($mail->actionUrl)->toContain('/verify/'.$receipt->verification_code)
            ->and($mail->rawAttachments)->toHaveCount(1)
            ->and($mail->rawAttachments[0]['name'])->toBe("Receipt {$receipt->number}.pdf")
            ->and($mail->rawAttachments[0]['options']['mime'])->toBe('application/pdf')
            ->and($mail->rawAttachments[0]['data'])->toStartWith('%PDF');
        // It is the kept PDF (evidence), rendered once: the receipt now points at it.
        $kept = storedReceipt($this->acme, $receipt);
        expect($kept->document_media_id)->not->toBeNull()
            ->and($sent[0]->toMail($this->kemiUser)->rawAttachments[0]['data'])->toBe($mail->rawAttachments[0]['data'])
            ->and(storedReceipt($this->acme, $receipt)->document_media_id)->toBe($kept->document_media_id);

        // Exactly once: the confirmation announced again, the listener retried.
        event(new PaymentConfirmed($payment->id, $this->acme->id, $this->kemi->id, 10_000_000, 'cash', 'manual', $this->owner->id, [], 0));
        app(TellTenantAboutPayment::class)->handle(new PaymentConfirmed($payment->id, $this->acme->id, $this->kemi->id, 10_000_000, 'cash', 'manual', $this->owner->id, [], 0));
        expect(Notification::sent($this->kemiUser, TenantPaymentNotice::class))->toHaveCount(1)
            ->and(DB::table('payment_notices')->where('payment_id', $payment->id)->count())->toBe(1)
            ->and(cashWhatsApps())->toBe([]);
        Notification::assertNothingSentTo(new AnonymousNotifiable);
    });

    it('sends the email with the receipt PDF for real, once, for a POS payment', function () {
        $pos = inOrganization($this->acme, fn () => app(PaymentService::class)->recordAndConfirm($this->owner, paymentInput($this->kemi, '100,000', ['method' => 'pos', 'note' => 'Moniepoint POS at the office', 'reference' => 'POS-0042'])));
        $receipt = receiptOf($this->acme, $pos);

        $messages = collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($message) => $message->getOriginalMessage());
        expect($messages)->toHaveCount(1);
        $email = $messages->sole();
        $attachments = $email->getAttachments();

        expect($email->getSubject())->toBe("Receipt {$receipt->number} for your payment of ₦100,000")
            ->and(array_map(fn ($address) => $address->getAddress(), $email->getTo()))->toBe(['kemi@example.test'])
            ->and($attachments)->toHaveCount(1)
            ->and($attachments[0]->getFilename())->toBe("Receipt {$receipt->number}.pdf")
            ->and($attachments[0]->getMediaType().'/'.$attachments[0]->getMediaSubtype())->toBe('application/pdf')
            ->and($attachments[0]->getBody())->toStartWith('%PDF')
            ->and(DB::table('notifications')->where('notifiable_id', $this->kemiUser->id)->count())->toBe(1);
    });

    it('emails a tenant without a portal account at the address on record, the PDF attached', function () {
        Notification::fake();
        $tola = tenantIn($this->acme, ['first_name' => 'Tola', 'last_name' => 'Ade', 'phone' => '+2348030000002', 'email' => 'tola@example.test']);
        $cash = inOrganization($this->acme, fn () => app(PaymentService::class)->recordAndConfirm($this->owner, paymentInput($tola, '20,000', ['method' => 'cash', 'note' => 'At the office'])));
        $receipt = receiptOf($this->acme, $cash);

        Notification::assertSentOnDemandTimes(TenantPaymentNotice::class, 1);
        Notification::assertSentOnDemand(TenantPaymentNotice::class, function (TenantPaymentNotice $notice, array $channels, AnonymousNotifiable $notifiable) use ($cash, $receipt): bool {
            return $notice->paymentId === $cash->id
                && $notice->event === PaymentNoticeEvent::Confirmed
                && $channels === ['mail']
                && ($notifiable->routes['mail'] ?? null) === 'tola@example.test'
                && $notice->attachReceiptId === $receipt->id
                && str_starts_with((string) ($notice->receiptPdf() ?? ''), '%PDF');
        });
    });

    it('sends a phone-only tenant the receipt by the agency\'s WhatsApp (the stand-in sender), never an SMS or an email', function () {
        Notification::fake();
        $musa = tenantIn($this->acme, ['first_name' => 'Musa', 'last_name' => 'Bello', 'phone' => '+2348030000004', 'email' => null]);
        $cash = inOrganization($this->acme, fn () => app(PaymentService::class)->recordAndConfirm($this->owner, paymentInput($musa, '15,000', ['method' => 'cash', 'note' => 'At the office'])));
        $receipt = receiptOf($this->acme, $cash);

        $messages = cashWhatsApps();
        expect($messages)->toHaveCount(1)
            ->and($messages[0]->to)->toBe('+2348030000004')
            ->and($messages[0]->event)->toBe('receipt_issued')
            ->and($messages[0]->text)->toContain($receipt->number, '/verify/'.$receipt->verification_code, 'cash payment of ₦15,000')
            ->and(noticesOf($this->acme, $cash))->toBe([['payment.confirmed', ['whatsapp']]])
            ->and($receipt->details['sent_via'])->toBe(['WhatsApp']);
        Notification::assertNothingSent();
    });
});

it('attaches the receipt only for cash and POS: a bank transfer\'s email links it', function () {
    Notification::fake();
    $transfer = confirmedPayment($this->acme, $this->owner, $this->kemi, '100,000');

    $sent = Notification::sent($this->kemiUser, TenantPaymentNotice::class)->values();
    $confirmed = $sent->first(fn (TenantPaymentNotice $notice): bool => $notice->event === PaymentNoticeEvent::Confirmed && $notice->paymentId === $transfer->id);

    expect($confirmed->attachReceiptId)->toBeNull()
        ->and($confirmed->toMail($this->kemiUser)->rawAttachments)->toBe([])
        ->and(implode(' ', $confirmed->toMail($this->kemiUser)->introLines))->not->toContain('attached');
});

it('still sends the email, with the number and verify link, when the receipt PDF cannot be read', function () {
    $notice = new TenantPaymentNotice(PaymentNoticeEvent::Confirmed, 1, [
        'organization' => 'Adeyemi Realty Ltd', 'amount' => '₦5,000', 'paid_on' => '25/12/2026', 'method' => 'cash',
        'receipt_number' => 'RCT-ADR-2026-000009', 'verify_url' => 'https://example.test/verify/ABCDEFGHJK', 'reason' => '',
    ], ['mail'], $this->acme->id, 999_999);

    $mail = $notice->toMail(new AnonymousNotifiable);
    expect($mail->rawAttachments)->toBe([])
        ->and($mail->subject)->toBe('Receipt RCT-ADR-2026-000009 for your payment of ₦5,000')
        ->and($mail->actionUrl)->toBe('https://example.test/verify/ABCDEFGHJK');
});

it('refuses cash or POS without a note, and records nothing', function (string $method) {
    Notification::fake();

    expect(fn () => inOrganization($this->acme, fn () => app(PaymentService::class)->recordAndConfirm($this->owner, paymentInput($this->kemi, '5,000', ['method' => $method, 'note' => '   ']))))
        ->toThrow(ValidationException::class, 'Cash and POS payments need a note');
    expect(paymentsOf($this->acme))->toHaveCount(0);
    Notification::assertNothingSent();
})->with(['cash', 'pos']);
