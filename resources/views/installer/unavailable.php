<?php
/*
 * Plain PHP: shown when the install is finished but its database marker
 * cannot be read (INSTALLER.md § 3), so it must not need the database.
 *
 * @var string $name
 * @var string $stylesheet
 */
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($name) ?> is temporarily unavailable</title>
    <link rel="stylesheet" href="<?= e($stylesheet) ?>">
</head>
<body>
    <main class="card">
        <h1><?= e($name) ?> is temporarily unavailable</h1>
        <p>Please try again in a few minutes.</p>
    </main>
</body>
</html>
