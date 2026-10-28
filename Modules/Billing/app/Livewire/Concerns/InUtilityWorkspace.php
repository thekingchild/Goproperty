<?php

declare(strict_types=1);

namespace Modules\Billing\Livewire\Concerns;

use App\Models\User;
use App\Tenancy\OrganizationContext;

/**
 * What every utilities page does (P03-06a): runs only inside the
 * `organization` middleware (replayed for Livewire updates), and refuses a
 * snapshot from another organization.
 */
trait InUtilityWorkspace
{
    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function organizationSlug(): string
    {
        return (string) app(OrganizationContext::class)->get()->slug;
    }
}
