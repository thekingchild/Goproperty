# Runbook — Installation Recovery

What to do when an install stops halfway or the installation markers disagree. Recovery is always from the command line or the hosting panel's File Manager, never over the web ([INSTALLER.md § 9](../../INSTALLER.md)). Run commands from the application folder (`goproperty/` or `goproperty-app/`).

Start with:

```bash
php artisan goproperty:check-installation
```

Its "Installation markers" line shows all three markers ([INSTALLER.md § 3](../../INSTALLER.md)):

- **lock file:** `storage/app/installed.lock`
- **database row:** the `installation_state` table
- **checkpoint:** `storage/app/private/install.checkpoint`

## 1. Markers that disagree

| Lock file | Database row | Checkpoint | Likely cause | Fix |
| --- | --- | --- | --- | --- |
| present | `finalized` | `finalized` | Healthy | Nothing to do |
| missing | missing or not `finalized` | missing, `configured`, `migrating` or `owner_ready` | Install not finished | Run `php artisan goproperty:install` again, or reopen `/install` and unlock it again (§ 5). It continues where it stopped; use the same database and super-admin details |
| missing | anything | `cleanup_pending` | The finish step stopped partway | `php artisan goproperty:finish-install` |
| missing | `finalized` | `finalized` or missing | `storage/` was replaced (for example, a re-upload that did not copy `storage/`) | Restore `storage/app/` from the backup. If there is none, recreate the lock: put `{"version":"1.4.0","finalized_at":"2026-11-01T09:00:00+01:00"}` (your version and the install date) in `storage/app/installed.lock` |
| present | missing or not `finalized` | `finalized` | `.env` points at another database, or the database was restored from a backup taken before the install finished | Check `DB_*` in `.env` (then `php artisan config:cache`). If the database is the right one and `check-installation` shows every migration ran, ask a developer to set the row; never edit it on a guess |
| any | any | "cannot be verified" | `APP_KEY` changed after the checkpoint was written | Put back the `APP_KEY` from the installation record. If the install never finished and nothing else works, start over (§ 4) |

The site opens only when the lock file and the database row both say finalized. Until then visitors see "being set up" (or "temporarily unavailable" when the lock exists but the database cannot be read).

## 2. A migration failed during the install

The error names the migration. Run the install again: migrations that finished are kept, and it continues from the next one. If the same migration fails again on a **new** install with no data, empty GoProperty's tables and build the database again. The wizard offers this (type the database name to confirm); from the command line, start over (§ 4).

## 3. Lost console path or admin access

- **Console path:** read `ADMIN_PATH` in `.env`. The installation record also has it.
- **Installation record never downloaded** (the web wizard offers it once, on the finish screen): copy `APP_KEY` from `.env` and store it offline in two places, with the version, site address and console path.
- **Locked-out super-admin:** `php artisan goproperty:staff-reset-2fa {email}` resets two-factor sign-in (audited). It arrives with the staff sign-in pages (P00-10).

## 4. Start over on a fresh database

Only while no organization exists. This deletes every GoProperty table.

1. Back up `.env` (it holds `APP_KEY`).
2. Delete `storage/app/installed.lock` and `storage/app/private/install.checkpoint`.
3. Empty the database: in phpMyAdmin (cPanel or Plesk), select every table and choose **Drop**. Or create a new empty database and use that one.
4. Run `php artisan goproperty:install` (or open `/install` and unlock it).

If the database has tables that are not GoProperty's, the installer refuses it. Use a database of its own.

## 5. The web installer

The wizard at `/install` ([INSTALLER.md § 4–5](../../INSTALLER.md)) keeps its own encrypted session, separate from the site's.

- **"Your installer session ended"** (60 minutes without a completed step, or the browser lost its cookie): unlock again on the first screen, with a new phrase in `storage/app/install-unlock.txt` or the setup token. The install carries on from the first unfinished step; email and storage may be asked again.
- **"Too many unlock attempts"**: wait 15 minutes. The count is per IP address, in `storage/app/private/install.attempts`; deleting that file in File Manager also resets it.
- **The unlock file is not found**: it belongs in `storage/app/` inside the GoProperty folder (`goproperty/` or `goproperty-app/`), not in `public_html`. The name is `install-unlock.txt`, and it holds only the phrase. A phrase is valid for 30 minutes; after that, reload `/install` and use the new one.
- **"Install stopped"**: the database settings in `.env` are not the ones the install started with. Put them back, or start over on a fresh database (§ 4).
- **A step page is blank or errors before the server check**: make `storage/` and `bootstrap/cache/` writable (755) in File Manager; the wizard keeps its session and grant there.
- **The finish screen was closed before the console path was saved**: § 3. The install itself is complete; `/install` stays a 404.
