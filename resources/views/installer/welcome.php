<?php
/*
 * The installer's first screen (INSTALLER.md § 4). Plain PHP and the static
 * install.css: it renders before APP_KEY exists, before storage/ is known to
 * be writable and without the Vite manifest. Public: shows pass/fail per
 * group only, never paths or values. The unlock forms arrive in P01-09.
 *
 * @var string $name
 * @var string $version
 * @var array<string, bool> $summary
 * @var bool $ready
 * @var string $stylesheet
 */
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Install <?= e($name) ?></title>
    <link rel="stylesheet" href="<?= e($stylesheet) ?>">
</head>
<body>
    <main class="card">
        <p class="eyebrow">Version <?= e($version) ?></p>
        <h1>Install <?= e($name) ?></h1>

        <h2>Server check</h2>
        <ul class="checks">
            <?php foreach ($summary as $group => $ok) { ?>
                <li class="<?= $ok ? 'pass' : 'fail' ?>">
                    <span class="mark" aria-hidden="true"><?= $ok ? '✓' : '✗' ?></span>
                    <?= e($group) ?>
                    <span class="visually-hidden"><?= $ok ? 'passed' : 'needs attention' ?></span>
                </li>
            <?php } ?>
        </ul>

        <?php if ($ready) { ?>
            <p>This server meets the requirements.</p>
        <?php } else { ?>
            <p>Some checks need attention. The detailed report, with how to fix each item in cPanel or Plesk, appears once the installer is unlocked.</p>
        <?php } ?>

        <p class="note">Unlocking the installer is not part of this build yet.</p>
    </main>
</body>
</html>
