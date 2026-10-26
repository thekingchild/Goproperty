<?php

declare(strict_types=1);

namespace App\Release;

/**
 * Checks one release layout against the packaging rules in INSTALLER.md § 2:
 * every file matches SHA256SUMS and nothing was added; no symlinks, unsafe
 * paths, environment files, keys, SQLite files, secret-shaped strings or dev
 * dependencies; the Vite manifest and its assets, VERSION and the old-PHP
 * guard are present.
 */
final class PackageVerifier
{
    public const string VERSION_PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/';

    /** Files every layout needs, whatever SHA256SUMS says. */
    private const array REQUIRED = [
        'artisan',
        'bootstrap/app.php',
        'bootstrap/php-version.php',
        'composer.json',
        'public/index.php',
        'VERSION',
        'SHA256SUMS',
    ];

    /**
     * Credentials that must never ship. Matches are named, never printed.
     * Each alternative is written so this file does not match itself.
     */
    private const string SECRETS = '~'
        .'(?<private_key>-----BEGIN (?:[A-Z0-9]+ )*PRIVATE KEY-----[\s\S]{0,200}?[A-Za-z0-9+/]{32})'
        .'|(?<aws_access_key>\b(?:AKIA|ASIA)(?![0-9A-Z]{9}EXAMPLE)[0-9A-Z]{16}\b)'
        .'|(?<payment_secret_key>\b[rs]k_(?:live|test)_[0-9A-Za-z]{20,})'
        .'|(?<github_token>\b(?:gh[oprsu]_[0-9A-Za-z]{36,}|github_pat_[0-9A-Za-z_]{60,}))'
        .'|(?<slack_token>\bxox[abposr]-[0-9A-Za-z-]{10,})'
        .'|(?<laravel_app_key>APP_KEY\s*=\s*["\']?base64:[A-Za-z0-9+/]{43}=)'
        .'|(?<google_api_key>\bAIza[0-9A-Za-z_-]{35}\b)'
        .'|(?<dsn_with_key>https?://[0-9a-f]{32}(?::[0-9a-f]{32})?@[a-z0-9.-]+/\d+)'
        .'~';

    /** cPanel's MultiPHP Manager and INI Editor write these blocks into .htaccess. */
    private const string CPANEL_HTACCESS_BLOCK = '/^# (?:php -- )?BEGIN cPanel-generated.*?^# (?:php -- )?END cPanel-generated[^\n]*(?:\n|$)/ms';

    public function verify(PackageTree $tree, VerifyMode $mode): VerificationReport
    {
        $entries = $tree->entries();
        $problems = [];

        if (! array_key_exists('VERSION', $entries) && ! array_key_exists('SHA256SUMS', $entries)) {
            return new VerificationReport(null, 0, [
                'Neither VERSION nor SHA256SUMS exists: this is not an unpacked release package.',
            ]);
        }

        $listed = $this->checksums($tree, $entries, $problems);
        $version = $this->version($tree, $entries, $problems);

        foreach (self::REQUIRED as $path) {
            if (! array_key_exists($path, $entries)) {
                $problems[] = "{$path} is missing.";
            }
        }

        $files = 0;
        $vendorPackages = [];

        foreach ($entries as $path => $isLink) {
            if ($path === 'SHA256SUMS' || ($mode === VerifyMode::Installation && $this->isOwnEnvironment($path))) {
                continue;
            }

            if (! self::isSafePath($path)) {
                $problems[] = 'Unsafe path: '.self::printable($path);

                continue;
            }

            if ($isLink) {
                $problems[] = "{$path} is a symlink.";

                continue;
            }

            if ($this->isRuntime($path)) {
                if (basename($path) !== '.gitignore') {
                    $problems[] = "{$path}: storage/ and bootstrap/cache/ must be empty in a package.";
                }

                continue;
            }

            if ($mode === VerifyMode::Installation && $this->isHostFile($path) && ! isset($listed[$path])) {
                continue;
            }

            $files++;

            if (preg_match('~^vendor/([^/]+/[^/]+)/~', $path, $match) === 1) {
                $vendorPackages[$match[1]] = true;
            }

            $forbidden = $this->forbiddenName($path);

            if ($forbidden !== null) {
                $problems[] = "{$path} is {$forbidden}.";
            }

            $contents = $tree->read($path);

            if (str_starts_with($contents, "SQLite format 3\0")) {
                $problems[] = "{$path} is an SQLite database.";
            }

            $secret = $this->secretIn($contents);

            if ($secret !== null) {
                $problems[] = "{$path} contains a secret-shaped string ({$secret}).";
            }

            if ($listed === null) {
                continue;
            }

            if (! isset($listed[$path])) {
                $problems[] = "{$path} is not listed in SHA256SUMS (added after the build).";
            } elseif (! $this->matches($path, $contents, $listed[$path], $mode)) {
                $problems[] = "{$path} does not match its SHA256SUMS checksum (changed after the build).";
            }
        }

        foreach (array_keys($listed ?? []) as $path) {
            if (! array_key_exists($path, $entries)) {
                $problems[] = "{$path} is listed in SHA256SUMS but missing.";
            }
        }

        $this->checkDependencies($tree, $entries, $vendorPackages, $problems);
        $this->checkViteManifest($tree, $entries, $problems);
        $this->checkPhpGuard($tree, $entries, $problems);

        return new VerificationReport($version, $files, array_values(array_unique($problems)));
    }

    /**
     * A relative path with no `..`, `.` or empty segments, backslashes,
     * drive letters or control characters.
     */
    public static function isSafePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $path) === 1 || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    public static function printable(string $path): string
    {
        return addcslashes($path, "\0..\37\177");
    }

    /**
     * @param  array<string, bool>  $entries
     * @param  list<string>  $problems
     * @return array<string, string>|null path => sha256, or null when there is no usable list
     */
    private function checksums(PackageTree $tree, array $entries, array &$problems): ?array
    {
        if (! array_key_exists('SHA256SUMS', $entries)) {
            return null;
        }

        if ($entries['SHA256SUMS']) {
            $problems[] = 'SHA256SUMS is a symlink.';

            return null;
        }

        $contents = trim($tree->read('SHA256SUMS'));

        if ($contents === '') {
            $problems[] = 'SHA256SUMS is empty.';

            return null;
        }

        $listed = [];

        foreach (preg_split('/\r?\n/', $contents) ?: [] as $number => $line) {
            // sha256sum format: "<hash>  <path>" (or " *<path>" in binary mode).
            if (preg_match('/^([0-9a-f]{64}) [ *](.+)$/', $line, $match) !== 1 || ! self::isSafePath($match[2])) {
                $problems[] = 'SHA256SUMS line '.($number + 1).' is not "<sha256>  <path>" with a safe path.';

                continue;
            }

            if (isset($listed[$match[2]])) {
                $problems[] = "SHA256SUMS lists {$match[2]} twice.";

                continue;
            }

            $listed[$match[2]] = $match[1];
        }

        return $listed;
    }

    /**
     * @param  array<string, bool>  $entries
     * @param  list<string>  $problems
     */
    private function version(PackageTree $tree, array $entries, array &$problems): ?string
    {
        if (! array_key_exists('VERSION', $entries) || $entries['VERSION']) {
            return null;
        }

        $version = trim($tree->read('VERSION'));

        if (preg_match(self::VERSION_PATTERN, $version) !== 1) {
            $problems[] = 'VERSION does not hold a version number such as 1.4.0.';

            return null;
        }

        return $version;
    }

    /** The installation's own `.env` (and the installer's temporary copies). */
    private function isOwnEnvironment(string $path): bool
    {
        return $path !== '.env.example' && preg_match('/^\.env(?:\.[^\/]+)?$/', $path) === 1;
    }

    private function isRuntime(string $path): bool
    {
        foreach (DirectoryTree::RUNTIME_PATHS as $runtime) {
            if (str_starts_with($path, $runtime.'/')) {
                return true;
            }
        }

        return false;
    }

    /** Files cPanel, Plesk or PHP add to an installation by themselves. */
    private function isHostFile(string $path): bool
    {
        return basename($path) === 'error_log'
            || in_array($path, ['.user.ini', 'php.ini', 'public/.user.ini', 'public/php.ini'], true)
            || str_starts_with($path, 'public/.well-known/')
            || str_starts_with($path, 'public/cgi-bin/');
    }

    private function forbiddenName(string $path): ?string
    {
        $segments = explode('/', $path);
        $name = array_pop($segments);

        return match (true) {
            array_intersect(['.git', 'node_modules'], $segments) !== [] => 'inside a development folder (.git or node_modules)',
            preg_match('/^\.env(?:\..+)?$/', $name) === 1 && ! str_ends_with($name, '.example') => 'an environment file',
            $name === 'auth.json' => 'a Composer credentials file',
            preg_match('/\.(?:key|p12|pfx|jks|keystore)$/i', $name) === 1,
            preg_match('/^id_(?:rsa|dsa|ecdsa|ed25519)$/', $name) === 1 => 'a key file',
            preg_match('/\.(?:sqlite3?|db)(?:-journal|-wal|-shm)?$/i', $name) === 1 => 'an SQLite database',
            default => null,
        };
    }

    private function secretIn(string $contents): ?string
    {
        // Binary files (fonts, images) are hashed but not scanned.
        if (str_contains(substr($contents, 0, 8000), "\0")) {
            return null;
        }

        if (preg_match(self::SECRETS, $contents, $match) !== 1) {
            return null;
        }

        foreach ($match as $name => $value) {
            if (is_string($name) && $value !== '') {
                return str_replace('_', ' ', $name);
            }
        }

        return 'credential';
    }

    private function matches(string $path, string $contents, string $expected, VerifyMode $mode): bool
    {
        if (hash_equals($expected, hash('sha256', $contents))) {
            return true;
        }

        // Changing the PHP version in cPanel rewrites the document root's
        // .htaccess; the rest of the file must still be ours.
        if ($mode === VerifyMode::Installation && $path === 'public/.htaccess') {
            $stripped = preg_replace(self::CPANEL_HTACCESS_BLOCK, '', $contents);

            if (! is_string($stripped)) {
                return false;
            }

            // cPanel separates its block with blank lines.
            foreach ([$stripped, trim($stripped, "\r\n")."\n"] as $candidate) {
                if (hash_equals($expected, hash('sha256', $candidate))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, bool>  $entries
     * @param  array<string, true>  $vendorPackages
     * @param  list<string>  $problems
     */
    private function checkDependencies(PackageTree $tree, array $entries, array $vendorPackages, array &$problems): void
    {
        if (! isset($entries['vendor/autoload.php'])) {
            $problems[] = 'vendor/ is missing: the package must include the production dependencies.';

            return;
        }

        $installed = $this->json($tree, $entries, 'vendor/composer/installed.json');

        if (($installed['dev'] ?? null) !== false) {
            $problems[] = 'vendor/ was installed with dev dependencies (build with composer install --no-dev).';
        }

        $devPackages = is_array($installed['dev-package-names'] ?? null) ? $installed['dev-package-names'] : [];
        $lock = $this->json($tree, $entries, 'composer.lock');

        foreach (is_array($lock['packages-dev'] ?? null) ? $lock['packages-dev'] : [] as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                $devPackages[] = $package['name'];
            }
        }

        foreach (array_unique(array_filter($devPackages, is_string(...))) as $name) {
            if (isset($vendorPackages[$name])) {
                $problems[] = "Dev dependency {$name} is in vendor/.";
            }
        }
    }

    /**
     * @param  array<string, bool>  $entries
     * @param  list<string>  $problems
     */
    private function checkViteManifest(PackageTree $tree, array $entries, array &$problems): void
    {
        $path = 'public/build/manifest.json';

        if (! array_key_exists($path, $entries)) {
            $problems[] = "The Vite manifest ({$path}) is missing: build the front-end assets.";

            return;
        }

        $manifest = $this->json($tree, $entries, $path);

        if ($manifest === []) {
            $problems[] = "The Vite manifest ({$path}) is not valid.";

            return;
        }

        foreach ($manifest as $chunk) {
            if (! is_array($chunk)) {
                continue;
            }

            $assets = [$chunk['file'] ?? null, ...(array) ($chunk['css'] ?? []), ...(array) ($chunk['assets'] ?? [])];

            foreach (array_filter($assets, is_string(...)) as $asset) {
                if (! array_key_exists('public/build/'.$asset, $entries)) {
                    $problems[] = "public/build/{$asset} is in the Vite manifest but missing.";
                }
            }
        }
    }

    /**
     * @param  array<string, bool>  $entries
     * @param  list<string>  $problems
     */
    private function checkPhpGuard(PackageTree $tree, array $entries, array &$problems): void
    {
        foreach (['artisan', 'public/index.php'] as $path) {
            if (isset($entries[$path]) && ! $entries[$path]
                && ! str_contains($tree->read($path), 'bootstrap/php-version.php')) {
                $problems[] = "{$path} does not load the old-PHP guard (bootstrap/php-version.php).";
            }
        }
    }

    /**
     * @param  array<string, bool>  $entries
     * @return array<mixed>
     */
    private function json(PackageTree $tree, array $entries, string $path): array
    {
        if (! array_key_exists($path, $entries) || $entries[$path]) {
            return [];
        }

        $decoded = json_decode($tree->read($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
