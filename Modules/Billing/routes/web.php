<?php

declare(strict_types=1);

use App\Http\Middleware\SignInPageHeaders;
use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\Controllers\InvoiceController;
use Modules\Billing\Http\Controllers\MeterPhotoController;
use Modules\Billing\Http\Controllers\PayPageController;
use Modules\Billing\Livewire\BillIndex;
use Modules\Billing\Livewire\BillShow;
use Modules\Billing\Livewire\UtilityReadingForm;
use Modules\Billing\Livewire\UtilityReadingIndex;
use Modules\Billing\Livewire\UtilitySettingForm;
use Modules\Billing\Livewire\UtilitySettingIndex;
use Modules\Billing\Livewire\UtilityTypeForm;
use Modules\Billing\Livewire\UtilityTypeIndex;

/*
 * Workspace pages of the Billing module. `organization` checks membership
 * (404 otherwise) and sets the organization context before route model
 * binding, so {bill} resolves only inside the organization
 * (ARCHITECTURE.md § 4).
 */
Route::middleware(['auth', 'verified', 'organization'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    Route::livewire('bills', BillIndex::class)->name('bills.index');
    Route::livewire('bills/{bill}', BillShow::class)->whereNumber('bill')->name('bills.show');

    // The bill's invoice (P03-05c): web view, and the kept PDF through a signed link.
    Route::controller(InvoiceController::class)->middleware('throttle:60,1')->group(function () {
        Route::get('bills/{bill}/invoice', 'show')->whereNumber('bill')->name('bills.invoice');
        Route::get('bills/{bill}/invoice.pdf', 'download')->whereNumber('bill')->name('bills.invoice.pdf');
    });
});

/*
 * The public pay page (P03-05c; BRANDING_DOCUMENTS.md § 1 "Pay page"): no
 * sign-in, the payment reference is the key (found by Pay\PayPages, the
 * P01-04 lookup pattern), unknown references a plain 404. One rate-limit
 * bucket per IP (prefix "pay").
 */
Route::get('pay/{reference}', PayPageController::class)
    ->middleware([SignInPageHeaders::class, 'throttle:30,1,pay'])
    ->where('reference', '[A-Za-z0-9 -]{6,64}')
    ->name('pay.show');

/*
 * Utilities (P03-06a): utility types, the units' utilities and meter
 * readings, inside the organization like the bill pages. {utilityType},
 * {setting} and {reading} resolve inside the organization only.
 */
Route::middleware(['auth', 'verified', 'organization'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    Route::livewire('utilities', UtilityTypeIndex::class)->name('utilities.index');
    Route::livewire('utilities/types/create', UtilityTypeForm::class)->name('utilities.types.create');
    Route::livewire('utilities/types/{utilityType}/edit', UtilityTypeForm::class)->whereNumber('utilityType')->name('utilities.types.edit');
    Route::livewire('utilities/units', UtilitySettingIndex::class)->name('utilities.settings.index');
    Route::livewire('utilities/units/add', UtilitySettingForm::class)->name('utilities.settings.create');
    Route::livewire('utilities/units/{setting}/edit', UtilitySettingForm::class)->whereNumber('setting')->name('utilities.settings.edit');
    Route::livewire('utilities/readings', UtilityReadingIndex::class)->name('utilities.readings.index');
    Route::livewire('utilities/readings/record', UtilityReadingForm::class)->name('utilities.readings.create');
    Route::livewire('utilities/readings/{reading}/edit', UtilityReadingForm::class)->whereNumber('reading')->name('utilities.readings.edit');
    Route::get('utilities/readings/{reading}/photo', MeterPhotoController::class)->whereNumber('reading')->middleware('throttle:60,1')->name('utilities.readings.photo');
});
