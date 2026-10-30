<?php

declare(strict_types=1);

namespace App\Mail\Templates;

/**
 * A value a template may use, written `{{ name }}` (PLATFORM_CONSOLE.md
 * § 5). `sample` fills it in the console's preview and test email.
 */
final readonly class Placeholder
{
    public function __construct(
        public string $name,
        public string $description,
        public string $sample,
    ) {}
}
