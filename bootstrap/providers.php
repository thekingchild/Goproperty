<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HostingServiceProvider;
use App\Providers\InstallerServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HostingServiceProvider::class,
    InstallerServiceProvider::class,
];
