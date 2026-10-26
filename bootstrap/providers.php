<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\AuditServiceProvider;
use App\Providers\BackupServiceProvider;
use App\Providers\Filament\ConsolePanelProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HostingServiceProvider;
use App\Providers\InstallerServiceProvider;
use App\Providers\StaffServiceProvider;

return [
    AppServiceProvider::class,
    AuditServiceProvider::class,
    BackupServiceProvider::class,
    FortifyServiceProvider::class,
    HostingServiceProvider::class,
    InstallerServiceProvider::class,
    StaffServiceProvider::class,
    ConsolePanelProvider::class,
];
