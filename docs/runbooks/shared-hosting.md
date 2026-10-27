# Runbook — Deploying GoProperty on Shared Hosting (cPanel or Plesk)

Applies to hosting Profile A ([ARCHITECTURE.md § 12.1](../../ARCHITECTURE.md)). Follow it for staging and production. Where cPanel and Plesk differ, both are shown.

## 1. Check the host before buying (or before go-live)

| Requirement | How to check | Why |
| --- | --- | --- |
| PHP 8.4 (web **and** CLI), patch release 8.4.1 or newer | cPanel: *MultiPHP Manager*. Plesk: *PHP Settings* per domain. CLI: `php -v` over SSH (cPanel CLI binary is usually `/opt/cpanel/ea-php84/root/usr/bin/php`; Plesk: `/opt/plesk/php/8.4/bin/php`) | Laravel 13 |
| PHP extensions: `pdo_mysql`, `mbstring`, `intl`, `bcmath`, `gd` or `imagick`, `zip`, `fileinfo`, `openssl`, `sodium`, `exif`, `curl`, `dom`, `xml`, `xmlreader`, `xmlwriter`, `tokenizer`, `ctype` | cPanel: *Select PHP Version → Extensions*. Plesk: *PHP Settings* | Money, images, Excel, PDFs |
| `proc_open` and `exec` (optional) | `php -i \| grep disable_functions` | Only for `mysqldump` backups; without them the pure-PHP dumper is used. The scheduler and queue run in-process and never need them |
| `memory_limit` ≥ 256M (512M better), `max_execution_time` ≥ 60 s web, `upload_max_filesize` ≥ 20M | PHP settings | Imports, PDFs, proof uploads |
| Database: **MySQL 8.0+ or MariaDB 10.6+** | cPanel: *MySQL Databases*. Plesk: *Databases* | Many shared hosts run MariaDB. GoProperty supports both; CI tests both |
| SSH access (recommended, not required) | cPanel: *Terminal* / *SSH Access*. Plesk: *SSH Terminal* or SSH user | CI deploys and CLI recovery. Without SSH, install with the web installer's file-check unlock and upgrade from the zip ([INSTALLER.md](../../INSTALLER.md)) |
| Cron **every minute** | cPanel: *Cron Jobs*. Plesk: *Scheduled Tasks* | Scheduler + queue worker. Hosts that allow only every 5 or 15 minutes are **not suitable** |
| Document root can point to `/public` | cPanel: *Domains → Document Root*. Plesk: *Hosting Settings → Document root* | Security: only `public/` is web-visible |
| Free SSL (AutoSSL / Let's Encrypt) | cPanel *SSL/TLS Status*; Plesk *SSL It!* | HTTPS everywhere |
| Outbound HTTPS allowed (Paystack, Termii, Meta, mail API, S3, Google) | `curl -I https://api.paystack.co` over SSH | Integrations |
| Inbound webhooks not blocked by host firewall / ModSecurity | Send a test webhook from Paystack test mode | Payments, WhatsApp |
| Resources: ≥ 2 GB RAM, ≥ 2 CPU cores per account, ≥ 20 GB SSD | Hosting plan sheet | Queue + PDFs |

Composer and Node on the server are **not** required: the release zip already contains `vendor/` and built assets (§ 3).

## 2. One-time setup

1. **Create the database** and a user with all privileges on it. Note host/name/user/password.
2. **Folder layout** (outside the web root):
   ```
   /home/USER/goproperty/            ← app (cPanel)   |  /var/www/vhosts/DOMAIN/goproperty/ (Plesk)
       releases/2026xxxx-xxxx/        ← each deploy
       shared/.env                    ← secrets, never in git
       shared/storage/                ← logs, cache, temp files
       current -> releases/<latest>   ← symlink
   ```
   Point the domain's **document root** to `goproperty/current/public`.
   If the host doesn't allow changing the document root (some cPanel primary domains), use the host's "addon domain" or contact support; as a last resort replace `public_html` with a symlink to `current/public`.
3. **`.env`** in `shared/` (copy from `.env.example`). Key shared-hosting values:
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://goproperty.ng
   ADMIN_PATH=gp-control-xxxxxx        # set by goproperty:install
   HOSTING_PROFILE=shared              # database queue/cache/sessions, no broadcasting, polling, dompdf, queue tick on
   FILESYSTEM_DISK=s3                  # media lives in S3-compatible storage, not on the host
   # MEDIA_DISK=media-s3               # the media library; already the default when FILESYSTEM_DISK=s3
   COMPLIANCE_DISK=compliance-s3       # Transaction Tier documents: their own private bucket (COMPLIANCE_S3_*)
   MAIL_MAILER=postmark                # or mailgun/ses — never the host's sendmail
   ```
   **Media library** (photos, payment proofs, branding images; [ARCHITECTURE.md § 9](../../ARCHITECTURE.md)): with `FILESYSTEM_DISK=s3` it uses the `media-s3` disk, which is the bucket the installer tested (`AWS_*`) under the `media/` prefix; set `MEDIA_S3_BUCKET` (and `MEDIA_S3_ACCESS_KEY_ID`, `MEDIA_S3_SECRET_ACCESS_KEY`, `MEDIA_S3_ENDPOINT`, `MEDIA_S3_REGION`) to give it a bucket of its own. Keep the bucket **private**: files are only ever streamed by the app through signed links that expire after `MEDIA_URL_LIFETIME` minutes (15). Without a bucket, files stay on the host in `storage/app/media` (in the nightly files backup). Thumbnails are made with GD by the queue tick (`media` queue, bulk lane) — no `jpegoptim`, `cwebp` or other binaries are needed. A 25-megapixel photo needs about 110 MB more memory while it is converted: where PHP's `memory_limit` is below 256M, set `MEDIA_MAX_MEGAPIXELS=12`.

   `HOSTING_PROFILE=shared` sets `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`, `BROADCAST_CONNECTION=null`, `REALTIME_DRIVER=polling`, `PDF_DRIVER=dompdf` and `QUEUE_TICK_ENABLED=true`. Set one of those only to override it. Broadcasting is `null`, not `log`, so event payloads never land in the logs. `php artisan goproperty:hosting` shows what is active.
4. **First install** — full detail in [INSTALLER.md](../../INSTALLER.md):
   - Download the release zip and its `.sha256` from the GitHub release and compare the checksum. Upload the zip with File Manager (or `scp`) and extract the `preferred/` layout (or `split/` if the domain must use `public_html`). With SSH, `php artisan goproperty:verify-package` in the app folder checks every file ([release.md § 4](release.md)).
   - Open `https://your-domain/install` and unlock it: **with SSH** run `php artisan goproperty:setup-token` and paste the token; **without SSH** create `storage/app/install-unlock.txt` containing the phrase the page shows.
   - Follow the wizard: server check → site & database → build the database → email & file storage tests → first super-admin with 2FA → finish (console path, cron line, live cron check, installation record).
   - Or, over SSH: `php artisan goproperty:install` (same steps, non-interactive mode available).
   - **Staging and any server deployed by the Deploy workflow** (the deploy layout of step 2): install with `scripts/deploy/first-install.sh` instead, from a deploy archive (`scripts/deploy/build-release.sh`, or the artifact of a Deploy run). It unpacks `releases/<name>/`, links its `.env` to `shared/.env` and `storage/` to `shared/storage/`, runs `goproperty:install` there (pass its options after `--`, or answer its questions), and points `current` at it:
     ```
     scp release.tar.gz USER@HOST:goproperty/upload.tar.gz
     ssh USER@HOST 'bash -s -- goproperty goproperty/upload.tar.gz 20261027000000-first /opt/cpanel/ea-php84/root/usr/bin/php' < scripts/deploy/first-install.sh
     ```
     Add `-- --env=production --url=https://staging.goproperty.ng …` for a non-interactive install (`php artisan goproperty:install --help` lists the options; passwords come from files).
   The installer writes `.env`, `APP_KEY` and the `ADMIN_PATH` suffix, runs migrations and seeders, and caches config, routes and views. In the deploy layout it writes through `.env` to `shared/.env`, and the cron line it prints runs `current/artisan`, which survives deploys. `scripts/deploy/deploy-drill.sh` rehearses all of this on one machine (CI runs it with the install drill).
   Media and documents live in S3-compatible storage, so `storage:link` is not needed (some hosts block symlinks inside `public/`).
5. **Cron (every minute)** — one entry only:
   - cPanel *Cron Jobs*: `* * * * * /opt/cpanel/ea-php84/root/usr/bin/php /home/USER/goproperty/current/artisan schedule:run >> /dev/null 2>&1`
   - Plesk *Scheduled Tasks* → *Run a command* every minute: `/opt/plesk/php/8.4/bin/php /var/www/vhosts/DOMAIN/goproperty/current/artisan schedule:run`
   - If Plesk's **Laravel Toolkit** extension is installed, it can manage the scheduler instead.
   Each minute the scheduler runs every due job in [ARCHITECTURE.md § 7](../../ARCHITECTURE.md) and an in-process tick drains the database queue (about 45 seconds' work, never overlapping). The installer's finish screen shows the exact line for your server and waits for the first heartbeat.
6. **SSL:** enable AutoSSL / Let's Encrypt; then turn on "redirect HTTP to HTTPS".
7. **Cloudflare** (recommended): proxy the domain, SSL mode *Full (strict)*, cache static assets only, add a WAF rule rate-limiting `/login` and the console path.
8. **PHP-FPM settings** (if the panel allows): `memory_limit=512M`, `max_execution_time=60`, `upload_max_filesize=20M`, `post_max_size=25M`, `opcache.enable=1`.

## 3. Every deploy

The **Deploy** workflow ([`.github/workflows/deploy.yml`](../../.github/workflows/deploy.yml)) does the heavy work so the server only receives files. **Every merge to `main` deploys staging.** Until the `staging` environment has its settings, that run skips with a warning instead of failing. Production is started from **Actions → Deploy → Run workflow**: pick `production`, and tick *maintenance* only for schema-breaking releases. Give the `production` environment a required reviewer.

1. It runs the full quality gate (MySQL **and** MariaDB, the `CI` workflow).
2. `scripts/deploy/build-release.sh` builds `<timestamp>-<sha>.tar.gz` (with `scripts/release/stage.sh`, shared with the release zip): tracked files, `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`; tests, docs, dev config and `storage/` are left out.
3. It uploads the archive with `scp` and runs `scripts/deploy/remote-deploy.sh` over SSH, which:
   ```
   unpack to releases/<name>/, link shared/.env and shared/storage
   php artisan goproperty:hosting      # stop here if the hosting settings cannot work together
   php artisan goproperty:backup-run db  # only with DEPLOY_BACKUP_FIRST=1; a failed backup stops the deploy
   php artisan down --retry=60         # only with "maintenance" ticked
   php artisan migrate --force
   php artisan db:seed --class='Database\Seeders\InstallSeeder' --force   # idempotent: roles gain new permissions
   php artisan config:cache && route:cache && view:cache && event:cache   # in the new release, before it is live
   ln -sfn releases/<name> current.next && mv -Tf current.next current   # atomic switch
   php artisan queue:restart
   php artisan up                      # if it went down
   delete all but the newest 5 releases (KEEP_RELEASES)
   ```
   If any step fails, `current` still points to the previous release. If maintenance mode was on, the site stays down until someone runs `php artisan up` from `current/`, because a half-run migration may need attention first.
4. It checks `APP_URL/up` returns 200.
5. Rollback = point `current` back to the previous release (`ln -sfn releases/<previous> current.next && mv -Tf current.next current`); migrations must be backward compatible for one release.

**GitHub environment settings** (Settings → Environments → `staging` / `production`; production should require a reviewer):

| Name | Kind | Example |
| --- | --- | --- |
| `DEPLOY_HOST` | variable | `server123.hosting.ng` |
| `DEPLOY_PORT` | variable (optional, default 22) | `21098` (some cPanel hosts use a custom SSH port) |
| `DEPLOY_USER` | variable | the cPanel/Plesk account user |
| `DEPLOY_PATH` | variable | `/home/USER/goproperty` (the folder holding `releases/`, `shared/`, `current`) |
| `DEPLOY_PHP` | variable (optional, default `php`) | `/opt/cpanel/ea-php84/root/usr/bin/php` or `/opt/plesk/php/8.4/bin/php` |
| `DEPLOY_KNOWN_HOSTS` | variable | the host's line from `ssh-keyscan -p PORT HOST`, checked against the fingerprint the host shows in its panel |
| `APP_URL` | variable | `https://staging.goproperty.ng` |
| `DEPLOY_SSH_KEY` | **secret** | private key of a deploy-only key pair; add the public key under cPanel *SSH Access → Manage Keys* or the Plesk SSH user |
| `DEPLOY_BACKUP_FIRST` | variable (optional) | `1` on production: back up the database before each deploy's migrations (needs a backup destination) |

The first install still uses the installer ([INSTALLER.md](../../INSTALLER.md)), which creates `shared/.env`; the deploy script refuses to run without it.

**Alternatives if CI can't SSH in:** cPanel *Git™ Version Control* with a `.cpanel.yml` that copies a pre-built branch; Plesk *Git* extension with "Additional deployment actions". In both cases deploy a **build branch** that already contains `vendor/` and built assets.

## 4. Backups on shared hosting

- Nightly DB and files backups go to S3-compatible storage and Google Drive ([PLATFORM_CONSOLE.md § 4](../../PLATFORM_CONSOLE.md)). They run inside the cron's PHP process with a pure-PHP dumper (`BACKUP_DUMPER=php`, the default), so they work where `exec` is disabled; `BACKUP_DUMPER=mysqldump` uses the binary where the host allows it. Add destinations with `php artisan goproperty:backup-destination:add`; restore with [restore.md](restore.md).
- Also keep the host's own daily backups switched on (cPanel *JetBackup* / Plesk *Backup Manager*) as a second line.
- Shared hosting has no point-in-time recovery: target RPO is 24 h. Add a midday DB backup to bring it to 12 h.

## 5. Health checks after deploy

- `https://goproperty.ng/up` returns 200.
- `php artisan goproperty:hosting` (from `current/`) reports a consistent configuration, a scheduler heartbeat under 2 minutes old and a recent queue tick.
- Console → Operations: scheduler heartbeat < 2 min old, queue wait < 1 min, last backup OK.
- Log in as a test agency; open an invoice PDF; send a test email; trigger a Paystack test webhook.
- `APP_DEBUG=false` confirmed (an error page must not show stack traces).

## 6. When to leave shared hosting

Move to a VPS (Profile B) when: ~50 paying customers or ~5,000 units; queue wait regularly over 5 minutes; the host throttles CPU/memory or suspends the account for resource use; Building Group needs instant updates; or PDF batches are slow. The move is configuration only: set `HOSTING_PROFILE=vps` (Redis queue, cache and sessions; Reverb; Chrome PDFs through Gotenberg at `PDF_CHROME_URL`; queue tick off because Horizon runs the queues), or switch the individual variables, and optionally move the console to its own subdomain. Run `php artisan goproperty:hosting` afterwards: it fails if a selected add-on is missing (no Redis client, no Pusher SDK for Reverb, no Gotenberg URL).
