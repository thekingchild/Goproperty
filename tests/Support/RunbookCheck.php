<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Installer\CronLine;
use Filament\Pages\Page;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Symfony\Component\Console\Command\Command;
use Throwable;
use UnitEnum;

/**
 * Keeps the install, recovery and upgrade guides true to the code (P09-07,
 * docs/runbooks/install.md "How to use this guide"). It reads only what the
 * guides mark, never free prose:
 *
 * - **bold** is text GoProperty shows on screen: it must appear in the
 *   installer's, console's or commands' own sources ("…" stands for a part
 *   that changes; a bold label ending in ":" is not checked);
 * - **Console → Group → Page** must be a page of the console's menu;
 * - `code` spans and code blocks: artisan commands and their options,
 *   environment keys, paths in the release layout, the panels' PHP
 *   binaries and installer routes must exist;
 * - links to other files and their #anchors must resolve.
 *
 * *Italics* are the hosting panels' words, which no test can see.
 */
final class RunbookCheck
{
    public const array GUIDES = [
        'docs/runbooks/install.md',
        'docs/runbooks/install-recovery.md',
        'docs/runbooks/upgrade.md',
    ];

    /** Where the words GoProperty shows on screen come from. */
    private const array UI_SOURCES = [
        'resources/views/installer',
        'resources/views/console',
        'app/Http/Controllers/Install',
        'app/Http/Controllers/Console',
        'app/Installer',
        'app/Platform/Filament',
        'app/Console/Commands',
        'bootstrap/php-version.php',
        // The console's sign-in and two-factor screens are Filament's own.
        'vendor/filament/filament/resources/lang/en',
    ];

    /** Where environment keys are read or written. */
    private const array ENV_SOURCES = ['.env.example', 'config', 'app/Installer', 'app/Hosting'];

    /** First folder of a path in the guides => the folder it is in the repository ('' = application root). */
    private const array ROOTS = [
        'goproperty-app' => '', 'goproperty' => '',
        'goproperty-app.previous' => '', 'goproperty-app.failed' => '',
        'goproperty.previous' => '', 'goproperty.failed' => '',
        'preferred/goproperty' => '', 'split/goproperty-app' => '',
        'public_html' => 'public', 'public_html.previous' => 'public', 'public_html.failed' => 'public',
        'split/public_html' => 'public', 'httpdocs' => 'public',
        'storage' => 'storage', 'bootstrap' => 'bootstrap', 'public' => 'public',
    ];

    /** Folders the hosting panel puts in the web folder (verify-package ignores them). */
    private const array PANEL_FOLDERS = ['cgi-bin', '.well-known'];

    /** Files the guides tell the owner to create while recovering. */
    private const array GUIDE_FILES = ['storage/logs/once.log', 'storage/logs/cron.log'];

    private ?string $ui = null;

    private ?string $env = null;

    /** @var array<string, list<string>>|null group => labels */
    private ?array $menu = null;

    /**
     * Every problem in one guide, as "file: problem".
     *
     * @return list<string>
     */
    public function problems(string $file, string $markdown): array
    {
        [$prose, $blocks] = self::split($markdown);
        $problems = [];
        $spans = [];

        if (preg_match_all('/`([^`\n]+)`/', $prose, $matches) > 0) {
            $spans = $matches[1];
        }

        $text = (string) preg_replace('/`[^`\n]+`/', '`code`', str_replace('\\*', '', $prose));

        if (preg_match_all('/\*\*(.+?)\*\*/', $text, $bold) > 0) {
            foreach ($bold[1] as $span) {
                $problems[] = $this->bold(trim($span));
            }
        }

        foreach ([...$spans, ...$blocks] as $code) {
            array_push($problems, ...$this->commands($code), ...$this->binaries($code));
        }

        foreach ($blocks as $block) {
            foreach (preg_split('/\s+/', $block) ?: [] as $word) {
                $problems[] = str_contains($word, '/') ? $this->path(trim($word, '\'"')) : null;
            }
        }

        foreach ($spans as $span) {
            $problems[] = $this->environmentKey($span);
            $problems[] = $this->path($span);
            $problems[] = $this->route($span);
        }

        if (preg_match_all('/\]\(([^)\s]+)\)/', $prose, $links) > 0) {
            foreach ($links[1] as $link) {
                $problems[] = $this->link($file, $link);
            }
        }

        return array_values(array_map(
            fn (string $problem): string => "{$file}: {$problem}",
            array_unique(array_filter($problems)),
        ));
    }

    /**
     * GitHub's anchors for a guide's headings, in order.
     *
     * @return list<string>
     */
    public static function anchors(string $markdown): array
    {
        [$prose] = self::split($markdown);
        $anchors = [];
        $seen = [];

        foreach (explode("\n", $prose) as $line) {
            if (preg_match('/^#{1,6}\s+(.+?)\s*#*$/', $line, $match) !== 1) {
                continue;
            }

            $slug = (string) preg_replace('/[^\p{L}\p{N}\s_-]/u', '', mb_strtolower($match[1]));
            $slug = (string) preg_replace('/\s/u', '-', $slug);
            $count = $seen[$slug] ?? 0;
            $seen[$slug] = $count + 1;
            $anchors[] = $count === 0 ? $slug : "{$slug}-{$count}";
        }

        return $anchors;
    }

    /** Text GoProperty shows, in the form the guides quote it. */
    public function uiText(): string
    {
        if ($this->ui !== null) {
            return $this->ui;
        }

        $sources = '';

        foreach (self::files(self::UI_SOURCES) as $path) {
            $sources .= "\n".File::get($path);
        }

        $sources = html_entity_decode(str_replace(['<?= e($name) ?>', "\\'"], ['GoProperty', "'"], $sources), ENT_QUOTES | ENT_HTML5);
        // A value filled in on screen is written "…" in the guides; the
        // sources are kept as they are too, for words inside an expression.
        $filled = (string) preg_replace(['/<\?=.*?\?>/s', '/\{\{.*?\}\}/s', '/\{\$[^}]+\}/'], '…', $sources);

        return $this->ui = (string) preg_replace('/\s+/u', ' ', implode("\n", [$sources, $filled, strip_tags($filled)]));
    }

    private function bold(string $span): ?string
    {
        if (str_ends_with($span, ':')) {
            return null;
        }

        if (str_starts_with($span, 'Console →')) {
            return $this->consolePage($span);
        }

        $span = (string) preg_replace('/\s+/u', ' ', $span);

        if (str_contains($this->uiText(), $span)) {
            return null;
        }

        // A message built in code from parts: each part must be there.
        $parts = array_filter(array_map('trim', explode('…', $span)), fn (string $part): bool => $part !== '');

        if (str_contains($span, '…') && $parts !== [] && array_filter($parts, fn (string $part): bool => ! str_contains($this->uiText(), $part)) === []) {
            return null;
        }

        return "**{$span}** is in bold, which these guides keep for text GoProperty shows, but no GoProperty screen or command shows it";
    }

    private function consolePage(string $span): ?string
    {
        $steps = array_map('trim', explode('→', $span));
        array_shift($steps);
        $menu = $this->menu();
        [$group, $label] = count($steps) === 1 ? ['', $steps[0]] : [$steps[0], $steps[1] ?? ''];

        if (count($steps) <= 2 && in_array($label, $menu[$group] ?? [], true)) {
            return null;
        }

        return "**{$span}** is not a page of the console's menu";
    }

    /**
     * The console's menu: group => page labels.
     *
     * @return array<string, list<string>>
     */
    public function menu(): array
    {
        if ($this->menu !== null) {
            return $this->menu;
        }

        $menu = [];

        foreach (File::allFiles(app_path('Platform/Filament')) as $file) {
            $class = 'App\\Platform\\Filament\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (! class_exists($class) || (new ReflectionClass($class))->isAbstract()
                || is_subclass_of($class, ResourcePage::class)
                || (! is_subclass_of($class, Page::class) && ! is_subclass_of($class, Resource::class))
                || ! $class::shouldRegisterNavigation()) {
                continue;
            }

            $group = $class::getNavigationGroup();
            $menu[$group instanceof UnitEnum ? $group->name : (string) $group][] = (string) $class::getNavigationLabel();
        }

        return $this->menu = $menu;
    }

    /** @return list<string> */
    private function commands(string $code): array
    {
        $problems = [];
        $code = (string) preg_replace('/\\\\\n\s*/', ' ', $code);

        foreach (explode("\n", $code) as $line) {
            if (preg_match('/(?:artisan\s+|^\s*)((?:goproperty:)?[a-z][\w-]*:[\w:-]+|(?<=artisan )[a-z][\w-]*)(.*)$/', $line, $match) !== 1) {
                continue;
            }

            $name = $match[1];
            $command = Artisan::all()[$name] ?? null;

            if (! $command instanceof Command) {
                $problems[] = "the command `{$name}` does not exist";

                continue;
            }

            $command->mergeApplicationDefinition();
            $rest = (string) preg_replace('/(>>|\||&&|;).*$/', '', $match[2]);

            if (preg_match_all('/(?<=\s)--([a-z][\w-]*)/', ' '.$rest, $options) > 0) {
                foreach ($options[1] as $option) {
                    if (! $command->getDefinition()->hasOption($option)) {
                        $problems[] = "`{$name}` has no option --{$option}";
                    }
                }
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function binaries(string $code): array
    {
        $problems = [];

        if (preg_match_all('#/opt/[^\s`]+/php\b#', $code, $matches) > 0) {
            foreach ($matches[0] as $binary) {
                if (! in_array($binary, CronLine::KNOWN_BINARIES, true)) {
                    $problems[] = "`{$binary}` is not a PHP the cron line looks for (App\\Installer\\CronLine::KNOWN_BINARIES)";
                }
            }
        }

        return $problems;
    }

    private function environmentKey(string $span): ?string
    {
        if (preg_match('/^([A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+)(?:=\S*)?$/', $span, $match) === 1) {
            return preg_match('/\b'.$match[1].'\b/', $this->environmentSources()) === 1
                ? null
                : "`{$match[1]}` is not a setting GoProperty reads (.env.example, config/)";
        }

        if (preg_match('/^([A-Z][A-Z0-9]*_)\*$/', $span, $match) === 1) {
            return preg_match('/\b'.$match[1].'[A-Z0-9_]+\b/', $this->environmentSources()) === 1
                ? null
                : "no setting starts with `{$match[1]}`";
        }

        return null;
    }

    private function environmentSources(): string
    {
        if ($this->env === null) {
            $this->env = '';

            foreach (self::files(self::ENV_SOURCES) as $path) {
                $this->env .= "\n".File::get($path);
            }
        }

        return $this->env;
    }

    /** A path in the release layout, or a file the installer writes. */
    private function path(string $span): ?string
    {
        if (str_contains($span, ' ') || str_contains($span, '*') || str_contains($span, '://')) {
            return null;
        }

        $path = (string) preg_replace('#^(~/|/home/USER/|/var/www/vhosts/goproperty\.ng/|/)#', '', $span);
        $path = rtrim($path, '/');

        if ($path === '.env' || $path === 'artisan') {
            $path = 'goproperty/'.$path;
        }

        $root = null;

        foreach (array_keys(self::ROOTS) as $candidate) {
            if (($path === $candidate || str_starts_with($path, $candidate.'/')) && ($root === null || strlen($candidate) > strlen($root))) {
                $root = $candidate;
            }
        }

        if ($root === null) {
            return null;
        }

        $relative = trim(self::ROOTS[$root].'/'.substr($path, strlen($root)), '/');
        $relative = (string) preg_replace('#/+#', '/', $relative);

        return $this->pathExists($relative) ? null : "`{$span}` is not in the release layout, and GoProperty does not write it";
    }

    private function pathExists(string $relative): bool
    {
        $runtime = ['.env', 'storage/framework/down', ...self::GUIDE_FILES, ...$this->configuredPaths()];
        $public = substr($relative, 0, 7) === 'public/' ? substr($relative, 7) : null;

        return $relative === ''
            || file_exists(base_path($relative))
            || in_array($relative, $runtime, true)
            // Made by the release build (scripts/release/build-package.sh, stage.sh).
            || (in_array($relative, ['VERSION', 'SHA256SUMS', 'bootstrap/public-path.php'], true) && str_contains(File::get(base_path('scripts/release/build-package.sh')), basename($relative)))
            || ($public !== null && (in_array(explode('/', $public)[0], self::PANEL_FOLDERS, true) || str_starts_with($public, 'build')));
    }

    /**
     * Every storage_path() and base_path() the config names (the installer's
     * markers, logs, disks), read from config/ itself: tests point them
     * elsewhere at run time.
     *
     * @return list<string>
     */
    private function configuredPaths(): array
    {
        $paths = [];

        foreach (self::files(['config']) as $file) {
            if (preg_match_all("/(storage|base)_path\\('([^']+)'\\)/", File::get($file), $matches, PREG_SET_ORDER) > 0) {
                foreach ($matches as [, $kind, $path]) {
                    $paths[] = ($kind === 'storage' ? 'storage/' : '').ltrim($path, '/');
                }
            }
        }

        return $paths;
    }

    /** Installer and console addresses. */
    private function route(string $span): ?string
    {
        if (preg_match('#^(?:https://goproperty\.ng)?(/[a-z{][\w{}/-]*)$#i', $span, $match) !== 1 || preg_match('#gp-control-[a-z0-9]{6}#', $span) === 1) {
            return null;
        }

        $uri = trim(str_replace('{ADMIN_PATH}', (string) config('app.admin_path'), $match[1]), '/');

        if (! str_starts_with($match[1], '/install') && ! str_starts_with($match[1], '/{ADMIN_PATH}')) {
            return null;
        }

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (trim($route->uri(), '/') === $uri) {
                return null;
            }
        }

        return "`{$span}` is not a route of the installer or the console";
    }

    private function link(string $file, string $link): ?string
    {
        if (preg_match('#^(https?:|mailto:)#', $link) === 1) {
            return null;
        }

        [$target, $anchor] = array_pad(explode('#', $link, 2), 2, null);
        $path = $target === '' ? base_path($file) : realpath(dirname(base_path($file)).'/'.$target);

        if ($path === false || ! file_exists($path)) {
            return "the link ({$link}) points at a file that does not exist";
        }

        if ($anchor !== null && $anchor !== '' && ! in_array($anchor, self::anchors(File::get($path)), true)) {
            return "the link ({$link}) points at a heading that does not exist";
        }

        return null;
    }

    /**
     * The guide without its code blocks, and the code blocks.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function split(string $markdown): array
    {
        $blocks = [];
        $prose = (string) preg_replace_callback('/^[ \t]*```[^\n]*\n(.*?)^[ \t]*```[ \t]*$/ms', function (array $match) use (&$blocks): string {
            $blocks[] = $match[1];

            return '';
        }, $markdown);

        return [$prose, $blocks];
    }

    /**
     * @param  list<string>  $sources  files or folders, from the repository root
     * @return list<string>
     */
    private static function files(array $sources): array
    {
        $files = [];

        foreach ($sources as $source) {
            $path = base_path($source);

            try {
                $files = [...$files, ...(is_dir($path) ? array_map(fn ($file): string => $file->getPathname(), File::allFiles($path)) : [$path])];
            } catch (Throwable) {
                continue;
            }
        }

        return array_values(array_filter($files, fn (string $path): bool => is_file($path) && preg_match('/(\.php|\.example)$/', $path) === 1));
    }
}
