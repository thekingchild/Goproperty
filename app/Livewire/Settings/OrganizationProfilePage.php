<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\Organization;
use App\Models\User;
use App\Onboarding\OrganizationSlug;
use App\Organizations\OrganizationProfile;
use App\Support\NigerianStates;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings → Profile, /o/{organization}/settings/profile: name, web address,
 * address, state, LGA, CAC number (OrganizationProfile). Org-owners and
 * administrators see it (`settings.view`, kept while suspended: read-only
 * then) and save it (`organization.update`). A new web address takes the
 * user to the page at the new address; the old one keeps redirecting.
 *
 * Inside the `organization` middleware (replayed for Livewire updates);
 * refuses a context for another organization.
 */
#[Layout('layouts::app')]
#[Title('Organization profile')]
final class OrganizationProfilePage extends Component
{
    #[Locked]
    public int $organizationId;

    public string $name = '';

    public string $slug = '';

    public string $address = '';

    public string $state = '';

    public string $lga = '';

    public string $cacNumber = '';

    #[Locked]
    public ?string $status = null;

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
        $organization = $context->get();
        $this->organizationId = $organization->id;
        $this->authorize('viewSettings', $organization);
        $this->fillFrom($organization);
    }

    public function save(OrganizationContext $context, OrganizationProfile $profile): void
    {
        $organization = $context->get();
        $this->authorize('updateProfile', $organization);
        $oldSlug = $organization->slug;

        $saved = $profile->update($this->user(), $organization, [
            'name' => $this->name,
            'slug' => $this->slug,
            'address' => $this->address,
            'state' => $this->state,
            'lga' => $this->lga,
            'cacNumber' => $this->cacNumber,
        ]);

        if ($saved->slug !== $oldSlug) {
            session()->flash('settings-status', __('Saved. Your workspace is now at /o/:slug; the old address redirects here.', ['slug' => $saved->slug]));
            $this->redirectRoute('workspace.settings.profile', ['organization' => $saved->slug]);

            return;
        }

        $this->fillFrom($saved);
        $this->status = __('Profile saved.');
    }

    public function render(OrganizationContext $context): View
    {
        $organization = $context->get();

        return view('livewire.settings.organization-profile-page', [
            'organization' => $organization,
            'states' => NigerianStates::ALL,
            'canEdit' => $this->user()->can('updateProfile', $organization),
            'locked' => OrganizationProfile::registrationLocked($organization),
            'host' => preg_replace('#^https?://#', '', rtrim(url('/'), '/')),
            'slugMax' => OrganizationSlug::MAX,
            'flash' => session('settings-status'),
        ]);
    }

    private function fillFrom(Organization $organization): void
    {
        $this->name = $organization->name;
        $this->slug = $organization->slug;
        $this->address = $organization->address ?? '';
        $this->state = $organization->state ?? '';
        $this->lga = $organization->lga ?? '';
        $this->cacNumber = $organization->cac_number ?? '';
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
