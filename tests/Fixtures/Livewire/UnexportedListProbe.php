<?php

declare(strict_types=1);

namespace Tests\Fixtures\Livewire;

use Livewire\Component;
use Livewire\WithPagination;

/** A paginated list page without an export, for the "every list exports" check (P02-07). */
final class UnexportedListProbe extends Component
{
    use WithPagination;

    public function render(): string
    {
        return '<div>A list without an export</div>';
    }
}
