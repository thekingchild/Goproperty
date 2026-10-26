<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Platform console (PLATFORM_CONSOLE.md, ONBOARDING_COMPLIANCE.md § 1)
|--------------------------------------------------------------------------
|
| The console lives at /{ADMIN_PATH} (config/app.php). Staff sessions use
| their own cookie, so they never mix with customer sessions on the same
| domain. The cookie is not limited to the console path: Livewire's update
| endpoint sits at the site root, and console pages must reach it.
|
*/

return [

    'session' => [
        'cookie' => 'gp_staff_session',
        // Idle timeout in minutes (ARCHITECTURE.md § 10).
        'lifetime' => 30,
    ],

    // Optional office/VPN allowlist, comma-separated IPs. Everyone else gets
    // a plain 404 for the console. Empty: no restriction.
    'ip_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('CONSOLE_IP_ALLOWLIST', ''))))),

    // Common guesses at an admin URL; requests to them are logged as probes.
    'probe_paths' => ['admin', 'admin/*', 'administrator', 'administrator/*', 'wp-admin', 'wp-admin/*', 'wp-login.php', 'phpmyadmin', 'phpmyadmin/*', 'cpanel'],

];
