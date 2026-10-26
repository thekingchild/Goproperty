<?php

declare(strict_types=1);

namespace App\Tenancy\Livewire;

use App\Tenancy\ImpersonationGuard;
use Livewire\Component;

/**
 * Livewire's part of ImpersonationGuard: during a GoProperty support
 * session, a component method marked #[BlockedWhileImpersonating] (or any
 * method of a component so marked) is refused before it runs, and in
 * read-only mode so is every call except paging, refreshes and
 * #[AllowedWhileSuspended] exports. Event listeners (`__dispatch`) are
 * judged by the method they call. Registered with Livewire\before('call').
 */
final class RefuseLivewireCallsWhileImpersonating
{
    public function __construct(private readonly ImpersonationGuard $guard) {}

    public function __invoke(mixed $component, mixed $method, mixed $params): void
    {
        if (! $this->guard->active() || ! $component instanceof Component || ! is_string($method)) {
            return;
        }

        $this->guard->ensureCallAllowed($component, $method, $params);
    }
}
