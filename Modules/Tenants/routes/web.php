<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Tenants\Livewire\TenantForm;
use Modules\Tenants\Livewire\TenantIndex;
use Modules\Tenants\Livewire\TenantShow;

/*
 * Workspace pages of the Tenants module (P03-01a). `organization` checks
 * membership (404 otherwise) and sets the organization context before
 * route model binding, so {tenant} resolves only inside the organization
 * (ARCHITECTURE.md § 4). A tenant's record opens archived too (read-only,
 * with Restore); the edit page only for an active tenant.
 */
Route::middleware(['auth', 'verified', 'organization'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    Route::livewire('tenants', TenantIndex::class)->name('tenants.index');
    Route::livewire('tenants/create', TenantForm::class)->name('tenants.create');
    Route::livewire('tenants/{tenant}', TenantShow::class)->whereNumber('tenant')->withTrashed()->name('tenants.show');
    Route::livewire('tenants/{tenant}/edit', TenantForm::class)->whereNumber('tenant')->name('tenants.edit');
});
