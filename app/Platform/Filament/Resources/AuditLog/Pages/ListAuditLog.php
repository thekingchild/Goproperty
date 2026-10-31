<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\AuditLog\Pages;

use App\Platform\Audit\AuditReads;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\AuditLog\AuditLogResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The audit log. Each page load is one `audit.viewed` entry with the
 * filters and search it opened with (PLATFORM_CONSOLE.md § 7); Livewire
 * re-renders (paging, changing a filter) are not logged again.
 */
final class ListAuditLog extends ListRecords
{
    use ActsAsStaff;

    protected static string $resource = AuditLogResource::class;

    public function mount(): void
    {
        parent::mount();

        app(AuditReads::class)->auditLogViewed(self::actor(), $this->tableFilters ?? [], is_string($this->tableSearch) ? $this->tableSearch : null);
    }
}
