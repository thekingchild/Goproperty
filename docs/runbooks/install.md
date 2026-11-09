# Runbook — Installing GoProperty (cPanel or Plesk, with or without SSH)

How to install GoProperty from a release zip on a cPanel or Plesk hosting account, with the web installer (no SSH needed) or from the command line (SSH). It is written for the person who does the install, not for developers: follow the steps in order and type exactly what is shown. It takes about 45 minutes.

What the installer does and why is in [INSTALLER.md](../../INSTALLER.md). What the hosting must offer before you start is in [shared-hosting.md § 1](shared-hosting.md). If a step does not work, [install-recovery.md](install-recovery.md) says what to do; each step below links to the right section. Upgrading later: [upgrade.md](upgrade.md). Staging and servers deployed by the Deploy workflow are installed differently ([shared-hosting.md § 2](shared-hosting.md) step 4).

## How to use this guide

**Pick your path:** read only the sections in your row, in that order:

| Your hosting | Read |
| --- | --- |
| cPanel, with SSH | § 1 → § 2 → § 4 → § 6 |
| cPanel, without SSH | § 1 → § 2 → § 5 → § 6 |
| Plesk, with SSH | § 1 → § 3 → § 4 → § 6 |
| Plesk, without SSH | § 1 → § 3 → § 5 → § 6 |

**Do you have SSH:** in cPanel, look for *Terminal* (under *Advanced*) or *SSH Access* (under *Security*). In Plesk, look for *SSH Terminal* on the domain's page, or ask the host whether the subscription's user has shell access. If you are not sure, use the "without SSH" path: it works on every host.

**How the text is written:**

- Words in **bold** are what GoProperty shows on screen: headings, buttons, messages.
- Words in *italics* are the hosting panel's own menus, buttons and fields.
- `Code` is what you type or paste, exactly. Longer things to type are in grey boxes.
- `goproperty.ng` stands for your domain and `USER` for your hosting account's username. Replace both.
- "You should see" tells you how to know a step worked. If you see something else, stop and use the link next to it.

## 1. What to have ready (every path)

1. **Domain:** it points at this hosting account, and opening `https://goproperty.ng` in a browser shows no certificate warning. HTTPS is set up in § 2 step 1 or § 3 step 1; the domain's DNS must already point here for the free certificate to work. Install at the address the site will use: once installed, GoProperty answers only at that address (§ 6 step 6).
2. **Release zip:** the zip and its checksum from the GoProperty repository on GitHub, *Releases*: `goproperty-X.Y.Z.zip` and `goproperty-X.Y.Z.zip.sha256` (X.Y.Z is the version). Check the zip on your own computer before you upload it:
   - Windows (PowerShell): `Get-FileHash goproperty-X.Y.Z.zip`
   - macOS: `shasum -a 256 goproperty-X.Y.Z.zip`
   - Linux: `sha256sum goproperty-X.Y.Z.zip`

   Open the `.sha256` file in a text editor. The long string of letters and numbers must be the same as the one the command printed (capital or small letters do not matter). If it differs, download the zip again.
3. **Outgoing email:** an account with an email service (Postmark, Mailgun, Amazon SES or another SMTP service) and its SMTP settings: host, port (usually 587), username, password or API token, and a sender address on your domain that the service has verified, such as `no-reply@goproperty.ng`. You can skip email during the install and add it later, but reminders and receipts need it.
4. **File storage (optional):** a private S3-compatible bucket (AWS S3, Cloudflare R2, DigitalOcean Spaces, Wasabi…) with its endpoint, bucket name, region, access key ID and secret access key. Without one, uploaded files stay on the hosting account.
5. **Authenticator app:** an app on your phone (Google Authenticator, Microsoft Authenticator, 1Password, Authy…). The console always asks for its 6-digit code.
6. **Password manager:** a password manager or another safe place for the database password, the installation record and the recovery codes the install gives you.

## 2. cPanel: prepare the hosting

### Step 1. Turn on HTTPS

1. In cPanel, open *Security* → *SSL/TLS Status*.
2. Tick `goproperty.ng` and `www.goproperty.ng`, then press *Run AutoSSL*.
3. Wait until both show a valid certificate (a green padlock). This can take a few minutes.

You should see: `https://goproperty.ng` opens without a certificate warning.

### Step 2. Create the database and its user

1. Open *Databases* → *MySQL Database Wizard*.
2. *New Database*: type `goproperty`, then *Next Step*. cPanel puts your username in front: the database is called `USER_goproperty`.
3. *Username*: type `gp`. Press *Password Generator*, copy the password into your password manager, tick that you copied it, *Use Password*, then *Create User*. The user is called `USER_gp`.
4. Tick *ALL PRIVILEGES*, then *Next Step* (or *Make Changes*).
5. Write down: database `USER_goproperty`, user `USER_gp`, the password, host `localhost`, port `3306`.
6. Note which database server the host runs: the cPanel home page lists it under *General Information* → *Server Information* (it says MySQL or MariaDB, with a version).

### Step 3. Check which folder the domain uses

1. Open *Domains* → *Domains*.
2. Find `goproperty.ng` and read its *Document Root*.

- If it is `/public_html`: carry on with step 4. This is the usual case, the account's main domain.
- If it is any other folder (an addon domain or a subdomain): follow step 4, but in step 4.6 move `preferred/goproperty` instead of `split/goproperty-app`, skip steps 4.7 and 4.8, then do step 4A. In the rest of this guide, read `goproperty` wherever it says `goproperty-app`.

### Step 4. Upload and unpack the release

1. Open *Files* → *File Manager*.
2. Press *Settings* (top right), tick *Show Hidden Files (dotfiles)* and press *Save*. GoProperty has a hidden `.htaccess` file that must be moved with the others.
3. Open your home folder (`/home/USER`, the *Home* link at the top of the folder list).
4. Press *Upload*, choose `goproperty-X.Y.Z.zip` and wait until it says 100%. Then press *Go Back to "/home/USER"*.
5. Right-click `goproperty-X.Y.Z.zip`, choose *Extract*, keep `/home/USER` as the folder and press *Extract File(s)*. You should see three new items: `preferred`, `split` and `README.txt`.
6. Open `split`. Right-click `goproperty-app`, choose *Move*, change the path to `/` and press *Move File(s)*. The application is now at `/home/USER/goproperty-app`, outside the web folder.
7. Open `public_html`. Apart from `cgi-bin` and `.well-known` (leave those), it must be empty. If it holds a placeholder such as `index.html` or `default.html`, or an old `.htaccess`, create a folder `public_html-old` in your home folder and move them there. If `public_html` holds a website you still need, stop: GoProperty needs a domain of its own.
8. Open `split/public_html`. Press *Select All*, then *Move*, change the path to `/public_html` and press *Move File(s)*. You should see in `public_html`: `.htaccess`, `index.php`, `install.css` and the folders `build`, `css`, `fonts`, `images` and `js`.
9. Go back to your home folder. Select `preferred`, `split` and `README.txt` and press *Delete*. Keep the zip until the install is done.

### Step 4A. Only for a domain with its own folder (step 3)

1. Open *Domains* → *Domains* and press *Manage* next to `goproperty.ng`.
2. In *New Document Root*, type `goproperty/public` and press *Update*.

### Step 5. Choose PHP 8.4 and its settings

Do this after step 4: cPanel writes its PHP setting into the `.htaccess` file you just moved.

If your cPanel has *Software* → *Select PHP Version* (hosts running CloudLinux):

1. Open *Select PHP Version*. Set *Current PHP version* to `8.4` and press *Set as current*.
2. On the *Extensions* tab, tick: `bcmath`, `ctype`, `curl`, `dom`, `exif`, `fileinfo`, `gd`, `intl`, `mbstring`, `opcache`, `openssl`, `pdo_mysql`, `sodium`, `tokenizer`, `xml`, `xmlreader`, `xmlwriter`, `zip`. Some are always on and cannot be unticked.
3. On the *Options* tab, set `memory_limit` to `512M`, `max_execution_time` to `60`, `upload_max_filesize` to `20M` and `post_max_size` to `25M`.

Otherwise:

1. Open *Software* → *MultiPHP Manager*. Tick `goproperty.ng`, choose *PHP 8.4 (ea-php84)* under *PHP Version* and press *Apply*.
2. Open *Software* → *MultiPHP INI Editor*, *Basic Mode*, choose `goproperty.ng` and set `memory_limit` to `512M`, `max_execution_time` to `60`, `upload_max_filesize` to `20M` and `post_max_size` to `25M`. Press *Apply*.
3. Extensions are the host's to switch on. The installer's server check names any that are missing; ask the host's support to enable them for PHP 8.4.

### Step 6. Check

Open `https://goproperty.ng/install`.

You should see: **First, prove you control this hosting account.**, a server check list, and two ways to unlock.

- **GoProperty needs PHP 8.4.1 or newer; this server runs …** instead: step 5 did not take effect ([install-recovery.md § 2.1](install-recovery.md#21-the-page-says-goproperty-needs-php-841-or-newer)).
- cPanel's own page, "Index of /" or a "Not Found" error: the files are not in the domain's folder ([install-recovery.md § 2.2](install-recovery.md#22-the-panels-own-page-index-of-or-not-found-instead-of-the-installer)).

Now go to § 4 (with SSH) or § 5 (without SSH).

## 3. Plesk: prepare the hosting

Plesk lets you choose the domain's document root, so Plesk installs always use the `preferred` layout: one folder, `goproperty`, whose `public` folder is the document root.

### Step 1. Turn on HTTPS

1. In Plesk, open *Websites & Domains*, then `goproperty.ng` → *SSL/TLS Certificates*.
2. Under the free Let's Encrypt certificate, press *Install*.
3. Type your email, tick *Include a "www" subdomain for the domain and each selected alias*, and press *Get it free*.

You should see: the certificate listed for the domain, and `https://goproperty.ng` opens without a certificate warning.

### Step 2. Create the database and its user

1. Open *Websites & Domains* → *Databases* → *Add Database*.
2. *Database name*: `goproperty`. *Related site*: `goproperty.ng`.
3. Note what *Database server* names: MySQL or MariaDB. If it does not say, ask the host.
4. *Database user name*: `gp`. Press *Generate* next to the password and copy it into your password manager.
5. Press *OK*.
6. Write down: database `goproperty`, user `gp` (some hosts add a prefix: use the names the list shows), the password, host `localhost`, port `3306`.

### Step 3. Upload and unpack the release

1. Open *Files*. It opens at the subscription's home folder (it holds `httpdocs` and `logs`).
2. Press the *+* button → *Upload File*, choose `goproperty-X.Y.Z.zip` and wait until the upload ends.
3. Click `goproperty-X.Y.Z.zip` and choose *Extract Files*. Keep the home folder as the destination and press *OK*. You should see `preferred`, `split` and `README.txt`.
4. Open `preferred`. Tick `goproperty`, choose *Move*, select the home folder (`/`) and press *OK*. The application is now at `/var/www/vhosts/goproperty.ng/goproperty`.
5. Go back to the home folder. Tick `preferred`, `split` and `README.txt` and press *Remove*. Keep the zip until the install is done.

### Step 4. Point the domain at the public folder

1. Open *Websites & Domains* → `goproperty.ng` → *Hosting Settings* (on newer Plesk: *Hosting & DNS* → *Hosting*).
2. In *Document root*, replace `httpdocs` with `goproperty/public`.
3. Press *OK* (or *Save*).

### Step 5. Choose PHP 8.4 and its settings

1. Open *Websites & Domains* → `goproperty.ng` → *PHP Settings* (on newer Plesk: *PHP*).
2. *PHP version*: choose `8.4`, served by *FPM application served by Apache* (or the FPM choice your host offers).
3. Set `memory_limit` to `512M`, `max_execution_time` to `60`, `upload_max_filesize` to `20M` and `post_max_size` to `25M`, and turn `opcache.enable` on.
4. Press *OK* (or *Apply*).

PHP extensions are set by the host on Plesk. The installer's server check names any that are missing; ask the host's support to enable them for PHP 8.4.

### Step 6. Check

Open `https://goproperty.ng/install`.

You should see: **First, prove you control this hosting account.**, a server check list, and two ways to unlock.

- **GoProperty needs PHP 8.4.1 or newer; this server runs …** instead: step 5 did not take effect ([install-recovery.md § 2.1](install-recovery.md#21-the-page-says-goproperty-needs-php-841-or-newer)).
- Plesk's default page or a "Not Found" error: the document root is not `goproperty/public` ([install-recovery.md § 2.2](install-recovery.md#22-the-panels-own-page-index-of-or-not-found-instead-of-the-installer)).

Now go to § 4 (with SSH) or § 5 (without SSH).

## 4. Install from the command line (with SSH)

### Step 1. Open a terminal in the GoProperty folder

1. Open a terminal on the server:
   - cPanel: *Advanced* → *Terminal* (accept the warning), or connect with an SSH program as `USER`.
   - Plesk: *Websites & Domains* → `goproperty.ng` → *SSH Terminal*, or connect with an SSH program as the subscription's user.
2. Go to the GoProperty folder:
   - cPanel: `cd ~/goproperty-app`
   - Plesk: `cd ~/goproperty`
3. Check which PHP the `php` command runs:
   ```bash
   php -v
   ```
   If the first line starts with `PHP 8.4.`, carry on. If it shows an older version or "command not found", type the line for your panel, then `php -v` again:
   - cPanel with *Select PHP Version* (§ 2 step 5): `alias php=/opt/alt/php84/usr/bin/php`
   - cPanel with *MultiPHP Manager*: `alias php=/opt/cpanel/ea-php84/root/usr/bin/php`
   - Plesk: `alias php=/opt/plesk/php/8.4/bin/php`

   The alias lasts until you close the terminal. Type it again each time you open one.

### Step 2. Check the uploaded files

```bash
php artisan goproperty:verify-package
```

You should see: **Package verified: every file matches SHA256SUMS and the packaging rules.** Anything else means the upload is incomplete or changed: upload and unpack the zip again (§ 2 step 4 or § 3 step 3).

### Step 3. Run the installer

For the live site:

```bash
php artisan goproperty:install --env=production
```

(For a test copy, type `--env=staging` instead.) It first checks the server. Lines marked ✗, each with a **cPanel:** and a **Plesk:** fix, stop the install: fix them in the panel and run the command again.

Then it asks these questions, one at a time. Where an answer is already filled in, press Enter to keep it. For a choice, move with the arrow keys and press Enter. Passwords do not show while you type.

| It asks | Answer |
| --- | --- |
| **Site address** | `https://goproperty.ng`, exactly the address people will use |
| **Database type** | **MySQL 8** or **MariaDB 10.6 or newer**, as noted in § 2 step 2 or § 3 step 2 |
| **Database host** | press Enter (`localhost`) |
| **Database port** | press Enter (`3306`) |
| **Database name** | cPanel: `USER_goproperty`. Plesk: `goproperty` |
| **Database user** | cPanel: `USER_gp`. Plesk: `gp` |
| **Database password** | the database password |
| **Set up outgoing email (SMTP) now?** | Yes (choose No only to set it up later) |
| **SMTP host** | your email service's SMTP host, e.g. `smtp.postmarkapp.com` |
| **SMTP port** | press Enter (`587`) unless the service says otherwise |
| **SMTP username** | from the email service |
| **SMTP password** | from the email service |
| **Send emails from** | `no-reply@goproperty.ng` (an address the service has verified) |
| **Send a test email to** | your own email address |
| **First super-admin's name** | your full name |
| **Their work email** | your work email |
| **Their password (12+ characters)** | a new password of 12 characters or more, used nowhere else |
| **Type it again** | the same password |

It then shows the database being built (**Building the database**, with a count of the changes done; a few minutes) and ends with **GoProperty is installed.**, followed by three things you need: the console address, the installation record's path and the cron line.

If it stops with an error instead, run the same command again: it carries on where it stopped. If it fails twice at the same place, see [install-recovery.md § 2](install-recovery.md#2-problems-during-the-install).

### Step 4. Keep the console address and the installation record

1. Bookmark the address printed after **Console (bookmark it; it is not linked anywhere)**, e.g. `https://goproperty.ng/gp-control-k3x9q2`. Nothing links to it.
2. Show the installation record:
   ```bash
   cat storage/app/private/installation-record.txt
   ```
   Copy everything it prints into your password manager and keep a second copy offline (for example printed, in a safe). It holds `APP_KEY`: without it, encrypted data cannot be read after a restore.
3. Then delete the file from the server:
   ```bash
   rm storage/app/private/installation-record.txt
   ```

### Step 5. Add the cron job

The installer printed one line starting with `* * * * *`. Everything after those five stars is the command, for example:

- cPanel: `/opt/cpanel/ea-php84/root/usr/bin/php /home/USER/goproperty-app/artisan schedule:run >> /dev/null 2>&1`
- Plesk: `/opt/plesk/php/8.4/bin/php /var/www/vhosts/goproperty.ng/goproperty/artisan schedule:run >> /dev/null 2>&1`

Use the line your installer printed, not these examples.

cPanel:

1. Open *Advanced* → *Cron Jobs*.
2. Under *Add New Cron Job*, set *Common Settings* to *Once Per Minute (\* \* \* \* \*)*.
3. Paste the command into *Command* and press *Add New Cron Job*.

Plesk (the same task as without SSH, so the PHP is Plesk's own whatever the shell finds):

1. Open *Websites & Domains* → `goproperty.ng` → *Scheduled Tasks* → *Add Task*.
2. *Task type*: *Run a PHP script*.
3. *Script path*: the path in the command that ends in `/artisan`, e.g. `/var/www/vhosts/goproperty.ng/goproperty/artisan`. *With arguments*: `schedule:run`. *Use PHP version*: `8.4`.
4. *Run*: *Cron style*, and type `* * * * *`.
5. *Notify*: *Do not notify*. Press *OK*.

### Step 6. Check the installation

Wait two minutes for cron to run, then:

```bash
php artisan goproperty:check-installation
```

You should see a list of ✓ lines ending with **The installation is healthy.** If **Cron runs the scheduler** says **It has never run.**, see [install-recovery.md § 3](install-recovery.md#3-cron-does-not-run). Any other ✗ line names what is wrong; [install-recovery.md § 1](install-recovery.md#1-start-here) explains the installation markers.

### Step 7. Sign in and set up two-factor sign-in

1. Open the console address from step 4.
2. On **Staff console**, type your work email and password.
3. GoProperty asks you to **Set up two-factor authentication**. Next to **Authenticator app**, press **Set up**.
4. Scan the QR code with your authenticator app. Save the recovery codes it shows in your password manager: each one signs you in once if you lose your phone.
5. Type the 6-digit code the app shows and press **Enable authenticator app**, then **Continue**.

You should see the console's home page. From now on every sign-in asks for a code from the app.

Now go to § 6. File storage is not asked by the command-line install: § 6 step 7 says how to add it.

## 5. Install with the web installer (without SSH)

Keep two browser tabs open: the installer, and the panel's file manager (cPanel *File Manager*, Plesk *Files*).

### Step 1. Unlock the installer with a file

1. Open `https://goproperty.ng/install`. Under **Unlock with a file (no SSH)** the page shows a phrase such as `GP-UNLOCK 7Q4M-K2XD-PL9A`. Copy it.
2. In the file manager, open the GoProperty folder, then `storage`, then `app`:
   - cPanel: `/home/USER/goproperty-app/storage/app`
   - Plesk: `/goproperty/storage/app`
3. Create a file named `install-unlock.txt` there:
   - cPanel: press *+ File*, type `install-unlock.txt`, press *Create New File*. Right-click it, choose *Edit* (then *Edit* again), paste the phrase and press *Save Changes*.
   - Plesk: press the *+* button → *Create File*, type `install-unlock.txt` and press *OK*. Click the file to open it in the editor, paste the phrase and press *Save*.
4. Back in the installer tab, press **I've created the file** (within 30 minutes of opening the page).

You should see: **Server check**. The installer deleted the file itself and created `.env` with a new `APP_KEY`.

- **The installer cannot find storage/app/install-unlock.txt.** or **storage/app/install-unlock.txt does not hold a phrase from the last …**: see [install-recovery.md § 2.3](install-recovery.md#23-the-unlock-file-is-not-accepted).
- **Turn on HTTPS first**: the page was opened with `http://`. Do § 2 step 1 or § 3 step 1, then open `https://goproperty.ng/install`.

### Step 2. Server check

You should see: **This server meets every requirement.** Press **Continue**; the next page is **Site & database**.

If some items are marked ✗, each says how to fix it under **cPanel:** and **Plesk:**. Fix them in the panel, then press **Check again**. Items marked ! are advice: the install can go on without them.

### Step 3. Site & database

1. **Site address** already holds the address you opened. It must be `https://goproperty.ng`, exactly the address people will use, with no folder after it.
2. **This is**: **Production (the live site)** (or **Staging (a test copy)** for a test copy).
3. **Database type**: **MySQL 8** or **MariaDB 10.6 or newer**, as noted in § 2 step 2 or § 3 step 2.
4. **Host**: `localhost`. **Port**: `3306`.
5. **Database name** and **Username**: cPanel `USER_goproperty` and `USER_gp`; Plesk `goproperty` and `gp`.
6. **Password**: the database password.
7. Press **Save and continue**.

You should see: **Build the database**. If the page names a problem instead (for example **The username or password was refused.**), nothing was saved: correct the field, type the password again and press **Save and continue**.

### Step 4. Build the database

1. Press **Build the database**.
2. The progress bar fills by itself (a count of **database changes done**), a few changes per step. Leave the tab open; it takes a few minutes. If the tab closes, open `https://goproperty.ng/install`, unlock again (step 1) and it carries on.
3. When the bar is full, press **Add the default data and continue**.

You should see: **Email & file storage**. If it says **Migration … failed**, see [install-recovery.md § 2.5](install-recovery.md#25-a-migration-failed).

### Step 5. Email & file storage

Outgoing email:

1. **Provider**: your email service (or **Other SMTP service**).
2. **SMTP host**: leave empty for Postmark, Mailgun and Amazon SES; for another service type its host. **Port**: `587` unless the service says otherwise.
3. **SMTP username**, **SMTP password or API token**: from the email service.
4. **Send emails from**: `no-reply@goproperty.ng` (an address the service has verified). **Send the test email to**: your own address.
5. Press **Save and send a test email**.

You should see: **Test email sent to …** Check that it arrived (and the spam folder). To set up email later instead, press **Skip for now**.

File storage, if you have a bucket (§ 1 item 4):

1. **Endpoint**: the service's endpoint address (leave empty for AWS S3). **Bucket**, **Region**, **Access key ID**, **Secret access key**: from the service.
2. Tick **Use path-style addresses** only if the service asks for it (MinIO does).
3. Press **Save and test**.

You should see: **File storage works: a test file was written, read back and deleted.** Without a bucket, press **Skip for now**.

When both are tested or skipped, the installer moves on to **First super-admin**.

### Step 6. First super-admin

1. **Full name**, **Work email**: yours.
2. **Password** and **Type the password again**: a new password of 12 characters or more, used nowhere else.
3. Press **Create the account**.
4. **Set up your authenticator app**: scan the QR code with the app on your phone (or type the setup key shown under it), type the 6-digit code the app shows and press **Confirm**.
5. **Save your recovery codes**: press **Download** and keep the file in your password manager. Each code signs you in once if you lose your phone.
6. Tick **I've saved my recovery codes** and press **Continue**.

You should see: **Finish**.

### Step 7. Finish

1. Press **Finish the installation** once. It takes up to half a minute.

You should see: **GoProperty is installed**. This screen is shown only once: keep the tab open until you have done steps 8 and 9.

### Step 8. Keep the console address and the installation record

1. Under **Console address**: bookmark the address (**Bookmark this — it isn't linked anywhere.**).
2. Under **Installation record**: press **Download the installation record**. Keep the file in your password manager and a second copy offline (for example printed, in a safe). It holds `APP_KEY`: without it, encrypted data cannot be read after a restore. No copy stays on the server.

### Step 9. Add the cron job

Under **Cron job** the screen shows the line for this server. Press **Where to add it** to see the command on its own (the line without its first five stars), for example:

- cPanel: `/opt/cpanel/ea-php84/root/usr/bin/php /home/USER/goproperty-app/artisan schedule:run >> /dev/null 2>&1`
- Plesk: `/opt/plesk/php/8.4/bin/php /var/www/vhosts/goproperty.ng/goproperty/artisan schedule:run >> /dev/null 2>&1`

Use the command your screen shows, not these examples.

cPanel:

1. Open *Advanced* → *Cron Jobs*.
2. Under *Add New Cron Job*, set *Common Settings* to *Once Per Minute (\* \* \* \* \*)*.
3. Paste the command into *Command* and press *Add New Cron Job*.

Plesk (the finish screen says the same: a subscription without SSH may not be able to run the command as written, so Plesk's own PHP runs `artisan`):

1. Open *Websites & Domains* → `goproperty.ng` → *Scheduled Tasks* → *Add Task*.
2. *Task type*: *Run a PHP script*.
3. *Script path*: the path in the command that ends in `/artisan`, e.g. `/var/www/vhosts/goproperty.ng/goproperty/artisan`. *With arguments*: `schedule:run`. *Use PHP version*: `8.4`.
4. *Run*: *Cron style*, and type `* * * * *`.
5. *Notify*: *Do not notify*. Press *OK*.

You should see, on the finish screen within two minutes: **Waiting for the first scheduler run…** turns into **Cron is working: the scheduler ran at …**. If it still waits after five minutes, see [install-recovery.md § 3](install-recovery.md#3-cron-does-not-run). If it says **Cannot check from here.**, check in the console instead (§ 6 step 3).

Press **Open the console**: you are already signed in. Now go to § 6.

## 6. After the install (every path)

### Step 1. Send http:// to https://

- cPanel: open *Domains* → *Domains* and switch *Force HTTPS Redirect* on for `goproperty.ng`.
- Plesk: open *Websites & Domains* → `goproperty.ng` → *Hosting Settings* (newer Plesk: *Hosting & DNS* → *Hosting*), tick *Permanent SEO-safe 301 redirect from HTTP to HTTPS* and press *OK*.

You should see: `http://goproperty.ng` opens as `https://goproperty.ng`.

### Step 2. Sign in to the console on another device

Open the console address. On **Staff console** type your work email and password, then on **Enter your 2FA code** the code from your authenticator app.

### Step 3. Check that background work runs

1. Open **Console → Operations → System operations**. In **Scheduler**, the state must be **Running**. The same section shows the cron line again, should you need it.
2. Open **Console → Operations → Version**. It shows the version you installed and **Nothing to run**.
3. With SSH: `php artisan goproperty:check-installation` must end with **The installation is healthy.**

If the scheduler shows **Never ran** or **Stopped**, see [install-recovery.md § 3](install-recovery.md#3-cron-does-not-run).

### Step 4. Check the public site

1. `https://goproperty.ng` shows the home page.
2. `https://goproperty.ng/install` shows a "Not Found" page. The installer is closed for good; recovery is from the panel or the command line ([install-recovery.md](install-recovery.md)).

### Step 5. Set up backups

Add at least one backup destination (an S3-compatible bucket or a Google Shared Drive) in **Console → Operations → Backup destinations**, or with SSH `php artisan goproperty:backup-destination:add` ([restore.md](restore.md)). Then, in **Console → Operations → Backups**, press **Back up database now** and check it shows **Success** within two minutes. Also keep the host's own daily backups switched on (cPanel *JetBackup*, Plesk *Backup Manager*).

### Step 6. Keep the site at its address

GoProperty answers only at the address in `APP_URL` (the **Site address** you gave), its `www.` form and its bare domain. Any other address, such as the server's IP or the host's temporary preview link, gets a plain "Bad Request" page. If you installed at another address, or the domain changes, see [install-recovery.md § 6](install-recovery.md#6-the-site-answers-bad-request).

### Step 7. Move uploaded files to a bucket (optional)

Uploaded files (photos, payment proofs, documents) are kept on the hosting account in `storage/app/media`, where the nightly files backup copies them. That is the default, and you can keep it. If the hosting account runs short of space, or you would rather keep files in a bucket, move them with one command, now or at any time later. The site keeps working while they move, and new uploads go to the bucket from the start of the move.

Before you start:

1. The bucket's settings must be in `.env`. The web installer's file storage test put them there if it passed. After a command-line install, or a skipped test, add them yourself: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`, and `AWS_USE_PATH_STYLE_ENDPOINT=true` only if the service needs it. Then rebuild the settings cache: with SSH `php artisan config:cache`; without SSH, with a one-time cron job ([install-recovery.md § 0](install-recovery.md#0-running-a-command-on-the-server)).
2. In the storage service's dashboard, turn on versioning for the bucket (or replication to a second bucket). Files in the bucket are no longer in the nightly files backup.

`.env` is a hidden file: in cPanel's *File Manager* it shows once *Show Hidden Files (dotfiles)* is ticked (§ 2 step 4); right-click it and choose *Edit*. In Plesk *Files*, click it to edit.

With SSH, in the GoProperty folder:

```bash
php artisan goproperty:media-move
```

It asks first, then tests the bucket, sets `MEDIA_DISK=media-s3` in `.env` (so new uploads go to the bucket) and copies every file, checks each copy and points it at the bucket. You should see **Every file that can move has moved.** at the end. If it says instead that files are still to move (files changed in the last ten minutes wait for the next run), run it again ten minutes later. Running it again is always safe: it carries on where it stopped.

Without SSH: run `goproperty:media-move --force` as a one-time cron job ([install-recovery.md § 0](install-recovery.md#0-running-a-command-on-the-server)) and read what it printed. On cPanel the second minute's run prints **Another move is running.** while the first is still copying; that is expected. If the output does not end with **Every file that can move has moved.**, run it again ten minutes later.

Then open a building with photos in the workspace and check the photos show. The old copies stay in `storage/app/media` until you remove them: run the command again with `--delete-source` (`goproperty:media-move --force --delete-source` without SSH).

To move the files back to the hosting account: `php artisan goproperty:media-move media` (`goproperty:media-move media --force` without SSH). It sets `MEDIA_DISK=media` and works the same way.

Set `MEDIA_DISK` this way, not `FILESYSTEM_DISK=s3`: that setting also sends the browser's uploads straight to the bucket, which then needs CORS settings, and it does not move the files already uploaded.

### Step 8. Tidy up and finish setting up

1. Delete `goproperty-X.Y.Z.zip` from the hosting account (keep a copy on your computer). On cPanel, the `public_html-old` folder of § 2 step 4.7 can go too.
2. Work through the finish screen's **Next steps** (the command-line install lists them in [INSTALLER.md § 5](../../INSTALLER.md)): Paystack keys, GoText for SMS, Turnstile, the platform theme, Cloudflare. Then [shared-hosting.md § 5](shared-hosting.md): operations alerts and an outside uptime monitor.

## 7. When a step does not work

| What you see | Where to look |
| --- | --- |
| **GoProperty needs PHP 8.4.1 or newer; this server runs …** | [install-recovery.md § 2.1](install-recovery.md#21-the-page-says-goproperty-needs-php-841-or-newer) |
| The panel's own page, "Index of /" or "Not Found" at `/install` | [install-recovery.md § 2.2](install-recovery.md#22-the-panels-own-page-index-of-or-not-found-instead-of-the-installer) |
| The unlock file is not accepted, **Too many unlock attempts.** | [install-recovery.md § 2.3](install-recovery.md#23-the-unlock-file-is-not-accepted) |
| **Your installer session ended after …** | [install-recovery.md § 2.4](install-recovery.md#24-the-installer-session-ended) |
| **Migration … failed** | [install-recovery.md § 2.5](install-recovery.md#25-a-migration-failed) |
| **Install stopped** | [install-recovery.md § 2.6](install-recovery.md#26-install-stopped) |
| The finish screen keeps **Waiting for the first scheduler run…** | [install-recovery.md § 3](install-recovery.md#3-cron-does-not-run) |
| Lost the console address, the installation record, or your phone | [install-recovery.md § 5](install-recovery.md#5-lost-console-address-installation-record-or-authenticator-app) |
| "Bad Request" on every page | [install-recovery.md § 6](install-recovery.md#6-the-site-answers-bad-request) |

## 8. To check on the real install

Panel screens were written from current cPanel (Jupiter theme) and Plesk Obsidian without access to a real account. On the first real installs (§ 9), check these and correct the guide where they differ:

1. cPanel § 2 step 2.6: the *Server Information* place that names MySQL or MariaDB; Plesk § 3 step 2.3: where the database server's type shows.
2. cPanel *File Manager* *Move* dialog: whether `/` and `/public_html` are the paths it expects (they are relative to the home folder).
3. cPanel § 2 step 5: on a CloudLinux host, whether *Select PHP Version* alone sets PHP 8.4 for the domain, or *MultiPHP Manager* must also be left on its default; and whether moving GoProperty's `.htaccess` in before choosing PHP keeps cPanel's PHP lines in it.
4. cPanel § 4 step 5 and § 5 step 9: on a CloudLinux host with both PHP builds, the cron line should name the build the site runs on (`/opt/alt/php84/usr/bin/php` with *Select PHP Version*, `/opt/cpanel/ea-php84/root/usr/bin/php` with *MultiPHP Manager*). A command-line install takes the `php` it runs on, so check the line after setting the `alias` of § 4 step 1 item 3 ([install-recovery.md § 3](install-recovery.md#3-cron-does-not-run) if cron reports missing extensions).
5. Plesk § 3 steps 4–5: the menu names *Hosting Settings* / *Hosting & DNS* → *Hosting* and *PHP Settings* / *PHP* on the host's Plesk version.
6. Plesk § 3 step 3: the *Files* buttons (*+* → *Upload File*, *Extract Files*, *Move*, *Remove*).
7. Plesk § 4 step 5 and § 5 step 9: the *Script path* form *Run a PHP script* accepts (full path, as the finish screen shows it, or relative to the home folder like `goproperty/artisan`).
8. § 4 step 7: the console's first sign-in screens for setting up two-factor sign-in (Filament's own), seen here only in its language files.
9. Plesk: whether any page other than the home page answers "Not Found" (nginx without Apache ignores `.htaccess`; [install-recovery.md § 7](install-recovery.md#7-plesk-every-page-but-the-home-page-is-not-found)).
10. Every "You should see": the exact wording on screen.

## 9. The acceptance installs (P09-07)

P09-07 is complete when two real installs follow this guide word for word ([IMPLEMENTATION_PLAN.md § 3](../../IMPLEMENTATION_PLAN.md)): one on a cPanel account and one on a Plesk account, one with SSH and one without. Suggested pairing: cPanel without SSH (§ 1 → § 2 → § 5 → § 6), because most cPanel shared plans have no SSH, and Plesk with SSH (§ 1 → § 3 → § 4 → § 6). Use fresh accounts or fresh domains with empty databases; staging accounts from PRE-04 will do, then start them over ([install-recovery.md § 4](install-recovery.md#4-start-over-on-a-fresh-database)) or keep them.

For each install:

1. Note the date, host, panel and its version (cPanel: *General Information*; Plesk: the *Help* menu → *About Plesk*), PHP version, database server and version, and the GoProperty version.
2. Follow your row of the table in "How to use this guide", reading each step aloud as you do it. Do not use anything that is not written here.
3. Whenever the screen differs from the guide (a menu name, a button, a message, a missing step, a step that did not work), write down the section and step, what the guide says, what you saw, and what you did instead. A screenshot helps.
4. Go through § 8 and tick or correct each item.
5. Time the whole install.

Then send the notes to the developers (or record them in [docs/PROGRESS.md](../PROGRESS.md), P09-07's entry). They correct this guide (and the installer's own wording if a GoProperty screen was wrong); P09-07 is marked complete when both installs passed without a step that had to be guessed.
