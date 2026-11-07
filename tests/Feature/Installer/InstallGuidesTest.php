<?php

declare(strict_types=1);

use App\Installer\CronLine;
use App\Installer\Web\WizardStep;
use Illuminate\Support\Facades\File;
use Tests\Support\RunbookCheck;

/*
 * P09-07: the install, recovery and upgrade guides (docs/runbooks/install.md,
 * install-recovery.md, upgrade.md) are followed word for word by people
 * who cannot tell a renamed command or button from their own mistake. This
 * keeps every command, option, setting, path, route, GoProperty screen text
 * and console page they name true to the code (Tests\Support\RunbookCheck
 * says what it reads), and the cross-references between them.
 */

function guide(string $name): string
{
    return File::get(base_path('docs/runbooks/'.$name));
}

it('names only commands, settings, paths, screens and console pages that exist', function (string $file) {
    expect((new RunbookCheck)->problems($file, File::get(base_path($file))))->toBe([]);
})->with(RunbookCheck::GUIDES);

it('quotes every step of the web installer by the name it shows', function () {
    $install = guide('install.md');

    foreach (WizardStep::cases() as $step) {
        if ($step !== WizardStep::Unlock) {
            expect($install)->toContain('**'.$step->label().'**');
        }
    }
});

it('shows the cron commands the installer prints for cPanel and Plesk', function () {
    $command = fn (string $binary, string $base): string => (string) preg_replace(
        '/^(\S+\s+){5}/', '', (new CronLine(fn (string $path): bool => $path === $binary))->line($base),
    );

    $cpanel = $command(CronLine::KNOWN_BINARIES['cPanel'], '/home/USER/goproperty-app');
    $plesk = $command(CronLine::KNOWN_BINARIES['Plesk'], '/var/www/vhosts/goproperty.ng/goproperty');

    expect(guide('install.md'))->toContain("`{$cpanel}`", "`{$plesk}`")
        ->and(guide('install-recovery.md'))->toContain($cpanel);
});

it('keeps the recovery section the "Install stopped" screen sends people to', function () {
    expect(File::get(resource_path('views/installer/steps/stopped.php')))->toContain('install-recovery.md</code> § 4')
        ->and(RunbookCheck::anchors(guide('install-recovery.md')))->toContain('4-start-over-on-a-fresh-database');
});

it('keeps uploads in the bucket with a disk that exists', function () {
    expect(guide('install.md'))->toContain('`MEDIA_DISK=media-s3`')
        ->and(config('filesystems.disks.media-s3.driver'))->toBe('s3')
        ->and(File::get(config_path('media-library.php')))->toContain("env('MEDIA_DISK'");
});

it('reads the console menu the guides point at', function () {
    $menu = (new RunbookCheck)->menu();

    expect($menu['Operations'] ?? [])->toContain('Version', 'Backups', 'Backup destinations', 'System operations')
        ->and($menu['Audit'] ?? [])->toContain('Audit log');
});

it('catches a guide that no longer matches the code', function (string $from, string $to, string $problem) {
    $install = guide('install.md');

    expect($install)->toContain($from);

    $problems = (new RunbookCheck)->problems('docs/runbooks/install.md', str_replace($from, $to, $install));

    expect(implode("\n", $problems))->toContain($problem);
})->with([
    'a renamed command' => ['goproperty:check-installation', 'goproperty:check-install', 'the command `goproperty:check-install` does not exist'],
    'a renamed option' => ['goproperty:install --env=production', 'goproperty:install --environment=production', '`goproperty:install` has no option --environment'],
    'a setting' => ['`APP_URL`', '`APP_ADDRESS`', '`APP_ADDRESS` is not a setting GoProperty reads'],
    'a console page' => ['**Console → Operations → Version**', '**Console → System → Version**', '**Console → System → Version** is not a page of the console\'s menu'],
    'a button' => ['**Finish the installation**', '**Complete the installation**', '**Complete the installation** is in bold'],
    'a screen message' => ['**Test email sent to …**', '**Test message sent to …**', '**Test message sent to …** is in bold'],
    'a path' => ['cat storage/app/private/installation-record.txt', 'cat storage/app/installation-record.txt', '`storage/app/installation-record.txt` is not in the release layout'],
    'a folder of the layout' => ['`/home/USER/goproperty-app/storage/app`', '`/home/USER/goproperty-app/storage/uploads`', '`/home/USER/goproperty-app/storage/uploads` is not in the release layout'],
    'a PHP binary' => ['`alias php=/opt/plesk/php/8.4/bin/php`', '`alias php=/opt/plesk/php/8.3/bin/php`', '`/opt/plesk/php/8.3/bin/php` is not a PHP the cron line looks for'],
    'an installer route' => ['`https://goproperty.ng/install`', '`https://goproperty.ng/installer`', '`https://goproperty.ng/installer` is not a route'],
    'a heading in another guide' => ['install-recovery.md#3-cron-does-not-run', 'install-recovery.md#3-cron-is-not-running', 'points at a heading that does not exist'],
]);
