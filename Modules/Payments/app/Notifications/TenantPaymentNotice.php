<?php

declare(strict_types=1);

namespace Modules\Payments\Notifications;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Payments\Models\Receipt;
use Modules\Payments\Notices\PaymentNoticeEvent;
use Modules\Payments\Receipts\Receipts;
use Throwable;

/**
 * What a tenant is told about their payment (P03-10b): recorded,
 * confirmed (the receipt's number and verify address), rejected (the
 * reason), receipt voided. In the app for a tenant with a portal account
 * and by email; built from plain values by Notices\TenantNotices, which
 * makes sure each transition is sent once. Never SMS (P03 decision 3).
 * Queued on `notifications`, after the commit.
 *
 * With $attachReceiptId (a confirmed cash or POS payment, P03-09;
 * ONBOARDING_COMPLIANCE.md § 4.3: "the receipt is sent straight to the
 * tenant"), the email carries the receipt's PDF as issued — the kept file,
 * rendered now (once) if the `pdf` queue has not got to it. If it cannot be
 * read, the email still goes, with the receipt number and verify link, and
 * the failure is reported.
 */
final class TenantPaymentNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, string>  $values  organization, amount, paid_on, method, receipt_number, verify_url, reason
     * @param  list<string>  $channels  `database` and/or `mail`
     * @param  ?int  $organizationId  the payment's organization (to read the receipt PDF)
     * @param  ?int  $attachReceiptId  the receipt whose PDF the email carries
     */
    public function __construct(
        public readonly PaymentNoticeEvent $event,
        public readonly int $paymentId,
        public readonly array $values,
        public readonly array $channels,
        public readonly ?int $organizationId = null,
        public readonly ?int $attachReceiptId = null,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->event->subject($this->values));

        foreach ($this->event->lines($this->values) as $line) {
            $mail->line($line);
        }

        $pdf = $this->receiptPdf();

        if ($pdf !== null) {
            $mail->line('Your receipt is attached.');
            $mail->attachData($pdf, 'Receipt '.$this->values['receipt_number'].'.pdf', ['mime' => 'application/pdf']);
        }

        if ($this->values['verify_url'] !== '') {
            $mail->action('Check the receipt', $this->values['verify_url']);
        }

        return $mail;
    }

    /** The bytes of the receipt PDF to attach, read inside the payment's organization. */
    public function receiptPdf(): ?string
    {
        if ($this->attachReceiptId === null || $this->organizationId === null) {
            return null;
        }

        try {
            $organization = Organization::query()->find($this->organizationId);

            if ($organization === null) {
                return null;
            }

            return app(OrganizationContext::class)->run($organization, function (): ?string {
                $receipt = Receipt::query()->find($this->attachReceiptId);

                if (! $receipt instanceof Receipt) {
                    return null;
                }

                $stream = app(Receipts::class)->pdf($receipt)->stream();
                $bytes = stream_get_contents($stream);
                fclose($stream);

                return is_string($bytes) && $bytes !== '' ? $bytes : null;
            });
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->event->value,
            'payment_id' => $this->paymentId,
            'organization' => $this->values['organization'],
            'amount' => $this->values['amount'],
            'receipt_number' => $this->values['receipt_number'] !== '' ? $this->values['receipt_number'] : null,
            'reason' => $this->values['reason'] !== '' ? $this->values['reason'] : null,
            'message' => $this->event->subject($this->values),
            'url' => $this->values['verify_url'] !== '' ? $this->values['verify_url'] : null,
        ];
    }
}
