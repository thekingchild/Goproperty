# Runbook — Upgrading from a Release Zip (and Rolling Back)

How to move an installation to a newer GoProperty release by uploading the release zip, on cPanel or Plesk, with or without SSH, and how to go back if something is wrong ([INSTALLER.md § 10](../../INSTALLER.md)). GoProperty's own servers are normally updated by the SSH Deploy workflow instead ([shared-hosting.md § 3](shared-hosting.md)); this runbook is for hosts without SSH, a manual upgrade, and the staging rehearsal (§ 5).

The words are used as in [install.md](install.md): **bold** is what GoProperty shows, *italics* the hosting panel's menus and buttons, `code` what you type. `USER` is your hosting account's username and `goproperty.ng` your domain. The folders are the ones [install.md](install.md) set up:

- cPanel: `goproperty-app` beside `public_html` (a domain with its own folder: `goproperty`, whose `public` folder is the document root; follow the Plesk-style steps below with cPanel's clicks).
- Plesk: `goproperty`, whose `public` folder is the document root.

## 1. Before you start

- Read the release notes on the GitHub release page. A release says when it needs more than the steps here.
- **Console → Operations → Version** (technical-admins and super-admins) shows the version running, the latest release on GitHub, migrations not run, whether the release's install seeders have run, the last database and files backups and maintenance mode. It is read-only: nothing is ever downloaded from the console.
- You need: the release zip and its `.sha256`, the hosting panel's file manager, a super-admin console account with its authenticator app (for the no-SSH step), and about 30 minutes.
- Upgrade one release at a time when you can. Skipping releases works (migrations run in order), but each release is only tested against the one before it (§ 6).

## 2. Upgrade

### Step 1. Download and check the zip

From the GitHub release: `goproperty-X.Y.Z.zip` and `goproperty-X.Y.Z.zip.sha256`. Compare the checksum on your computer as in [install.md § 1](install.md#1-what-to-have-ready-every-path) item 2.

### Step 2. Look at the Version page

Open **Console → Operations → Version**. Note **Version running** and the last backups.

### Step 3. Back up

1. Open **Console → Operations → Backups**. Press **Back up database now**, then **Back up files now**.
2. Wait until both show **Success** in the list (the scheduler runs them within a minute; reload the page). Write down the database backup's name in the *File* column (e.g. `2026-11-09-01-30-00`): it is what a rollback restores.
3. Download a copy of `.env` from the GoProperty folder to your computer (cPanel *File Manager*: right-click → *Download*; Plesk *Files*: the file's menu → *Download*).

### Step 4. Turn on maintenance mode

- With SSH, in the GoProperty folder: `php artisan down --retry=60`. This also closes the console: do step 6 over SSH too.
- Without SSH: in **Console → Operations → Version**, press **Turn on maintenance mode** and confirm with your password and authenticator code (super-admins). Visitors see the maintenance page; the console stays open for staff.

### Step 5. Put the new release in place

The new folder is prepared first and swapped in last, so the site never runs half-copied files. With SSH you may do the same with `mv` and `cp -a`; the file manager works for everyone.

cPanel (`goproperty-app` and `public_html`):

1. In *File Manager* (with *Show Hidden Files (dotfiles)* ticked), open your home folder, *Upload* `goproperty-X.Y.Z.zip`, then right-click it → *Extract* into `/home/USER`. You should see `preferred`, `split` and `README.txt`.
2. Right-click `goproperty-app/.env` → *Copy*, type `/split/goproperty-app` and press *Copy File(s)*.
3. Open `split/goproperty-app`, select its `storage` folder and press *Delete*. Then right-click `goproperty-app/storage` (the running one) → *Copy*, type `/split/goproperty-app` and press *Copy File(s)*. It holds the lock file, the maintenance-mode flag, logs and uploads kept on the host.
4. Open `split/goproperty-app/storage/framework/views`, press *Select All*, then *Delete*. These are the old release's compiled pages; Laravel would keep using them.
5. Right-click `goproperty-app` → *Rename* → `goproperty-app.previous`.
6. Open `split`, right-click `goproperty-app` → *Move*, type `/` and press *Move File(s)*.
7. Create a folder `public_html.previous` in your home folder. Open `public_html`, select everything except `cgi-bin` and `.well-known`, press *Move*, type `/public_html.previous` and press *Move File(s)*.
8. Open `split/public_html`, press *Select All*, then *Move*, type `/public_html` and press *Move File(s)*.
9. If you chose PHP in *MultiPHP Manager* at the install: the new `.htaccess` does not have cPanel's PHP lines yet. Open *Software* → *MultiPHP Manager*, tick `goproperty.ng`, choose another version and *Apply*, then *PHP 8.4 (ea-php84)* and *Apply*.
10. Back in your home folder, delete `preferred`, `split`, `README.txt` and the zip.

If the domain has its own folder (`goproperty`): do steps 1–7 of the Plesk list below with cPanel's buttons.

Plesk (`goproperty`):

1. In *Files*, open the home folder, *Upload File* `goproperty-X.Y.Z.zip`, then click it → *Extract Files* into the home folder. You should see `preferred`, `split` and `README.txt`.
2. Copy `goproperty/.env` into `preferred/goproperty` (tick it, *Copy*, choose `preferred/goproperty`, *OK*).
3. Open `preferred/goproperty`, tick its `storage` folder and press *Remove*. Then copy `goproperty/storage` (the running one) into `preferred/goproperty` the same way. It holds the lock file, the maintenance-mode flag, logs and uploads kept on the host.
4. Open `preferred/goproperty/storage/framework/views`, tick all files and press *Remove*. These are the old release's compiled pages; Laravel would keep using them.
5. Rename `goproperty` to `goproperty.previous` (tick it, *Rename*).
6. Move `preferred/goproperty` into the home folder (tick it, *Move*, choose `/`, *OK*). The document root `goproperty/public` now serves the new release.
7. Remove `preferred`, `split`, `README.txt` and the zip.

### Step 6. Run the migrations and the install seeders

- With SSH, in the GoProperty folder:
  ```bash
  php artisan migrate --force
  php artisan db:seed --class='Database\Seeders\InstallSeeder' --force
  ```
- Without SSH:
  1. Open **Console → Operations → Version** and press **Run pending migrations** (super-admins).
  2. Type **Your password** and the **Code from your authenticator app**, tick that a database backup was taken (step 3), and press **Confirm and start**.
  3. Press **Run the upgrade**. The page then runs one short step per request by itself — a few migrations each, then the install seeders, then the caches — with a progress bar. Keep the tab open until it says **Upgraded to …**. It turns maintenance mode on first if it is not on yet, and off at the end. If two people press it at once, the second is told **Another staff member is running the upgrade right now.**

### Step 7. Caches, checks, back up

- With SSH:
  ```bash
  php artisan optimize
  php artisan goproperty:check-installation
  php artisan up
  ```
  `check-installation` must end with **The installation is healthy.** If it complains only about **Cron runs the scheduler** or **The queue tick runs**, run `php artisan schedule:run` once and check again.
- Without SSH: the upgrade step rebuilt the caches and ended maintenance mode. **Console → Operations → Version** shows the new version and **Nothing to run**; **Console → Operations → System operations** shows the **Scheduler** as **Running**.
- Both: open the site's home page and the console; sign in as a test agency and open a bill. Keep `goproperty-app.previous` (or `goproperty.previous`) and, on cPanel, `public_html.previous` until the next upgrade.

If a migration fails (the upgrade step names it and shows the error; the command line prints it), the site stays in maintenance mode. Fix the cause if it is clear (for example a full disk) and run step 6 again (**Try this step again**): migrations that finished are kept. Otherwise roll back (§ 3). Never edit the `migrations` table by hand.

## 3. Rollback

When the new release misbehaves, or a migration failed and the cause is not clear:

1. Maintenance mode on (§ 2 step 4; in the new release the console button works too).
2. Swap the folders back:
   - cPanel: rename `goproperty-app` to `goproperty-app.failed` and `goproperty-app.previous` to `goproperty-app`. Create `public_html.failed`, move everything in `public_html` except `cgi-bin` and `.well-known` into it, then move everything in `public_html.previous` back into `public_html`.
   - Plesk (and a cPanel domain with its own folder): rename `goproperty` to `goproperty.failed` and `goproperty.previous` to `goproperty`.

   The previous folder still has its own `.env`, `storage` and caches.
3. Restore the database from the backup taken in § 2 step 3, with the previous release's tools:
   - the console of the restored folder: **Console → Operations → Backups** → that backup → **Restore to production** (password, code, the backup's name typed, a second super-admin approves; [PLATFORM_CONSOLE.md § 4](../../PLATFORM_CONSOLE.md)); or
   - [restore.md](restore.md) (`php artisan goproperty:backup-restore …` over SSH, or phpMyAdmin with the decrypted dump).

   The restore must happen before the old code serves anyone: it does not know the new release's tables and columns.
4. `php artisan up` (or **Turn off maintenance mode** on the Version page), then check as in § 2 step 7.
5. Tell the developers which release failed and how (the log is in `goproperty-app.failed/storage/logs/` or `goproperty.failed/storage/logs/`).

Never run `php artisan migrate:rollback` (migrations are forward-only and some drop data on the way down) and never regenerate `APP_KEY` (encrypted data could no longer be read). Anything written between the backup and the rollback is lost; keep the maintenance window short.

## 4. What the console's upgrade step does and does not do

- It is for holders of `system.upgrade` (super-admins; a protected permission, so no other role gets it without a second super-admin's approval), behind the console's sign-in, 2FA and CSRF, with a fresh password and code (10 minutes) and the backup tick.
- One piece of work per request (migrations in batches of 5 / 20 s, then the seeders and caches), so each request stays inside shared hosting's 30 seconds. A file lock (`storage/app/private/upgrade.lock`) keeps two requests from running at once.
- It refuses when nothing is pending: every migration has run and the install seeders ran for this release (`installation_state.version`).
- Every step is audited (log `operations`: `upgrade.maintenance_on`, `upgrade.migrated` with the migrations that ran, `upgrade.migration_failed`, `upgrade.finished`, `upgrade.finish_failed`, `upgrade.maintenance_off`).
- It never downloads or replaces code, never rolls back, resets or empties anything. The console pages it uses (sign-in, Version, Run pending migrations) must work on the previous release's database: the upgrade drill (§ 6) proves it for every release.

## 5. Staging rehearsal (P09-06 acceptance: one zip upgrade + rollback)

Done once on staging by a person, before launch, and again for a release with risky migrations. Record the date, versions, timings and anything that did not match this runbook in [docs/PROGRESS.md](../PROGRESS.md) (P09-06's entry).

1. Prepare. Staging runs release *N* (installed from its zip, or deployed). It has production-like data (P09-04's copy or the demo organization) and a working backup destination. Note `goproperty:check-installation` output and **Console → Operations → Version**.
2. Zip upgrade to *N+1* without SSH, following § 2 exactly with the no-SSH variants: checksum, Version page, both backups (note the database backup's name and the time), **Turn on maintenance mode**, folder swap in the file manager, **Run pending migrations**, checks of step 7. Time each step. Confirm: visitors saw the maintenance page during the upgrade; the Version page says **Nothing to run** with *N+1*; the audit log (**Console → Audit → Audit log**, log `operations`) has the `upgrade.*` entries with your name; a test agency's bills, payments and documents open.
3. Rollback to *N* following § 3: maintenance mode on, folders swapped back, the step-2 database backup restored (console **Restore to production** with a second super-admin, or [restore.md](restore.md)), maintenance mode off. Confirm: Version shows *N* with nothing pending; `goproperty:check-installation` passes (SSH, or a one-time cron job: [install-recovery.md § 0](install-recovery.md#0-running-a-command-on-the-server)); the data entered before step 2 is there; anything entered during the upgrade window is gone (expected).
4. Upgrade again to *N+1* (this time with SSH if staging has it: `php artisan down`, swap, `migrate --force`, the install seeders, `optimize`, check, `up`), so both variants are rehearsed, and leave staging on *N+1*.
5. Record the results; mark P09-06's acceptance "one zip upgrade + rollback rehearsed on staging" as done.

## 6. The upgrade drill (CI)

`scripts/release/upgrade-drill.sh <previous.zip> <new.zip>` ([`.github/workflows/upgrade-drill.yml`](../../.github/workflows/upgrade-drill.yml)): nightly, on pull requests touching migrations, seeders, the installer, the upgrade step or the release scripts, by hand (with a `previous` input), and before every release, on MySQL 8.0 and 8.4 and MariaDB 10.6 and 11.4. Under shared-host limits (`max_execution_time=30`, `memory_limit=256M`, `exec`/`proc_open`/pcntl disabled) it:

- installs the previous release's split layout from the command line, adds data (an agency, its owner, a setting), copies the database (the backup), then `down`, the folder swap of § 2 step 5, `migrate --force`, the install seeders, `optimize`, `up`; checks `check-installation`, `audit-verify`, `verify-package`, the recorded version, the pages, that the data is unchanged, and that the schema equals a plain `migrate` of the new release; then rolls back (folders swapped back, the copy restored) and checks the previous release runs with its data;
- installs the previous release again by walking the web installer over HTTP, adds data, swaps the folders without any command on the server, opens **Console → Operations → Version**, and runs **Run pending migrations** over HTTP with curl (password, TOTP code, backup tick; one migration per request) until it says the upgrade is done; visitors get 503 while it runs; then the same checks, the `upgrade.finished` audit entry, and a second run refused with **Nothing to run**.

The drill swaps whole folders with `mv` (`public_html` included); the file-manager steps of § 2 step 5 reach the same result, moving `public_html`'s contents rather than the folder itself because cPanel keeps that folder's own settings.

Which previous release (`scripts/release/previous-ref.sh`): the workflow's `previous` input when given; else the newest `v*` tag before this commit; else, on a pull request, its base commit (what `main` runs now); else this commit's first parent. There are no tags yet (Nov 2026), so pull requests upgrade from `main` and the nightly run from the commit before the last merge. The previous release is built from that commit by `scripts/release/build-previous.sh` with this checkout's release scripts (a packaging fix applies to both zips; the code, `vendor/` and assets are the previous commit's).

Locally (needs PHP 8.4 with the installer's extensions, Composer, Node 22, `zip`/`unzip`, `curl`, and a MySQL or MariaDB account that may create databases):

```bash
scripts/release/build-previous.sh "$(scripts/release/previous-ref.sh)" 0.0.0-drill.1.1 dist
scripts/release/build-package.sh 0.0.0-drill.1.2 dist
DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root DB_PASSWORD=secret \
  scripts/release/upgrade-drill.sh dist/goproperty-0.0.0-drill.1.1.zip dist/goproperty-0.0.0-drill.1.2.zip
```

## 7. To check on the real install

Written without access to a real panel; check these on the first real upgrade (or the staging rehearsal, § 5):

1. cPanel *File Manager* *Copy* into a folder that already has a folder of the same name: § 2 step 5.3 deletes the new `storage` first so the copy cannot merge.
2. That copying `storage` in the file manager keeps the hidden `framework/down` file (maintenance mode) and file permissions.
3. Plesk *Files*: the *Copy*, *Move*, *Rename* and *Remove* actions as named here.
4. cPanel: that moving `public_html`'s contents (rather than renaming the folder) leaves the domain working, and whether step 5.9 is needed (with *MultiPHP Manager*) or not (with *Select PHP Version*); a site that says **GoProperty needs PHP 8.4.1 or newer; this server runs …** after the swap needs it ([install-recovery.md § 2.1](install-recovery.md#21-the-page-says-goproperty-needs-php-841-or-newer)).
