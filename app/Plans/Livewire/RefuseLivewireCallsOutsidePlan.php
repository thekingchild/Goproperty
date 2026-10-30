<?php

declare(strict_types=1);

namespace App\Plans\Livewire;

use App\Plans\PlanGate;
use App\Tenancy\Livewire\RefuseLivewireWritesWhileSuspended;
use Livewire\Component;

/**
 * Livewire's part of PlanGate (P06-01b): a component method marked
 * #[RequiresFeature] or #[CountsAgainstLimit] (or a component so marked)
 * is refused before it runs when the organization's plan does not allow
 * it. Event listeners (`__dispatch`) are judged by the method they call.
 * Registered with Livewire\before('call').
 */
final readonly class RefuseLivewireCallsOutsidePlan
{
    /** Magic actions and pagination: they change nothing. */
    private const array READS = ['$refresh', '$set', '$sync', '$commit', 'previousPage', 'nextPage', 'gotoPage', 'resetPage', 'setPage'];

    public function __construct(private PlanGate $gate) {}

    public function __invoke(mixed $component, mixed $method, mixed $params): void
    {
        if (! $component instanceof Component || ! is_string($method)) {
            return;
        }

        $target = $method === '__dispatch' ? RefuseLivewireWritesWhileSuspended::listenerMethod($component, $params) : $method;

        if ($target === null || in_array($target, self::READS, true)) {
            return;
        }

        $this->gate->ensureCallAllowed($component::class, $target);
    }
}
