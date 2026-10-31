<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\AuditLog\Pages;

use App\Platform\Audit\AuditLogEntry;
use App\Platform\Audit\AuditReads;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\AuditLog\AuditLogResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * One audit log entry, read-only. Opening it is one `audit.viewed` entry
 * naming it (PLATFORM_CONSOLE.md § 7).
 *
 * @property-read AuditLogEntry $record
 */
final class ViewAuditLogEntry extends ViewRecord
{
    use ActsAsStaff;

    protected static string $resource = AuditLogResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->record->loadMissing(AuditLogResource::relationsForList());

        app(AuditReads::class)->entryViewed(self::actor(), $this->record);
    }

    public function getTitle(): string
    {
        return 'Audit log entry #'.$this->record->id;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
