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

CI builds the package on every tagged release (GitHub Actions → GitHub Releases):

```
goproperty-1.4.0.zip            ← upload this
goproperty-1.4.0.zip.sha256     ← checksum to compare after upload
```

- **Contents:** application code, `vendor/` (`composer install --no-dev --optimize-autoloader`), built front-end assets in `public/build/` (Vite), a static `public/install.css` so the installer's first screens render without the build manifest, `.env.example`, `VERSION`, and an inner `SHA256SUMS`.
- **Two layouts in one zip:**
  - `preferred/` — the domain's document root points at `goproperty/public`. Use this whenever the panel allows changing the document root.
  - `split/goproperty-app/` + `split/public_html/` — for hosts whose primary domain must use `public_html`. The generated `public_html/index.php` points at `../goproperty-app` and calls `$app->usePublicPath(__DIR__)`. Only public files sit in `public_html`.
- **Package verifier** (runs in CI and as `php artisan goproperty:verify-package` on the server). It refuses a package that contains symlinks, `..` paths, `.env*` files, keys, SQLite files, dev dependencies or secret-shaped strings, or that has a missing Vite manifest, a checksum mismatch, or a version that doesn't match `VERSION`.
- **Old-PHP guard:** `public/index.php` and `artisan` first load a tiny PHP-7-parseable `bootstrap/php-version.php` that prints one plain sentence ("GoProperty needs PHP 8.4 or newer; this server runs 8.1") instead of a fatal error.
- **No `storage:link`:** media and documents live in S3-compatible storage, and brand assets are streamed by controllers (`/brand/*`), so nothing needs a symlink inside `public/`.

## 3. How the app knows whether it is installed

| Marker | Where | Written |
| --- | --- | --- |
| Install lock | `storage/app/installed.lock` | At finish |
| Database row | `installation_state` (singleton): `status` = `configured` → `migrating` → `owner_ready` → `finalized`, `version`, `finalized_at` | Through the wizard |
| Checkpoint | `storage/app/private/install.checkpoint` (stage + HMAC fingerprint of the DB settings) | Through the wizard |

- **Installer closed** if **any** marker says finished (fail closed). **Site open to the public** only when the lock file **and** the database row both say `finalized`.
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

- The screen shows a short random phrase, for example `GP-UNLOCK 7Q4M-K2XD-PL9A`, valid for 30 minutes. Its hash is stored in `storage/app/private/unlock.challenge`.
- The owner creates a file named `install-unlock.txt` in `storage/app/` containing that phrase and clicks "I've created the file".
- The installer reads the file, compares it with the stored hash, then deletes it. Creating a file outside the web root is something only the hosting account holder can do, so it proves control without needing a shell. (SocietyFlow needed SSH or a "one-shot cron" trick here; this replaces both.)

**Both paths:**

- HTTPS is required in production. The installer checks that AutoSSL / Let's Encrypt is active first and explains how to turn it on if not.
- Same-origin check: the `Origin` header must match, or `Sec-Fetch-Site: same-origin` plus a matching `Referer`.
- 5 attempts per IP per 15 minutes, counted in a file with `flock` (atomic; the database doesn't exist yet).
- On success:
  1. Create `.env` from `.env.example` if it's missing (mode 0600). SocietyFlow required this file to be made by hand.
  2. Generate `APP_KEY` (an existing valid key is reused) and write it atomically: temp file → fsync → rename.
  3. Switch the key into the running request.
  4. Start an encrypted file session and issue the **installer grant**.
- **Installer grant:** session-bound, rotated on first use, valid **60 minutes from the last step**. It renews on every completed step, so enrolling 2FA can't make it expire (a SocietyFlow pain point). If it does expire, the owner simply unlocks again with method A or B — no shell needed.
- These routes run without the normal session, CSRF and cookie-encryption middleware, because none of them can work before APP_KEY exists. Every route after unlock uses the normal web stack with CSRF.

## 5. Wizard steps

Layout: the same split shell as the login page ([UX_DESIGN.md § 3](UX_DESIGN.md)) — brand panel on the left with a vertical step list (✓ for done, `aria-current="step"`), the form on the right. It is plain Blade with full-page POST forms (each step is a fresh request, which suits config and key changes), uses the static `install.css` until the Vite manifest is confirmed, and is `noindex`, accessible and readable at 360 px.

| # | Step | What happens |
| --- | --- | --- |
| 1 | **Unlock** | § 4 |
| 2 | **Server check** | Detailed report, grouped. **Blocking:** PHP ≥ 8.4 (web), extensions (`pdo_mysql`, `mbstring`, `intl`, `bcmath`, `gd` or `imagick`, `zip`, `fileinfo`, `openssl`, `sodium`, `exif`, `curl`, `dom`, `xml`, `tokenizer`, `ctype`), `max_execution_time` ≥ 30 s, writable `storage/*`, `bootstrap/cache` and `.env`'s folder (a real probe file is written and deleted), public-root safety (no `.env`, `artisan` or `composer.json` reachable from the web; document root = `public`), Vite manifest present, HTTPS. **Advisory:** `memory_limit` ≥ 256M, `upload_max_filesize` ≥ 20M, `proc_open`/`exec` (only used by `mysqldump`; the pure-PHP dumper works without them), free disk ≥ 2 GB, OPcache on. Each failure says how to fix it in cPanel and in Plesk |
| 3 | **Site & database** | Fields: site address (`https://` origin, no path), staging or production, database type (MySQL 8 / MariaDB 10.6+), host, port, name, username, password. **Test connection** (3-second timeout, temporary connection). Refuses a database that already has tables unless they are GoProperty's own from an earlier attempt (table listing is limited to this database — on shared hosts the panel user can often see others). Writes `APP_URL`, `APP_ENV`, `DB_*`, the shared-hosting profile defaults (`QUEUE_CONNECTION=database`, `CACHE_STORE=file` for now, `PDF_DRIVER=dompdf`, `BROADCAST_CONNECTION=log`) and `APP_TIMEZONE=Africa/Lagos`. Values are validated (no control characters), quoted and escaped; the file is written atomically. The password is never shown again |
| 4 | **Build the database** | Runs the migrations **in small batches with a progress bar**: the page posts one batch at a time (a few migrations, each request kept under ~20 s), shows "34 of 112", and carries on by itself. Closing the tab is safe — reopening continues from Laravel's own `migrations` table. Then the idempotent seeders run (roles and permissions, default plans and prices, Transaction Tiers, default theme, email templates). See § 6 for failures |
| 5 | **Email & file storage** | **Email:** provider (Postmark, Mailgun, SES or SMTP — never the host's `sendmail`, [ARCHITECTURE.md § 12.1](ARCHITECTURE.md)), credentials, from-address, then **Send a test email** to the owner's address (must succeed or be explicitly skipped). **File storage:** S3-compatible endpoint, bucket, region, keys, then **Test** (write, read, delete a small file). Secrets go to `.env`, never to the database. Paystack, Termii, Turnstile and backup destinations are set later in the console |
| 6 | **First super-admin** | Creates the first `platform_staff` account with the `super-admin` role: name, work email, password (12+ characters, checked against breached-password lists). **2FA is set up right here:** QR code + manual key, confirm with a 6-digit code, then 8 recovery codes shown with "Download" / "Copy" and a required "I've saved my recovery codes" tick. Refused if any staff account already exists. Resuming after a timeout needs the same email and password |
| 7 | **Finish** | See below |

**Finish:**

1. Write the `cleanup_pending` checkpoint first (if anything below fails, the installer is already closed and recovery is via the CLI, § 9).
2. Generate the console path `ADMIN_PATH=gp-control-<6 random characters>` ([PLATFORM_CONSOLE.md § 10](PLATFORM_CONSOLE.md)) and switch `SESSION_DRIVER` and `CACHE_STORE` to `database`.
3. Delete the setup token, unlock challenge and grant.
4. Write `installed.lock` and set the database row to `finalized`.
5. Cache config, routes, views and events.
6. Record the installation in the audit log (who, when, version, IP). Until then, installer events go to `storage/logs/install.log`, never including passwords or keys.
7. Hand the owner over to a signed-in **console** session without signing out.

**Then the finish screen shows:**

- **Console address**, shown once with "Bookmark this — it isn't linked anywhere".
- **Cron job:** the exact line for this server. The installer finds the PHP **CLI** binary by checking the known panel paths that exist — cPanel `/opt/cpanel/ea-php84/root/usr/bin/php`, CloudLinux `/opt/alt/php84/usr/bin/php`, Plesk `/opt/plesk/php/8.4/bin/php` — instead of trusting `PHP_BINARY`, which under the web server is often `php-fpm` or `lsphp` (a SocietyFlow bug). It shows cPanel and Plesk click paths.
- **Live cron check:** "Waiting for the first scheduler run…", which turns green when the scheduler heartbeat arrives (it polls every 5 s). The owner knows reminders, bills and backups will run before leaving the page.
- **Installation record** (download once): version, date, site address, console path, database name, and the `APP_KEY` with a warning to store it offline — without it, encrypted data can't be read after a restore.
- **Next steps checklist,** linking into the console: Paystack keys, Termii sender ID, Turnstile keys, backup destinations, platform theme, Cloudflare, `APP_DEBUG=false` confirmed.

## 6. Failure handling (simple by design)

- **Unreachable database / wrong credentials:** shown on step 3 with the driver's message cleaned of secrets. Nothing is written.
- **Timeout during a migration batch:** reopen the page; Laravel's `migrations` table says what's done, and the next batch continues.
- **A migration failed halfway** (MySQL can't roll back schema changes): the installer stops and explains that this is a brand-new install with no data, so the safe fix is **"Empty the database and start this step again"**. A button drops only GoProperty's own tables in this database, after the owner types the database name to confirm. There is **no hand-maintained list of every table and column per migration** — SocietyFlow's 2,930-line recovery manifest is deliberately not copied. Rule for developers instead: every migration must be safe to run on an empty database, and one migration does one schema change where possible (enforced in review and by the install drill, § 10).
- **Settings changed mid-install** (the DB fingerprint no longer matches the checkpoint): the installer stops and shows the recovery steps (§ 9).

## 7. Command-line install (SSH or VPS)

The same services, without the browser:

```
php artisan goproperty:install                  # interactive, asks the same questions as the wizard
php artisan goproperty:install --no-interaction \
  --url=https://goproperty.ng --env=production \
  --db-host=localhost --db-name=gp --db-user=gp --db-password-file=/home/USER/.gp-db-pass \
  --mail-test=owner@goproperty.ng \
  --admin-name="Ada Obi" --admin-email=ada@goproperty.ng
```

- Passwords are read from a prompt or a file, never from a command-line argument (they would end up in shell history).
- The super-admin's 2FA is enrolled at first console sign-in (it is mandatory, so the console won't open without it).
- Prints the console path, the cron line and the installation record path at the end.
- Other commands: `goproperty:setup-token`, `goproperty:check-installation {--json}` (runs the server check, compares the schema with the migrations, checks markers, defaults, the scheduler heartbeat and that every required scheduled job ran recently), `goproperty:verify-package`.

## 8. Cron and background work without `exec`

- One cron line runs `artisan schedule:run` every minute ([docs/runbooks/shared-hosting.md § 2](docs/runbooks/shared-hosting.md)).
- Scheduled work is registered with `Schedule::call()` / `Schedule::job()`, so it runs **inside** the cron's PHP process. The queue is drained in-process by a "tick" that pops jobs from the `database` queue with per-lane caps (payments, notifications, default, bulk) and a time budget of ~45 s, with a lock so ticks never overlap and a heartbeat for the console. This is adopted from SocietyFlow: it means `proc_open`/`exec` are **not required** (some hosts disable them).
- On a VPS (Profile B), Horizon replaces the tick by configuration.

## 9. Recovery (never over HTTP)

`docs/runbooks/install-recovery.md` covers, with exact commands and File Manager equivalents:

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
6. Run the migrations (`php artisan migrate --force`, or the web upgrade step: an authenticated, `super-admin`-only page in the console that runs pending migrations in batches with the same progress bar as the installer).
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

- **Feature tests:** pre-key routes (redirect/503, no DB query, no log noise), unlock (both methods, wrong token, rate limit, origin checks, HTTPS), grant (rotation, renewal, expiry → re-unlock), requirements checker, `.env` writer (escaping, duplicates, atomic write, password never echoed), database step (refuses foreign tables, scoped listing), batched migrations (resume after interruption), super-admin + 2FA, finish ordering (cleanup checkpoint first), `/install` 404 afterwards, public routes 503 before finish, no HTTP recovery route, cron-path detection.
- **Install drill in CI** (every pull request that adds a migration, and nightly): build the package, verify it, unpack the **split** layout, serve it with shared-host limits (`max_execution_time=30`, `memory_limit=256M`, `disable_functions=exec,proc_open`), and walk the whole wizard over HTTP — including the file-check unlock and TOTP enrolment — on **MySQL 8 and MariaDB 10.6**. A second drill upgrades the previous release to the new one.
- **Schema check:** a database built by the installer must match one built by `migrate` exactly.
- **Manual drills before launch:** a real install on a cPanel account and a Plesk account, one with SSH and one without, following the owner manual word for word.
