<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Installer\Finishing\AdminPath;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

/**
 * Tells console requests apart from customer requests, before the session
 * starts: the console path (or host), plus Livewire updates sent from a
 * console page (Livewire has one update endpoint for the whole site, and
 * records the page's path in every component snapshot).
 */
final class ConsoleRequests
{
    public function __construct(private readonly Config $config) {}

    /** The configured path, or null when it breaks the rules (the console then stays off). */
    public function path(): ?string
    {
        $path = trim($this->string('app.admin_path'), '/');

        if ($path === '' && $this->domain() !== null) {
            return '';
        }

        return AdminPath::isValid($path) ? $path : null;
    }

    public function domain(): ?string
    {
        $domain = strtolower(trim($this->string('app.admin_domain')));

        return $domain !== '' ? $domain : null;
    }

    public function enabled(): bool
    {
        return $this->path() !== null;
    }

    public function isConsoleRequest(Request $request): bool
    {
        $path = $this->path();

        if ($path === null) {
            return false;
        }

        $domain = $this->domain();

        if ($domain !== null) {
            return strtolower($request->getHost()) === $domain && ($path === '' || $this->underPath($request->path(), $path));
        }

        return $this->underPath($request->path(), $path) || $this->isLivewireUpdateFromConsole($request, $path);
    }

    public function ipAllowed(Request $request): bool
    {
        $allowlist = $this->config->get('console.ip_allowlist', []);

        return ! is_array($allowlist) || $allowlist === [] || in_array($request->ip(), $allowlist, true);
    }

    /**
     * A Livewire update whose components were rendered on a console page.
     * The snapshot is signed, but it is only read here to choose which
     * session cookie to use: a forged path gains nothing without a valid
     * staff session.
     */
    private function isLivewireUpdateFromConsole(Request $request, string $path): bool
    {
        if (! $request->isMethod('POST') || $request->path() !== ltrim(EndpointResolver::updatePath(), '/')) {
            return false;
        }

        $components = $request->input('components');

        if (! is_array($components) || $components === []) {
            return false;
        }

        foreach ($components as $component) {
            $snapshot = is_array($component) && is_string($component['snapshot'] ?? null)
                ? json_decode($component['snapshot'], true)
                : null;
            $page = is_array($snapshot) ? ($snapshot['memo']['path'] ?? null) : null;

            if (! is_string($page) || ! $this->underPath($page, $path)) {
                return false;
            }
        }

        return true;
    }

    private function underPath(string $requestPath, string $consolePath): bool
    {
        $requestPath = trim($requestPath, '/');

        return $requestPath === $consolePath || str_starts_with($requestPath, $consolePath.'/');
    }

    private function string(string $key): string
    {
        $value = $this->config->get($key);

        return is_string($value) ? $value : '';
    }
}
