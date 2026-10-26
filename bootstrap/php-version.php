<?php

/*
 * Old-PHP guard (INSTALLER.md § 2). public/index.php and artisan load this
 * before Composer, so a server on an older PHP prints one plain sentence
 * instead of a parse or platform error. It must parse on old PHP versions:
 * no strict_types, type declarations, `??`, arrow functions or `match`.
 * CI runs it on PHP 7.4 and 8.1 (ci.yml, scripts/release/verify-drill.sh).
 */

if (! function_exists('goproperty_php_version_problem')) {
    /**
     * The sentence to show when this PHP is too old, or null when it is fine.
     *
     * @param  int  $versionId  PHP_VERSION_ID, e.g. 80100
     * @return string|null
     */
    function goproperty_php_version_problem($versionId)
    {
        if ($versionId >= 80400) {
            return null;
        }

        $major = (int) floor($versionId / 10000);
        $minor = (int) floor(($versionId % 10000) / 100);

        return 'GoProperty needs PHP 8.4 or newer; this server runs '.$major.'.'.$minor.'.';
    }
}

$goPropertyPhpProblem = goproperty_php_version_problem(PHP_VERSION_ID);

if ($goPropertyPhpProblem !== null) {
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
        fwrite(STDERR, $goPropertyPhpProblem.PHP_EOL);
    } else {
        if (! headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }

        echo $goPropertyPhpProblem.PHP_EOL;
    }

    exit(1);
}

unset($goPropertyPhpProblem);
