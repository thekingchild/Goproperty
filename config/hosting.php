<?php

declare(strict_types=1);

$vps = env('HOSTING_PROFILE', 'shared') === 'vps';

return [

    /*
    |--------------------------------------------------------------------------
    | Hosting profile
    |--------------------------------------------------------------------------
    |
    | "shared" (Profile A: cPanel or Plesk) or "vps" (Profile B). The profile
    | sets the default queue, cache, session, broadcast, realtime and PDF
    | drivers; each driver's own variable still overrides it. Moving hosts is
    | configuration only. See ARCHITECTURE.md § 12.1.
    |
    */

    'profile' => env('HOSTING_PROFILE', 'shared'),

    /*
    |--------------------------------------------------------------------------
    | Realtime updates
    |--------------------------------------------------------------------------
    |
    | "polling": Livewire polls every `poll_seconds`. "reverb": WebSockets
    | through Reverb (VPS only), with a slower poll kept as a fallback.
    |
    */

    'realtime' => [
        'driver' => env('REALTIME_DRIVER', $vps ? 'reverb' : 'polling'),
        'poll_seconds' => (int) env('REALTIME_POLL_SECONDS', 15),
        'fallback_poll_seconds' => (int) env('REALTIME_FALLBACK_POLL_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | In-process queue tick
    |--------------------------------------------------------------------------
    |
    | On shared hosting the one cron line runs `schedule:run`, and this tick
    | drains the database queue inside that PHP process: no `exec` or
    | `proc_open` (INSTALLER.md § 8). On a VPS, Horizon or always-on workers
    | replace it.
    |
    | Lanes are visited in turn, one job each per pass, so a long bulk queue
    | cannot starve payments or notifications. `max_jobs` caps a lane per tick.
    | The lock outlives the budget plus one job's timeout, so a tick that is
    | still finishing its last job is never joined by the next minute's tick.
    |
    */

    'queue_tick' => [
        'enabled' => (bool) env('QUEUE_TICK_ENABLED', ! $vps),
        'connection' => env('QUEUE_TICK_CONNECTION', 'database'),
        'budget_seconds' => (int) env('QUEUE_TICK_BUDGET', 45),
        'job_timeout' => (int) env('QUEUE_TICK_JOB_TIMEOUT', 50),
        'lock_seconds' => (int) env('QUEUE_TICK_LOCK_SECONDS', 120),
        'memory_mb' => (int) env('QUEUE_TICK_MEMORY_MB', 192),
        'tries' => (int) env('QUEUE_TICK_TRIES', 3),
        'backoff' => (int) env('QUEUE_TICK_BACKOFF', 30),

        'lanes' => [
            'payments' => ['queues' => ['billing'], 'max_jobs' => 50],
            'notifications' => ['queues' => ['notifications'], 'max_jobs' => 100],
            'default' => ['queues' => ['default'], 'max_jobs' => 50],
            'bulk' => ['queues' => ['pdf', 'imports'], 'max_jobs' => 20],
        ],
    ],

];
