# GoProperty — Application Installer

Companion documents: [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) · [ARCHITECTURE.md § 12](ARCHITECTURE.md) · [docs/runbooks/shared-hosting.md](docs/runbooks/shared-hosting.md) · [PLATFORM_CONSOLE.md § 10](PLATFORM_CONSOLE.md)

How the platform owner installs GoProperty on their own hosting — cPanel or Plesk shared hosting, or a VPS — from a release zip, **with or without SSH**, and how it is upgraded afterwards. It is the installer for GoProperty's own servers (production and staging), not something agencies use.

The design follows the installer in the product owner's SocietyFlow build (studied Oct 2026) and keeps its security model, but fixes the parts that made SocietyFlow hard to use on shared hosting. § 11 lists what was kept and what was changed.

## 1. Goals

1. **Upload a zip, open a URL, finish in the browser in about 10 minutes.** SSH is optional; there is no Composer or Node on the server.
2. **A stranger who finds a freshly uploaded copy cannot take it over.** Only someone who controls the hosting account can unlock the installer.
3. **Resumable.** A timeout, closed tab or lost connection never leaves a half-installed site that needs a developer.
4. **Shared-hosting safe.** No `exec`/`proc_open`, no symlinks, works inside 60-second request limits, and only touches its own database.
5. **Ends verified.** The finish screen proves the cron job, email and file storage really work before the owner leaves.
6. **Closed for good.** After finishing, `/install` returns 404 forever. Recovery is via files or the command line, never over the web.

## 2. The release package

CI builds the package on every version tag (`v1.4.0`, `v1.4.0-rc.1`) with the **Release** workflow (GitHub Actions → GitHub Releases). How to cut a release: [docs/runbooks/release.md](docs/runbooks/release.md).

```
goproperty-1.4.0.zip            ← upload this
goproperty-1.4.0.zip.sha256     ← checksum to compare after upload
```

- **Contents:** application code, `vendor/` (`composer install --no-dev --optimize-autoloader`), built front-end assets in `public/build/` (Vite), `.env.example`, `VERSION`, an inner `SHA256SUMS`, and the empty `storage/` and `bootstrap/cache/` folders. Tests, docs, dev config and Laravel's package caches are left out. The installer's static `public/install.css`, so its first screens render without the build manifest. Built by `scripts/release/build-package.sh <version> <dir>`.
- **Two layouts in one zip,** plus a `README.txt` saying which to use:
  - `preferred/goproperty/` — the domain's document root points at `goproperty/public`. Use this whenever the panel allows changing the document root.
  - `split/goproperty-app/` + `split/public_html/` — for hosts whose primary domain must use `public_html`. The generated `public_html/index.php` points at `../goproperty-app` and calls `$app->usePublicPath(__DIR__)`; `goproperty-app/bootstrap/public-path.php` gives artisan and cron the same folder (edit it if the host names the folder differently, e.g. Plesk's `httpdocs`). Only public files sit in `public_html`.
- **`SHA256SUMS`** (one per layout, `sha256sum` format) lists every file except `storage/` and `bootstrap/cache/`. Public files are listed as `public/…` in both layouts, wherever they live.
- **Package verifier:** `php artisan goproperty:verify-package [path]` checks a zip (read in place, before anything is unpacked), an unpacked layout folder, or with no argument the running installation. CI runs it on every release. It refuses a package that contains symlinks, unsafe paths (`..`, absolute, backslashes), `.env*` files (only `*.example` is allowed), `auth.json`, key files, SQLite files, `.git`/`node_modules` folders, dev dependencies (checked against `composer.lock` and Composer's `installed.json`) or secret-shaped strings (private keys, AWS, Paystack/Stripe, GitHub, Slack, Google keys, a filled-in `APP_KEY`, DSNs with keys; reported by kind, never printed), or that has a missing Vite manifest or asset, a changed, missing or added file against `SHA256SUMS`, an entry point that skips the old-PHP guard, a zip that does not match its `.sha256`, or a `VERSION` that does not match the zip's name. On an installation it ignores the site's own `.env`, `storage/`, `bootstrap/cache/` and what hosting panels add (`error_log`, `.user.ini`, `public/.well-known/`, `public/cgi-bin/`, and cPanel's PHP-handler block in `public/.htaccess`).
- **Old-PHP guard:** `public/index.php` and `artisan` first load a tiny `bootstrap/php-version.php` (no PHP 8 syntax, so old versions can parse it) that prints one plain sentence ("GoProperty needs PHP 8.4.1 or newer; this server runs 8.1.30.") with exit code 1, or HTTP 500 on the web, instead of a fatal error. CI runs it on PHP 7.4 and 8.1.
- **No `storage:link`:** media and documents live in S3-compatible storage, and brand assets are streamed by controllers (`/brand/*`), so nothing needs a symlink inside `public/`.

## 3. How the app knows whether it is installed

| Marker | Where | Written |
| --- | --- | --- |
| Install lock | `storage/app/installed.lock` | At finish |
| Database row | `installation_state` (singleton): `status` = `configured` → `migrating` → `owner_ready` → `finalized`, `version`, `finalized_at` | Through the wizard |
| Checkpoint | `storage/app/private/install.checkpoint` (stage + HMAC fingerprint of the DB settings) | Through the wizard |

- **Installer closed** if **any** marker says finished (fail closed). **Site open to the public** only when the lock file **and** the database row both say `finalized`.
- How each marker is read (`App\Installer\InstallationMarkers`):
  - **Lock file:** present = finished.
  - **Checkpoint:** JSON with the stage, the database fingerprint and an HMAC over both, keyed by `APP_KEY`. `cleanup_pending` or `finalized`, or a checkpoint that cannot be parsed or verified (for example after `APP_KEY` changed), counts as finished.
  - **Database row:** read only once `APP_KEY` exists, through a copy of the default connection with a 3-second timeout (`installer.database_timeout`). If the database cannot be reached, the installer stays closed when a checkpoint exists (the installer had recorded a database), and stays open when none does (a fresh `.env` still holds `.env.example`'s placeholder settings).
  - **Site gate:** without the lock file the site shows "being set up" with no database query. With it, every request reads the row (one primary-key query). If that read fails, the page says "temporarily unavailable" (503) instead of "being set up".
- These pages and every installer screen are plain PHP views (`resources/views/installer/*.php`) styled by the static `public/install.css`. They render without compiling Blade into `storage/`, without the Vite manifest and (until step 3) without the database. `/up` stays reachable once `APP_KEY` exists, so uptime checks work during setup.
- **Development:** `php artisan migrate --seed` writes both markers on a `local` copy (`DatabaseSeeder`). Tests get an installed site by default and use `markNotInstalled()` to undo it.
- **Before APP_KEY exists:** a global `AwaitApplicationKey` middleware (first in the stack) redirects every GET to `/install` and answers writes with a plain 503 + `Retry-After`. It uses no session, cookie or database, so a fresh upload never fills the log with "No application encryption key" errors.
- **After APP_KEY, before finish:** every public route (landing page, `/login`, signup, `/o/…`, `/portal`, webhooks) returns a neutral "GoProperty is being set up" page (503, `noindex`). This closes SocietyFlow's gap where pages were reachable mid-install and a stranger could register first.
- **After finish:** `/install` and `/install/*` return 404. They are also excluded from custom code, the sitemap and `robots.txt` indexing (`Disallow: /install`).

## 4. Unlocking: proving you control the hosting

The first screen (`GET /install`) shows the version, a public requirements summary (pass/fail only, no paths) and **two ways to unlock**:

**A. Setup token (SSH or VPS)**

```
php artisan goproperty:setup-token
```

This prints a 40-character token once and stores only its hash (`storage/app/private/setup.token.hash`, mode 0600). The owner pastes the token into the form.

**B. File check (no SSH — cPanel File Manager / Plesk File Manager)**

- The screen shows a short random phrase, for example `GP-UNLOCK 7Q4M-K2XD-PL9A`, valid for 30 minutes. Its hash is stored in `storage/app/private/unlock.challenge`. Each view of the screen adds a new phrase; the 20 most recent stay valid, so reloading the page never breaks a file already created.
- The owner creates a file named `install-unlock.txt` in `storage/app/` containing that phrase and clicks "I've created the file".
- The installer reads the file (a byte-order mark, Windows line endings, extra spaces and lower case are ignored; a symlink or a file over 512 bytes is refused), compares it with the stored hashes, then deletes it and every phrase. Creating a file outside the web root is something only the hosting account holder can do, so it proves control without needing a shell. The phrase itself is not a secret: anyone who opens `/install` sees one. (SocietyFlow needed SSH or a "one-shot cron" trick here; this replaces both.)

**Both paths:**

- HTTPS is required in production. The installer checks that AutoSSL / Let's Encrypt is active first and explains how to turn it on if not.
- Same-origin check: the `Origin` header must match, or `Sec-Fetch-Site: same-origin` plus a matching `Referer`.
- 5 attempts per IP per 15 minutes, counted in a file with `flock` (`storage/app/private/install.attempts`, addresses hashed; atomic, as the database doesn't exist yet). A successful unlock clears that address's count.
- On success:
  1. Create `.env` from `.env.example` if it's missing (mode 0600). SocietyFlow required this file to be made by hand.
  2. Generate `APP_KEY` (an existing valid key is reused) and write it atomically: temp file → fsync → rename. `APP_DEBUG=false` is written at the same time (`.env.example` ships with debug pages on).
  3. Switch the key into the running request.
  4. Start an encrypted file session and issue the **installer grant**.
- **Installer session:** the wizard's own encrypted file session (`storage/framework/sessions`), cookie `gp_install_session` limited to `/install`, `HttpOnly`, `SameSite=Strict`, `Secure` on https. The database doesn't exist yet, and `.env.example` asks for database sessions, so the wizard never uses the site's session settings.
- **Installer grant:** session-bound, rotated on first use, valid **60 minutes from the last step**. The session holds a random token; `storage/app/private/install.grant` (mode 0600) holds only its hash, so there is one grant at a time and a new unlock ends any other browser's access. The first request after the unlock swaps the token and the session ID. Every completed step (each migration batch included) renews it, so enrolling 2FA can't make it expire (a SocietyFlow pain point); looking at pages does not. If it does expire, the owner simply unlocks again with method A or B (the setup token stays valid until finish) — no shell needed, and nothing done so far is lost.
- These routes run without the normal session, CSRF and cookie-encryption middleware, because none of them can work before APP_KEY exists. Every route after unlock uses the normal web stack with CSRF; without `APP_KEY` those routes send the browser back to `/install` before any of that middleware is built.

## 5. Wizard steps

Layout: the same split shell as the login page ([UX_DESIGN.md § 3](UX_DESIGN.md)) — brand panel on the left with a vertical step list (✓ for done, `aria-current="step"`), the form on the right; on a phone the panel becomes a slim bar saying "Step 3 of 7". It is plain PHP views with full-page POST forms (each step is a fresh request, which suits config and key changes; no Blade is compiled, so it works before `storage/` is known to be writable), uses the static `install.css`, and is `noindex`, accessible and readable at 360 px.

Steps open in order: a step further than the first unfinished one redirects back to it, and steps already done can be opened again. What is done is read from the markers, Laravel's `migrations` table and the staff table, so a lost tab or a new unlock carries on where the install stopped; only choices that leave no trace (server check seen, email or storage skipped) are asked again. If the database settings in `.env` stop matching the checkpoint, every step shows "Install stopped" with the recovery steps (§ 6). Code: `App\Http\Controllers\Install`, `App\Installer\Web`, `routes/install.php`.

| # | Step | What happens |
| --- | --- | --- |
| 1 | **Unlock** | § 4 |
| 2 | **Server check** | Detailed report, grouped. **Blocking:** PHP ≥ 8.4.1 (web; the same minimum as Composer's platform check), extensions (`pdo_mysql`, `mbstring`, `intl`, `bcmath`, `gd` or `imagick`, `zip`, `fileinfo`, `openssl`, `sodium`, `exif`, `curl`, `dom`, `xml`, `xmlreader`, `xmlwriter` (Excel import and templates), `tokenizer`, `ctype`), `max_execution_time` ≥ 30 s, writable `storage/*`, `bootstrap/cache` and `.env`'s folder (a real probe file is written and deleted), public-root safety (no `.env`, `artisan` or `composer.json` reachable from the web; document root = `public`), Vite manifest present, HTTPS. **Advisory:** `memory_limit` ≥ 256M, `upload_max_filesize` ≥ 20M, `proc_open`/`exec` (only used by `mysqldump`; the pure-PHP dumper works without them), free disk ≥ 2 GB, OPcache on. Each failure says how to fix it in cPanel and in Plesk |
| 3 | **Site & database** | Fields: site address (`https://` origin, no path), staging or production, database type (MySQL 8 / MariaDB 10.6+), host, port, name, username, password. **Test connection** (3-second timeout, temporary connection). Refuses a database that already has tables unless they are GoProperty's own from an earlier attempt (table listing is limited to this database — on shared hosts the panel user can often see others). Writes `APP_URL`, `APP_ENV`, `APP_DEBUG=false`, `DB_*`, `HOSTING_PROFILE=shared` (database queue, polling, dompdf, no broadcasting: [ARCHITECTURE.md § 12.1](ARCHITECTURE.md)), `CACHE_STORE=file` and `SESSION_DRIVER=file` until the tables exist, and `APP_TIMEZONE=Africa/Lagos`. Values are validated (no control characters), quoted and escaped; the file is written atomically. The password is never shown again |
| 4 | **Build the database** | Runs the migrations **in small batches with a progress bar**: the page posts one batch at a time (5 migrations or 20 s, `installer.migrations_per_batch` / `batch_seconds`), shows "34 of 112", and carries on by itself (a small script submits the next batch; without JavaScript the owner presses the button). Closing the tab is safe — reopening continues from Laravel's own `migrations` table. Then the idempotent seeders run (roles and permissions, default plans and prices, Transaction Tiers, default theme, email templates). See § 6 for failures |
| 5 | **Email & file storage** | **Email:** provider (Postmark, Mailgun, SES or SMTP, all over SMTP — never the host's `sendmail`, [ARCHITECTURE.md § 12.1](ARCHITECTURE.md)), credentials, from-address, then **Send a test email** to the address the owner gives (the settings are written, then the test must succeed, or email is explicitly skipped). **File storage:** S3-compatible endpoint (empty for AWS), bucket, region, keys, path-style option, then **Test** (write, read back and delete a small file); the `AWS_*` settings are written only after the test passes. Storage can be skipped too; the finish screen lists whatever was skipped as a next step. Secrets go to `.env`, never to the database, and passwords are never shown again (left empty on a later visit, the saved one is kept). Paystack, GoText (SMS), Turnstile and backup destinations are set later in the console |
| 6 | **First super-admin** | Creates the first `platform_staff` account with the `super-admin` role (`InstallationSteps::createSuperAdmin`, shared with the CLI, calls `App\Platform\Staff\FirstSuperAdmin::grant()`; it works only for the first staff account and is audited): name, work email, password (12+ characters, typed twice, checked against breached-password lists). **2FA is set up right here:** QR code + manual key, confirm with a 6-digit code, then 8 recovery codes shown with "Download" / "Copy" and a required "I've saved my recovery codes" tick. Stored the way the console's own enrolment stores it (secret encrypted, codes hashed), so the first console sign-in asks for a code. The secret waits in the encrypted installer session until confirmed. Refused if any staff account already exists. Resuming after a timeout needs the same email and password (an authenticator already confirmed is kept) |
| 7 | **Finish** | See below |

**Finish:**

1. Write the `cleanup_pending` checkpoint first (if anything below fails, the installer is already closed and recovery is via the CLI, § 9).
2. Generate the console path `ADMIN_PATH=gp-control-<6 random characters>` ([PLATFORM_CONSOLE.md § 10](PLATFORM_CONSOLE.md)) and switch `SESSION_DRIVER` and `CACHE_STORE` to `database`.
3. Delete the setup token, unlock challenge and grant.
4. Write `installed.lock` and set the database row to `finalized`.
5. Cache config, routes, views and events.
6. Record the installation in the audit log (who, when, version, IP; `log_name` `installer`, also from the CLI). Until then, installer events go to `storage/logs/install.log`, never including passwords or keys.
7. Hand the owner over to a signed-in **console** session without signing out: a session in the `sessions` table under the staff cookie with the console's flags, recorded in the authentication log like any sign-in. With the console on its own domain (`ADMIN_DOMAIN`) the owner signs in there instead.

**Then the finish screen shows** (it is the reply to the finish post, so it is shown once: `/install` is a 404 from then on):

- **Console address**, shown once with "Bookmark this — it isn't linked anywhere".
- **Cron job:** the exact line for this server. The installer finds the PHP **CLI** binary by checking the known panel paths that exist — cPanel `/opt/cpanel/ea-php84/root/usr/bin/php`, CloudLinux `/opt/alt/php84/usr/bin/php`, Plesk `/opt/plesk/php/8.4/bin/php` — instead of trusting `PHP_BINARY`, which under the web server is often `php-fpm` or `lsphp` (a SocietyFlow bug). It shows cPanel and Plesk click paths.
- **Live cron check:** "Waiting for the first scheduler run…", which turns green when the scheduler heartbeat arrives (it polls the console's `/{ADMIN_PATH}/system/heartbeat` every 5 s with the handed-over session; staff with `system.status`). The owner knows reminders, bills and backups will run before leaving the page.
- **Installation record** (download once): version, date, site address, console path, database name, and the `APP_KEY` with a warning to store it offline — without it, encrypted data can't be read after a restore. The web wizard offers it as a download inside the page and leaves no copy on the server (the command-line install writes `storage/app/private/installation-record.txt` instead, § 7).
- **Next steps checklist,** linking into the console: Paystack keys, GoText API token and sender ID, Turnstile keys, backup destinations, platform theme, Cloudflare, `APP_DEBUG=false` confirmed.

## 6. Failure handling (simple by design)

- **Unreachable database / wrong credentials:** shown on step 3 with the driver's message cleaned of secrets. Nothing is written.
- **Timeout during a migration batch:** reopen the page; Laravel's `migrations` table says what's done, and the next batch continues.
- **A migration failed halfway** (MySQL can't roll back schema changes): the installer stops and explains that this is a brand-new install with no data, so the safe fix is **"Empty the database and start this step again"**. A button drops only GoProperty's own tables in this database, after the owner types the database name to confirm. There is **no hand-maintained list of every table and column per migration** — SocietyFlow's 2,930-line recovery manifest is deliberately not copied. Rule for developers instead: every migration must be safe to run on an empty database, and one migration does one schema change where possible (enforced in review and by the install drill, § 10).
- **Settings changed mid-install** (the DB fingerprint no longer matches the checkpoint): the installer stops and shows the recovery steps (§ 9).

## 7. Command-line install (SSH or VPS)

The same services as the wizard (`App\Installer\InstallationSteps`), without the browser:

```
php artisan goproperty:install                  # interactive: asks for anything not given as an option
php artisan goproperty:install --no-interaction \
  --env=production --url=https://goproperty.ng \
  --db-driver=mysql --db-host=localhost --db-port=3306 \
  --db-name=acct_gp --db-user=acct_gp --db-password-file=/home/USER/.gp-db-pass \
  --mail-host=smtp.postmarkapp.com --mail-port=587 --mail-user=TOKEN \
  --mail-password-file=/home/USER/.gp-mail-pass --mail-from=no-reply@goproperty.ng \
  --mail-test=owner@goproperty.ng \
  --admin-name="Ada Obi" --admin-email=ada@goproperty.ng --admin-password-file=/home/USER/.gp-admin-pass
```

- Order: server check → every answer collected and validated (so a typo never leaves a half-written `.env`) → `.env` and `APP_KEY` → test the database, write the site and database settings (`HOSTING_PROFILE=shared`, `APP_TIMEZONE=Africa/Lagos`, `APP_DEBUG=false`) → migrations in batches with progress, then the install seeders → email → first super-admin → finish → installation record.
- `--env` is Laravel's own option; it must be `production` or `staging`. `--db-driver` defaults to `mysql`, the host to `localhost`, the port to 3306.
- Passwords are read from a hidden prompt or a file (trailing new line removed; a warning if others can read it), never from a command-line argument (they would end up in shell history). The super-admin password needs 12+ characters and must not appear in a known breach.
- **Email** is SMTP (Postmark, Mailgun and SES all offer it); with `--mail-test` a failed test email stops the install before it finishes. **File storage** (S3) is not asked by the command-line install yet: set `AWS_*` in `.env` (the wizard tests it, P01-09).
- The super-admin's 2FA is enrolled at first console sign-in (it is mandatory, so the console won't open without it). The finish is recorded in the audit log, as from the wizard.
- Safe to run again after an interruption: it reuses `APP_KEY`, continues the migrations and accepts the same super-admin email and password. It refuses other database settings than the ones the checkpoint recorded (§ 6), and refuses to run at all once installed.
- Prints the console path, the cron line (§ 5: panel CLI binaries first) and the installation record path (`storage/app/private/installation-record.txt`, mode 0600, with `APP_KEY`: store it offline, then delete it).
- Other commands:
  - `goproperty:setup-token`: prints the unlock token once and stores only its hash.
  - `goproperty:check-installation {--json}`: the server check, pending migrations and missing tables, the three markers, production settings (key, debug off, unique console path, https, hosting profile), and fresh scheduler and queue-tick heartbeats. Exits 1 on any failure.
  - `goproperty:finish-install`: recovery for a finish that stopped at `cleanup_pending`.
  - `goproperty:verify-package`.

## 8. Cron and background work without `exec`

- One cron line runs `artisan schedule:run` every minute ([docs/runbooks/shared-hosting.md § 2](docs/runbooks/shared-hosting.md)).
- Scheduled work is registered with `Schedule::call()` / `Schedule::job()`, so it runs **inside** the cron's PHP process (`Schedule::command()` and `runInBackground()` start a shell through `proc_open`; a test rejects them). The queue is drained in-process by a "tick" (`App\Hosting\Queue\QueueTick`, registered last in `routes/console.php`) that pops jobs from the `database` queue with per-lane caps and a time budget of ~45 s, with a lock so ticks never overlap and a heartbeat for the console. This is adopted from SocietyFlow: it means `proc_open`/`exec` are **not required** (some hosts disable them).
- Lanes map to the queue names in [ARCHITECTURE.md § 7](ARCHITECTURE.md): payments = `billing`, notifications = `notifications`, default = `default`, bulk = `pdf` + `media` + `imports`. Lanes take one job each in turn, so a long import cannot starve payments. Settings are in `config/hosting.php` (`QUEUE_TICK_*`).
- Hosts often load the `pcntl` extension but disable its functions. Laravel's scheduler would then fail every event, so the app turns off the scheduler's signal hook in that case, and the tick enforces job timeouts only when the pcntl functions work.
- CI drill (`tests/Drill/CronDrillTest.php`): runs `php artisan schedule:run` with `exec`, `proc_open` and pcntl disabled and checks that a queued job ran.
- On a VPS (Profile B), Horizon replaces the tick by configuration.

## 9. Recovery (never over HTTP)

[docs/runbooks/install-recovery.md](docs/runbooks/install-recovery.md) covers, with exact commands and File Manager equivalents:

- **Markers disagree** (for example the lock exists but the database row doesn't): a table of each combination and the fix.
- **Lost console path:** read `ADMIN_PATH` from `.env`.
- **Locked-out super-admin:** `php artisan goproperty:staff-reset-2fa {email}` (SSH), or the one-shot cron pattern for hosts without SSH. Audited.
- **Start over on a fresh database:** delete `installed.lock`, `install.checkpoint`, the database row and all tables. Only allowed while no organization exists.

There is deliberately no web route for any of these; a test fails if one is added.

## 10. Upgrades

GoProperty's own production is normally upgraded by CI over SSH ([docs/runbooks/shared-hosting.md § 3](docs/runbooks/shared-hosting.md)). For hosts without SSH, or a manual upgrade from a zip, `docs/runbooks/upgrade.md`:

1. Download the release zip; compare the `.sha256` checksum.
2. Console → **System → Version** shows the current version, the latest release on GitHub, pending migrations and the last backup time. (Read-only — there is **no in-app code download or "update now" button**, so a stolen console session can't replace the code.)
3. Run a fresh database + files backup from the console and wait for it to finish.
4. Maintenance mode (`php artisan down`, or Console → System → Maintenance).
5. Rename the current folder to `goproperty.previous`; upload and extract the new release; copy `.env` and `storage/` across.
6. Run the migrations (`php artisan migrate --force`, or the web upgrade step: an authenticated, `super-admin`-only page in the console that runs pending migrations in batches with the same progress bar as the installer). Then run the idempotent install seeders, so roles gain the permissions the release adds: `php artisan db:seed --class='Database\Seeders\InstallSeeder' --force` (the web upgrade step must do the same).
7. Clear and rebuild caches, `goproperty:check-installation`, then bring the site back up.

- **Rollback:** swap the folders back and restore the database from the pre-upgrade backup. Never `migrate:rollback`, never regenerate `APP_KEY`.
- Migrations are forward-only and must keep working with the previous release's code for one release (already a rule in the deploy runbook).

## 11. SocietyFlow: kept vs changed

| Area | SocietyFlow | GoProperty |
| --- | --- | --- |
| Security model | Host-only setup token (hash stored), session-bound grant rotated on first use, same-origin check, HTTPS only, three fail-closed markers, `/install` 404 after finish, no HTTP recovery | **Kept** |
| Pre-key handling | Global key-await middleware; cold routes without session/CSRF | **Kept** |
| Release package | Zip with `vendor/` + built assets, preferred/split layouts, package verifier with secret scan | **Kept** |
| Cron/queue | In-process tick, no `exec` | **Kept** |
| Unlock without SSH | One-shot cron trick to print the token | **File check** (create a file with a phrase in File Manager) |
| `.env` | Must be created by hand with exactly one `APP_KEY=` line | Created automatically from `.env.example` after unlock |
| Grant lifetime | 15 minutes; often expired during 2FA enrolment and needed the CLI to recover | 60 minutes, renewed every step; re-unlock in the browser |
| Migrations | One long request (76 s measured) + a 2,930-line per-migration recovery manifest | Batched requests with a progress bar; resume from the `migrations` table; "empty and retry" for a failed fresh install |
| Pages before finish | Reachable (only registration was blocked) | Every public route shows "being set up" (503) |
| Email, storage | Configured after install | Tested inside the wizard |
| Finish | Redirect to security settings, no summary | Finish screen: console path, exact cron line, live cron check, installation record, next steps |
| Cron line | Built from `PHP_BINARY` (often wrong under the web server) | Detected from known panel CLI paths |
| Installer events | Not logged | `install.log`, then an audit entry at finish |
| CLI install | None (token + diagnostics only) | `goproperty:install` with a non-interactive mode |
| Admin path | None | Random `ADMIN_PATH` suffix generated at finish |

## 12. Tests and drills

- **Built in P00-05** (`tests/Feature/Installer/`, `tests/Drill/FreshCopyDrillTest.php`): pre-key redirect and 503 with no database query, cookie or log line (also through a real `php -S` server with an unreachable database); "being set up" on public routes until both markers agree; every marker on its own, a forged or re-keyed checkpoint, and an unreachable recorded database each keep `/install` at 404; requirements checker against a simulated host; `.env` writer (escaping round-trip, duplicates, atomic write, mode 0600, values never echoed); database probe against real throwaway databases (scoped listing, foreign tables, finished install, wrong password, missing database, timeout); batched migrations (batch size, time budget, resume, failure by name); emptying only GoProperty's tables; seeders run twice give identical data; finalizer order and its fail-closed checkpoint.
- **Feature tests:** pre-key routes (redirect/503, no DB query, no log noise), unlock (both methods, wrong token, rate limit, origin checks, HTTPS), grant (rotation, renewal, expiry → re-unlock), requirements checker, `.env` writer (escaping, duplicates, atomic write, password never echoed), database step (refuses foreign tables, scoped listing), batched migrations (resume after interruption), super-admin + 2FA, finish ordering (cleanup checkpoint first), `/install` 404 afterwards, public routes 503 before finish, no HTTP recovery route, cron-path detection.
- **Built in P01-09:** `tests/Feature/Installer/WebUnlockTest.php` (both unlock methods, phrase expiry and reloads, symlinked file, rate limit, same-origin and HTTPS checks, grant rotation, session binding, a second unlock ending the first session, expiry and renewal, steps in order); `WebInstallWizardTest.php` (the whole wizard over HTTP into a throwaway database through a cookie-carrying test browser: real test email to a local SMTP sink, storage test, TOTP enrolment, finish; the same `.env`, markers, staff, audit entry and schema as the CLI; console handover and heartbeat; resume after a lost session; foreign tables, refused password, changed settings, failed migration → empty and retry, super-admin rules and resume, recovery-codes tick, failed email and refused bucket); `NoHttpRecoveryTest.php` (every installer route is behind `EnsureInstallerOpen`, answers 404 for every method once any marker says finished, without a cookie or any change; no route outside `/install` injects installer services, and no web-reachable code mentions them or the install, finish, migrate or wipe commands).
- **Release drill** (`scripts/release/verify-drill.sh`, on every release): the package's own `goproperty:verify-package` passes the zip and both unpacked layouts; a changed file, an added file and a changed zip entry are refused; both layouts serve pages and built assets; artisan and `index.php` print the old-PHP sentence on PHP 7.4 and 8.1.
- **Built in P00-06:** `goproperty:install` end to end into a real throwaway database (options, prompts, validation before writing, breached password, refused database password, failed test email, resume after interruption, changed database settings refused, second install refused), and a schema snapshot (`information_schema`: tables, columns, indexes, foreign keys, migrations) of the installed database equal to a plain `migrate` (`tests/Feature/Installer/InstallCommandTest.php`); setup token, `check-installation`, `finish-install`, cron-line detection.
- **Install drill in CI** (`scripts/release/install-drill.sh`, workflow `install-drill.yml`: nightly, on pull requests touching migrations, seeders or the installer, and before every release): build the package, unpack the **split** layout, run `goproperty:install --no-interaction` with shared-host limits (`max_execution_time=30`, `memory_limit=256M`, `exec`/`proc_open`/pcntl disabled), refuse a second install, run cron once and pass `check-installation`, serve `public_html` (`/` 200, `/install` 404), pass `verify-package`. Then a second split layout is installed by **walking the web wizard over HTTP** with `curl` against `php -S` under the same limits: file-check unlock, server check, site and database, the migrations one batch per request, a test email to a local SMTP sink (`scripts/release/smtp-sink.php`), storage skipped, the super-admin with TOTP enrolment and 8 recovery codes, finish (console path from `.env`, cron line, record with `APP_KEY`), the handed-over console session, the heartbeat turning green after one cron run, `/install` and the wizard routes 404, `check-installation` and the audit chain. Both schemas are compared with a plain `migrate` of the same release, on **MySQL 8.0 and 8.4, MariaDB 10.6 and 11.4**. Still to come: a second drill that upgrades the previous release to the new one.
- **Schema check:** a database built by the installer must match one built by `migrate` exactly.
- **Manual drills before launch:** a real install on a cPanel account and a Plesk account, one with SSH and one without, following the owner manual word for word.
