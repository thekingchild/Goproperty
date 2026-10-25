# Runbook — Deploying GoProperty on Shared Hosting (cPanel or Plesk)

Applies to hosting Profile A ([ARCHITECTURE.md § 12.1](../../ARCHITECTURE.md)). Follow it for staging and production. Where cPanel and Plesk differ, both are shown.

## 1. Check the host before buying (or before go-live)

| Requirement | How to check | Why |
| --- | --- | --- |
| PHP 8.4 (web **and** CLI) | cPanel: *MultiPHP Manager*. Plesk: *PHP Settings* per domain. CLI: `php -v` over SSH (cPanel CLI binary is usually `/opt/cpanel/ea-php84/root/usr/bin/php`; Plesk: `/opt/plesk/php/8.4/bin/php`) | Laravel 13 |
| PHP extensions: `pdo_mysql`, `mbstring`, `intl`, `bcmath`, `gd` or `imagick`, `zip`, `fileinfo`, `openssl`, `sodium`, `exif`, `curl`, `dom` | cPanel: *Select PHP Version → Extensions*. Plesk: *PHP Settings* | Money, images, Excel, PDFs |
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
   QUEUE_CONNECTION=database
   CACHE_STORE=database
   SESSION_DRIVER=database
   BROADCAST_CONNECTION=log            # no Reverb on shared hosting
   PDF_DRIVER=dompdf
   FILESYSTEM_DISK=s3                  # media lives in S3-compatible storage, not on the host
   MAIL_MAILER=postmark                # or mailgun/ses — never the host's sendmail
   ```
4. **First install** — full detail in [INSTALLER.md](../../INSTALLER.md):
   - Upload the release zip with File Manager (or `scp`), compare its `.sha256`, extract the `preferred/` layout (or `split/` if the domain must use `public_html`).
   - Open `https://your-domain/install` and unlock it: **with SSH** run `php artisan goproperty:setup-token` and paste the token; **without SSH** create `storage/app/install-unlock.txt` containing the phrase the page shows.
   - Follow the wizard: server check → site & database → build the database → email & file storage tests → first super-admin with 2FA → finish (console path, cron line, live cron check, installation record).
   - Or, over SSH: `php artisan goproperty:install` (same steps, non-interactive mode available).
   The installer writes `.env`, `APP_KEY` and the `ADMIN_PATH` suffix, runs migrations and seeders, and caches config, routes and views.
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

CI (GitHub Actions) does the heavy work so the server only receives files:

1. CI runs tests (MySQL **and** MariaDB), then builds an artifact: `composer install --no-dev --optimize-autoloader` + `npm ci && npm run build` (Vite assets) → `release.tar.gz`.
2. CI uploads the artifact over SSH (rsync/scp) to `releases/<timestamp>/`, links `shared/.env` and `shared/storage`.
3. CI runs over SSH:
   ```
   php artisan down --render="errors::maintenance"   # only for schema-breaking releases
   php artisan migrate --force
   ln -sfn releases/<timestamp> current               # atomic switch
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   php artisan queue:restart
   php artisan up
   ```
4. Keep the last 5 releases; rollback = point `current` back to the previous release (migrations must be backward compatible for one release).

**Alternatives if CI can't SSH in:** cPanel *Git™ Version Control* with a `.cpanel.yml` that copies a pre-built branch; Plesk *Git* extension with "Additional deployment actions". In both cases deploy a **build branch** that already contains `vendor/` and built assets.

## 4. Backups on shared hosting

- Nightly DB and files backups go to S3-compatible storage and Google Drive ([PLATFORM_CONSOLE.md § 4](../../PLATFORM_CONSOLE.md)). They need `mysqldump` via `exec`. If the host disables it, switch the dumper to the pure-PHP fallback (`DB_DUMPER=php`), which is slower but works anywhere.
- Also keep the host's own daily backups switched on (cPanel *JetBackup* / Plesk *Backup Manager*) as a second line.
- Shared hosting has no point-in-time recovery: target RPO is 24 h. Add a midday DB backup to bring it to 12 h.

## 5. Health checks after deploy

- `https://goproperty.ng/up` returns 200.
- Console → Operations: scheduler heartbeat < 2 min old, queue wait < 1 min, last backup OK.
- Log in as a test agency; open an invoice PDF; send a test email; trigger a Paystack test webhook.
- `APP_DEBUG=false` confirmed (an error page must not show stack traces).

## 6. When to leave shared hosting

Move to a VPS (Profile B) when: ~50 paying customers or ~5,000 units; queue wait regularly over 5 minutes; the host throttles CPU/memory or suspends the account for resource use; Building Group needs instant updates; or PDF batches are slow. The move is configuration only: switch `QUEUE_CONNECTION`/`CACHE_STORE`/`SESSION_DRIVER` to `redis`, `BROADCAST_CONNECTION` to `reverb`, `PDF_DRIVER` to `chrome`, and optionally move the console to its own subdomain.
