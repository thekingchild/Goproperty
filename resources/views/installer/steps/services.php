<?php
/*
 * Step 5, email & file storage (INSTALLER.md § 5). Each is tested or
 * explicitly skipped.
 *
 * @var string $base
 * @var string|null $token
 * @var array<string, array{label: string, host: string}> $providers
 * @var string|null $mail     tested, skipped or null
 * @var string|null $storage  tested, skipped or null
 * @var bool $done
 * @var array<string, string> $values
 * @var array<string, mixed> $old
 * @var array<string, string> $errors
 * @var array<string, string> $status
 */
$value = fn (string $key): string => is_string($old[$key] ?? null) ? $old[$key] : ($values[$key] ?? '');
$badge = fn (?string $state): string => match ($state) {
    'tested' => '<span class="status status--pass">✓ Tested</span>',
    'skipped' => '<span class="status status--wait">Skipped</span>',
    default => '',
};
?>
<div class="form">
    <div class="heading">
        <h1>Email &amp; file storage</h1>
        <p>Test both now, so you know reminders, receipts and uploads will work. You can skip either and set it up later; the finish screen will remind you.</p>
    </div>

    <form class="section" method="post" action="<?= e($base) ?>/install/services/mail" aria-labelledby="mail-heading">
        <input type="hidden" name="_token" value="<?= e((string) $token) ?>">
        <h2 id="mail-heading">Outgoing email <?= $badge($mail) ?></h2>
        <p>Use an email service's SMTP settings; the host's own sendmail is not used.</p>
        <?php if (isset($errors['mail'])) { ?><p class="alert" role="alert"><?= e($errors['mail']) ?></p><?php } ?>
        <?php if (isset($status['mail'])) { ?><p class="alert alert--ok" role="status"><?= e($status['mail']) ?></p><?php } ?>
        <div class="field">
            <label for="mail_provider">Provider</label>
            <select class="input" id="mail_provider" name="mail_provider">
                <?php foreach ($providers as $key => $provider) { ?>
                    <option value="<?= e($key) ?>"<?= $value('mail_provider') === $key ? ' selected' : '' ?>><?= e($provider['label']) ?><?= $provider['host'] !== '' ? ' ('.e($provider['host']).')' : '' ?></option>
                <?php } ?>
            </select>
        </div>
        <div class="grid-2">
            <div class="field">
                <label for="mail_host">SMTP host</label>
                <input class="input" id="mail_host" name="mail_host" value="<?= e($value('mail_host')) ?>" placeholder="Leave empty for the provider's" autocomplete="off">
            </div>
            <div class="field">
                <label for="mail_port">Port</label>
                <input class="input" id="mail_port" name="mail_port" inputmode="numeric" value="<?= e($value('mail_port')) ?>">
            </div>
        </div>
        <div class="field">
            <label for="mail_username">SMTP username</label>
            <input class="input" id="mail_username" name="mail_username" value="<?= e($value('mail_username')) ?>" autocomplete="off">
        </div>
        <div class="field">
            <label for="mail_password">SMTP password or API token</label>
            <input class="input" id="mail_password" name="mail_password" type="password" autocomplete="new-password">
        </div>
        <div class="field">
            <label for="mail_from">Send emails from</label>
            <input class="input" id="mail_from" name="mail_from" type="email" value="<?= e($value('mail_from')) ?>" placeholder="no-reply@goproperty.ng">
        </div>
        <div class="field">
            <label for="mail_test">Send the test email to</label>
            <input class="input" id="mail_test" name="mail_test" type="email" value="<?= e($value('mail_test')) ?>" placeholder="you@goproperty.ng">
        </div>
        <div class="actions">
            <button type="submit" name="action" value="test" class="btn btn-primary">Save and send a test email</button>
            <button type="submit" name="action" value="skip" class="btn btn-secondary" formnovalidate>Skip for now</button>
        </div>
    </form>

    <form class="section" method="post" action="<?= e($base) ?>/install/services/storage" aria-labelledby="storage-heading">
        <input type="hidden" name="_token" value="<?= e((string) $token) ?>">
        <h2 id="storage-heading">File storage (S3-compatible) <?= $badge($storage) ?></h2>
        <p>Payment proofs, documents and photos are kept in a bucket: AWS S3, Cloudflare R2, DigitalOcean Spaces, Wasabi or another S3-compatible service.</p>
        <?php if (isset($errors['storage'])) { ?><p class="alert" role="alert"><?= e($errors['storage']) ?></p><?php } ?>
        <?php if (isset($status['storage'])) { ?><p class="alert alert--ok" role="status"><?= e($status['storage']) ?></p><?php } ?>
        <div class="field">
            <label for="storage_endpoint">Endpoint</label>
            <input class="input" id="storage_endpoint" name="storage_endpoint" type="url" value="<?= e($value('storage_endpoint')) ?>" placeholder="https://<account>.r2.cloudflarestorage.com">
            <p class="hint">Leave empty for AWS S3.</p>
        </div>
        <div class="grid-2">
            <div class="field">
                <label for="storage_bucket">Bucket</label>
                <input class="input" id="storage_bucket" name="storage_bucket" value="<?= e($value('storage_bucket')) ?>" autocomplete="off">
            </div>
            <div class="field">
                <label for="storage_region">Region</label>
                <input class="input" id="storage_region" name="storage_region" value="<?= e($value('storage_region')) ?>">
            </div>
        </div>
        <div class="field">
            <label for="storage_key">Access key ID</label>
            <input class="input" id="storage_key" name="storage_key" value="<?= e($value('storage_key')) ?>" autocomplete="off" spellcheck="false">
        </div>
        <div class="field">
            <label for="storage_secret">Secret access key</label>
            <input class="input" id="storage_secret" name="storage_secret" type="password" autocomplete="new-password">
        </div>
        <label class="check"><input type="checkbox" name="storage_path_style" value="1"<?= ($old['storage_path_style'] ?? '') === '1' ? ' checked' : '' ?>> Use path-style addresses (MinIO and some other services need this)</label>
        <div class="actions">
            <button type="submit" name="action" value="test" class="btn btn-primary">Save and test</button>
            <button type="submit" name="action" value="skip" class="btn btn-secondary" formnovalidate>Skip for now</button>
        </div>
    </form>

    <?php if ($done) { ?>
        <a class="btn btn-primary btn-wide" href="<?= e($base) ?>/install/admin">Continue</a>
    <?php } ?>
</div>
