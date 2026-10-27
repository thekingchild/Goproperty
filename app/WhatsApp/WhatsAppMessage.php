<?php

declare(strict_types=1);

namespace App\WhatsApp;

use SensitiveParameter;

/**
 * One WhatsApp message for one number. `event` names what it is about
 * (e.g. `tenant_invitation`); P05-06 maps each event to one of the
 * agency's approved Meta templates and each of `variables` to a template
 * variable (ARCHITECTURE.md § 6.7). `text` is the plain message the
 * template stands for. Links in it may carry a one-time token, so the
 * whole message is treated as a secret (never logged, encrypted on the
 * queue).
 */
final readonly class WhatsAppMessage
{
    /** @param  array<string, string>  $variables  placeholder => value, e.g. 'tenant.first_name' => 'Ada' */
    public function __construct(
        public string $to,
        public string $event,
        #[SensitiveParameter] public string $text,
        #[SensitiveParameter] public array $variables = [],
    ) {}

    /** "+234 ••• 4567": enough to recognise the number in a log or a page. */
    public function maskedTo(): string
    {
        return strlen($this->to) > 8 ? substr($this->to, 0, 4).' ••• '.substr($this->to, -4) : '•••';
    }
}
