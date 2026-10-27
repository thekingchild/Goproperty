<?php

declare(strict_types=1);

namespace Modules\Properties\Livewire;

use App\Exports\Livewire\WithListExports;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Properties\Assignments\AgentNarrowing;
use Modules\Properties\Lists\OwnerList;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Owners\OwnerProfiles;

/**
 * Owners, /o/{organization}/owners: the organization's property owners
 * (DATABASE_SCHEMA.md § 1), paginated on the server, with a search over
 * name, email and phone and an Active / Archived switch. Members with
 * `owners.view` see the list; with `owners.manage` they archive and
 * restore (soft deletes only). An agent limited to some buildings sees the
 * owners of those buildings only (P02-03, AgentNarrowing). The rows come
 * from Lists\OwnerList, which also exports them (P02-07, Export button).
 *
 * Runs inside the `organization` middleware, replayed for Livewire updates:
 * every request has the organization's context and the component refuses
 * any other. Every action checks OwnerProfilePolicy (the service checks
 * again).
 */
#[Layout('layouts::app')]
#[Title('Owners')]
final class OwnerProfileIndex extends Component
{
    use WithListExports, WithPagination;

    public const int PER_PAGE = 25;

    #[Locked]
    public int $organizationId;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: false)]
    public bool $archived = false;

    #[Locked]
    public ?string $notice = null;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context): void
    {
        $this->organizationId = $context->id();
        $this->authorize('viewAny', OwnerProfile::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedArchived(): void
    {
        $this->resetPage();
    }

    public function archive(int $ownerId, OwnerProfiles $owners, AgentNarrowing $narrowing): void
    {
        $owner = $narrowing->owners(OwnerProfile::query(), $this->user())->findOrFail($ownerId);
        $this->authorize('delete', $owner);
        $this->resetErrorBag();
        $this->notice = null;

        // Refused (ValidationException, key `archive`) while the owner still owns active buildings.
        $owners->archive($this->user(), $owner);
        $this->notice = __(':name was archived. Find it under Archived to restore it.', ['name' => $owner->display_name]);
    }

    public function restore(int $ownerId, OwnerProfiles $owners, AgentNarrowing $narrowing): void
    {
        $owner = $narrowing->owners(OwnerProfile::query()->onlyTrashed(), $this->user())->findOrFail($ownerId);
        $this->authorize('restore', $owner);

        $owners->restore($this->user(), $owner);
        $this->notice = __(':name was restored.', ['name' => $owner->display_name]);
    }

    public function render(OrganizationContext $context, OwnerList $list): View
    {
        $owners = $list->query($this->user(), $list->filters($this->listExports()['owners']))->paginate(self::PER_PAGE);

        return view('properties::livewire.owner-profile-index', [
            'organization' => $context->get(),
            'owners' => $owners,
            'canManage' => $this->user()->can('create', OwnerProfile::class),
        ]);
    }

    /** The list on the page, with its filters (OwnerList; the Export button). */
    protected function listExports(): array
    {
        return ['owners' => ['search' => $this->search, 'archived' => $this->archived]];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
