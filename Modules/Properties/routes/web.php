<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Properties\Livewire\OwnerProfileForm;
use Modules\Properties\Livewire\OwnerProfileIndex;
use Modules\Properties\Livewire\PropertyForm;
use Modules\Properties\Livewire\PropertyIndex;
use Modules\Properties\Livewire\PropertyShow;
use Modules\Properties\Livewire\UnitBulkForm;
use Modules\Properties\Livewire\UnitForm;

/*
 * Workspace pages of the Properties module. `organization` checks
 * membership (404 otherwise) and sets the organization context before
 * route model binding, so {owner}, {property} (a building's slug) and
 * {unit} resolve only inside the organization
 * (ARCHITECTURE.md § 4).
 */
Route::middleware(['auth', 'verified', 'organization'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    // Property owners (DATABASE_SCHEMA.md § 1).
    Route::livewire('owners', OwnerProfileIndex::class)->name('owners.index');
    Route::livewire('owners/create', OwnerProfileForm::class)->name('owners.create');
    Route::livewire('owners/{owner}', OwnerProfileForm::class)->whereNumber('owner')->name('owners.edit');

    // Buildings (DATABASE_SCHEMA.md § 3), by slug; "create" is never a slug.
    Route::livewire('buildings', PropertyIndex::class)->name('buildings.index');
    Route::livewire('buildings/create', PropertyForm::class)->name('buildings.create');
    Route::livewire('buildings/{property}', PropertyShow::class)->name('buildings.show');
    Route::livewire('buildings/{property}/edit', PropertyForm::class)->name('buildings.edit');

    // Units of a building (DATABASE_SCHEMA.md § 3); the unit must be the building's.
    Route::livewire('buildings/{property}/units/create', UnitForm::class)->name('units.create');
    Route::livewire('buildings/{property}/units/bulk', UnitBulkForm::class)->name('units.bulk');
    Route::livewire('buildings/{property}/units/{unit}/edit', UnitForm::class)->whereNumber('unit')->name('units.edit');
});
