<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Settings registry (PLATFORM_CONSOLE.md § 9.8)
|--------------------------------------------------------------------------
|
| Every setting is declared once: type (string, integer, boolean, json),
| default, validation rules, scope (global or organization), whether it is
| a secret (encrypted at rest, never logged or shown), the console
| permission that may change it, and whether changing it needs step-up.
| Modules declare their own keys with SettingsRegistry::define().
| Theme, website and SEO keys arrive with P00-09.
|
*/

return [

    'definitions' => [

        'site.name' => [
            'type' => 'string',
            'default' => 'GoProperty',
            'rules' => ['required', 'string', 'max:60'],
            'permission' => 'settings.site',
        ],

        // Cloudflare Turnstile on sign-in (PLATFORM_CONSOLE.md § 9.6).
        'auth.turnstile_site_key' => [
            'type' => 'string',
            'rules' => ['nullable', 'string', 'max:100'],
            'permission' => 'settings.security',
        ],

        'auth.turnstile_secret_key' => [
            'type' => 'string',
            'rules' => ['nullable', 'string', 'max:100'],
            'secret' => true,
            'permission' => 'settings.security',
            'step_up' => true,
        ],

    ],

];
