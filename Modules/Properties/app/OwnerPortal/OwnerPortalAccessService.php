<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use App\Models\User;
use App\Tenancy\OrganizationContext;
use Closure;
use Modules\Properties\Contracts\OwnerPortalAccess;
use Modules\Properties\Contracts\PortalOwner;
use Modules\Properties\Models\OwnerProfile;

/**
 * The OwnerPortalAccess contract (P08-05): OwnerPortfolios (the "linked to
 * my user" path, each profile allowed by OwnerProfilePolicy::viewAsOwner)
 * for other modules' /owner pages.
 */
final readonly class OwnerPortalAccessService implements OwnerPortalAccess
{
    public function __construct(
        private OwnerPortfolios $portfolios,
        private OrganizationContext $context,
    ) {}

    public function owners(User $user): array
    {
        $owners = [];

        foreach ($this->portfolios->map($user, static fn (OwnerPortfolio $portfolio): array => self::ownersOf($portfolio)) as $found) {
            array_push($owners, ...$found);
        }

        return $owners;
    }

    public function within(User $user, int $ownerProfileId, Closure $callback): mixed
    {
        foreach ($this->portfolios->of($user) as $portfolio) {
            if (in_array($ownerProfileId, $portfolio->ownerProfileIds, true)) {
                return $this->context->run($portfolio->organization, static function () use ($portfolio, $ownerProfileId, $callback): mixed {
                    foreach (self::ownersOf($portfolio) as $owner) {
                        if ($owner->ownerProfileId === $ownerProfileId) {
                            return $callback($owner);
                        }
                    }

                    return null;
                });
            }
        }

        return null;
    }

    /**
     * The portfolio's owner profiles, inside its organization.
     *
     * @return list<PortalOwner>
     */
    private static function ownersOf(OwnerPortfolio $portfolio): array
    {
        return array_values(OwnerProfile::query()->whereKey($portfolio->ownerProfileIds)->orderBy('id')->get()
            ->map(static fn (OwnerProfile $owner): PortalOwner => new PortalOwner(
                organizationId: (int) $portfolio->organization->id,
                organizationName: (string) $portfolio->organization->name,
                ownerProfileId: $owner->id,
                ownerName: $owner->display_name,
            ))->all());
    }
}
