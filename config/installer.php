<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Installation markers and installer files
    |--------------------------------------------------------------------------
    |
    | The three markers (INSTALLER.md § 3): the lock file, the
    | `installation_state` row and the checkpoint. The installer stays closed
    | if any of them says the install finished; the site opens only when the
    | lock file and the row both say "finalized". The other files belong to
    | the unlock step (§ 4) and are deleted by the finalizer.
    |
    */

    'paths' => [
        'lock' => storage_path('app/installed.lock'),
        'checkpoint' => storage_path('app/private/install.checkpoint'),
        'setup_token' => storage_path('app/private/setup.token.hash'),
        'unlock_challenge' => storage_path('app/private/unlock.challenge'),
        'unlock_file' => storage_path('app/install-unlock.txt'),
        'grant' => storage_path('app/private/install.grant'),
        'attempts' => storage_path('app/private/install.attempts'),
        // Written once by the command-line install: version, site, console
        // path, database and APP_KEY. Store it offline, then delete it.
        'record' => storage_path('app/private/installation-record.txt'),
    ],

    /*
    | The connection used to read the `installation_state` marker while the
    | installer may still be open. Null: a copy of the default connection
    | with a short timeout, so a wrong host never hangs the page.
    */
    'marker_connection' => null,

    'database_timeout' => 3,

    /*
    | Batched migrations (INSTALLER.md § 4): a few migrations per request,
    | each request kept well under shared hosts' 30–60 s limits.
    */
    'migrations_per_batch' => 5,
    'batch_seconds' => 20,

    /*
    |--------------------------------------------------------------------------
    | Web installer (INSTALLER.md § 4–5)
    |--------------------------------------------------------------------------
    |
    | The unlock phrase for the file check is valid for 30 minutes; unlock
    | attempts are limited per IP address. The grant lasts 60 minutes from
    | the last completed step. The wizard's session is an encrypted file
    | session of its own, since the database does not exist yet.
    |
    */

    'challenge_minutes' => 30,
    'unlock_attempts' => 5,
    'unlock_decay_minutes' => 15,
    'grant_minutes' => 60,
    'session_cookie' => 'gp_install_session',
    'session_files' => storage_path('framework/sessions'),

];
