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
| missing | missing or not `finalized` | missing, `configured`, `migrating` or `owner_ready` | Install not finished | Run `php artisan goproperty:install` again (or reopen `/install`). It continues where it stopped; use the same database and super-admin details |
| missing | anything | `cleanup_pending` | The finish step stopped partway | `php artisan goproperty:finish-install` |
| missing | `finalized` | `finalized` or missing | `storage/` was replaced (for example, a re-upload that did not copy `storage/`) | Restore `storage/app/` from the backup. If there is none, recreate the lock: put `{"version":"1.4.0","finalized_at":"2026-11-01T09:00:00+01:00"}` (your version and the install date) in `storage/app/installed.lock` |
| present | missing or not `finalized` | `finalized` | `.env` points at another database, or the database was restored from a backup taken before the install finished | Check `DB_*` in `.env` (then `php artisan config:cache`). If the database is the right one and `check-installation` shows every migration ran, ask a developer to set the row; never edit it on a guess |
| any | any | "cannot be verified" | `APP_KEY` changed after the checkpoint was written | Put back the `APP_KEY` from the installation record. If the install never finished and nothing else works, start over (§ 4) |

The site opens only when the lock file and the database row both say finalized. Until then visitors see "being set up" (or "temporarily unavailable" when the lock exists but the database cannot be read).

## 2. A migration failed during the install

The error names the migration. Run the install again: migrations that finished are kept, and it continues from the next one. If the same migration fails again on a **new** install with no data, empty GoProperty's tables and build the database again. The wizard offers this (type the database name to confirm); from the command line, start over (§ 4).

## 3. Lost console path or admin access

- **Console path:** read `ADMIN_PATH` in `.env`. The installation record also has it.
- **Locked-out super-admin:** `php artisan goproperty:staff-reset-2fa {email}` resets two-factor sign-in (audited). It arrives with the staff sign-in pages (P00-10).

## 4. Start over on a fresh database

Only while no organization exists. This deletes every GoProperty table.

1. Back up `.env` (it holds `APP_KEY`).
2. Delete `storage/app/installed.lock` and `storage/app/private/install.checkpoint`.
3. Empty the database: in phpMyAdmin (cPanel or Plesk), select every table and choose **Drop**. Or create a new empty database and use that one.
4. Run `php artisan goproperty:install` (or open `/install` and unlock it).

If the database has tables that are not GoProperty's, the installer refuses it. Use a database of its own.
