<?php
/*
 * The finish screen (INSTALLER.md § 5), shown once: the reply to the finish
 * post. Console address, the cron line for this server with a live check
 * for the scheduler's first run, the installation record (download once),
 * and next steps linking into the console.
 *
 * @var string $base
 * @var string $console
 * @var bool $signedIn
 * @var string|null $heartbeat
 * @var string $cronLine
 * @var string $cronCommand
 * @var string $record
 * @var array{mail: string, storage: string} $services
 * @var bool $debugOff
 * @var list<string> $warnings
 */
$next = [];

if ($services['mail'] !== 'tested') {
    $next[] = ['Set up outgoing email: put an email service\'s SMTP settings (MAIL_*) in .env, then send a test from the console.', null];
}

if ($services['storage'] !== 'tested') {
    $next[] = ['Set up file storage: put the bucket\'s settings (AWS_*) in .env. Uploads need it.', null];
}

$next[] = ['Paystack keys for your own subscription income (Console → Settings, with billing).', $console];
$next[] = ['GoText API token and sender ID for SMS notifications (Console → Settings, with notifications).', $console];
$next[] = ['Turnstile keys for bot protection on sign-in pages (Console → Settings → Sign-in security).', $console.'/settings/sign-in-security'];
$next[] = ['Backup destinations: an S3 bucket and a Google Shared Drive (php artisan goproperty:backup-destination:add, see docs/runbooks/restore.md).', null];
$next[] = ['Platform theme: logo, colours and sign-in image (Console → Settings → Brand & appearance).', $console.'/settings/brand'];
$next[] = ['Cloudflare in front of the site: proxied DNS and SSL mode "Full (strict)" (docs/runbooks/shared-hosting.md).', null];
?>
<div class="form">
    <div class="heading">
        <h1>GoProperty is installed</h1>
        <p>The installer is closed for good. <?= $signedIn ? 'You are signed in to the console.' : 'Sign in to the console with your email, password and authenticator app.' ?></p>
    </div>

    <?php foreach ($warnings as $warning) { ?>
        <p class="alert alert--warn"><?= e($warning) ?></p>
    <?php } ?>

    <section class="section" aria-labelledby="console-heading">
        <h2 id="console-heading">Console address</h2>
        <code class="phrase" id="console-url"><?= e($console) ?></code>
        <p class="note"><b>Bookmark this — it isn't linked anywhere.</b> If you lose it, it is <code>ADMIN_PATH</code> in <code>.env</code>, and in the installation record.</p>
        <a class="btn btn-primary" href="<?= e($console) ?>">Open the console</a>
    </section>

    <section class="section" aria-labelledby="cron-heading">
        <h2 id="cron-heading">Cron job</h2>
        <p>Reminders, bills, backups and emails run from one cron job, every minute. The line for this server:</p>
        <code class="codeblock" id="cron-line"><?= e($cronLine) ?></code>
        <details>
            <summary>Where to add it</summary>
            <ol class="howto">
                <li><b>cPanel:</b> Advanced → Cron Jobs → Common Settings: "Once Per Minute (* * * * *)", then paste the command: <code><?= e($cronCommand) ?></code></li>
                <li><b>Plesk:</b> Websites &amp; Domains → Scheduled Tasks → Add Task → "Run a command", "Cron style" <code>* * * * *</code>, then the same command.</li>
            </ol>
        </details>
        <p id="cron-status" role="status" aria-live="polite">
            <span class="status status--wait" data-cron-state>Waiting for the first scheduler run…</span>
        </p>
        <?php if ($heartbeat === null) { ?>
            <p class="note">Check it later in the console, or with <code>php artisan goproperty:check-installation</code>.</p>
        <?php } ?>
    </section>

    <section class="section" aria-labelledby="record-heading">
        <h2 id="record-heading">Installation record</h2>
        <p>Version, date, site address, console path, database name and your <b>APP_KEY</b>. Store it offline in two places: without the key, encrypted data cannot be read after a restore. This is the only time it is offered.</p>
        <a class="btn btn-secondary" id="record" download="goproperty-installation-record.txt" href="data:text/plain;charset=utf-8;base64,<?= base64_encode($record) ?>">Download the installation record</a>
    </section>

    <section class="section" aria-labelledby="next-heading">
        <h2 id="next-heading">Next steps</h2>
        <ul class="checks">
            <li class="<?= $debugOff ? 'pass' : 'fail' ?>"><span class="mark" aria-hidden="true"><?= $debugOff ? '✓' : '✗' ?></span><span>Debug pages are <?= $debugOff ? 'off' : 'ON: set APP_DEBUG=false in .env' ?> (APP_DEBUG=false).</span></li>
            <?php foreach ($next as [$text, $link]) { ?>
                <li><span class="mark" aria-hidden="true">○</span><span><?php if ($link !== null) { ?><a href="<?= e($link) ?>"><?= e($text) ?></a><?php } else { ?><?= e($text) ?><?php } ?></span></li>
            <?php } ?>
        </ul>
    </section>
</div>
<?php if ($heartbeat !== null) { ?>
<script>
    // Live cron check (INSTALLER.md § 5): turns green when the scheduler's
    // heartbeat arrives; asks the console every 5 seconds.
    (function () {
        var state = document.querySelector('[data-cron-state]');
        var url = <?= json_encode($heartbeat, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
        var timer = window.setInterval(check, 5000);

        function check() {
            fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (response) { return response.ok ? response.json() : Promise.reject(response.status); })
                .then(function (data) {
                    if (data && data.scheduler && data.scheduler.fresh) {
                        window.clearInterval(timer);
                        state.className = 'status status--pass';
                        state.textContent = '✓ Cron is working: the scheduler ran at ' + new Date(data.scheduler.at).toLocaleTimeString() + '.';
                    }
                })
                .catch(function () {
                    window.clearInterval(timer);
                    state.className = 'status status--fail';
                    state.textContent = 'Cannot check from here. Sign in to the console, or run php artisan goproperty:check-installation.';
                });
        }

        check();
    })();
</script>
<?php } ?>
