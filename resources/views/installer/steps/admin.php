<?php
/*
 * Step 6a, the first super-admin's account (INSTALLER.md § 5).
 *
 * @var string $base
 * @var string|null $token
 * @var bool $ready
 * @var array<string, mixed> $old
 * @var array<string, string> $errors
 * @var array<string, string> $status
 */
$value = fn (string $key): string => is_string($old[$key] ?? null) ? $old[$key] : '';
$invalid = fn (string $key): string => isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="'.$key.'-error"' : '';
$error = fn (string $key): string => isset($errors[$key]) ? '<p class="error" id="'.e($key).'-error">'.e($errors[$key]).'</p>' : '';
?>
<form class="form" method="post" action="<?= e($base) ?>/install/admin">
    <input type="hidden" name="_token" value="<?= e((string) $token) ?>">
    <div class="heading">
        <h1>First super-admin</h1>
        <p>Your own staff account for the console, with every permission. Next you will set up an authenticator app for it.</p>
    </div>

    <?php foreach ($status as $message) { ?>
        <p class="alert alert--ok" role="status"><?= e($message) ?></p>
    <?php } ?>
    <?php if (isset($errors['form'])) { ?>
        <p class="alert" role="alert"><?= e($errors['form']) ?></p>
    <?php } ?>
    <?php if ($ready) { ?>
        <p class="alert alert--info" role="status">The first super-admin and their authenticator app are set up. <a href="<?= e($base) ?>/install/finish">Continue to the last step</a>.</p>
    <?php } ?>

    <div class="field">
        <label for="name">Full name</label>
        <input class="input" id="name" name="name" required autocomplete="name" value="<?= e($value('name')) ?>"<?= $invalid('name') ?>>
        <?= $error('name') ?>
    </div>
    <div class="field">
        <label for="email">Work email</label>
        <input class="input" id="email" name="email" type="email" required autocomplete="username" value="<?= e($value('email')) ?>"<?= $invalid('email') ?>>
        <?= $error('email') ?>
    </div>
    <div class="field">
        <label for="password">Password</label>
        <input class="input" id="password" name="password" type="password" required minlength="12" autocomplete="new-password"<?= $invalid('password') ?>>
        <p class="hint">12 characters or more. It is checked against known data leaks (only a short hash prefix leaves this server).</p>
        <?= $error('password') ?>
    </div>
    <div class="field">
        <label for="password_confirmation">Type the password again</label>
        <input class="input" id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"<?= $invalid('password_confirmation') ?>>
        <?= $error('password_confirmation') ?>
    </div>
    <p class="hint">Continuing an install that stopped? Enter the same email and password as before.</p>
    <button type="submit" class="btn btn-primary btn-wide">Create the account</button>
</form>
