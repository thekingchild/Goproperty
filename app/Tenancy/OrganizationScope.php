<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits every query of an organization-owned model to the current
 * organization. Without a context it throws instead of returning every
 * organization's rows (fail closed). Only the Platform namespace may remove
 * it (ARCHITECTURE.md § 4); a Larastan rule enforces that.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
final class OrganizationScope implements Scope
{
    /**
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(OrganizationContext::class);

        if (! $context->has()) {
            throw MissingOrganizationContext::forModel($model::class);
        }

        $builder->where($model->qualifyColumn('organization_id'), $context->id());
    }
}
