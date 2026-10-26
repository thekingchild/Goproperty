<?php

declare(strict_types=1);

use Tests\Support\OrganizationScopedModels;

// Every organization-owned model; add new ones in Tests\Support\OrganizationScopedModels.
dataset('organization-scoped models', OrganizationScopedModels::dataset());
