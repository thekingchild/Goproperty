<?php
/*
 * Step 6b, the authenticator app (INSTALLER.md § 5): QR code and manual
 * key, confirmed with a 6-digit code.
 *
 * @var string $base
 * @var string|null $token
 * @var string $email
 * @var string $secret
 * @var string $qr   data: URI
 * @var string $uri  otpauth:// address
 * @var array<string, string> $errors
 */
?>
<form class="form" method="post" action="<?= e($base) ?>/install/admin/two-factor">
    <input type="hidden" name="_token" value="<?= e((string) $token) ?>">
    <div class="heading">
        <h1>Set up your authenticator app</h1>
        <p>The console always asks for a 6-digit code from an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password, Authy…) after the password of <b><?= e($email) ?></b>.</p>
    </div>

    <?php foreach ($errors as $message) { ?>
        <p class="alert" role="alert" id="code-error"><?= e($message) ?></p>
    <?php } ?>

    <ol class="howto">
        <li>In the app, add an account and scan this QR code:
            <span class="qr"><img src="<?= e($qr) ?>" alt="QR code for your authenticator app" width="200" height="200"></span>
        </li>
        <li>Can't scan it? Choose "enter a setup key" and type:
            <code class="codeblock" data-key="<?= e($secret) ?>"><?= e(trim(chunk_split($secret, 4, ' '))) ?></code>
            <span class="hint">Or open <a href="<?= e($uri) ?>">this link</a> on the phone itself.</span>
        </li>
        <li>Type the 6-digit code the app shows now.</li>
    </ol>

    <div class="field">
        <label for="code">6-digit code</label>
        <input class="input input--code" id="code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required<?= $errors !== [] ? ' aria-invalid="true" aria-describedby="code-error"' : '' ?>>
    </div>
    <button type="submit" class="btn btn-primary btn-wide">Confirm</button>
</form>
