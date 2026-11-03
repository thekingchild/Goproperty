/*
 * Reverb in the browser (VPS profile only, ARCHITECTURE.md § 12.1): Laravel
 * Echo over the Pusher protocol, loaded on demand by a live screen
 * (resources/js/app.js `groupLive`) so shared-hosting pages never download
 * it. One connection per page load, shared by every live screen; the
 * settings come from the page (App\Hosting\WebsocketClient), never from the
 * build, because one release serves every host. Livewire sends
 * window.Echo's socket id with its requests, so a writer's own tab is not
 * nudged by its own write.
 */
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let echo = null;

export function connect(settings) {
    if (echo !== null) {
        return echo;
    }

    const csrf = document.querySelector('[data-csrf]')?.getAttribute('data-csrf')
        ?? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        ?? '';

    window.Pusher = Pusher;
    echo = new Echo({
        broadcaster: 'reverb',
        key: settings.key,
        wsHost: settings.host,
        wsPort: settings.port,
        wssPort: settings.port,
        forceTLS: settings.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: settings.authEndpoint,
        auth: { headers: { 'X-CSRF-TOKEN': csrf } },
    });
    window.Echo = echo;

    return echo;
}
