<?php

declare(strict_types=1);

namespace Modules\Payments\Notices;

use App\Branding\Branding;
use App\Documents\Verification\VerificationCodes;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use App\WhatsApp\SendWhatsAppMessage;
use App\WhatsApp\WhatsAppMessage;
use App\WhatsApp\WhatsAppSender;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentNotice;
use Modules\Payments\Models\Receipt;
use Modules\Payments\Notifications\TenantPaymentNotice;
use Modules\Tenants\Contracts\TenantDirectory;
use Modules\Tenants\Contracts\TenantSummary;

/**
 * Tells the tenant about their payment, once per transition (P03-10b;
 * ARCHITECTURE.md § 6.4): recorded (waiting for confirmation), confirmed
 * (with the receipt's number and verify address), rejected (with the
 * reason), receipt voided (with the reason). The only writer of
 * `payment_notices`.
 *
 * send() runs after the transition's transaction has committed
 * (Listeners\TellTenantAboutPayment). It first writes the payment's notice
 * row for the transition — unique per payment and event — and sends only
 * if that row is new, so a transition handled twice (an event replayed, a
 * listener retried) tells the tenant once. Channels: NoticeChannels (in the
 * app with a portal account, email when an address is known, else the
 * agency's WhatsApp through App\WhatsApp\WhatsAppSender; never SMS, P03
 * decision 3). Queued on `notifications`. A confirmed cash or POS payment's
 * email carries the receipt PDF (P03-09, ONBOARDING_COMPLIANCE.md § 4.3).
 */
final readonly class TenantNotices
{
    public function __construct(
        private OrganizationContext $context,
        private TenantDirectory $tenants,
        private WhatsAppSender $whatsapp,
    ) {}

    /** Where this tenant hears from the organization now. */
    public function channelsFor(TenantSummary $tenant, Organization $organization): NoticeChannels
    {
        $user = $tenant->userId !== null ? User::query()->find($tenant->userId) : null;
        $mailTo = self::address($user?->email) ?? self::address($tenant->email);
        $phone = $mailTo === null && trim($tenant->phone) !== '' && $this->whatsapp->available($organization) ? $tenant->phone : null;

        return new NoticeChannels($user, $mailTo, $phone);
    }

    /**
     * Tell the payment's tenant about the transition, unless they were told
     * already. Runs in the payment's organization (the current context).
     *
     * @param  array<string, string>  $extra  e.g. reason
     */
    public function send(PaymentNoticeEvent $event, int $paymentId, array $extra = []): ?PaymentNotice
    {
        $organization = $this->context->get();
        $payment = Payment::query()->withTrashed()->find($paymentId);
        $tenant = $payment instanceof Payment ? $this->tenants->find($payment->tenant_profile_id) : null;

        if (! $payment instanceof Payment || $tenant === null) {
            return null;
        }

        $channels = $this->channelsFor($tenant, $organization);

        try {
            $notice = DB::transaction(function () use ($payment, $event, $channels): PaymentNotice {
                $notice = new PaymentNotice;
                $notice->forceFill([
                    'payment_id' => $payment->id,
                    'event' => $event->value,
                    'channels' => $channels->all(),
                ])->save();

                return $notice;
            });
        } catch (UniqueConstraintViolationException) {
            // Told already: a transition is announced once.
            return null;
        }

        $values = $this->values($organization, $payment, $event, $extra);
        $this->deliver($organization, $event, $payment, $channels, $values);

        return $notice;
    }

    /** @param  array<string, string>  $values */
    private function deliver(Organization $organization, PaymentNoticeEvent $event, Payment $payment, NoticeChannels $channels, array $values): void
    {
        // Cash and POS: the receipt itself goes straight to the tenant, so
        // they can check what the member recorded (ONBOARDING_COMPLIANCE.md
        // § 4.3, P03-09): the PDF is attached to the email.
        $attach = $event === PaymentNoticeEvent::Confirmed && $payment->method->needsNote() && ($values['receipt_id'] ?? '') !== ''
            ? (int) $values['receipt_id']
            : null;

        if ($channels->user !== null) {
            $via = $channels->mailsTheUser() ? [NoticeChannels::DATABASE, NoticeChannels::MAIL] : [NoticeChannels::DATABASE];
            $channels->user->notify(new TenantPaymentNotice($event, $payment->id, $values, $via, $organization->id, $attach));
        }

        if ($channels->mailTo !== null && ! $channels->mailsTheUser()) {
            Notification::route('mail', $channels->mailTo)->notify(new TenantPaymentNotice($event, $payment->id, $values, [NoticeChannels::MAIL], $organization->id, $attach));
        }

        if ($channels->phone !== null) {
            SendWhatsAppMessage::dispatch((int) $organization->id, new WhatsAppMessage(
                to: $channels->phone,
                event: $event->whatsAppEvent(),
                text: $event->text($values),
                variables: [
                    'agency.name' => $values['organization'],
                    'payment.amount' => $values['amount'],
                    'receipt.number' => $values['receipt_number'] ?? '',
                    'reason' => $values['reason'] ?? '',
                ],
            ));
        }
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function values(Organization $organization, Payment $payment, PaymentNoticeEvent $event, array $extra): array
    {
        $values = [
            'organization' => Branding::fromOrganization($organization)->displayName(),
            'amount' => $payment->amount_kobo->format(),
            'paid_on' => $payment->paid_at->format('d/m/Y'),
            'method' => match ($payment->method) {
                PaymentMethod::BankTransfer => 'bank transfer',
                PaymentMethod::Cash => 'cash',
                PaymentMethod::Pos => 'POS',
                PaymentMethod::Paystack => 'Paystack',
            },
        ];

        if ($event === PaymentNoticeEvent::Confirmed || $event === PaymentNoticeEvent::ReceiptVoided) {
            $receipt = Receipt::query()->where('payment_id', $payment->id)->first();

            if ($receipt instanceof Receipt) {
                $values['receipt_id'] = (string) $receipt->id;
                $values['receipt_number'] = $receipt->number;
                $values['verify_url'] = VerificationCodes::url($receipt->verification_code);
                $values['reason'] = (string) $receipt->void_reason;
            }
        }

        if ($event === PaymentNoticeEvent::Rejected) {
            $values['reason'] = (string) $payment->rejection_reason;
        }

        return array_merge(['receipt_number' => '', 'verify_url' => '', 'reason' => ''], $values, $extra);
    }

    private static function address(?string $email): ?string
    {
        $email = $email === null ? '' : trim($email);

        return $email === '' ? null : $email;
    }
}
