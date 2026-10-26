<?php
/*
 * Step 7, before finishing (INSTALLER.md § 5).
 *
 * @var string $base
 * @var string|null $token
 * @var array{mail: string, storage: string} $services
 * @var array<string, string> $errors
 * @var array<string, string> $status
 */
?>
<form class="form" method="post" action="<?= e($base) ?>/install/finish">
    <input type="hidden" name="_token" value="<?= e((string) $token) ?>">
    <div class="heading">
        <h1>Finish</h1>
        <p>Everything is in place. Finishing gives the console its own secret address, closes this installer for good and signs you in to the console.</p>
    </div>

    <?php foreach ($status as $message) { ?>
        <p class="alert alert--ok" role="status"><?= e($message) ?></p>
    <?php } ?>
    <?php foreach ($errors as $message) { ?>
        <p class="alert" role="alert"><?= e($message) ?></p>
    <?php } ?>
    <?php if ($services['mail'] === 'skipped' || $services['storage'] === 'skipped') { ?>
        <p class="alert alert--warn">You skipped <?= e(implode(' and ', array_keys(array_filter(['email' => $services['mail'] === 'skipped', 'file storage' => $services['storage'] === 'skipped'])))) ?>. The next screen says how to set it up later.</p>
    <?php } ?>

    <div class="alert alert--info">
        <b>The next screen is shown once</b>
        It has the console address (not linked anywhere), the cron job for this server and the installation record with your APP_KEY. Keep it open until you have saved them.
    </div>

    <button type="submit" class="btn btn-primary btn-wide">Finish the installation</button>
</form>
