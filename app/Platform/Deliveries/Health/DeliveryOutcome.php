<?php

declare(strict_types=1);

namespace App\Platform\Deliveries\Health;

use App\Enums\Concerns\HasValues;
use App\Sms\DeliveryStatus;

/**
 * What the delivery health dashboard counts (P05-05; PLATFORM_CONSOLE.md
 * § 2 "Messaging"), each a set of `notification_deliveries.status` values.
 * The dashboard's figures and the delivery log's "Outcome" filter both use
 * these sets, so a figure's link always lists exactly the rows it counted.
 *
 * - sent: the provider took it (still waiting for a report, delivered or not delivered);
 * - attempted: sent, plus failed (GoProperty tried and the attempt is over);
 *   messages still waiting and those never sent for lack of SMS credit are not attempts;
 * - problems: failed or not delivered, the failure rate's numerator.
 * - no_credit is counted on its own, never as a failure (P05-03 default 37).
 */
enum DeliveryOutcome: string
{
    use HasValues;

    case Sent = 'sent';
    case Delivered = 'delivered';
    case AwaitingReport = 'awaiting_report';
    case Undelivered = 'undelivered';
    case Failed = 'failed';
    case NoCredit = 'no_credit';
    case Waiting = 'waiting';
    case Attempted = 'attempted';
    case Problems = 'problems';

    /** @return list<DeliveryStatus> */
    public function statuses(): array
    {
        return match ($this) {
            self::Sent => [DeliveryStatus::Sent, DeliveryStatus::Delivered, DeliveryStatus::Undelivered],
            self::Delivered => [DeliveryStatus::Delivered],
            self::AwaitingReport => [DeliveryStatus::Sent],
            self::Undelivered => [DeliveryStatus::Undelivered],
            self::Failed => [DeliveryStatus::Failed],
            self::NoCredit => [DeliveryStatus::NoCredit],
            self::Waiting => [DeliveryStatus::Queued, DeliveryStatus::Sending],
            self::Attempted => [DeliveryStatus::Sent, DeliveryStatus::Delivered, DeliveryStatus::Undelivered, DeliveryStatus::Failed],
            self::Problems => [DeliveryStatus::Failed, DeliveryStatus::Undelivered],
        };
    }

    /** @return list<string> */
    public function statusValues(): array
    {
        return array_map(static fn (DeliveryStatus $status): string => $status->value, $this->statuses());
    }

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Sent (taken by the provider)',
            self::Delivered => 'Delivered',
            self::AwaitingReport => 'Sent, no report yet',
            self::Undelivered => 'Not delivered',
            self::Failed => 'Failed',
            self::NoCredit => 'Not sent: no SMS credit',
            self::Waiting => 'Still waiting to send',
            self::Attempted => 'Attempted',
            self::Problems => 'Failed or not delivered',
        };
    }
}
