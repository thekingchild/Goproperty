# Runbook — Installation Recovery

What to do when an install stops halfway, a step of [install.md](install.md) does not work, or the installation markers disagree. Recovery is always from the command line or the hosting panel, never over the web ([INSTALLER.md § 9](../../INSTALLER.md)): once installed, `/install` is closed for good.

The words are used as in [install.md](install.md): **bold** is what GoProperty shows, *italics* the hosting panel's menus and buttons, `code` what you type. `USER` is your hosting account's username and `goproperty.ng` your domain. The GoProperty folder is `goproperty-app` on cPanel (`goproperty` if the domain has its own folder) and `goproperty` on Plesk.

## 0. Running a command on the server

Several fixes below run a GoProperty command (`php artisan …`).

**With SSH:** open a terminal and go to the GoProperty folder as in [install.md § 4 step 1](install.md#step-1-open-a-terminal-in-the-goproperty-folder) (including the `php -v` check), then type the command.

**Without SSH:** run it once from the panel's cron jobs.

cPanel:

1. Open *Advanced* → *Cron Jobs*. Under *Current Cron Jobs*, copy the command of GoProperty's cron job, for example `/opt/cpanel/ea-php84/root/usr/bin/php /home/USER/goproperty-app/artisan schedule:run >> /dev/null 2>&1`.
2. Under *Add New Cron Job*, set *Common Settings* to *Once Per Minute (\* \* \* \* \*)*. In *Command*, paste it and replace `schedule:run >> /dev/null 2>&1` with the command you need followed by `>> /home/USER/goproperty-app/storage/logs/once.log 2>&1`. For example, to rebuild the settings cache:
   ```
   /opt/cpanel/ea-php84/root/usr/bin/php /home/USER/goproperty-app/artisan config:cache >> /home/USER/goproperty-app/storage/logs/once.log 2>&1
   ```
   Press *Add New Cron Job*.
3. Wait two minutes, then delete that cron job (*Current Cron Jobs* → *Delete*). Until you do, it runs every minute.
4. Open `goproperty-app/storage/logs/once.log` in *File Manager* to read what the command printed. Delete the file when you are done.

Plesk:

1. Open *Websites & Domains* → `goproperty.ng` → *Scheduled Tasks* → *Add Task*.
2. *Task type*: *Run a PHP script*. *Script path*: the GoProperty folder's `artisan`, e.g. `/var/www/vhosts/goproperty.ng/goproperty/artisan`. *With arguments*: the command without `php artisan`, e.g. `config:cache`. *Use PHP version*: `8.4`.
3. Press *Run Now*. Plesk shows what the command printed.
4. Press *Cancel*, so the task is not kept (or remove it from the list if you saved it).

A command that asks a question cannot answer it from a cron job: use the form this runbook gives for that case (for example `--force`).

## 1. Start here

```bash
php artisan goproperty:check-installation
```

(Without SSH: § 0, with `goproperty:check-installation`.) It ends with **The installation is healthy.** or names each failed check with ✗. Its **Installation markers agree** line shows all three markers ([INSTALLER.md § 3](../../INSTALLER.md)):

- **lock file:** `storage/app/installed.lock`
- **database row:** the `installation_state` table
- **checkpoint:** `storage/app/private/install.checkpoint`

When they disagree:

| Lock file | Database row | Checkpoint | Likely cause | Fix |
| --- | --- | --- | --- | --- |
| present | `finalized` | `finalized` | Healthy | Nothing to do |
| missing | missing or not `finalized` | missing, `configured`, `migrating` or `owner_ready` | Install not finished | Run `php artisan goproperty:install` again, or reopen `/install` and unlock it again (§ 2.4). It continues where it stopped; use the same database and super-admin details |
| missing | anything | `cleanup_pending` | The finish step stopped partway | `php artisan goproperty:finish-install` |
| missing | `finalized` | `finalized` or missing | `storage/` was replaced (for example, a re-upload that did not copy `storage/`) | Restore `storage/app/` from the backup. If there is none, recreate the lock: put `{"version":"1.4.0","finalized_at":"2026-11-01T09:00:00+01:00"}` (your version and the install date) in `storage/app/installed.lock` |
| present | missing or not `finalized` | `finalized` | `.env` points at another database, or the database was restored from a backup taken before the install finished | Check `DB_*` in `.env` (then `php artisan config:cache`). If the database is the right one and `check-installation` shows every migration ran, ask a developer to set the row; never edit it on a guess |
| any | any | "cannot be verified" | `APP_KEY` changed after the checkpoint was written | Put back the `APP_KEY` from the installation record. If the install never finished and nothing else works, start over (§ 4) |

The site opens only when the lock file and the database row both say finalized. Until then visitors see **GoProperty is being set up** (or **GoProperty is temporarily unavailable** when the lock exists but the database cannot be read).

## 2. Problems during the install

### 2.1 The page says GoProperty needs PHP 8.4.1 or newer

The page (or the terminal) shows **GoProperty needs PHP 8.4.1 or newer; this server runs …**: the domain (or the `php` command) still uses an older PHP.

- cPanel with *Select PHP Version*: open it, set *Current PHP version* to `8.4` and press *Set as current*.
- cPanel with *MultiPHP Manager*: tick `goproperty.ng`, choose *PHP 8.4 (ea-php84)* and press *Apply*. If it already shows PHP 8.4, choose another version and *Apply*, then PHP 8.4 again and *Apply*: cPanel then writes its PHP lines into `public_html/.htaccess` again (they are lost when that file is replaced, as in [install.md § 2 step 4](install.md#step-4-upload-and-unpack-the-release)).
- Plesk: *Websites & Domains* → `goproperty.ng` → *PHP Settings* (newer Plesk: *PHP*), choose `8.4` and press *OK*.
- In a terminal: use the full path of PHP 8.4, as in [install.md § 4 step 1](install.md#step-1-open-a-terminal-in-the-goproperty-folder).

Then reload `https://goproperty.ng/install`.

### 2.2 The panel's own page, "Index of" or "Not Found" instead of the installer

The domain does not serve GoProperty's public folder.

- cPanel, main domain: in *File Manager* (with *Show Hidden Files (dotfiles)* ticked), `public_html` must hold `.htaccess`, `index.php` and `install.css`, and no `index.html` or `default.html`. Move the missing files in from `split/public_html` (unpack the zip again if you deleted it), as in [install.md § 2 step 4](install.md#step-4-upload-and-unpack-the-release).
- cPanel, domain with its own folder: *Domains* → *Domains* must show `goproperty.ng`'s *Document Root* as `/goproperty/public` ([install.md § 2 step 4A](install.md#step-4a-only-for-a-domain-with-its-own-folder-step-3)).
- Plesk: *Hosting Settings* (newer Plesk: *Hosting & DNS* → *Hosting*) must show *Document root* `goproperty/public` ([install.md § 3 step 4](install.md#step-4-point-the-domain-at-the-public-folder)).

### 2.3 The unlock file is not accepted

- **The installer cannot find storage/app/install-unlock.txt.**: the file belongs in `storage/app/` inside the GoProperty folder (`goproperty-app/storage/app/` or `goproperty/storage/app/`), not in `public_html` or `httpdocs`. The name is exactly `install-unlock.txt`.
- **storage/app/install-unlock.txt does not hold a phrase from the last …**: the file must hold only the phrase, and a phrase is valid for 30 minutes. Reload `https://goproperty.ng/install`, copy the new phrase into the file (replacing the old one), save it, and press **I've created the file** again.
- **Too many unlock attempts.**: wait 15 minutes. The count is per IP address, in `storage/app/private/install.attempts`; deleting that file in the file manager also resets it.
- **Turn on HTTPS first** or **Unlocking needs HTTPS.**: the page was opened with `http://`. Turn on the certificate ([install.md § 2 step 1](install.md#step-1-turn-on-https) or [§ 3 step 1](install.md#step-1-turn-on-https-1)), then open `https://goproperty.ng/install`.
- **This request did not come from this installer page.**: open `https://goproperty.ng/install` again and use its buttons, in a normal browser window (some privacy extensions remove what the check needs).
- **The installer cannot write in storage/app/private** (no phrase on the page): make `storage` writable, § 2.7.

With SSH there is a second way: run `php artisan goproperty:setup-token`, paste the token it prints into **Setup token** on the first screen and press **Unlock**. **That setup token is not right.** means it was mistyped or replaced: run the command again and paste the new one.

### 2.4 The installer session ended

**Your installer session ended after …** (60 minutes without a completed step, or the browser lost its cookie): unlock again on the first screen, with a new phrase in `storage/app/install-unlock.txt` or the setup token. The install carries on from the first unfinished step; email and storage may be asked again. **Continue the install** on the first screen does the same when this browser is still unlocked.

### 2.5 A migration failed

The error names the migration (**Migration … failed**). Run the step again (**Try this step again**, or `php artisan goproperty:install` again): migrations that finished are kept, and it continues from the next one. If the same migration fails again on a new install with no data, empty GoProperty's tables and build the database again: the web installer offers **Empty the database and start this step again** (type the database name to confirm, then press **Empty the database**); from the command line, start over (§ 4). Either way, send the error to the developers.

### 2.6 Install stopped

**Install stopped**: the database settings in `.env` are not the ones the install started with. Put them back, or start over on a fresh database (§ 4).

### 2.7 A page is blank or errors before the server check

Make `storage/` and `bootstrap/cache/` in the GoProperty folder writable: cPanel *File Manager* → right-click the folder → *Change Permissions* → `755`; Plesk *Files* → the folder → *Change Permissions* → allow *Write* for the owner. The installer keeps its session and unlock there.

### 2.8 The finish screen was closed before the console address was saved

See § 5. The install itself is complete; `/install` stays a "Not Found" page.

## 3. Cron does not run

The finish screen keeps **Waiting for the first scheduler run…**, **Console → Operations → System operations** shows the **Scheduler** as **Never ran** or **Stopped**, or `check-installation` says **Cron runs the scheduler** … **It has never run.**

1. Check the cron job exists and runs every minute: cPanel *Advanced* → *Cron Jobs* → *Current Cron Jobs*; Plesk *Websites & Domains* → *Scheduled Tasks*.
2. See what it prints:
   - cPanel: press *Edit* on the cron job, replace `>> /dev/null 2>&1` at the end with `>> /home/USER/goproperty-app/storage/logs/cron.log 2>&1` and press *Edit Line*. Wait two minutes and open `goproperty-app/storage/logs/cron.log` in *File Manager*.
   - Plesk: open the task and press *Run Now*.
3. Read the first lines:
   - **GoProperty needs PHP 8.4.1 or newer; this server runs …**: the command starts with an older PHP. Use `/opt/cpanel/ea-php84/root/usr/bin/php` (cPanel, *MultiPHP Manager*), `/opt/alt/php84/usr/bin/php` (cPanel with *Select PHP Version*) or `/opt/plesk/php/8.4/bin/php` (Plesk).
   - A message from Composer about missing PHP extensions: the command's PHP lacks an extension the website's PHP has. On a cPanel host with *Select PHP Version*, replace `/opt/cpanel/ea-php84/root/usr/bin/php` with `/opt/alt/php84/usr/bin/php`. Otherwise ask the host to enable that extension for PHP 8.4 on the command line.
   - "No such file or directory" or "not found": a path in the command is wrong. Compare it with the cron line in **Console → Operations → System operations** → **Scheduler**. On Plesk without SSH, use a *Run a PHP script* task ([install.md § 5 step 9](install.md#step-9-add-the-cron-job)).
   - Nothing at all after five minutes: the host may not run cron every minute. Ask the host; a host that allows only every 5 or 15 minutes is not suitable ([shared-hosting.md § 1](shared-hosting.md)).
4. Once the scheduler shows **Running**, put `>> /dev/null 2>&1` back at the end of the cPanel cron job (the log would keep growing) and delete `cron.log`.

## 4. Start over on a fresh database

Only while no organization exists. This deletes every GoProperty table.

1. Back up `.env` (it holds `APP_KEY`): download it from the file manager.
2. Delete `storage/app/installed.lock` and `storage/app/private/install.checkpoint` in the GoProperty folder.
3. Empty the database: open phpMyAdmin (cPanel *Databases* → *phpMyAdmin*; Plesk *Websites & Domains* → *Databases* → *phpMyAdmin* next to the database), choose the database on the left, tick *Check all* under the table list, choose *Drop* under *With selected* and confirm. Or create a new empty database ([install.md § 2 step 2](install.md#step-2-create-the-database-and-its-user) or [§ 3 step 2](install.md#step-2-create-the-database-and-its-user-1)) and use that one.
4. Run `php artisan goproperty:install` again, or open `https://goproperty.ng/install` and unlock it.

If the database has tables that are not GoProperty's, the installer refuses it. Use a database of its own.

## 5. Lost console address, installation record or authenticator app

- **Console address:** it is the site address followed by `/` and `ADMIN_PATH` from `.env` (e.g. `ADMIN_PATH=gp-control-k3x9q2` → `https://goproperty.ng/gp-control-k3x9q2`). Open `.env` in the file manager to read it (cPanel: tick *Show Hidden Files (dotfiles)* first). The installation record has it too.
- **Installation record never saved:** the web installer offers it once, on the finish screen. Copy `APP_KEY` from `.env` and store it offline in two places, with the version, site address and console address.
- **Lost your phone, still have a recovery code:** on **Enter your 2FA code**, choose **Use a recovery code instead** and type one of the codes saved at the install. Each code works once.
- **Lost your phone and the recovery codes:** reset two-factor sign-in for your account:
  - With SSH: `php artisan goproperty:staff-reset-2fa you@goproperty.ng` and answer yes.
  - Without SSH: run `goproperty:staff-reset-2fa you@goproperty.ng --force` once (§ 0).

  It prints **Two-factor sign-in is reset for …**. At the next console sign-in, after your password, you set up a new authenticator app and get new recovery codes ([install.md § 4 step 7](install.md#step-7-sign-in-and-set-up-two-factor-sign-in)). The reset is in the audit log.

## 6. The site answers "Bad Request"

Once installed, GoProperty answers only at the address in `APP_URL`, its `www.` form, its bare domain and `ADMIN_DOMAIN` if set; any other name gets a plain "Bad Request" page (docs/security/review-2026-11.md SR-01). This happens when the install was done at another address (the server's IP, a temporary preview link) or the domain changed.

1. Open `.env` in the GoProperty folder and set `APP_URL` to the address the site is reached at, starting with `https://`, e.g. `APP_URL=https://goproperty.ng`. Save.
2. Rebuild the settings cache: `php artisan config:cache` (without SSH: § 0).

## 7. Plesk: every page but the home page is "Not Found"

GoProperty's `.htaccess` sends every address to `index.php`, and only Apache reads it. In *Websites & Domains* → `goproperty.ng` → *Apache & nginx Settings*, *Proxy mode* must be ticked (Apache behind nginx, Plesk's usual set-up). If the host serves sites with nginx alone, ask the host to serve this domain through Apache.

## 8. To check on the real install

Written without access to a real panel; check these on the first real installs ([install.md § 9](install.md#9-the-acceptance-installs-p09-07)):

1. § 0, Plesk: the *Run Now* button on a new task's form, and whether *Cancel* afterwards keeps nothing.
2. § 0, cPanel: that a command that asks no question runs from cron without `--no-interaction`.
3. § 2.1: that re-applying the PHP version in *MultiPHP Manager* writes cPanel's lines into `public_html/.htaccess` again.
4. § 3: the wording Composer prints when the cron's PHP lacks an extension, and where cPanel's *Edit* of a cron job is (*Edit Line*).
5. § 4: phpMyAdmin's *Check all* / *With selected* → *Drop* on the host's version.
6. § 7: the name and place of *Apache & nginx Settings* → *Proxy mode* on the host's Plesk.
