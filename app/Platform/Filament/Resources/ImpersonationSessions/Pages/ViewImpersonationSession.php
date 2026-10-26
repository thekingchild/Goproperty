<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\ImpersonationSessions\Pages;

use App\Models\ImpersonationSession;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\ImpersonationSessions\ImpersonationSessionResource;
use App\Platform\Impersonation\ImpersonationAudit;
use Filament\Resources\Pages\ViewRecord;

/**
 * One support session: reason, approval and the full timeline. Opening it
 * is audited (PLATFORM_CONSOLE.md § 6).
 *
 * @property-read ImpersonationSession $record
 */
final class ViewImpersonationSession extends ViewRecord
{
    use ActsAsStaff;

    protected static string $resource = ImpersonationSessionResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        app(ImpersonationAudit::class)->logViewed(self::actor(), $this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            ImpersonationSessionResource::endAction()
                ->record(fn (): ImpersonationSession => $this->record)
                ->after(fn () => $this->record = $this->record->fresh() ?? $this->record),
        ];
    }
}
