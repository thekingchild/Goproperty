<?php
/*
 * Plain PHP, not Blade: shown before the install finishes, when storage/ may
 * not be writable yet for compiled views (INSTALLER.md § 3).
 *
 * @var string $name
 * @var string $stylesheet
 * @var bool $local
 */
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($name) ?> is being set up</title>
    <link rel="stylesheet" href="<?= e($stylesheet) ?>">
</head>
<body>
    <main class="card">
        <h1><?= e($name) ?> is being set up</h1>
        <p>This site is not open yet. Please check back soon.</p>
        <?php if ($local) { ?>
            <p class="note">Local development: run <code>php artisan migrate --seed</code> to mark this copy as installed.</p>
        <?php } ?>
    </main>
</body>
</html>
