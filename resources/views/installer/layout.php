<?php
/*
 * The installer's split shell (INSTALLER.md § 5, UX_DESIGN.md § 3): brand
 * panel with the step list on the left (✓ for done, aria-current="step"),
 * the step's form on the right. Plain PHP and the static install.css: it
 * renders before APP_KEY exists, before storage/ is known to be writable
 * and without the Vite manifest. noindex.
 *
 * @var string $name
 * @var string $base
 * @var string $title
 * @var string $version
 * @var string $stylesheet
 * @var list<array{label: string, number: int, state: string, url: string|null}> $steps
 * @var \App\Installer\Web\WizardStep $step
 * @var string $content  rendered step HTML
 */
?><!DOCTYPE html>
<html lang="en-NG">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> · Install <?= e($name) ?></title>
    <link rel="stylesheet" href="<?= e($stylesheet) ?>">
</head>
<body class="installer">
<a class="skip-link" href="#main">Skip to content</a>
<div class="shell">
    <aside class="panel" aria-label="Installation progress">
        <div class="panel__art" aria-hidden="true"><img src="<?= e($base) ?>/images/skyline.svg" alt=""></div>
        <span class="brand">
            <svg viewBox="0 0 34 34" aria-hidden="true">
                <rect width="34" height="34" rx="9" fill="#fff"/>
                <path d="M9 16.2 17 10l8 6.2V25a1 1 0 0 1-1 1h-4.5v-5.5h-5V26H10a1 1 0 0 1-1-1z" fill="#1d4ed8"/>
                <circle cx="24.5" cy="10.5" r="3" fill="#f59e0b"/>
            </svg>
            <span><?= e($name) ?></span>
        </span>
        <span class="panel__step">Step <?= $step->number() ?> of <?= count($steps) ?>: <?= e($step->label()) ?></span>

        <div class="panel__body">
            <div>
                <p class="panel__title">Install <span><?= e($name) ?></span></p>
                <p class="panel__version">Version <?= e($version) ?></p>
            </div>
            <nav aria-label="Installation steps">
                <ol class="steps">
                    <?php foreach ($steps as $item) { ?>
                        <li class="is-<?= e($item['state']) ?>"<?= $item['state'] === 'current' ? ' aria-current="step"' : '' ?>>
                            <span class="steps__mark" aria-hidden="true"><?= $item['state'] === 'done' ? '✓' : $item['number'] ?></span>
                            <?php if ($item['url'] !== null && $item['state'] !== 'current') { ?>
                                <a href="<?= e($item['url']) ?>"><?= e($item['label']) ?></a>
                            <?php } else { ?>
                                <span><?= e($item['label']) ?></span>
                            <?php } ?>
                            <?php if ($item['state'] === 'done') { ?><span class="visually-hidden">(done)</span><?php } ?>
                        </li>
                    <?php } ?>
                </ol>
            </nav>
            <p class="trustline">Only someone who controls this hosting account can unlock the installer. Once finished, it closes for good.</p>
        </div>
    </aside>

    <main id="main" class="main" tabindex="-1">
        <div class="main__center">
            <?= $content ?>
        </div>
        <footer class="foot">© <?= date('Y') ?> <?= e($name) ?> · Installer</footer>
    </main>
</div>
</body>
</html>
