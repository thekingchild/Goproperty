<?php

declare(strict_types=1);

namespace App\Installer\Requirements;

use App\Installer\Support\LocalHost;
use Illuminate\Http\Request;

/**
 * The installer's server check (INSTALLER.md § 5, step 2). Blocking checks
 * stop the install; advisory ones are shown with a warning. Every failure
 * says how to fix it in cPanel and in Plesk.
 */
final class RequirementsChecker
{
    // Same as Composer's platform check (composer.json config.platform.php).
    public const string MINIMUM_PHP = '8.4.1';

    /** Required extensions; gd or imagick is checked separately. */
    public const array EXTENSIONS = [
        'pdo_mysql', 'mbstring', 'intl', 'bcmath', 'zip', 'fileinfo', 'openssl', 'sodium',
        'exif', 'curl', 'dom', 'xml', 'xmlreader', 'xmlwriter', 'tokenizer', 'ctype',
    ];

    /** Folders the app writes to, relative to the base path. */
    public const array WRITABLE = [
        'storage/app', 'storage/app/private', 'storage/framework/cache', 'storage/framework/sessions',
        'storage/framework/views', 'storage/logs', 'bootstrap/cache',
    ];

    private const string CPANEL_PHP = 'cPanel → MultiPHP INI Editor (or Select PHP Version → Options), choose the domain and change the value.';

    private const string PLESK_PHP = 'Plesk → Websites & Domains → PHP Settings, change the value and save.';

    public function __construct(private readonly ServerEnvironment $server) {}

    /**
     * @param  Request|null  $request  the web request; without one (CLI),
     *                                 the document root and HTTPS checks are skipped
     */
    public function check(?Request $request = null): RequirementsReport
    {
        return new RequirementsReport([
            $this->phpVersion(),
            ...$this->extensions(),
            $this->executionTime(),
            ...$this->writableFolders(),
            $this->publicFolder(),
            $this->documentRoot($request),
            $this->https($request),
            $this->viteManifest(),
            $this->memoryLimit(),
            $this->uploadLimit(),
            $this->shellFunctions(),
            $this->freeDisk(),
            $this->opcache(),
        ]);
    }

    private function phpVersion(): RequirementCheck
    {
        $version = $this->server->phpVersion();

        return new RequirementCheck(
            'PHP', 'PHP '.self::MINIMUM_PHP.' or newer',
            version_compare($version, self::MINIMUM_PHP, '>=') ? CheckStatus::Pass : CheckStatus::Fail,
            true,
            "This site runs PHP {$version}.",
            'cPanel → MultiPHP Manager: select the domain and set PHP 8.4 (ea-php84).',
            'Plesk → Websites & Domains → PHP Settings (or Hosting Settings): choose PHP 8.4.',
        );
    }

    /** @return list<RequirementCheck> */
    private function extensions(): array
    {
        $checks = [];
        $required = [...self::EXTENSIONS, 'gd or imagick'];

        foreach ($required as $extension) {
            $loaded = $extension === 'gd or imagick'
                ? $this->server->extensionLoaded('gd') || $this->server->extensionLoaded('imagick')
                : $this->server->extensionLoaded($extension);

            $checks[] = new RequirementCheck(
                'PHP', "PHP extension: {$extension}",
                $loaded ? CheckStatus::Pass : CheckStatus::Fail,
                true,
                $loaded ? '' : "The {$extension} extension is not loaded.",
                "cPanel → Select PHP Version → Extensions: tick {$extension} (on hosts without that page, ask support to enable ea-php84-php-{$extension}).",
                "Plesk → Tools & Settings → PHP Settings → 8.4 → enable {$extension} (on shared plans, ask support).",
            );
        }

        return $checks;
    }

    private function executionTime(): RequirementCheck
    {
        $seconds = (int) $this->server->ini('max_execution_time');
        $ok = $seconds === 0 || $seconds >= 30;

        return new RequirementCheck(
            'PHP', 'max_execution_time of 30 seconds or more',
            $ok ? CheckStatus::Pass : CheckStatus::Fail,
            true,
            "max_execution_time is {$seconds} seconds.",
            self::CPANEL_PHP.' Set max_execution_time to 60.',
            self::PLESK_PHP.' Set max_execution_time to 60.',
        );
    }

    /**
     * A real file is written and deleted in each folder: permission bits can
     * look fine while the host still refuses writes.
     *
     * @return list<RequirementCheck>
     */
    private function writableFolders(): array
    {
        $folders = [...self::WRITABLE, '.env folder'];
        $checks = [];

        foreach ($folders as $folder) {
            $path = $folder === '.env folder' ? dirname(app()->environmentFilePath()) : base_path($folder);
            $ok = self::canWrite($path);

            $checks[] = new RequirementCheck(
                'Folders', $folder === '.env folder' ? 'The application folder (for .env) is writable' : "{$folder} is writable",
                $ok ? CheckStatus::Pass : CheckStatus::Fail,
                true,
                $ok ? '' : "Cannot create a file in {$path}.",
                'cPanel → File Manager: right-click the folder → Change Permissions → 755, and check it belongs to your cPanel user.',
                'Plesk → Files: select the folder → Change Permissions → allow Write for the owner.',
            );
        }

        return $checks;
    }

    /** Files that must never be inside the web-visible folder. */
    private function publicFolder(): RequirementCheck
    {
        $exposed = array_values(array_filter(
            ['.env', 'artisan', 'composer.json', 'composer.lock', 'vendor', 'storage'],
            fn (string $name): bool => file_exists(public_path($name)),
        ));

        return new RequirementCheck(
            'Security', 'No private files in the web folder',
            $exposed === [] ? CheckStatus::Pass : CheckStatus::Fail,
            true,
            $exposed === [] ? '' : 'The web folder contains: '.implode(', ', $exposed).'.',
            'Upload the layout again: only the contents of public/ (or public_html/ in the split layout) belong in the web folder.',
            'Upload the layout again: only the contents of public/ (or httpdocs/ in the split layout) belong in the web folder.',
        );
    }

    private function documentRoot(?Request $request): RequirementCheck
    {
        $label = 'The domain serves only the public folder';
        $root = $request?->server('DOCUMENT_ROOT');

        if (! is_string($root) || $root === '') {
            return new RequirementCheck('Security', $label, CheckStatus::Warn, false, 'Checked in the browser only.');
        }

        $ok = realpath($root) !== false && realpath($root) === realpath(public_path());

        return new RequirementCheck(
            'Security', $label,
            $ok ? CheckStatus::Pass : CheckStatus::Fail,
            true,
            $ok ? '' : "The document root is {$root}, not ".public_path().'.',
            'cPanel → Domains: set the document root to goproperty/public, or use the split layout for public_html.',
            'Plesk → Websites & Domains → Hosting Settings: set the document root to goproperty/public.',
        );
    }

    private function https(?Request $request): RequirementCheck
    {
        $label = 'HTTPS';

        if ($request === null) {
            return new RequirementCheck('Security', $label, CheckStatus::Warn, false, 'Checked in the browser only.');
        }

        if ($request->isSecure()) {
            return new RequirementCheck('Security', $label, CheckStatus::Pass, true);
        }

        // A local test copy (localhost, *.test) may run without a certificate.
        $local = LocalHost::is($request->getHost());

        return new RequirementCheck(
            'Security', $label,
            $local ? CheckStatus::Warn : CheckStatus::Fail,
            ! $local,
            'This page was not opened over https://.',
            'cPanel → SSL/TLS Status: run AutoSSL for the domain, then open the site with https://.',
            'Plesk → Websites & Domains → SSL/TLS Certificates: install a free Let\'s Encrypt certificate, then open the site with https://.',
        );
    }

    private function viteManifest(): RequirementCheck
    {
        $ok = is_file(public_path('build/manifest.json'));

        return new RequirementCheck(
            'Build', 'Built front-end files (public/build)',
            $ok ? CheckStatus::Pass : CheckStatus::Fail,
            true,
            $ok ? '' : 'build/manifest.json is missing from the web folder.',
            'Upload the release zip from GitHub Releases, not the source code: it includes the built files.',
            'Upload the release zip from GitHub Releases, not the source code: it includes the built files.',
        );
    }

    private function memoryLimit(): RequirementCheck
    {
        $bytes = self::bytes($this->server->ini('memory_limit'));
        $ok = $bytes < 0 || $bytes >= 256 * 1024 * 1024;

        return new RequirementCheck(
            'Advisory', 'memory_limit of 256M or more',
            $ok ? CheckStatus::Pass : CheckStatus::Warn,
            false,
            'memory_limit is '.$this->server->ini('memory_limit').'. Imports and PDFs need more.',
            self::CPANEL_PHP.' Set memory_limit to 512M.',
            self::PLESK_PHP.' Set memory_limit to 512M.',
        );
    }

    private function uploadLimit(): RequirementCheck
    {
        $ok = self::bytes($this->server->ini('upload_max_filesize')) >= 20 * 1024 * 1024;

        return new RequirementCheck(
            'Advisory', 'upload_max_filesize of 20M or more',
            $ok ? CheckStatus::Pass : CheckStatus::Warn,
            false,
            'upload_max_filesize is '.$this->server->ini('upload_max_filesize').'. Payment proofs and imports may be refused.',
            self::CPANEL_PHP.' Set upload_max_filesize to 20M and post_max_size to 25M.',
            self::PLESK_PHP.' Set upload_max_filesize to 20M and post_max_size to 25M.',
        );
    }

    private function shellFunctions(): RequirementCheck
    {
        $ok = $this->server->functionAvailable('proc_open') && $this->server->functionAvailable('exec');

        return new RequirementCheck(
            'Advisory', 'proc_open and exec (optional)',
            $ok ? CheckStatus::Pass : CheckStatus::Warn,
            false,
            'Disabled: database backups use the built-in PHP dumper instead of mysqldump. Nothing else needs them.',
        );
    }

    private function freeDisk(): RequirementCheck
    {
        $free = $this->server->freeDiskBytes(base_path());
        $ok = $free !== null && $free >= 2 * 1024 ** 3;

        return new RequirementCheck(
            'Advisory', '2 GB of free disk space',
            $ok ? CheckStatus::Pass : CheckStatus::Warn,
            false,
            $free === null ? 'The host does not report free space.' : sprintf('%.1f GB free.', $free / 1024 ** 3),
            'cPanel → the Statistics panel shows disk usage; upgrade the plan or remove old files.',
            'Plesk → Websites & Domains → Statistics shows disk usage; upgrade the plan or remove old files.',
        );
    }

    private function opcache(): RequirementCheck
    {
        $ok = $this->server->opcacheEnabled();

        return new RequirementCheck(
            'Advisory', 'OPcache',
            $ok ? CheckStatus::Pass : CheckStatus::Warn,
            false,
            $ok ? '' : 'OPcache is off: pages will be slower.',
            self::CPANEL_PHP.' Set opcache.enable to On (Select PHP Version → Extensions: tick opcache).',
            self::PLESK_PHP.' Turn on opcache.enable.',
        );
    }

    public static function canWrite(string $directory): bool
    {
        if (! is_dir($directory)) {
            return false;
        }

        $probe = $directory.'/.gp-write-probe-'.bin2hex(random_bytes(6));

        if (@file_put_contents($probe, 'probe') !== 5) {
            @unlink($probe);

            return false;
        }

        return @unlink($probe);
    }

    /** PHP shorthand ("256M", "1G", "-1") to bytes; -1 means unlimited. */
    public static function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
