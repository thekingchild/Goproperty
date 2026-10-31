<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Staff\RelationManagers;

use App\Models\PlatformStaff;
use App\Platform\Audit\AuditLogEntry;
use App\Platform\Audit\AuditReads;
use App\Platform\Filament\Resources\AuditLog\AuditLogResource;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A staff member's Actions (PLATFORM_CONSOLE.md § 7, P06-07c, default 62):
 * every audit entry they caused, newest first, beside the History of
 * entries about them. Needs `audit.view` (AuditLogEntryPolicy); opening it
 * is audited like the audit log. Read-only.
 */
final class ActionsRelationManager extends RelationManager
{
    protected static string $relationship = 'auditActions';

    protected static ?string $title = 'Actions';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $staff = Filament::auth()->user();

        return $staff instanceof PlatformStaff && $staff->can('viewAny', AuditLogEntry::class);
    }

    public function mount(): void
    {
        $reader = Filament::auth()->user();

        // Filament checks canViewForRecord() when it lists the tabs; checked
        // again here, before anything is read or logged.
        $member = $this->getOwnerRecord();
        abort_unless($reader instanceof PlatformStaff && $member instanceof PlatformStaff && self::canViewForRecord($member, $this->pageClass), 403);

        parent::mount();

        app(AuditReads::class)->staffActionsViewed($reader, $member);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return AuditLogResource::entriesTable($table);
    }
}
