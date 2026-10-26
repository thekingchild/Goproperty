<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For every customer-domain model (AGENT.md § 3 rule 1, ARCHITECTURE.md § 4):
 * queries are limited to the current organization, `organization_id` is
 * filled from the context on create, and a record can never be created in
 * or moved to another organization.
 *
 * @property int $organization_id
 *
 * @phpstan-require-extends Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function (Model $model): void {
            $context = app(OrganizationContext::class);

            if (! $context->has()) {
                throw MissingOrganizationContext::forModel($model::class);
            }

            $current = $model->getAttribute('organization_id');

            if ($current === null) {
                $model->setAttribute('organization_id', $context->id());
            } elseif ((int) $current !== $context->id()) {
                throw new CrossOrganizationWrite($model::class.' cannot be created in another organization than the current one.');
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('organization_id')) {
                throw new CrossOrganizationWrite($model::class.' cannot be moved to another organization.');
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
