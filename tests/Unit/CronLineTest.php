<?php

declare(strict_types=1);

use App\Installer\CronLine;

/*
 * P00-06: the cron line uses the PHP CLI binary from the panels' known
 * paths, never php-fpm or lsphp (INSTALLER.md § 5).
 */

it('prefers the panel PHP binaries in order', function (array $present, string $expected) {
    $cron = new CronLine(fn (string $path): bool => in_array($path, $present, true), '/usr/bin/php');

    expect($cron->phpBinary())->toBe($expected);
})->with([
    'cPanel' => [['/opt/cpanel/ea-php84/root/usr/bin/php', '/opt/plesk/php/8.4/bin/php'], '/opt/cpanel/ea-php84/root/usr/bin/php'],
    'CloudLinux' => [['/opt/alt/php84/usr/bin/php'], '/opt/alt/php84/usr/bin/php'],
    'Plesk' => [['/opt/plesk/php/8.4/bin/php'], '/opt/plesk/php/8.4/bin/php'],
    'none: the CLI binary' => [[], '/usr/bin/php'],
]);

it('never uses a web server binary', function (string $binary) {
    expect((new CronLine(fn (): bool => false, $binary))->phpBinary())->toBe('php');
})->with(['/usr/sbin/php-fpm8.4', '/usr/local/lsws/lsphp84/bin/lsphp', '']);

it('accepts versioned CLI binaries', function () {
    expect((new CronLine(fn (): bool => false, '/usr/bin/php8.4'))->phpBinary())->toBe('/usr/bin/php8.4');
});

it('writes the full cron line, quoting odd paths', function () {
    $cron = new CronLine(fn (string $path): bool => $path === '/opt/plesk/php/8.4/bin/php');

    expect($cron->line('/var/www/vhosts/goproperty.ng/goproperty/'))
        ->toBe('* * * * * /opt/plesk/php/8.4/bin/php /var/www/vhosts/goproperty.ng/goproperty/artisan schedule:run >> /dev/null 2>&1')
        ->and($cron->line('/home/my site/app'))->toContain("'/home/my site/app/artisan'");
});

it('runs current/artisan in the deploy layout, not a release folder', function () {
    $root = sys_get_temp_dir().'/gp-cron-'.bin2hex(random_bytes(4));
    mkdir($root.'/releases/20261027-abc', 0700, true);
    mkdir($root.'/shared', 0700, true);

    $cron = new CronLine(fn (): bool => false, '/usr/bin/php');

    expect($cron->line($root.'/releases/20261027-abc'))->toBe("* * * * * /usr/bin/php {$root}/current/artisan schedule:run >> /dev/null 2>&1")
        ->and($cron->line($root.'/plain'))->toContain($root.'/plain/artisan');

    exec('rm -rf '.escapeshellarg($root));
});
