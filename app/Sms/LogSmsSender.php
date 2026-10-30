<?php

declare(strict_types=1);

namespace App\Sms;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The stand-in SMS driver (P05-02): sends nothing. Each message is kept in
 * memory for the current process (tests read sent()), and one line goes
 * to the log with the masked number and the length, never the text. Its
 * messages count as delivered when checked.
 */
final class LogSmsSender implements SmsSender
{
    /** @var list<array{to: string, text: string, id: string}> */
    private array $sent = [];

    public function name(): string
    {
        return 'log';
    }

    public function send(string $to, string $text): SmsReceipt
    {
        $id = 'log-'.Str::uuid();
        $this->sent[] = ['to' => $to, 'text' => $text, 'id' => $id];

        Log::info('SMS not sent (log driver)', ['to' => SmsText::mask($to), 'length' => strlen($text)]);

        return new SmsReceipt($id, 'logged', 1, null);
    }

    public function status(string $messageId): SmsReport
    {
        return new SmsReport(DeliveryStatus::Delivered, 'logged');
    }

    /** @return list<array{to: string, text: string, id: string}> */
    public function sent(): array
    {
        return $this->sent;
    }
}
