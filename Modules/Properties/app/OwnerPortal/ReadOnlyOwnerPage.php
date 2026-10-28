<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

/**
 * Marks the owner portal's Livewire pages (P03-15): read-only by design
 * (nothing there is editable). The module's provider refuses every method
 * call to such a component (403) except a refresh, so no action can be
 * added to one by accident and no call sent by hand reaches one.
 */
interface ReadOnlyOwnerPage {}
