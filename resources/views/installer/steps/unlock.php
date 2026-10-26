<?php
/*
 * Step 1, unlock (INSTALLER.md § 4). Public: shows pass/fail per group of
 * the server check only, never paths or values. Two ways to prove control
 * of the hosting account: a setup token printed over SSH, or a file created
 * in File Manager. Rendered before APP_KEY exists, so there is no session
 * and no CSRF token here; the unlock posts are checked for same origin.
 *
 * @var string $name
 * @var string $base
 * @var string $version
 * @var array<string, bool> $summary
 * @var bool $ready
 * @var string|null $phrase
 * @var int $minutes
 * @var string|null $error
 * @var string|null $notice
 * @var bool $insecure
 * @var string $unlockFile
 */
?>
<div class="form">
    <div class="heading">
        <p class="eyebrow">Version <?= e($version) ?></p>
        <h1>Install <?= e($name) ?></h1>
        <p>First, prove you control this hosting account. Only its owner can unlock the installer.</p>
    </div>

    <?php if ($error !== null) { ?>
        <p class="alert" role="alert"><?= e($error) ?></p>
    <?php } ?>
    <?php if ($notice !== null) { ?>
        <p class="alert alert--info" role="status"><?= e($notice) ?></p>
    <?php } ?>
    <?php if ($insecure) { ?>
        <div class="alert alert--warn">
            <b>Turn on HTTPS first</b>
            This page was opened without https://, so unlocking is refused. In cPanel open SSL/TLS Status and run AutoSSL for the domain; in Plesk open SSL/TLS Certificates and install a free Let's Encrypt certificate. Then open this page with https://.
        </div>
    <?php } ?>

    <section class="section" aria-labelledby="server-check">
        <h2 id="server-check">Server check</h2>
        <ul class="checks">
            <?php foreach ($summary as $group => $ok) { ?>
                <li class="<?= $ok ? 'pass' : 'fail' ?>">
                    <span class="mark" aria-hidden="true"><?= $ok ? '✓' : '✗' ?></span>
                    <span><?= e($group) ?> <span class="visually-hidden"><?= $ok ? 'passed' : 'needs attention' ?></span></span>
                </li>
            <?php } ?>
        </ul>
        <?php if ($ready) { ?>
            <p class="note">This server meets the requirements.</p>
        <?php } else { ?>
            <p class="note">Some checks need attention. The detailed report, with how to fix each item in cPanel or Plesk, appears once the installer is unlocked.<?php if (! ($summary['Folders'] ?? true)) { ?> If the installer cannot be unlocked, first make the <code>storage</code> and <code>bootstrap/cache</code> folders writable (File Manager → Change Permissions → 755).<?php } ?></p>
        <?php } ?>
    </section>

    <section class="section" aria-labelledby="unlock-file">
        <h2 id="unlock-file">Unlock with a file (no SSH)</h2>
        <?php if ($phrase !== null) { ?>
            <ol class="howto">
                <li>In cPanel File Manager or Plesk Files, open the GoProperty folder (<code>goproperty</code> or <code>goproperty-app</code>), then <code>storage/app</code>.</li>
                <li>Create a file named <code>install-unlock.txt</code> there, containing exactly:
                    <code class="phrase" id="unlock-phrase"><?= e($phrase) ?></code>
                </li>
                <li>Save it, then press the button below within <?= $minutes ?> minutes.</li>
            </ol>
            <form method="post" action="<?= e($base) ?>/install/unlock/file">
                <button type="submit" class="btn btn-primary btn-wide">I've created the file</button>
            </form>
        <?php } else { ?>
            <p class="alert">The installer cannot write in <code>storage/app/private</code>, so it cannot show an unlock phrase. Make the <code>storage</code> folder writable (File Manager → Change Permissions → 755), then reload this page.</p>
        <?php } ?>
    </section>

    <section class="section" aria-labelledby="unlock-token">
        <h2 id="unlock-token">Unlock with a setup token (SSH)</h2>
        <p>On the server, in the GoProperty folder, run <code>php artisan goproperty:setup-token</code> and paste the token it prints.</p>
        <form method="post" action="<?= e($base) ?>/install/unlock/token" class="stack">
            <div class="field">
                <label for="token">Setup token</label>
                <input class="input" id="token" name="token" type="password" autocomplete="off" spellcheck="false" minlength="40" maxlength="40" required>
            </div>
            <button type="submit" class="btn btn-secondary btn-wide">Unlock</button>
        </form>
    </section>

    <p class="note">Already unlocked in this browser? <a href="<?= e($base) ?>/install/resume">Continue the install</a>.</p>
</div>
