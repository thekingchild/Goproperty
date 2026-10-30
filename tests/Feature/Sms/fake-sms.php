<?php

declare(strict_types=1);

use App\Sms\DeliveryStatus;
use App\Sms\SmsFailed;
use App\Sms\SmsReceipt;
use App\Sms\SmsReport;
use App\Sms\SmsSender;

/**
 * An SMS driver for tests: fails the first $failures sends, then takes
 * them; `report` is what a status check answers.
 */
final class ScriptedSms implements SmsSender
{
    /** @var list<array{to: string, text: string}> */
    public array $sent = [];

    public int $calls = 0;

    public int $checks = 0;

    public function __construct(
        public int $failures = 0,
        public ?DeliveryStatus $report = DeliveryStatus::Delivered,
    ) {}

    public function name(): string
    {
        return 'scripted';
    }

    public function send(string $to, string $text): SmsReceipt
    {
        $this->calls++;

        if ($this->failures-- > 0) {
            throw new SmsFailed('GoText refused the request (HTTP 503).');
        }

        $this->sent[] = ['to' => $to, 'text' => $text];

        return new SmsReceipt('uid-'.count($this->sent), 'Sent', 1, 250);
    }

    public function status(string $messageId): SmsReport
    {
        $this->checks++;

        return new SmsReport($this->report, $this->report?->value ?? 'Pending');
    }
}

function scriptedSms(int $failures = 0, ?DeliveryStatus $report = DeliveryStatus::Delivered): ScriptedSms
{
    $sms = new ScriptedSms($failures, $report);
    app()->instance(SmsSender::class, $sms);

    return $sms;
}
