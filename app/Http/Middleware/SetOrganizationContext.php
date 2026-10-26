<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use App\Tenancy\OrganizationWriteGuard;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * For /o/{organization} routes (ARCHITECTURE.md § 4): finds the organization
 * by slug, checks the signed-in user is a member, and sets the context
 * before route model binding, so models bound from the URL are already
 * scoped. Unknown organizations and non-members both get a plain 404, so
 * slugs cannot be probed. An old slug redirects to the current one, for
 * members only. Membership is OrganizationPolicy::view. Spatie's permission
 * team follows the context (OrganizationTeamResolver).
 *
 * In a suspended or cancelled organization any request that is not a GET
 * is refused here unless its action carries #[AllowedWhileSuspended]
 * (OrganizationWriteGuard). Livewire runs this again on every update request
 * (persistent middleware, with the page's method), so components know their
 * organization; their method calls are judged by
 * RefuseLivewireWritesWhileSuspended.
 */
final class SetOrganizationContext
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationWriteGuard $writes,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $slug = $route instanceof Route ? $route->parameter('organization') : null;

        if (! is_string($slug) || $slug === '') {
            throw new NotFoundHttpException;
        }

        $organization = Organization::query()->where('slug', $slug)->first();

        if ($organization === null) {
            $renamed = Organization::query()->whereJsonContains('previous_slugs', $slug)->first();

            if ($renamed !== null && $this->isMember($request, $renamed)) {
                return new RedirectResponse($this->currentUrl($request, $slug, $renamed->slug), 301);
            }

            throw new NotFoundHttpException;
        }

        if (! $this->isMember($request, $organization)) {
            throw new NotFoundHttpException;
        }

        $this->context->set($organization);
        /** @var Route $route */
        $route->setParameter('organization', $organization);
        URL::defaults(['organization' => $organization->slug]);

        $this->writes->ensureRequestAllowed($request);

        return $next($request);
    }

    private function isMember(Request $request, Organization $organization): bool
    {
        $user = $request->user();

        return $user instanceof User && Gate::forUser($user)->allows('view', $organization);
    }

    private function currentUrl(Request $request, string $old, string $new): string
    {
        $path = (string) preg_replace('#^o/'.preg_quote($old, '#').'(?=/|$)#', 'o/'.$new, $request->path());
        $query = $request->getQueryString();

        return $request->getSchemeAndHttpHost().$request->getBaseUrl().'/'.ltrim($path, '/').($query !== null ? '?'.$query : '');
    }
}
