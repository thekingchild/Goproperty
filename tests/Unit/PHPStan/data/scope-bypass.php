<?php

declare(strict_types=1);

namespace App\Billing {

    use App\Models\User;
    use App\Tenancy\OrganizationScope;
    use Tests\Fixtures\Models\ScopedRecord;

    function bypasses(ScopedRecord $record): void
    {
        ScopedRecord::query()->withoutGlobalScope(OrganizationScope::class);
        ScopedRecord::withoutGlobalScope(OrganizationScope::class);
        ScopedRecord::query()->withoutGlobalScope(new OrganizationScope);
        ScopedRecord::query()->withoutGlobalScopes([OrganizationScope::class]);
        ScopedRecord::query()->withoutGlobalScopes();
        ScopedRecord::withoutGlobalScopes();
        $record->newQuery()->withoutGlobalScopes();
        ScopedRecord::query()->withoutGlobalScope('App\Tenancy\OrganizationScope');
    }

    function allowed(): void
    {
        ScopedRecord::query()->withoutGlobalScope('soft-deletes');
        User::query()->withoutGlobalScopes();
        User::withoutGlobalScopes();
        ScopedRecord::query()->withoutGlobalScopes(['other-scope']);
    }
}

namespace App\Platform\Reports {

    use App\Tenancy\OrganizationScope;
    use Tests\Fixtures\Models\ScopedRecord;

    function crossOrganizationReport(): void
    {
        ScopedRecord::query()->withoutGlobalScope(OrganizationScope::class);
        ScopedRecord::withoutGlobalScopes();
    }
}

namespace Modules\Platform\Analytics {

    use App\Tenancy\OrganizationScope;
    use Tests\Fixtures\Models\ScopedRecord;

    function metrics(): void
    {
        ScopedRecord::query()->withoutGlobalScope(OrganizationScope::class);
    }
}

namespace App\PlatformLookalike {

    use App\Tenancy\OrganizationScope;
    use Tests\Fixtures\Models\ScopedRecord;

    function notPlatform(): void
    {
        ScopedRecord::query()->withoutGlobalScope(OrganizationScope::class);
    }
}
