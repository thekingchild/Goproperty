# Runbook — Upgrading from a Release Zip (and Rolling Back)

How to move an installation to a newer GoProperty release by uploading the release zip, with or without SSH, and how to go back if something is wrong ([INSTALLER.md § 10](../../INSTALLER.md)). GoProperty's own servers are normally updated by the SSH **Deploy** workflow instead ([shared-hosting.md § 3](shared-hosting.md)); this runbook is for hosts without SSH, a manual upgrade, and the staging rehearsal (§ 5). P09-07 turns it into the owner's guide with cPanel and Plesk click paths.

Paths below are for the **preferred** layout (`goproperty/`, document root `goproperty/public`). In the **split** layout read `goproperty-app/` for `goproperty/`, and swap `public_html/` the same way as the app folder (step 5).

## 1. Before you start

- **Read the release notes** on the GitHub release page. A release says when it needs more than the steps here.
- **Console → Operations → Version** (`/{ADMIN_PATH}/system/version`, technical-admins and super-admins) shows the version running, the latest release on GitHub, migrations not run, whether the release's install seeders have run, the last database and files backups and maintenance mode. It is read-only: nothing is ever downloaded from the console.
- You need: the release zip and its `.sha256`, the hosting panel (File Manager), a super-admin console account with its authenticator (for the no-SSH step), and about 20 minutes.
- Upgrade one release at a time when you can. Skipping releases works (migrations run in order), but each release is only tested against the one before it (§ 6).

## 2. Upgrade

1. **Download and check the zip.** From the GitHub release: `goproperty-X.Y.Z.zip` and `goproperty-X.Y.Z.zip.sha256`. Compare: `sha256sum goproperty-X.Y.Z.zip` (Linux/macOS) or `Get-FileHash goproperty-X.Y.Z.zip` (Windows PowerShell) must print the hash in the `.sha256` file. With SSH, `php artisan goproperty:verify-package ~/goproperty-X.Y.Z.zip` checks it too ([release.md § 4](release.md)).
2. **Look at Console → Operations → Version.** Note the version running and the last backups.
3. **Back up.** Console → Operations → Backups → **Back up database now**, then **Back up files now**; wait until both show **Succeeded** in the list (the scheduler runs them within a minute). Write down the database backup's name (e.g. `2026-11-09-01-30-00`): it is what a rollback restores. Also keep a copy of `.env`.
4. **Maintenance mode on.**
   - With SSH: `php artisan down --retry=60` in `goproperty/`. This also closes the console; use the command line for step 6.
   - Without SSH: Console → Operations → Version → **Turn on maintenance mode** (super-admins; your password and authenticator code). Visitors see the maintenance page; the console stays open for staff.
5. **Swap the folders** (File Manager or SSH):
   1. rename `goproperty/` to `goproperty.previous/`;
   2. upload the zip and extract the same layout as before (`preferred/goproperty/`, or `split/goproperty-app/` + `split/public_html/`) so the new folder takes the old one's place; in the split layout first rename `public_html/` to `public_html.previous/` and put the new `public_html/` in its place;
   3. copy `goproperty.previous/.env` into `goproperty/`;
   4. replace the new `goproperty/storage/` with a copy of `goproperty.previous/storage/` (it holds the lock file, the maintenance-mode flag, logs and uploads kept on the host);
   5. empty `goproperty/storage/framework/views/` (compiled pages of the old release; Laravel would keep using them because the new files are older);
   6. in the split layout, if the public folder is not called `public_html` (Plesk: `httpdocs`), edit `goproperty-app/bootstrap/public-path.php` as at install.
6. **Run the migrations and the install seeders.**
   - With SSH, in `goproperty/`:
     ```bash
     php artisan migrate --force
     php artisan db:seed --class='Database\Seeders\InstallSeeder' --force
     ```
   - Without SSH: Console → Operations → Version → **Run pending migrations** (super-admins). Confirm your password and authenticator code and tick that the backup of step 3 was taken. The page then runs one short step per request — a few migrations each (5 or 20 seconds), then the install seeders, then the config, route, view and event caches — with a progress bar, and carries on by itself. Keep the tab open until it says **Upgraded to X.Y.Z**. It turns maintenance mode on first if it is not on yet, and off at the end. If two people press it at once, the second is told to wait.
7. **Caches, checks, back up.**
   - With SSH: `php artisan optimize`, `php artisan goproperty:check-installation` (must exit 0; run `php artisan schedule:run` once first if it complains about heartbeats), then `php artisan up`.
   - Without SSH: the upgrade step rebuilt the caches and ended maintenance mode. Check Console → Operations → Version (**Nothing to run**, the new version) and System operations (scheduler heartbeat under 2 minutes old).
   - Both: open the site's home page and the console; log in as a test agency and open a bill. Keep `goproperty.previous/` until the next upgrade.

**If a migration fails** (the upgrade step names it and shows the error; the command line prints it): the site stays in maintenance mode. Fix the cause if it is clear (for example a full disk) and run step 6 again: migrations that finished are kept. Otherwise roll back (§ 3). Never edit the `migrations` table by hand.

## 3. Rollback

When the new release misbehaves, or a migration failed and the cause is not clear:

1. Maintenance mode on (step 4 above; in the new release the console button works too).
2. Rename `goproperty/` to `goproperty.failed/` and `goproperty.previous/` back to `goproperty/` (split layout: `public_html/` the same way). The previous folder still has its own `.env`, `storage/` and caches.
3. **Restore the database from the backup taken in step 2.3**, with the previous release's tools:
   - the console of the restored folder: Operations → Backups → that backup → **Restore to production** (password, code, the backup's name typed, a second super-admin approves; [PLATFORM_CONSOLE.md § 4](../../PLATFORM_CONSOLE.md)); or
   - [restore.md](restore.md) (`php artisan goproperty:backup-restore …` over SSH, or phpMyAdmin with the decrypted dump).
   The restore must happen **before** the old code serves anyone: it does not know the new release's tables and columns.
4. `php artisan up` (or Version → **Turn off maintenance mode**), then check as in step 7.
5. Tell the developers which release failed and how (the log is in `goproperty.failed/storage/logs/`).

**Never** run `php artisan migrate:rollback` (migrations are forward-only and some drop data on the way down) and **never** regenerate `APP_KEY` (encrypted data could no longer be read). Anything written between the backup and the rollback is lost; keep the maintenance window short.

## 4. What the console's upgrade step does and does not do

- It is for holders of `system.upgrade` (super-admins; a protected permission, so no other role gets it without a second super-admin's approval), behind the console's sign-in, 2FA and CSRF, with a fresh password and code (10 minutes) and the backup tick.
- One piece of work per request (migrations in batches of 5 / 20 s, then the seeders and caches), so each request stays inside shared hosting's 30 seconds. A file lock (`storage/app/private/upgrade.lock`) keeps two requests from running at once.
- It refuses when nothing is pending: every migration has run and the install seeders ran for this release (`installation_state.version`).
- Every step is audited (log `operations`: `upgrade.maintenance_on`, `upgrade.migrated` with the migrations that ran, `upgrade.migration_failed`, `upgrade.finished`, `upgrade.finish_failed`, `upgrade.maintenance_off`).
- It never downloads or replaces code, never rolls back, resets or empties anything. The console pages it uses (sign-in, Version, Run pending migrations) must work on the previous release's database: the upgrade drill (§ 6) proves it for every release.

## 5. Staging rehearsal (P09-06 acceptance: one zip upgrade + rollback)

Done once on staging by a person, before launch, and again for a release with risky migrations. Record the date, versions, timings and anything that did not match this runbook in [docs/PROGRESS.md](../PROGRESS.md) (P09-06's entry).

1. **Prepare.** Staging runs release *N* (installed from its zip, or deployed). It has production-like data (P09-04's copy or the demo organization) and a working backup destination. Note `goproperty:check-installation` output and Console → Operations → Version.
2. **Zip upgrade to *N+1* without SSH**, following § 2 exactly with the no-SSH variants: checksum, Version page, both backups (note the database backup's name and the time), **Turn on maintenance mode**, folder swap in File Manager, **Run pending migrations**, checks of step 7. Time each step. Confirm: visitors saw the maintenance page during the upgrade; the Version page says **Nothing to run** with *N+1*; the audit log (Console → Audit log, log `operations`) has the `upgrade.*` entries with your name; a test agency's bills, payments and documents open.
3. **Rollback to *N*** following § 3: maintenance mode on, folders swapped back, the step-2 database backup restored (console **Restore to production** with a second super-admin, or [restore.md](restore.md)), maintenance mode off. Confirm: Version shows *N* with nothing pending; `goproperty:check-installation` passes (SSH or the host's terminal, if any); the data entered before step 2 is there; anything entered during the upgrade window is gone (expected).
4. **Upgrade again to *N+1*** (this time with SSH if staging has it: `php artisan down`, swap, `migrate --force`, the install seeders, `optimize`, check, `up`), so both variants are rehearsed, and leave staging on *N+1*.
5. **Record** the results; mark P09-06's acceptance "one zip upgrade + rollback rehearsed on staging" as done.

## 6. The upgrade drill (CI)

`scripts/release/upgrade-drill.sh <previous.zip> <new.zip>` ([`.github/workflows/upgrade-drill.yml`](../../.github/workflows/upgrade-drill.yml)): nightly, on pull requests touching migrations, seeders, the installer, the upgrade step or the release scripts, by hand (with a `previous` input), and before every release, on MySQL 8.0 and 8.4 and MariaDB 10.6 and 11.4. Under shared-host limits (`max_execution_time=30`, `memory_limit=256M`, `exec`/`proc_open`/pcntl disabled) it:

- installs the previous release's split layout from the command line, adds data (an agency, its owner, a setting), copies the database (the backup), then `down`, the folder swap of § 2.5, `migrate --force`, the install seeders, `optimize`, `up`; checks `check-installation`, `audit-verify`, `verify-package`, the recorded version, the pages, that the data is unchanged, and that the schema equals a plain `migrate` of the new release; then **rolls back** (folders swapped back, the copy restored) and checks the previous release runs with its data;
- installs the previous release again by walking the **web wizard** over HTTP, adds data, swaps the folders without any command on the server, opens Console → Operations → Version, and runs **Run pending migrations** over HTTP with curl (password, TOTP code, backup tick; one migration per request) until it says the upgrade is done; visitors get 503 while it runs; then the same checks, the `upgrade.finished` audit entry, and a second run refused with "Nothing to run".

**Which previous release** (`scripts/release/previous-ref.sh`): the workflow's `previous` input when given; else the newest `v*` tag before this commit; else, on a pull request, its base commit (what `main` runs now); else this commit's first parent. There are no tags yet (Nov 2026), so pull requests upgrade from `main` and the nightly run from the commit before the last merge. The previous release is built from that commit by `scripts/release/build-previous.sh` with **this** checkout's release scripts (a packaging fix applies to both zips; the code, `vendor/` and assets are the previous commit's).

Locally (needs PHP 8.4 with the installer's extensions, Composer, Node 22, `zip`/`unzip`, `curl`, and a MySQL or MariaDB account that may create databases):

```bash
scripts/release/build-previous.sh "$(scripts/release/previous-ref.sh)" 0.0.0-drill.1.1 dist
scripts/release/build-package.sh 0.0.0-drill.1.2 dist
DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root DB_PASSWORD=secret \
  scripts/release/upgrade-drill.sh dist/goproperty-0.0.0-drill.1.1.zip dist/goproperty-0.0.0-drill.1.2.zip
```
