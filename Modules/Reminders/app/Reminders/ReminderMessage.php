<?php

declare(strict_types=1);

namespace Modules\Reminders\Reminders;

/**
 * What a reminder says (ReminderMessages): which of GoProperty's email
 * templates (ReminderTemplates, P05-04) and the plain values for its
 * placeholders; the template is filled in only with whitelisted values,
 * never through Blade (AGENT.md § 3 rule 10). `url` is where the button
 * goes (none for a tenant without a portal account before the bill exists).
 */
final readonly class ReminderMessage
{
    /** @param  array<string, string>  $values */
    public function __construct(
        public string $template,
        public array $values,
        public ?string $url,
    ) {}
}
