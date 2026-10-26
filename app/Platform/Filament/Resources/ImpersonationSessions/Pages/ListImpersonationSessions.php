<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\ImpersonationSessions\Pages;

use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\ImpersonationSessions\ImpersonationSessionResource;
use App\Platform\Impersonation\ImpersonationAudit;
use Filament\Resources\Pages\ListRecords;

/** The impersonation log. Opening it is audited (PLATFORM_CONSOLE.md § 6). */
final class ListImpersonationSessions extends ListRecords
{
    use ActsAsStaff;

    protected static string $resource = ImpersonationSessionResource::class;

    public function mount(): void
    {
        parent::mount();

        app(ImpersonationAudit::class)->logViewed(self::actor());
    }
}
