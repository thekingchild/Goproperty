<?php

declare(strict_types=1);

namespace App\Platform\Operations\Alerts;

/**
 * One subject of a rule that is failing at the moment of the check, e.g.
 * the scheduler (subject ''), the `notifications` queue, or one agency's
 * Paystack webhook. Summaries and details never carry personal data.
 */
final readonly class AlertCondition
{
    /** @param  array<string, scalar|null>  $details */
    public function __construct(
        public AlertRule $rule,
        public string $subject,
        public string $summary,
        public array $details = [],
    ) {}

    public function key(): string
    {
        return self::openKey($this->rule, $this->subject);
    }

    public static function openKey(AlertRule $rule, string $subject): string
    {
        return $rule->value.'|'.$subject;
    }
}
