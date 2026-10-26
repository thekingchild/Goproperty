<?php
/*
 * Step 2, server check (INSTALLER.md § 5).
 *
 * @var string $base
 * @var string|null $token
 * @var array<string, list<array{label: string, status: string, detail: string, cpanel: string|null, plesk: string|null}>> $groups
 * @var bool $passed
 * @var int $failures
 * @var int $warnings
 * @var array<string, string> $errors
 */
$marks = ['pass' => '✓', 'warn' => '!', 'fail' => '✗'];
$words = ['pass' => 'passed', 'warn' => 'advice', 'fail' => 'must be fixed'];
?>
<div class="form">
    <div class="heading">
        <h1>Server check</h1>
        <p><?= $passed ? 'This server meets every requirement.' : e($failures.' '.($failures === 1 ? 'item needs' : 'items need').' fixing before the install can go on.') ?>
            <?= $warnings > 0 ? e(' '.$warnings.' '.($warnings === 1 ? 'piece' : 'pieces').' of advice below.') : '' ?></p>
    </div>

    <?php foreach ($errors as $message) { ?>
        <p class="alert" role="alert"><?= e($message) ?></p>
    <?php } ?>

    <?php foreach ($groups as $group => $checks) { ?>
        <?php
        $problems = array_values(array_filter($checks, fn (array $check): bool => $check['status'] !== 'pass'));
        $passes = array_values(array_filter($checks, fn (array $check): bool => $check['status'] === 'pass'));
        $id = 'group-'.strtolower($group);
        ?>
        <section class="section" aria-labelledby="<?= e($id) ?>">
            <h2 id="<?= e($id) ?>"><?= e($group) ?></h2>
            <?php if ($problems !== []) { ?>
                <ul class="checks">
                    <?php foreach ($problems as $check) { ?>
                        <li class="<?= e($check['status']) ?>">
                            <span class="mark" aria-hidden="true"><?= $marks[$check['status']] ?></span>
                            <span>
                                <?= e($check['label']) ?> <span class="visually-hidden">(<?= $words[$check['status']] ?>)</span>
                                <?php if ($check['detail'] !== '') { ?><span class="detail"><?= e($check['detail']) ?></span><?php } ?>
                                <?php if ($check['cpanel'] !== null) { ?><span class="fix"><b>cPanel:</b> <?= e($check['cpanel']) ?></span><?php } ?>
                                <?php if ($check['plesk'] !== null) { ?><span class="fix"><b>Plesk:</b> <?= e($check['plesk']) ?></span><?php } ?>
                            </span>
                        </li>
                    <?php } ?>
                </ul>
            <?php } ?>
            <?php if ($passes !== []) { ?>
                <details>
                    <summary><?= count($passes) ?> <?= count($passes) === 1 ? 'check' : 'checks' ?> passed</summary>
                    <ul class="checks">
                        <?php foreach ($passes as $check) { ?>
                            <li class="pass"><span class="mark" aria-hidden="true">✓</span><span><?= e($check['label']) ?> <span class="visually-hidden">(passed)</span></span></li>
                        <?php } ?>
                    </ul>
                </details>
            <?php } ?>
        </section>
    <?php } ?>

    <form method="post" action="<?= e($base) ?>/install/server" class="actions">
        <input type="hidden" name="_token" value="<?= e((string) $token) ?>">
        <?php if ($passed) { ?>
            <button type="submit" class="btn btn-primary btn-wide">Continue</button>
        <?php } else { ?>
            <a class="btn btn-primary btn-wide" href="<?= e($base) ?>/install/server">Check again</a>
        <?php } ?>
    </form>
</div>
