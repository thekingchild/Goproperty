<?php
/*
 * Step 3, site & database (INSTALLER.md § 5).
 *
 * @var string $base
 * @var string|null $token
 * @var bool $configured
 * @var array<string, string> $values
 * @var array<string, mixed> $old
 * @var array<string, string> $errors
 * @var array<string, string> $status
 */
$value = fn (string $key): string => is_string($old[$key] ?? null) ? $old[$key] : ($values[$key] ?? '');
$selected = fn (string $key, string $option): string => $value($key) === $option ? ' selected' : '';
?>
<form class="form" method="post" action="<?= e($base) ?>/install/site">
    <input type="hidden" name="_token" value="<?= e((string) $token) ?>">
    <?php /* Enter in a field saves, rather than pressing "Test connection" (the first button). */ ?>
    <button type="submit" name="action" value="save" class="visually-hidden" tabindex="-1" aria-hidden="true">Save</button>
    <div class="heading">
        <h1>Site &amp; database</h1>
        <p>Where the site lives and the empty database it uses. Create the database and its user first (cPanel → MySQL Databases; Plesk → Databases).</p>
    </div>

    <?php foreach ($errors as $key => $message) { ?>
        <p class="alert" role="alert"><?= $key === 'probe' ? '<b>Test failed</b>' : '' ?><?= e($message) ?></p>
    <?php } ?>
    <?php foreach ($status as $message) { ?>
        <p class="alert alert--ok" role="status"><?= e($message) ?></p>
    <?php } ?>

    <section class="section" aria-labelledby="site-heading">
        <h2 id="site-heading">Site</h2>
        <div class="field">
            <label for="site_url">Site address</label>
            <input class="input" id="site_url" name="site_url" type="url" inputmode="url" required value="<?= e($value('site_url')) ?>" placeholder="https://goproperty.ng">
            <p class="hint">Just the address, starting with https://, with no folder after it.</p>
        </div>
        <div class="field">
            <label for="environment">This is</label>
            <select class="input" id="environment" name="environment">
                <option value="production"<?= $selected('environment', 'production') ?>>Production (the live site)</option>
                <option value="staging"<?= $selected('environment', 'staging') ?>>Staging (a test copy)</option>
            </select>
        </div>
    </section>

    <section class="section" aria-labelledby="database-heading">
        <h2 id="database-heading">Database</h2>
        <div class="field">
            <label for="db_driver">Database type</label>
            <select class="input" id="db_driver" name="db_driver">
                <option value="mysql"<?= $selected('db_driver', 'mysql') ?>>MySQL 8</option>
                <option value="mariadb"<?= $selected('db_driver', 'mariadb') ?>>MariaDB 10.6 or newer</option>
            </select>
        </div>
        <div class="grid-2">
            <div class="field">
                <label for="db_host">Host</label>
                <input class="input" id="db_host" name="db_host" required value="<?= e($value('db_host')) ?>" autocomplete="off">
            </div>
            <div class="field">
                <label for="db_port">Port</label>
                <input class="input" id="db_port" name="db_port" inputmode="numeric" required value="<?= e($value('db_port')) ?>">
            </div>
        </div>
        <div class="field">
            <label for="db_name">Database name</label>
            <input class="input" id="db_name" name="db_name" required value="<?= e($value('db_name')) ?>" placeholder="cpaneluser_goproperty" autocomplete="off">
        </div>
        <div class="field">
            <label for="db_user">Username</label>
            <input class="input" id="db_user" name="db_user" required value="<?= e($value('db_user')) ?>" placeholder="cpaneluser_gp" autocomplete="off">
        </div>
        <div class="field">
            <label for="db_password">Password</label>
            <input class="input" id="db_password" name="db_password" type="password" autocomplete="new-password"<?= $configured ? ' placeholder="Unchanged (leave empty to keep it)"' : '' ?>>
            <p class="hint">Never shown again after you save.</p>
        </div>
        <button type="submit" name="action" value="test" class="btn btn-secondary" formnovalidate>Test connection</button>
    </section>

    <button type="submit" name="action" value="save" class="btn btn-primary btn-wide">Save and continue</button>
</form>
