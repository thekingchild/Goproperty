<?php

declare(strict_types=1);

namespace App\Mail\Templates;

/**
 * A template filled in (TemplateRenderer): the subject as plain text, the
 * body as safe HTML (from Markdown, every typed tag escaped) and as plain
 * text, and the button's label. Plain strings, so a queued notification
 * can carry it.
 */
final readonly class RenderedEmail
{
    public function __construct(
        public string $subject,
        public string $html,
        public string $text,
        public ?string $actionLabel,
    ) {}
}
