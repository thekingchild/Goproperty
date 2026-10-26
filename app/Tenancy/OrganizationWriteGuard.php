<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use Illuminate\Auth\Access\Response;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use ReflectionMethod;

/**
 * The one rule for suspended (and cancelled) organizations: their members
 * can still sign in, read and export, but every write is refused, from the
 * very next request (BILLING_PAYMENTS.md § 2, ONBOARDING_COMPLIANCE.md § 3
 * and § 5.5). The status is read from the organization the request loaded
 * (SetOrganizationContext reads it fresh each request; nothing caches it).
 *
 * Applied centrally, so later features are covered without their own check
 * (ARCHITECTURE.md § 4, "Suspended organizations"):
 *
 * - HTTP: SetOrganizationContext refuses any non-GET request inside
 *   /o/{organization} unless the action carries #[AllowedWhileSuspended].
 * - Livewire: RefuseLivewireWritesWhileSuspended refuses every method call
 *   on a component inside a suspended organization except pagination,
 *   refreshes and #[AllowedWhileSuspended] methods.
 * - Permissions and policies: members lose every permission whose
 *   OrganizationPermission::allowedWhileSuspended() is false
 *   (HasOrganizationRoles::hasPermissionTo), and a Gate::before hook denies
 *   every policy ability that is not a read (view…, export…, download…).
 */
final class OrganizationWriteGuard
{
    /** Policy abilities that only read; any other ability is a write. */
    private const string READ_ABILITY = '/^(view|export|download)([A-Z_]|$)|^switchTo$/';

    public function __construct(private readonly OrganizationContext $context) {}

    /** The organization in context, if it refuses writes. */
    public function blockedOrganization(): ?Organization
    {
        if (! $this->context->has()) {
            return null;
        }

        $organization = $this->context->get();

        return $organization->status->blocksWrites() ? $organization : null;
    }

    /** May a member use this customer permission in the current organization? */
    public function allowsPermission(string $permission): bool
    {
        return $this->blockedOrganization() === null
            || (OrganizationPermission::tryFrom($permission)?->allowedWhileSuspended() ?? false);
    }

    /**
     * For Gate::before: a denial with the reason when the ability writes to
     * a suspended organization (the one passed, else the one in context),
     * null to let the permission and policy decide.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    public function inspect(string $ability, array $arguments = []): ?Response
    {
        $organization = $this->organizationFor($arguments);

        if ($organization === null || ! $organization->status->blocksWrites() || $this->isRead($ability)) {
            return null;
        }

        return Response::deny($this->message($organization));
    }

    public function isRead(string $ability): bool
    {
        $permission = OrganizationPermission::tryFrom($ability);

        return $permission !== null
            ? $permission->allowedWhileSuspended()
            : preg_match(self::READ_ABILITY, $ability) === 1;
    }

    /** For SetOrganizationContext: refuses a non-GET request in a suspended organization. */
    public function ensureRequestAllowed(Request $request): void
    {
        $organization = $this->blockedOrganization();

        if ($organization === null || $request->isMethodSafe()) {
            return;
        }

        $route = $request->route();
        $controller = $route instanceof Route ? $route->getControllerClass() : null;

        if ($route instanceof Route && $controller !== null && $this->methodAllowed($controller, $route->getActionMethod())) {
            return;
        }

        $this->refuse($organization);
    }

    /** Does the method carry #[AllowedWhileSuspended]? */
    public function methodAllowed(string $class, string $method): bool
    {
        return method_exists($class, $method)
            && (new ReflectionMethod($class, $method))->getAttributes(AllowedWhileSuspended::class) !== [];
    }

    /** @throws OrganizationWritesBlocked */
    public function refuse(Organization $organization): never
    {
        throw new OrganizationWritesBlocked($this->message($organization));
    }

    public function message(Organization $organization): string
    {
        return $organization->status === OrganizationStatus::Cancelled
            ? "{$organization->name} is closed, so nothing can be changed. You can still view and export its data."
            : "{$organization->name} is suspended, so nothing can be changed until it is reactivated. You can still sign in, view and export its data.";
    }

    /** @param  array<array-key, mixed>  $arguments */
    private function organizationFor(array $arguments): ?Organization
    {
        $first = $arguments[array_key_first($arguments) ?? 0] ?? null;

        if ($first instanceof Organization) {
            return $first;
        }

        return $this->context->has() ? $this->context->get() : null;
    }
}
