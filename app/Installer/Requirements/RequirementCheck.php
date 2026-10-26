<?php

declare(strict_types=1);

namespace App\Installer\Requirements;

final readonly class RequirementCheck
{
    /**
     * @param  string  $group  "PHP", "Folders", "Security", "Build" or "Advisory"
     * @param  string  $detail  shown only after unlock: may name paths and values
     * @param  string|null  $cpanel  how to fix it in cPanel
     * @param  string|null  $plesk  how to fix it in Plesk
     */
    public function __construct(
        public string $group,
        public string $label,
        public CheckStatus $status,
        public bool $blocking,
        public string $detail = '',
        public ?string $cpanel = null,
        public ?string $plesk = null,
    ) {}

    public function blocks(): bool
    {
        return $this->blocking && $this->status === CheckStatus::Fail;
    }
}
