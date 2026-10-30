<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Templates;

/** What a template sync did: templates seen, and the events whose mapping it switched off. */
final readonly class SyncResult
{
    /** @param  list<string>  $disabledEvents */
    public function __construct(
        public bool $fetched,
        public int $templates = 0,
        public array $disabledEvents = [],
        public ?string $error = null,
    ) {}
}
