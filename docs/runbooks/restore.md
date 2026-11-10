# Runbook — Restoring a Backup

Backups are described in [PLATFORM_CONSOLE.md § 4](../../PLATFORM_CONSOLE.md). This runbook is the way back when the console cannot be used: the site is down, the server is gone, or a restore must be done by hand. While the site works, use **Console → Operations → Backups** instead (§ 0).

## 0. From the console (the site works)

- **Verify** a backup, **restore to staging** (`BACKUP_STAGING_DB_DATABASE`), or **restore to production** from Console → Operations → Backups. A production restore needs a super-admin's approval in Staff → Approvals; it then runs within a minute: maintenance mode, a safety backup, the restore, newer migrations, everyone signed out, maintenance mode off. Follow it in Backup activity.
- **If the site stays in maintenance mode** after a restore to production, both the restore and putting back the safety backup failed (a critical log line says so). Restore the safety backup named in that log line by hand (§ 2, with the zip from the destination), then `php artisan up`.
- **Record each quarterly drill** with Record a drill on the same page.

## 1. What a backup is

- **Two nightly jobs**, run in-process by the scheduler (no `exec` needed):
  - 01:30, **database**: one zip in `goproperty-db/`.
  - 02:30, **uploaded files**: one zip in `goproperty-files/`.
- **Where:** every active destination that takes that job (an S3-compatible bucket, a Google Drive Shared Drive folder). Folder names are `<root>/goproperty-db/YYYY-MM-DD-HH-MM-SS.zip`.
- **Encryption:** each zip is AES-256 encrypted. The password is `BACKUP_ARCHIVE_PASSWORD` when set. Otherwise it is derived from `APP_KEY`: on any working copy of the site with the same `APP_KEY`, `php artisan goproperty:backup-password` prints it.
- **Inside a database zip:** `db-dumps/<…>.sql`, a plain SQL dump made over PDO (`DROP TABLE IF EXISTS` / `CREATE TABLE` / `INSERT`).
- **Inside a files zip:** `brand/`, `compliance/` (Transaction Tier documents on the local `compliance` disk, encrypted with the application key: restore with the same `APP_KEY`), `media/` (the media library on the local `media` disk: photos, payment proofs, branding images; only the files still on the host after `goproperty:media-move`: those in the `media-s3` bucket are restored from the bucket's own versions or replica), `private/` and `public/` from `storage/app`.
- **Retention:** every backup from the last day, one a day for 30 days, one a month for 12 months.
- **Records:** each run is in `backup_runs` (status, file, size, verified). A failure is also a critical log line.

**Keep `APP_KEY` safe and separate from the backups.** Without it (or `BACKUP_ARCHIVE_PASSWORD`) the zips cannot be opened, and the encrypted columns inside them cannot be read either.

## 2. Restore the database with the app's own command (preferred)

The command never writes to the site's own database. Restore into a new, empty database, check it, then switch.

1. **Create a database:** an empty one in the control panel (cPanel → MySQL Databases), e.g. `acct_goproperty_restore`, and give the site's database user all privileges on it.
2. **Maintenance mode:** put the site in maintenance mode with `php artisan down`. Do this unless you are only investigating.
3. **Restore:** `php artisan goproperty:backup-restore latest --database=acct_goproperty_restore`. It asks before emptying that database: answer `yes` (add `--force` when it runs as a one-time cron job without SSH, [install-recovery.md § 0](install-recovery.md#0-running-a-command-on-the-server)). This downloads the newest database backup from the first destination that takes database backups; add `--destination=<id>` to choose another. To use a zip you already have, pass its path instead of `latest`. The restored copy starts with an empty cache, and a backup that was still running when the backup was taken shows as **Failed** with the reason "Still running when this backup was taken" (its zip is fine if it is at the destination).
4. **Check the restored data:**
   - log in with a database tool and look at recent rows (`payments`, `bills`);
   - run `php artisan goproperty:audit-verify` after the switch: the audit log must be intact.
5. **Switch:** set `DB_DATABASE=acct_goproperty_restore` in `.env`, then run `php artisan config:cache` and `php artisan up`. Then `php artisan goproperty:check-installation`: right after the switch it may say the scheduler and the queue tick have not run for a while (the restored data is from the backup's time); run `php artisan schedule:run` once and check again. The backup list in the console then starts from the restored copy: backups taken after it (the one you restored included) are not listed, but their zips are still at the destination.
6. **Keep the old database** until you are sure. Write down what happened in the incident notes.

## 3. Restore by hand (no working copy of the site)

1. **Download** the zip from the bucket or the Shared Drive.
2. **Get the password:**
   - `BACKUP_ARCHIVE_PASSWORD` if it was set;
   - otherwise, on any machine with PHP and the old `APP_KEY`, from a fresh copy of the release: put `APP_KEY` in `.env` and run `php artisan goproperty:backup-password`.
3. **Open the zip** with 7-Zip or `7z x file.zip` (plain `unzip` may not support AES-256), and enter the password.
4. **Import** `db-dumps/*.sql` into an empty database: phpMyAdmin → Import, or `mysql -u user -p dbname < dump.sql`.
5. **Install the release** on the new server with the **same `APP_KEY`**, point `DB_*` at that database, then run `php artisan migrate --force` (only newer migrations run) and `php artisan config:cache`.

## 4. Restore uploaded files

1. **Open** the newest `goproperty-files/*.zip` the same way.
2. **Copy** its `brand/`, `compliance/`, `media/`, `private/` and `public/` folders into `storage/app/`, over what is there. Media rows in the database point at `media/{organization id}/{uuid}/…`, so restore the files from the same night as the database.
3. **Fix ownership** so the web server user owns them, and check a logo and an uploaded document open.

## 5. Monthly check

- **Verify:** on the 1st of each month at 04:00 the scheduler restores the newest database backup into `BACKUP_VERIFY_DB_DATABASE` and checks every table and the migration history are there, then empties the verify database again. The backup is then marked verified, and the check is listed in Console → Operations → Backup activity. Run it any time with **Verify** on a backup in the console, or `php artisan goproperty:backup-verify`.
- **Set-up:** on shared hosting the verify database must be created once in the control panel. It is wiped on every verify, so never point it at real data. The same goes for `BACKUP_STAGING_DB_DATABASE`, which a restore to staging replaces.
