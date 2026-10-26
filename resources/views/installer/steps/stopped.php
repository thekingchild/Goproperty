<?php
/*
 * The database settings changed mid-install (INSTALLER.md § 6): the wizard
 * stops instead of carrying on against another database.
 *
 * @var string $base
 */
?>
<div class="form">
    <div class="heading">
        <h1>Install stopped</h1>
        <p>The database settings in <code>.env</code> are not the ones this install started with, so the installer will not go on.</p>
    </div>
    <ol class="howto">
        <li>If <code>.env</code> was edited by mistake, put the original database settings back and reload this page.</li>
        <li>To use another database, start over as described in <code>docs/runbooks/install-recovery.md</code> § 4 (delete <code>storage/app/private/install.checkpoint</code>, then open <code>/install</code> again).</li>
    </ol>
    <p class="note">Recovery is always from the hosting panel's File Manager or the command line, never from this page.</p>
</div>
