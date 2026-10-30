<?php

declare(strict_types=1);

namespace Modules\Reminders\Reminders;

/**
 * What a reminder says (ReminderMessages): plain strings built from plain
 * values, never a template an agency edits (AGENT.md § 3 rule 10; P05-04
 * moves the wording into GoProperty's email templates). `url` is where
 * the button goes (none for a tenant without a portal account before the
 * bill exists).
 */
final readonly class ReminderMessage
{
    /** @param  list<string>  $lines */
    public function __construct(
        public string $subject,
        public array $lines,
        public ?string $url,
        public string $actionLabel,
        public string $greetingName,
    ) {}
}
