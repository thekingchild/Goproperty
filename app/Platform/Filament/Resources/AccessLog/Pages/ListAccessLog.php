<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\AccessLog\Pages;

use App\Platform\Audit\AuditReads;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\AccessLog\AccessLogResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The access log. Each page load is one `access_log.viewed` entry
 * (PLATFORM_CONSOLE.md § 8); Livewire re-renders are not logged again.
 */
final class ListAccessLog extends ListRecords
{
    use ActsAsStaff;

    protected static string $resource = AccessLogResource::class;

    public function mount(): void
    {
        parent::mount();

        app(AuditReads::class)->accessLogViewed(self::actor(), $this->tableFilters ?? [], is_string($this->tableSearch) ? $this->tableSearch : null);
    }
}
