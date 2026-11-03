<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Livewire\Portal;

use App\Branding\BrandColours;
use App\Branding\Branding;
use App\Models\User;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\BuildingGroup\Enums\AuthorKind;
use Modules\BuildingGroup\Feed\GroupFeed;
use Modules\BuildingGroup\Livewire\Concerns\RunsGroupFeed;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\BuildingGroup\Portal\TenantGroups;
use Modules\Tenants\Contracts\PortalTenant;

/**
 * A Building Group in the tenant portal, /portal/groups/{group} (P07-02):
 * the same feed as the workspace's, written as a tenant of the building
 * (AuthorKind::Tenant, through the tenant record that makes them a
 * member). The group is looked up among the user's own on every request
 * (Portal\TenantGroups): a group they are not a member of now — never was,
 * or their lease there has ended — answers 404, and an open page goes back
 * to the list at its next refresh (P07-03). Phone first: one column,
 * 44 px targets, no autofocus.
 */
#[Layout('layouts::app')]
#[Title('Building Group')]
final class PortalGroupShow extends Component
{
    use RunsGroupFeed;

    #[Locked]
    public int $groupId;

    public function mount(TenantGroups $groups, int $group): void
    {
        $this->groupId = $group;
        $this->found($groups);
    }

    public function render(TenantGroups $groups, GroupFeed $feed): View
    {
        $user = $this->user();
        [$tenancy, $group] = $this->found($groups);
        $branding = Branding::fromOrganization($tenancy->organization);
        $live = $this->goLive();

        $state = $groups->within($tenancy, function () use ($user, $group, $groups, $feed, $tenancy): array {
            $gate = Gate::forUser($user);
            $images = $gate->allows('viewImagesAsTenant', $group);
            [$posts, $more] = $feed->page($group, $user, $this->limit, $images);
            $writable = ! $tenancy->readOnly();

            return [
                'name' => $groups->nameOf($group),
                'posts' => $posts,
                'more' => $more,
                'showImages' => $images,
                'canPost' => $writable && $gate->allows('postAsTenant', $group),
                'canAttach' => $writable && $gate->allows('attachImagesAsTenant', $group),
                'canComment' => $writable && $gate->allows('commentAsTenant', $group),
                'canReact' => $writable && $gate->allows('reactAsTenant', $group),
            ];
        });

        $this->keepBoxes($state['posts']);

        return view('buildinggroup::livewire.portal.group-show', $state + [
            'group' => $group,
            'agency' => $branding->displayName(),
            'colour' => BrandColours::textShade($branding->primaryColor),
            'initials' => $branding->initials(),
            'organizationId' => $tenancy->organization->id,
            'readOnly' => $tenancy->readOnly(),
            'live' => $live,
        ]);
    }

    protected function author(): AuthorKind
    {
        return AuthorKind::Tenant;
    }

    protected function inGroup(Closure $callback): mixed
    {
        $user = $this->user();
        $groups = app(TenantGroups::class);
        [$tenancy, $group] = $this->found($groups);

        return $groups->within($tenancy, static fn (): mixed => $callback($group, $user));
    }

    protected function leaveTo(): string
    {
        return route('portal.groups.index');
    }

    /** @return array{PortalTenant, BuildingGroup} */
    private function found(TenantGroups $groups): array
    {
        $found = $groups->find($this->user(), $this->groupId);
        abort_if($found === null, 404);

        return $found;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
