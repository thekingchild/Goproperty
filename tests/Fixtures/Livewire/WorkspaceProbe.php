<?php

declare(strict_types=1);

namespace Tests\Fixtures\Livewire;

use App\Tenancy\AllowedWhileSuspended;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A stand-in workspace component for the suspension tests: a write, an
 * export marked #[AllowedWhileSuspended], pagination and event listeners.
 * It renders the organization in context, so an update request without the
 * context (persistent middleware missing) fails.
 */
final class WorkspaceProbe extends Component
{
    use WithPagination;

    public string $search = '';

    public int $saves = 0;

    public int $exports = 0;

    /** @var array<string, string> */
    protected $listeners = ['probe-save' => 'save', 'probe-refresh' => '$refresh'];

    public function save(): void
    {
        Cache::store('array')->increment('probe.livewire-writes');
        $this->saves++;
    }

    #[AllowedWhileSuspended]
    public function export(): void
    {
        $this->exports++;
    }

    public function render(): string
    {
        $slug = app(OrganizationContext::class)->get()->slug;

        return <<<HTML
            <div>Workspace {$slug}: saves {{ \$saves }}, exports {{ \$exports }}</div>
            HTML;
    }
}
