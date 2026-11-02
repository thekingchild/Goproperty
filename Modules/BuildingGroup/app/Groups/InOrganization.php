<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Groups;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Closure;

/** Runs work for an event's organization: in the current context when it is that one, else in its own. */
final readonly class InOrganization
{
    public function __construct(private OrganizationContext $context) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(int $organizationId, Closure $callback): mixed
    {
        if ($this->context->has() && $this->context->id() === $organizationId) {
            return $callback();
        }

        return $this->context->run(Organization::query()->findOrFail($organizationId), static fn (): mixed => $callback());
    }
}
