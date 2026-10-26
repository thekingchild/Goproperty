<?php
/*
 * Step 6c, recovery codes (INSTALLER.md § 5): shown once, with Download
 * and Copy, and a required "I've saved my recovery codes" tick.
 *
 * @var string $base
 * @var string|null $token
 * @var list<string> $codes
 * @var array<string, string> $errors
 */
$text = "GoProperty console recovery codes\nEach code signs you in once if you lose your phone.\n\n".implode("\n", $codes)."\n";
?>
<form class="form" method="post" action="<?= e($base) ?>/install/admin/recovery-codes">
    <input type="hidden" name="_token" value="<?= e((string) $token) ?>">
    <div class="heading">
        <h1>Save your recovery codes</h1>
        <p>If you lose your phone, each code signs you in once instead of the authenticator app. They are shown only now.</p>
    </div>

    <?php foreach ($errors as $message) { ?>
        <p class="alert" role="alert"><?= e($message) ?></p>
    <?php } ?>

    <ul class="recovery" id="recovery-codes" aria-label="Recovery codes">
        <?php foreach ($codes as $code) { ?>
            <li><?= e($code) ?></li>
        <?php } ?>
    </ul>

    <div class="actions">
        <a class="btn btn-secondary" download="goproperty-recovery-codes.txt" href="data:text/plain;charset=utf-8;base64,<?= base64_encode($text) ?>">Download</a>
        <button type="button" class="btn btn-secondary" data-copy hidden>Copy</button>
    </div>

    <label class="check"><input type="checkbox" name="saved" value="1" required> I've saved my recovery codes</label>
    <button type="submit" class="btn btn-primary btn-wide">Continue</button>
</form>
<script>
    (function () {
        var button = document.querySelector('[data-copy]');
        if (!button || !navigator.clipboard) { return; }
        button.hidden = false;
        button.addEventListener('click', function () {
            navigator.clipboard.writeText(<?= json_encode($text, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>).then(function () { button.textContent = 'Copied'; });
        });
    })();
</script>
