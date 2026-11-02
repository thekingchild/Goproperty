<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\BuildingGroup\Livewire\GroupIndex;
use Modules\BuildingGroup\Livewire\GroupShow;
use Modules\BuildingGroup\Livewire\Portal\PortalGroups;
use Modules\BuildingGroup\Livewire\Portal\PortalGroupShow;

/*
 * Workspace pages of the BuildingGroup module (P07-02). `organization`
 * checks membership (404 otherwise) and sets the organization context, so
 * a group id resolves only inside the organization (ARCHITECTURE.md § 4);
 * `plan:building_group` refuses the pages when the organization's plan
 * does not include Building Group (ARCHITECTURE.md § 6.10), and
 * BuildingGroupPolicy answers 404 outside a narrowed agent's buildings.
 */
Route::middleware(['auth', 'verified', 'organization', 'plan:building_group'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    Route::livewire('groups', GroupIndex::class)->name('groups.index');
    Route::livewire('groups/{group}', GroupShow::class)->whereNumber('group')->name('groups.show');
});

/*
 * The tenant portal's Building Groups (P07-02; the portal's rules,
 * P03-11a): across every organization the user rents from, so no
 * `organization` middleware and no organization in the URL. Pages read
 * through Portal\TenantGroups (the Tenants module's TenantPortalAccess)
 * and check BuildingGroupPolicy::viewAsTenant; a group the user is not a
 * member of now answers 404.
 */
Route::middleware(['auth', 'verified', 'throttle:120,1'])->prefix('portal')->name('portal.')->group(function () {
    Route::livewire('groups', PortalGroups::class)->name('groups.index');
    Route::livewire('groups/{group}', PortalGroupShow::class)->whereNumber('group')->name('groups.show');
});
