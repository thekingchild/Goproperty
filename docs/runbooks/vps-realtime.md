# Runbook — Live Updates with Reverb (VPS)

Applies to hosting Profile B ([ARCHITECTURE.md § 12.1](../../ARCHITECTURE.md)). On shared hosting nothing here is needed: Building Group pages poll every 15 seconds. With Reverb, a post, comment or reaction shows on other open pages within a second, and pages still poll every 60 seconds in case a nudge is missed ([ARCHITECTURE.md § 6.8](../../ARCHITECTURE.md) "How live updates are built").

Reverb ships inside the release (`laravel/reverb` in `vendor/`): nothing to install with Composer on the server.

## 1. Settings (`shared/.env`)

```
HOSTING_PROFILE=vps                 # sets REALTIME_DRIVER=reverb and BROADCAST_CONNECTION=reverb
REVERB_APP_ID=<6 random digits>
REVERB_APP_KEY=<20 random letters and digits>     # public: browsers see it
REVERB_APP_SECRET=<40 random letters and digits>  # secret: never shown to browsers
# How the app reaches Reverb (same server, no TLS):
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
# How browsers reach it (through the web server, with TLS):
REVERB_CLIENT_HOST=app.example.ng   # the site's own host, or a ws. subdomain
REVERB_CLIENT_PORT=443
REVERB_CLIENT_SCHEME=https
# Only when pages are served from more than APP_URL's host:
# REVERB_ALLOWED_ORIGINS=app.example.ng,*.example.ng
```

Make the key and secret with `php -r 'echo bin2hex(random_bytes(10)), PHP_EOL, bin2hex(random_bytes(20)), PHP_EOL;'`. Changing them later only needs open pages to be reloaded.

Then run `php artisan config:cache` and `php artisan goproperty:hosting`: it must say the configuration is consistent and show "Reverb (fallback poll 60s)". It fails when the keys, `REVERB_HOST` or a host for browsers are missing.

## 2. Keep Reverb running (Supervisor)

`/etc/supervisor/conf.d/goproperty-reverb.conf`:

```
[program:goproperty-reverb]
command=php /home/USER/goproperty/current/artisan reverb:start --host=127.0.0.1 --port=8080
user=USER
autostart=true
autorestart=true
stopwaitsecs=10
stdout_logfile=/home/USER/goproperty/shared/storage/logs/reverb.log
redirect_stderr=true
minfds=10000
```

`supervisorctl reread && supervisorctl update`. Each open page holds one connection: raise the open-files limit (`minfds`, and `ulimit -n` for the user) above the number of people you expect online at once.

**After every deploy** restart it so it runs the new release: `php artisan reverb:restart` (or `supervisorctl restart goproperty-reverb`). Forge and Ploi have a Reverb toggle that writes the same daemon.

## 3. Web server proxy (nginx)

Browsers connect to `wss://REVERB_CLIENT_HOST/app/<key>`; nginx passes `/app` and `/apps` to Reverb:

```
location ~ ^/apps?/ {
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header Scheme $scheme;
    proxy_set_header SERVER_PORT $server_port;
    proxy_set_header REMOTE_ADDR $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_read_timeout 120s;
    proxy_pass http://127.0.0.1:8080;
}
```

Port 8080 stays closed to the internet (firewall): only nginx and the app talk to it.

## 4. Check it works

1. Open the same Building Group in two browsers signed in as two members (or a tenant and a member of staff).
2. In the browser's developer tools, Network → WS: one connection to `/app/<key>`, status 101, a `pusher_internal:subscription_succeeded` message for `private-group.<id>`.
3. Post in one browser: the post appears in the other within a second.
4. `php artisan reverb:start --debug` (stopped daemon, in a terminal) shows each message if something is unclear.

## 5. When it goes wrong

| Symptom | Cause and fix |
| --- | --- |
| Posts show only after up to a minute | Reverb is down or unreachable from browsers: pages fall back to polling. Check `supervisorctl status`, `reverb.log`, the nginx `location`, and the browser's WS connection. Laravel's log has "Pusher error" lines when the app cannot reach Reverb (writes still succeed). |
| WS connection refused with 4009 / closed at once | The page's host is not in the allowed origins: set `REVERB_ALLOWED_ORIGINS`. |
| `/broadcasting/auth` answers 403 | The person may not open that group (not a member, lease ended, plan without Building Group): expected. For everyone: check the session cookie reaches the app (same host). |
| `/broadcasting/auth` answers 419 | The page's CSRF token expired: reloading the page fixes it. |
| `goproperty:hosting` fails | Its message names the missing setting. |

To turn live updates off without a deploy, set `REALTIME_DRIVER=polling` and `BROADCAST_CONNECTION=null`, `php artisan config:cache`: pages go back to polling every 15 seconds.
