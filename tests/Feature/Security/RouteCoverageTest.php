<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureInstallerOpen;
use App\Http\Middleware\SetOrganizationContext;
use App\Models\Organization;
use App\Tenancy\BelongsToOrganization;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Filament\Http\Middleware\Authenticate as StaffAuthenticate;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Livewire\Component;

/*
 * Security review 2026-11 (docs/security/review-2026-11.md § "Lasting
 * guards"): AGENT.md § 3 rule 4 says every controller action, Livewire
 * component and console page checks a policy. These tests walk the whole
 * route list and every Livewire component, so a new route or component
 * that skips the checks fails here instead of reaching production:
 *
 * - every route is signed-in (customer `auth`, or the console's staff
 *   guard with two-factor), or on the public list below with its reason
 *   and its rate limit or signature;
 * - every workspace route (/o/{organization}) runs SetOrganizationContext,
 *   and every model it binds from the URL is organization-scoped, so
 *   another organization's id is a 404 (CrossTenantAccessTest probes the
 *   models themselves);
 * - portal and owner-portal routes (one person across organizations) bind
 *   no model from the URL: they look records up through the person's own
 *   tenancies (PortalTenancies, OwnerPortfolios);
 * - every Livewire component calls a policy or permission check, or is on
 *   the short list of components that check through a portal service.
 *
 * The console's pages × roles are walked by StaffTwoFactorEnforcementTest;
 * cross-organization ids on actions by each module's access tests and
 * CrossTenantAccessTest.
 */

/**
 * Routes nobody signs in for, by URI, with why and what limits them:
 * `throttle` (a rate limit on the route), `post-throttle` (a form whose
 * POST is limited), `signed` (a signed URL),
 * `inside` (the controller or a middleware limits it; the RateLimitTest
 * proves it) or `none` (static assets and public pages, nothing to guess).
 *
 * @return array<string, array{0: string, 1: 'throttle'|'post-throttle'|'signed'|'inside'|'none'}>
 */
function publicRoutes(): array
{
    return [
        // Sign-in, sign-up and recovery (UX_DESIGN.md § 3).
        'login' => ['the sign-in form, and its POST through the login limiter and lockout', 'post-throttle'],
        'register' => ['signup, its POST through the register limiter', 'post-throttle'],
        'register/check-email' => ['"check your email" after signup', 'none'],
        'forgot-password' => ['reset link request, its POST limited', 'post-throttle'],
        'reset-password/{token}' => ['the reset form; the token is the permission', 'none'],
        'reset-password' => ['the reset POST, limited by ThrottlePasswordChecks', 'inside'],
        'two-factor-challenge' => ['the code step of sign-in, its POST limited per account', 'post-throttle'],
        'passkeys/login/options' => ['passkey sign-in', 'throttle'],
        'passkeys/login' => ['passkey sign-in', 'throttle'],
        'email/verify/{id}/{hash}' => ['the emailed verification link', 'signed'],
        '.well-known/passkey-endpoints' => ['where browsers manage passkeys (two URLs, no data)', 'none'],
        // Links sent to people (tokens, hashed, one use, expiring).
        'invitations/{token}' => ['team invitation', 'throttle'],
        'portal/invitations/{token}' => ['tenant invitation', 'throttle'],
        'portal/sign-in/{token}' => ['agency-made one-time sign-in link', 'throttle'],
        'owner/invitations/{token}' => ['owner invitation', 'throttle'],
        'impersonation/enter/{session}' => ['the console\'s 60-second signed hand-off to a support session', 'throttle'],
        'impersonation/end' => ['ends only the session this browser holds (CSRF-protected)', 'none'],
        // Tenants paying without an account (BILLING_PAYMENTS.md).
        'pay/{reference}' => ['the bill\'s pay page by its reference', 'throttle'],
        'pay/{reference}/online' => ['Paystack checkout of a bill (signed link)', 'throttle'],
        'pay/{reference}/online/return' => ['Paystack\'s return; settlement asks Paystack', 'throttle'],
        'verify' => ['document verification form', 'throttle'],
        'verify/{code}' => ['document verification by code', 'throttle'],
        // Files: the signed link is the permission (ARCHITECTURE.md § 9).
        'media/{organization}/{media}/{conversion?}' => ['signed 15-minute media links, checked in MediaController', 'inside'],
        'brand/{role}' => ['the published platform theme images', 'none'],
        'storage/{path}' => ['Laravel\'s local-disk serving: refuses any request without a valid temporary signature', 'inside'],
        // Server-to-server calls, proved by signature or secret token.
        'webhooks/paystack' => ['GoProperty\'s own Paystack (HMAC)', 'throttle'],
        'webhooks/paystack/{token}' => ['an agency\'s Paystack (token + HMAC)', 'throttle'],
        'webhooks/email/{provider}/{token}' => ['email provider bounces (token + signature)', 'throttle'],
        'webhooks/whatsapp/{ulid}' => ['WhatsApp Cloud API (verify token / signature)', 'throttle'],
        'up' => ['health check', 'none'],
        'up/scheduler/{token}' => ['uptime monitor of the scheduler (secret token)', 'throttle'],
        // Public site (PLATFORM_CONSOLE.md § 9).
        '/' => ['home page', 'none'],
        'pricing' => ['pricing page', 'none'],
        'privacy' => ['privacy policy', 'none'],
        'terms' => ['terms', 'none'],
        'robots.txt' => ['robots', 'none'],
        'sitemap.xml' => ['sitemap', 'none'],
        'favicon.ico' => ['redirect to the theme icon', 'none'],
        // Packages' front-end files and endpoints.
        'flux/*' => ['Flux UI scripts and flags', 'none'],
        'livewire-*/update' => ['Livewire updates: replays the page route\'s middleware (auth, organization, staff) and checks the component\'s snapshot', 'none'],
        'livewire-*/upload-file' => ['Livewire temporary uploads (signed, limited)', 'throttle'],
        'livewire-*/preview-file/{filename}' => ['Livewire temporary previews (signed in the controller)', 'inside'],
        'livewire-*/*' => ['Livewire\'s scripts, maps and component assets', 'none'],
        // Filament's export/import downloads: no panel uses exports or imports
        // (no `exports`/`imports` tables); Filament checks the owner if one existed.
        'filament/exports/{export}/download' => ['unused Filament route (no exports)', 'none'],
        'filament/imports/{import}/failed-rows/download' => ['unused Filament route (no imports)', 'none'],
    ];
}

/** Console routes reached before signing in, or before two-factor is set up. */
const CONSOLE_BEFORE_SIGN_IN = ['login', 'invitation/{token}'];

const CONSOLE_BEFORE_TWO_FACTOR = ['login', 'invitation/{token}', 'logout', 'multi-factor-authentication/set-up'];

/**
 * Livewire classes with nothing to protect. Portal components count the
 * checks of their traits (the record rebuilt from the signed-in person's
 * own tenancies, then `viewAsTenant` inside its organization).
 */
const LIVEWIRE_WITHOUT_OWN_CHECK = [
    'App\Livewire\Onboarding\OrganizationForm' => 'a form object of CreateOrganization / SetupWizard, which check',
    'App\Livewire\Actions\Logout' => 'signs the current user out',
];

/** @return list<string> the route's middleware, resolved to classes (parameters kept after ":") */
function routeMiddleware(Route $route): array
{
    return array_values(array_filter(Router::gatherRouteMiddleware($route), 'is_string'));
}

function routeUses(Route $route, string $class): bool
{
    foreach (routeMiddleware($route) as $middleware) {
        if (explode(':', $middleware, 2)[0] === $class) {
            return true;
        }
    }

    return false;
}

function consoleRelativeUri(Route $route): ?string
{
    $path = trim((string) config('app.admin_path'), '/');

    if ($route->getDomain() !== null && $route->getDomain() === config('app.admin_domain')) {
        return $route->uri();
    }

    if ($path !== '' && ($route->uri() === $path || str_starts_with($route->uri(), $path.'/'))) {
        return ltrim(substr($route->uri(), strlen($path)), '/');
    }

    return null;
}

function publicEntryFor(Route $route): ?array
{
    foreach (publicRoutes() as $pattern => $entry) {
        if ($pattern === $route->uri() || ($pattern === '/' && $route->uri() === '/') || Str::is($pattern, $route->uri())) {
            return $entry;
        }
    }

    return null;
}

/**
 * The class and method a route runs: a controller method, an invokable
 * controller, or a Livewire page component's mount().
 *
 * @return array{0: class-string, 1: string}|null
 */
function routeTarget(Route $route): ?array
{
    $component = $route->getAction('livewire_component');

    if (is_string($component)) {
        $class = class_exists($component) ? $component : null;

        return $class !== null && method_exists($class, 'mount') ? [$class, 'mount'] : null;
    }

    $uses = $route->getAction('uses');

    if (! is_string($uses) || ! str_contains($uses, '@')) {
        return is_string($uses) && class_exists($uses) && method_exists($uses, '__invoke') ? [$uses, '__invoke'] : null;
    }

    [$class, $method] = explode('@', $uses, 2);

    return class_exists($class) && method_exists($class, $method) ? [$class, $method] : null;
}

/** @return list<string> "parameter: class" of route parameters bound to Eloquent models */
function boundModels(Route $route): array
{
    $target = routeTarget($route);

    if ($target === null) {
        return [];
    }

    $bound = [];

    foreach ((new ReflectionMethod($target[0], $target[1]))->getParameters() as $parameter) {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin() || ! in_array($parameter->getName(), $route->parameterNames(), true)) {
            continue;
        }

        if (is_subclass_of($type->getName(), Model::class)) {
            $bound[] = $parameter->getName().': '.$type->getName();
        }
    }

    return $bound;
}

/** @return list<string> routes neither signed in nor on the public list with their limit */
function uncoveredRoutes(): array
{
    $problems = [];

    foreach (Router::getRoutes() as $route) {
        $uri = $route->uri();
        $console = consoleRelativeUri($route);

        if ($console !== null) {
            if (! in_array($console, CONSOLE_BEFORE_SIGN_IN, true) && ! routeUses($route, StaffAuthenticate::class)) {
                $problems[] = "console {$uri}: no staff sign-in";
            }

            if (! in_array($console, CONSOLE_BEFORE_TWO_FACTOR, true) && ! routeUses($route, EnsureMultiFactorAuthenticationIsEnabled::class)) {
                $problems[] = "console {$uri}: no two-factor requirement";
            }

            continue;
        }

        if (routeUses($route, Authenticate::class)) {
            continue;
        }

        if (routeUses($route, EnsureInstallerOpen::class)) {
            // The installer: 404 once installed, then unlocked by the setup
            // token or file check (InstallerUnlockTest, INSTALLER.md § 3).
            continue;
        }

        $entry = publicEntryFor($route);

        if ($entry === null) {
            $problems[] = implode('|', $route->methods())." {$uri}: neither signed in nor on the public list (tests/Feature/Security/RouteCoverageTest.php)";

            continue;
        }

        $limited = match ($entry[1]) {
            'throttle' => routeUses($route, ThrottleRequests::class),
            'post-throttle' => routeUses($route, ThrottleRequests::class) || array_diff($route->methods(), ['GET', 'HEAD']) === [],
            'signed' => routeUses($route, ValidateSignature::class),
            default => true,
        };

        if (! $limited) {
            $problems[] = implode('|', $route->methods())." {$uri}: listed as {$entry[1]} but has no such middleware";
        }
    }

    return $problems;
}

it('signs in for every route, or lists it as public with its reason and its limit', function () {
    expect(uncoveredRoutes())->toBe([]);
});

it('notices a new route that neither signs in nor is listed', function () {
    Router::get('_probe/open-door', fn () => 'anyone')->middleware('web');
    Router::middleware(['web', 'auth'])->get('/'.config('app.admin_path').'/_probe/no-staff-guard', fn () => 'staff only?');
    Router::getRoutes()->refreshNameLookups();

    expect(uncoveredRoutes())->toContain('GET|HEAD _probe/open-door: neither signed in nor on the public list (tests/Feature/Security/RouteCoverageTest.php)')
        ->toContain('console '.config('app.admin_path').'/_probe/no-staff-guard: no staff sign-in');
});

it('rate limits every public route that takes a POST, unless it is the unused or self-limited kind', function () {
    $problems = [];

    foreach (Router::getRoutes() as $route) {
        if (consoleRelativeUri($route) !== null || routeUses($route, Authenticate::class) || routeUses($route, EnsureInstallerOpen::class)) {
            continue;
        }

        $entry = publicEntryFor($route);

        if ($entry === null || ! array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            continue;
        }

        if (! routeUses($route, ThrottleRequests::class) && ! in_array($entry[1], ['inside', 'none', 'signed'], true)) {
            $problems[] = implode('|', $route->methods()).' '.$route->uri();
        }
    }

    expect($problems)->toBe([]);
});

it('runs every workspace route inside its organization, signed in and verified, and binds only organization-scoped models', function () {
    $problems = [];
    $workspace = 0;

    foreach (Router::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'o/{organization}')) {
            continue;
        }

        $workspace++;

        foreach ([Authenticate::class, EnsureEmailIsVerified::class, SetOrganizationContext::class] as $required) {
            if (! routeUses($route, $required)) {
                $problems[] = $route->uri().': no '.class_basename($required);
            }
        }

        foreach (boundModels($route) as $binding) {
            [$name, $class] = explode(': ', $binding);

            if ($class !== Organization::class && ! in_array(BelongsToOrganization::class, class_uses_recursive($class), true)) {
                $problems[] = $route->uri().": binds {$binding}, which is not organization-scoped";
            }
        }
    }

    expect($workspace)->toBeGreaterThan(80)
        ->and($problems)->toBe([]);
});

it('binds no model from the URL on portal and owner-portal routes, which serve one person across organizations', function () {
    $problems = [];

    foreach (Router::getRoutes() as $route) {
        if (! preg_match('#^(portal|owner)(/|$)#', $route->uri())) {
            continue;
        }

        if (! routeUses($route, Authenticate::class) && publicEntryFor($route) === null) {
            $problems[] = $route->uri().': not signed in';
        }

        foreach (boundModels($route) as $binding) {
            $problems[] = $route->uri().": binds {$binding}";
        }
    }

    expect($problems)->toBe([]);
});

it('checks a policy or permission in every Livewire component, or lists how it checks', function () {
    $roots = [app_path('Livewire'), ...glob(base_path('Modules/*/app/Livewire')) ?: []];
    $problems = [];
    $components = 0;

    foreach ($roots as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Concerns/')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1 || preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $class) !== 1) {
                continue;
            }

            $name = $namespace[1].'\\'.$class[1];

            if (! class_exists($name) || ! is_subclass_of($name, Component::class) || (new ReflectionClass($name))->isAbstract()) {
                continue;
            }

            $components++;
            // The component's own code and its traits'.
            $code = $source;

            foreach (class_uses_recursive($name) as $trait) {
                $code .= (string) file_get_contents((string) (new ReflectionClass($trait))->getFileName());
            }

            $checks = preg_match('/->authorize\(|Gate::|->can\(|->cannot\(|->allows\(|->denies\(|ReadOnlyOwnerPage/', $code) === 1;

            if (! $checks && ! array_key_exists($name, LIVEWIRE_WITHOUT_OWN_CHECK)) {
                $problems[] = "{$name}: no policy or permission check";
            }

            if ($checks && array_key_exists($name, LIVEWIRE_WITHOUT_OWN_CHECK)) {
                $problems[] = "{$name}: checks now; take it off LIVEWIRE_WITHOUT_OWN_CHECK";
            }
        }
    }

    expect($components)->toBeGreaterThan(90)
        ->and($problems)->toBe([]);
});
