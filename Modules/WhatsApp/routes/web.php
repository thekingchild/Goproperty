<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\WhatsApp\Livewire\WhatsAppMessagesPage;
use Modules\WhatsApp\Livewire\WhatsAppSettingsPage;

/*
 * Settings → WhatsApp (the organization's own WhatsApp Business account:
 * connection and templates; the messages' templates and channel order).
 * `organization` checks membership (404 otherwise) and sets the
 * organization context (ARCHITECTURE.md § 4).
 */
Route::middleware(['auth', 'verified', 'organization'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    Route::livewire('settings/operations/whatsapp', WhatsAppSettingsPage::class)->name('settings.operations.whatsapp');
    Route::livewire('settings/operations/whatsapp/messages', WhatsAppMessagesPage::class)->name('settings.operations.whatsapp.messages');
});
