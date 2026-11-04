<?php

declare(strict_types=1);

namespace App\Dashboards;

use Illuminate\Database\Eloquent\Model;

/**
 * For the records the dashboards are worked out from (P08-04): bills,
 * payments and their allocations, receipts, tenant credit, expenses,
 * buildings, units, leases, tenants, import batches. Every save that
 * changes the record, and every delete (soft or not), gives the record's
 * organization a new dashboard generation after the commit
 * (DashboardGenerations), so cached figures are worked out again on their
 * next read. The module owning the model opts it in, so no module reaches
 * into another to say its figures changed (ARCHITECTURE.md § 8).
 *
 * Writes that skip model events (query-builder updates and deletes) say so
 * themselves: building moves (OrganizationMoves) and imports (through their
 * batch's save in the same transaction).
 *
 * @phpstan-require-extends Model
 */
trait ChangesDashboardFigures
{
    public static function bootChangesDashboardFigures(): void
    {
        static::saved(static function (Model $model): void {
            if ($model->wasRecentlyCreated || $model->wasChanged()) {
                self::dashboardFiguresChanged($model);
            }
        });

        static::deleted(static function (Model $model): void {
            self::dashboardFiguresChanged($model);
        });
    }

    /**
     * Whether this write can change dashboard figures; a model narrows it
     * by overriding (an import batch only once rows are written).
     */
    public function changesDashboardFigures(): bool
    {
        return true;
    }

    private static function dashboardFiguresChanged(Model $model): void
    {
        if (method_exists($model, 'changesDashboardFigures') && ! $model->changesDashboardFigures()) {
            return;
        }

        $organizationId = $model->getAttribute('organization_id');

        if (is_numeric($organizationId)) {
            app(DashboardGenerations::class)->changed((int) $organizationId);
        }
    }
}
