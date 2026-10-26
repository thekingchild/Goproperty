<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * A tiny release package with the same shape as the real one
 * (scripts/release/build-package.sh), for fast verifier tests.
 */
final class ReleaseFixture
{
    /**
     * Files of the preferred layout, by path from the application root.
     *
     * @return array<string, string>
     */
    public static function files(string $version = '1.4.0'): array
    {
        return [
            '.env.example' => "APP_NAME=GoProperty\nAPP_KEY=\n",
            'VERSION' => $version."\n",
            'artisan' => "<?php\nrequire __DIR__.'/bootstrap/php-version.php';\nrequire __DIR__.'/vendor/autoload.php';\n",
            'bootstrap/app.php' => "<?php\nreturn null;\n",
            'bootstrap/php-version.php' => "<?php\n",
            'bootstrap/cache/.gitignore' => "*\n!.gitignore\n",
            'composer.json' => '{"name": "goproperty/goproperty"}',
            'composer.lock' => json_encode(['packages' => [['name' => 'acme/lib']], 'packages-dev' => [['name' => 'pestphp/pest']]], JSON_THROW_ON_ERROR),
            'config/app.php' => "<?php\nreturn ['name' => env('APP_NAME')];\n",
            'public/.htaccess' => "<IfModule mod_rewrite.c>\nRewriteEngine On\n</IfModule>\n",
            'public/index.php' => "<?php\nrequire __DIR__.'/../bootstrap/php-version.php';\nrequire __DIR__.'/../vendor/autoload.php';\n",
            'public/robots.txt' => "User-agent: *\nDisallow:\n",
            'public/build/manifest.json' => json_encode([
                'resources/css/app.css' => ['file' => 'assets/app-1a2b.css', 'src' => 'resources/css/app.css'],
                'resources/js/app.js' => ['file' => 'assets/app-3c4d.js', 'css' => ['assets/app-1a2b.css'], 'assets' => ['assets/inter-400.woff2']],
            ], JSON_THROW_ON_ERROR),
            'public/build/assets/app-1a2b.css' => 'body{margin:0}',
            'public/build/assets/app-3c4d.js' => 'console.log(1)',
            'public/build/assets/inter-400.woff2' => "wOF2\0\0binary",
            'storage/logs/.gitignore' => "*\n!.gitignore\n",
            'vendor/autoload.php' => "<?php\n",
            'vendor/composer/installed.json' => json_encode(['packages' => [['name' => 'acme/lib']], 'dev' => false, 'dev-package-names' => []], JSON_THROW_ON_ERROR),
            'vendor/acme/lib/src/Lib.php' => "<?php\nfinal class Lib {}\n",
        ];
    }

    /**
     * The split layout's files: the preferred files plus its public path.
     *
     * @param  array<string, string>  $files
     * @return array<string, string>
     */
    public static function split(array $files): array
    {
        $files['bootstrap/public-path.php'] = "<?php\nreturn dirname(__DIR__, 2).'/public_html';\n";
        $files['public/index.php'] = "<?php\nrequire __DIR__.'/../goproperty-app/bootstrap/php-version.php';\n\$app->usePublicPath(__DIR__);\n";

        return $files;
    }

    /**
     * SHA256SUMS as the build writes it: everything except runtime folders.
     *
     * @param  array<string, string>  $files
     */
    public static function sums(array $files): string
    {
        ksort($files, SORT_STRING);
        $lines = '';

        foreach ($files as $path => $contents) {
            if ($path !== 'SHA256SUMS' && ! str_starts_with($path, 'storage/') && ! str_starts_with($path, 'bootstrap/cache/')) {
                $lines .= hash('sha256', $contents).'  '.$path."\n";
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, string>  $files
     * @return array<string, string>
     */
    public static function withSums(array $files): array
    {
        return $files + ['SHA256SUMS' => self::sums($files)];
    }

    /**
     * Writes a layout to disk; public/ files go to $publicDir when given.
     *
     * @param  array<string, string>  $files  with or without SHA256SUMS
     */
    public static function write(string $dir, array $files, ?string $publicDir = null): void
    {
        if (! array_key_exists('SHA256SUMS', $files)) {
            $files = self::withSums($files);
        }

        foreach ($files as $path => $contents) {
            $target = $publicDir !== null && str_starts_with($path, 'public/')
                ? $publicDir.'/'.substr($path, 7)
                : $dir.'/'.$path;

            File::ensureDirectoryExists(dirname($target));
            file_put_contents($target, $contents);
        }
    }

    /**
     * Builds a release zip with both layouts.
     *
     * @param  array<string, string>|null  $preferred  with or without SHA256SUMS
     * @param  array<string, string>|null  $split  with or without SHA256SUMS
     * @param  array<string, string>  $extra  raw zip entries
     */
    public static function zip(string $path, ?array $preferred = null, ?array $split = null, array $extra = [], bool $sidecar = true): void
    {
        $preferred ??= self::files();
        $split ??= self::split(self::files());

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('README.txt', "GoProperty\n");

        foreach (array_key_exists('SHA256SUMS', $preferred) ? $preferred : self::withSums($preferred) as $file => $contents) {
            $zip->addFromString('preferred/goproperty/'.$file, $contents);
        }

        foreach (array_key_exists('SHA256SUMS', $split) ? $split : self::withSums($split) as $file => $contents) {
            $name = str_starts_with($file, 'public/') ? 'split/public_html/'.substr($file, 7) : 'split/goproperty-app/'.$file;
            $zip->addFromString($name, $contents);
        }

        foreach ($extra as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        if ($sidecar) {
            file_put_contents($path.'.sha256', hash_file('sha256', $path).'  '.basename($path)."\n");
        }
    }
}
