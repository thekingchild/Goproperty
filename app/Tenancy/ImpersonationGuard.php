<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Enums\OrganizationPermission;
use App\Models\Organization;
use App\Platform\Impersonation\ImpersonationAudit;
use App\Tenancy\Livewire\RefuseLivewireWritesWhileSuspended;
use Illuminate\Auth\Access\Response;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * What GoProperty staff may do while impersonating a user
 * (PLATFORM_CONSOLE.md § 6, AGENT.md § 3 rule 9). Applied in the same four
 * places as the suspended-organization rule (OrganizationWriteGuard), so a
 * feature is covered without its own check:
 *
 * - HTTP: ResumeImpersonation refuses sign-in and account-security routes,
 *   actions marked #[BlockedWhileImpersonating], and in read-only mode any
 *   request that is not a GET, except exports marked
 *   #[AllowedWhileSuspended] (Livewire calls are judged below).
 * - Livewire: RefuseLivewireCallsWhileImpersonating refuses methods (or
 *   components) marked #[BlockedWhileImpersonating], and in read-only mode
 *   every call except paging, refreshes and #[AllowedWhileSuspended] exports.
 * - Permissions: HasOrganizationRoles::hasPermissionTo drops every
 *   permission OrganizationPermission::blockedWhileImpersonating() names,
 *   and in read-only mode every permission that writes.
 * - Policies: a Gate::before hook denies the same permissions, policy
 *   methods marked #[BlockedWhileImpersonating], abilities that delete or
 *   void (delete…, forceDelete…, destroy…, remove…, purge…, void…), in
 *   read-only mode every ability that is not a read, and any organization
 *   other than the session's.
 *
 * Only the impersonated user is affected, only during a session. Refused
 * requests and calls are written to the audit log (`impersonation.blocked`).
 */
final class ImpersonationGuard
{
    /** Policy abilities that only read (as for suspended organizations). */
    private const string READ_ABILITY = '/^(view|export|download)([A-Z_]|$)|^switchTo$/';

    /** Policy abilities that delete or void: always refused ("deleting anything"). */
    private const string DELETE_ABILITY = '/^(delete|forceDelete|destroy|remove|purge|void)([A-Z_]|$)/';

    /**
     * Customer routes that change or reveal sign-in details, or sign in:
     * password, 2FA (incl. recovery codes and the secret), passkeys, the
     * email verification mail, password confirmation (staff never know the
     * password), and the sign-in and sign-up forms.
     */
    private const array BLOCKED_ROUTES = [
        'security.edit', 'password.*', 'two-factor.*', 'passkey.*', 'verification.send',
        'user-password.*', 'user-profile-information.*', 'login.store', 'register', 'register.*',
    ];

    /** Livewire calls that change nothing (as for suspended organizations). */
    private const array READ_CALLS = ['$refresh', '$set', '$sync', '$commit', 'previousPage', 'nextPage', 'gotoPage', 'resetPage', 'setPage'];

    public const string READ_ONLY_MESSAGE = 'This is a read-only GoProperty support session: nothing can be changed.';

    public const string BLOCKED_MESSAGE = 'GoProperty support cannot do this while viewing an account: money actions, bank and Paystack settings, billing transfers, sign-in details, members and deleting are always blocked.';

    public function __construct(
        private readonly CurrentImpersonation $current,
        private readonly ImpersonationAudit $audit,
    ) {}

    public function active(): bool
    {
        return $this->current->active();
    }

    /** For HasOrganizationRoles: may the impersonated user use this customer permission now? */
    public function allowsPermission(mixed $user, string $permission): bool
    {
        if (! $this->current->isImpersonated($user)) {
            return true;
        }

        $known = OrganizationPermission::tryFrom($permission);

        if ($known === null) {
            // A permission given another way (by ID, unknown name): fail closed.
            return false;
        }

        return ! $known->blockedWhileImpersonating()
            && (! $this->current->readOnly() || $known->allowedWhileSuspended());
    }

    /**
     * For Gate::before: a denial with the reason when the impersonated user
     * may not use the ability now, null to let the permission and policy
     * decide.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    public function inspect(mixed $user, string $ability, array $arguments = []): ?Response
    {
        $session = $this->current->session();

        if ($session === null || ! $this->current->isImpersonated($user)) {
            return null;
        }

        $first = $arguments[array_key_first($arguments) ?? 0] ?? null;

        if ($first instanceof Organization && $first->getKey() !== $session->organization_id) {
            return Response::deny('This support session is limited to one organization.');
        }

        if (OrganizationPermission::tryFrom($ability) !== null) {
            return $this->allowsPermission($user, $ability) ? null : Response::deny($this->message());
        }

        if (preg_match(self::DELETE_ABILITY, $ability) === 1 || $this->policyMethodBlocked($ability, $first)) {
            return Response::deny(self::BLOCKED_MESSAGE);
        }

        if ($this->current->readOnly() && preg_match(self::READ_ABILITY, $ability) !== 1) {
            return Response::deny(self::READ_ONLY_MESSAGE);
        }

        return null;
    }

    /** For ResumeImpersonation: refuses a request staff may not make now. */
    public function ensureRequestAllowed(Request $request): void
    {
        if (! $this->active()) {
            return;
        }

        $route = $request->route();

        if ($route instanceof Route && $route->getName() !== null && Str::is(self::BLOCKED_ROUTES, $route->getName())) {
            $this->refuse(self::BLOCKED_MESSAGE, ['route' => $route->getName()]);
        }

        $controller = $route instanceof Route ? $route->getControllerClass() : null;

        if ($route instanceof Route && $controller !== null && $this->marked($controller, $route->getActionMethod())) {
            $this->refuse(self::BLOCKED_MESSAGE, ['route' => $route->getName(), 'action' => $controller.'@'.$route->getActionMethod()]);
        }

        // Read-only: nothing but reads and exports (#[AllowedWhileSuspended]
        // marks an export; one that also pays is refused by its permission).
        if ($this->current->readOnly()
            && ! $request->isMethodSafe()
            && ! $this->isLivewireUpdate($request)
            && ! $request->routeIs('impersonation.end')
            && ! ($route instanceof Route && $controller !== null && app(OrganizationWriteGuard::class)->methodAllowed($controller, $route->getActionMethod()))) {
            $this->refuse(self::READ_ONLY_MESSAGE, ['route' => $route instanceof Route ? $route->getName() : null]);
        }
    }

    /** For the Livewire before('call') hook: refuses a component method call. */
    public function ensureCallAllowed(Component $component, string $method, mixed $params): void
    {
        if (! $this->active()) {
            return;
        }

        $target = $method === '__dispatch' ? RefuseLivewireWritesWhileSuspended::listenerMethod($component, $params) : $method;

        if ($target === null) {
            return;
        }

        $context = ['component' => $component->getName(), 'method' => $target];

        if ($this->marked($component::class, $target)) {
            $this->refuse(self::BLOCKED_MESSAGE, $context);
        }

        if ($this->current->readOnly()
            && ! in_array($target, self::READ_CALLS, true)
            && ! app(OrganizationWriteGuard::class)->methodAllowed($component::class, $target)) {
            $this->refuse(self::READ_ONLY_MESSAGE, $context);
        }
    }

    /**
     * Records the refusal in the audit log and answers 403.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws ImpersonationBlocked
     */
    public function refuse(string $message, array $context = []): never
    {
        $session = $this->current->session();

        if ($session !== null && ! $this->current->refusalRecorded()) {
            $request = app()->bound('request') ? request() : null;
            $what = isset($context['method']) && isset($context['component'])
                ? $context['component'].'::'.$context['method']
                : ($request !== null ? $request->method().' /'.ltrim($request->path(), '/') : 'request');
            $this->audit->record($session, 'impersonation.blocked', 'Blocked: '.$what, array_filter($context + [
                'method' => $request?->method(),
                'path' => $request !== null ? '/'.ltrim($request->path(), '/') : null,
                'message' => $message,
            ], static fn (mixed $value): bool => $value !== null));
            $this->current->noteRefusal();
        }

        throw new ImpersonationBlocked($message);
    }

    /** Does the class, or the method on it, carry #[BlockedWhileImpersonating]? */
    public function marked(string $class, string $method): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        if ((new ReflectionClass($class))->getAttributes(BlockedWhileImpersonating::class) !== []) {
            return true;
        }

        return method_exists($class, $method)
            && (new ReflectionMethod($class, $method))->getAttributes(BlockedWhileImpersonating::class) !== [];
    }

    public function isLivewireUpdate(Request $request): bool
    {
        return $request->isMethod('POST') && trim($request->path(), '/') === trim(EndpointResolver::updatePath(), '/');
    }

    private function message(): string
    {
        return $this->current->readOnly() ? self::READ_ONLY_MESSAGE : self::BLOCKED_MESSAGE;
    }

    /** A policy method for the ability marked #[BlockedWhileImpersonating]. */
    private function policyMethodBlocked(string $ability, mixed $first): bool
    {
        if (! is_object($first) && ! (is_string($first) && class_exists($first))) {
            return false;
        }

        try {
            $policy = Gate::getPolicyFor($first);
        } catch (Throwable) {
            return false;
        }

        return is_object($policy) && method_exists($policy, $ability) && $this->marked($policy::class, $ability);
    }
}
