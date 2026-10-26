<?php
/*
 * Step 4, build the database (INSTALLER.md § 5–6). While migrations are
 * pending the page posts the next batch by itself; without JavaScript the
 * owner presses the button.
 *
 * @var string $base
 * @var string|null $token
 * @var int $done
 * @var int $total
 * @var bool $finished
 * @var array{migration: string, error: string}|null $failed
 * @var string|null $unreachable
 * @var string $database
 * @var array<string, string> $errors
 * @var array<string, string> $status
 */
$auto = ! $finished && $failed === null && $unreachable === null && $errors === [] && $done > 0;
?>
<div class="form">
    <div class="heading">
        <h1>Build the database</h1>
        <p>Creates GoProperty's tables a few at a time, then adds the default data. You can close this tab at any time: opening the installer again carries on from here.</p>
    </div>

    <?php if ($unreachable !== null) { ?>
        <p class="alert" role="alert"><?= e($unreachable) ?></p>
    <?php } ?>
    <?php foreach ($errors as $message) { ?>
        <p class="alert" role="alert"><?= e($message) ?></p>
    <?php } ?>
    <?php foreach ($status as $message) { ?>
        <p class="alert alert--ok" role="status"><?= e($message) ?></p>
    <?php } ?>

    <section class="section" aria-labelledby="progress-heading">
        <h2 id="progress-heading">Progress</h2>
        <progress id="migrations" max="<?= max(1, $total) ?>" value="<?= $done ?>" aria-describedby="progress-label"><?= $done ?> of <?= $total ?></progress>
        <p id="progress-label" role="status" aria-live="polite"><b data-progress><?= $done ?> of <?= $total ?></b> database changes done<?= $finished ? '.' : '…' ?></p>
    </section>

    <?php if ($failed !== null) { ?>
        <div class="alert" role="alert">
            <b>Migration <?= e($failed['migration']) ?> failed</b>
            <?= e($failed['error']) ?>
        </div>
        <section class="section" aria-labelledby="reset-heading">
            <h2 id="reset-heading">Empty the database and start this step again</h2>
            <p>MySQL cannot undo a half-made table change. On a brand-new install with no data the safe fix is to drop GoProperty's own tables and build again. Other tables in this database are never touched, and nothing is dropped once an organization exists.</p>
            <form method="post" action="<?= e($base) ?>/install/database/reset" class="stack">
                <input type="hidden" name="_token" value="<?= e((string) $token) ?>">
                <div class="field">
                    <label for="confirm_database">Type the database name, <code><?= e($database) ?></code>, to confirm</label>
                    <input class="input" id="confirm_database" name="confirm_database" required autocomplete="off" spellcheck="false">
                </div>
                <button type="submit" class="btn btn-danger">Empty the database</button>
            </form>
        </section>
    <?php } ?>

    <form method="post" action="<?= e($base) ?>/install/database" id="batch">
        <input type="hidden" name="_token" value="<?= e((string) $token) ?>">
        <button type="submit" class="btn btn-primary btn-wide">
            <?= $finished ? 'Add the default data and continue' : ($done === 0 ? 'Build the database' : ($failed !== null ? 'Try this step again' : 'Continue building')) ?>
        </button>
    </form>
</div>
<?php if ($auto) { ?>
<script>
    // Carries on with the next batch (one request each, INSTALLER.md § 5).
    window.setTimeout(function () { document.getElementById('batch').submit(); }, 250);
</script>
<?php } ?>
