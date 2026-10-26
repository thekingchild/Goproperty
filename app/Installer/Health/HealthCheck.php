<?php

declare(strict_types=1);

namespace App\Installer\Health;

use App\Installer\Requirements\CheckStatus;

final readonly class HealthCheck
{
    public function __construct(
        public string $group,
        public string $label,
        public CheckStatus $status,
        public string $detail = '',
    ) {}

    /** @return array{group: string, label: string, status: string, detail: string} */
    public function toArray(): array
    {
        return ['group' => $this->group, 'label' => $this->label, 'status' => $this->status->value, 'detail' => $this->detail];
    }
}
