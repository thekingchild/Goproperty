<?php

/*
 * Upgrade drill (P09-06): prints the rows an upgrade must keep, as JSON, from
 * one database. Plain PDO, so the same file reads a database of any release.
 * Only columns that existed in the first release are read, so a later
 * release adding a column never changes the fingerprint; a release that
 * rewrites one of these values breaks the drill on purpose.
 *
 * Usage: php upgrade-fingerprint.php <database> [<last audit entry id>]
 *        (DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD from the environment).
 *        With an id, audit entries written after it (by the upgrade) are
 *        left out.
 */

declare(strict_types=1);

$database = $argv[1] ?? '';
$upTo = isset($argv[2]) ? (int) $argv[2] : PHP_INT_MAX;

if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
    fwrite(STDERR, "Usage: php upgrade-fingerprint.php <database>\n");
    exit(64);
}

$pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_PORT'), $database), (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tables = [
    'platform_staff' => ['id', 'name', 'email', 'password', 'two_factor_secret', 'status', 'created_at'],
    'organizations' => ['id', 'name', 'slug', 'type', 'status', 'transaction_tier', 'created_at'],
    'users' => ['id', 'name', 'email', 'password', 'created_at'],
    'organization_user' => ['id', 'organization_id', 'user_id', 'is_default'],
    'settings' => ['id', 'scope_type', 'scope_id', 'key', 'value'],
];
$fingerprint = [];

foreach ($tables as $table => $columns) {
    $list = implode(', ', array_map(static fn (string $column): string => "`{$column}`", $columns));
    $where = $table === 'settings' ? " where `key` like 'upgrade_drill.%'" : '';
    $fingerprint[$table] = $pdo->query("select {$list} from `{$table}`{$where} order by `id`")->fetchAll(PDO::FETCH_ASSOC);
}

// The audit log before the upgrade: its entries and their hash chain.
$audit = $pdo->prepare('select `id`, `log_name`, `event`, `description`, `causer_id`, `hash` from `activity_log` where `id` <= ? order by `id`');
$audit->execute([$upTo]);
$fingerprint['activity_log'] = $audit->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($fingerprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
