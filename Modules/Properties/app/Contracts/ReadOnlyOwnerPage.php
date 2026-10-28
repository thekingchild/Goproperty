<?php

declare(strict_types=1);

namespace Modules\Properties\Contracts;

/**
 * Marks the owner portal's Livewire pages (P03-15): read-only by design
 * (nothing there is editable). The Properties provider refuses every
 * method call to such a component (403) except a refresh, so no action can
 * be added to one by accident and no call sent by hand reaches one. Other
 * modules' owner pages (the owner statements, P08-05) implement it too.
 */
interface ReadOnlyOwnerPage {}
