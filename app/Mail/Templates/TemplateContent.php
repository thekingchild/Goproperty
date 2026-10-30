<?php

declare(strict_types=1);

namespace App\Mail\Templates;

/**
 * The words of one version of a template: subject, Markdown body with
 * `{{ placeholders }}`, and the button's label (shown only when the sender
 * gives a link).
 */
final readonly class TemplateContent
{
    public function __construct(
        public string $subject,
        public string $body,
        public ?string $actionLabel = null,
    ) {}

    public function equals(self $other): bool
    {
        return $this->subject === $other->subject && $this->body === $other->body && $this->actionLabel === $other->actionLabel;
    }
}
