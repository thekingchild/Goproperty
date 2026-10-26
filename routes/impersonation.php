<?php

declare(strict_types=1);

use App\Http\Controllers\Impersonation\EndImpersonationController;
use App\Http\Controllers\Impersonation\EnterImpersonationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| GoProperty support sessions, customer side (PLATFORM_CONSOLE.md § 6)
|--------------------------------------------------------------------------
|
| The console starts a session and sends the browser to a one-time link
| (signed, 60 seconds), which opens a separate customer-side session; the
| support banner's "End session" posts to impersonation.end. Everything in
| between is App\Http\Middleware\ResumeImpersonation.
|
*/

Route::get('impersonation/enter/{session}', EnterImpersonationController::class)
    ->whereNumber('session')
    ->middleware('throttle:20,1')
    ->name('impersonation.enter');

Route::post('impersonation/end', EndImpersonationController::class)->name('impersonation.end');
