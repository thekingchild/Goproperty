<?php

declare(strict_types=1);

namespace Modules\Properties\Livewire;

use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Owners\OwnerProfiles;

/**
 * Owners, /o/{organization}/owners: the organization's property owners
 * (DATABASE_SCHEMA.md § 1), paginated on the server, with a search over
 * name, email and phone and an Active / Archived switch. Members with
 * `owners.view` see the list; with `owners.manage` they archive and
 * restore (soft deletes only).
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
    use WithPagination;

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

    public function archive(int $ownerId, OwnerProfiles $owners): void
    {
        $owner = OwnerProfile::query()->findOrFail($ownerId);
        $this->authorize('delete', $owner);

        $owners->archive($this->user(), $owner);
        $this->notice = __(':name was archived. Find it under Archived to restore it.', ['name' => $owner->display_name]);
    }

    public function restore(int $ownerId, OwnerProfiles $owners): void
    {
        $owner = OwnerProfile::query()->onlyTrashed()->findOrFail($ownerId);
        $this->authorize('restore', $owner);

        $owners->restore($this->user(), $owner);
        $this->notice = __(':name was restored.', ['name' => $owner->display_name]);
    }

    public function render(OrganizationContext $context): View
    {
        $search = trim($this->search);

        $owners = OwnerProfile::query()
            ->when($this->archived, fn (Builder $query) => $query->onlyTrashed())
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $digits = preg_replace('/\D/', '', $search) ?? '';

                $query->where(function (Builder $query) use ($like, $digits): void {
                    $query->where('display_name', 'like', $like)->orWhere('email', 'like', $like);

                    // Phones are stored as +234…; "0803 123" finds +234803123….
                    if (strlen($digits) >= 4) {
                        $query->orWhere('phone', 'like', '%'.(str_starts_with($digits, '0') ? substr($digits, 1) : $digits).'%');
                    }
                });
            })
            ->orderBy('display_name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE);

        return view('properties::livewire.owner-profile-index', [
            'organization' => $context->get(),
            'owners' => $owners,
            'canManage' => $this->user()->can('create', OwnerProfile::class),
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
