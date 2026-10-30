<?php

declare(strict_types=1);

namespace App\Sms;

/**
 * Sends one SMS from GoProperty's own account (P05-02;
 * docs/plans/sms-provider-2026-10-29.md: one GoText account for the whole
 * platform, agencies never connect their own). Bound in AppServiceProvider:
 * `gotext` only in production (AGENT.md § 7: never real SMS outside it),
 * the `log` stand-in everywhere else. Use App\Sms\SmsOutbox, never this
 * directly: the outbox writes the delivery row first.
 */
interface SmsSender
{
    /** The provider's name for `notification_deliveries.provider`. */
    public function name(): string;

    /**
     * @param  string  $to  E.164, e.g. +2348031112222
     *
     * @throws SmsFailed
     */
    public function send(string $to, string $text): SmsReceipt;

    /** The message's status now, from its provider id. */
    public function status(string $messageId): SmsReport;
}
