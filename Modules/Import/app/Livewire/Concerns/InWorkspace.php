<?php

declare(strict_types=1);

namespace Modules\Import\Livewire\Concerns;

use App\Models\User;
use App\Tenancy\OrganizationContext;
use Livewire\Attributes\Locked;

/**
 * For the import pages: they run inside the `organization` middleware,
 * replayed for Livewire updates, so every request has the organization's
 * context; a component refuses any other organization than the one it was
 * opened in (404).
 */
trait InWorkspace
{
    #[Locked]
    public int $organizationId;

    public function bootInWorkspace(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrateInWorkspace(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    protected function enterWorkspace(OrganizationContext $context): void
    {
        $this->organizationId = $context->id();
    }

    protected function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
