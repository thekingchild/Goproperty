<?php

declare(strict_types=1);

namespace Modules\Payments\Payments;

use Modules\Payments\Contracts\PaymentDraft;
use Modules\Payments\Contracts\PaymentRecorder;
use Modules\Payments\Contracts\RecordedPayment;

/** The Payments module's answer to Contracts\PaymentRecorder: everything goes through PaymentService. */
final readonly class Recorder implements PaymentRecorder
{
    public function __construct(private PaymentService $payments) {}

    public function record(PaymentDraft $draft): RecordedPayment
    {
        return $this->payments->recordDraft($draft, confirm: false);
    }

    public function recordConfirmed(PaymentDraft $draft): RecordedPayment
    {
        return $this->payments->recordDraft($draft, confirm: true);
    }
}
