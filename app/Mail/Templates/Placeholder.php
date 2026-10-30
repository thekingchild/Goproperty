<?php

declare(strict_types=1);

namespace App\Mail\Templates;

use Closure;

/**
 * A value a template may use, written `{{ name }}` (PLATFORM_CONSOLE.md
 * § 5). `sample` fills it in the console's preview and test email. `max`
 * (SMS templates) is the longest value it may fill in: longer values are
 * cut to it when sent, and the template is refused unless it fits one SMS
 * page with every value at its longest; a closure for values that depend
 * on the installation (a link's length depends on APP_URL).
 */
final readonly class Placeholder
{
    /** @param  int|(Closure(): int)|null  $max */
    public function __construct(
        public string $name,
        public string $description,
        public string $sample,
        public int|Closure|null $max = null,
    ) {}

    public function maxLength(): ?int
    {
        return $this->max instanceof Closure ? (int) ($this->max)() : $this->max;
    }
}
