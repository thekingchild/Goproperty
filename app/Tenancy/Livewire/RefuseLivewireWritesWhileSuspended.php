<?php

declare(strict_types=1);

namespace App\Tenancy\Livewire;

use App\Tenancy\OrganizationWriteGuard;
use Livewire\Component;
use Livewire\Features\SupportEvents\SupportEvents;
use Throwable;

/**
 * Livewire's part of OrganizationWriteGuard: on an update request inside a
 * suspended organization (SetOrganizationContext runs again as persistent
 * middleware), every method call is refused before it runs, unless it only
 * pages, refreshes or syncs, or the method carries #[AllowedWhileSuspended].
 * Event listeners (`__dispatch`) are judged by the method they call.
 * Registered with Livewire\before('call'), ahead of Livewire's own hooks,
 * which run listeners themselves.
 */
final class RefuseLivewireWritesWhileSuspended
{
    /** Magic actions and pagination: they change no data. */
    private const array READS = ['$refresh', '$set', '$sync', '$commit', 'previousPage', 'nextPage', 'gotoPage', 'resetPage', 'setPage'];

    public function __construct(private readonly OrganizationWriteGuard $guard) {}

    public function __invoke(mixed $component, mixed $method, mixed $params): void
    {
        $organization = $this->guard->blockedOrganization();

        if ($organization === null || ! $component instanceof Component || ! is_string($method)) {
            return;
        }

        $target = $method === '__dispatch' ? self::listenerMethod($component, $params) : $method;

        if ($target === null || in_array($target, self::READS, true) || $this->guard->methodAllowed($component::class, $target)) {
            return;
        }

        $this->guard->refuse($organization);
    }

    /** The method an event would run; null for an unknown event (Livewire refuses it itself). */
    public static function listenerMethod(Component $component, mixed $params): ?string
    {
        $event = is_array($params) ? ($params[0] ?? null) : null;

        if (! is_string($event)) {
            return null;
        }

        try {
            $method = SupportEvents::getListenerMethodName($component, $event);
        } catch (Throwable) {
            return null;
        }

        return is_string($method) ? $method : null;
    }
}
