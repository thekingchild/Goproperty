<?php

declare(strict_types=1);

use App\Privacy\Data\PersonalDataMap;
use App\Privacy\Erasure\OrganizationPurge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Tests\Support\OrganizationScopedModels;

/*
 * P09-02: the NDPA tooling's map covers the whole schema, so a future
 * table cannot be silently left out of exports and erasures.
 */

it('lists every table that carries organization_id, and nothing else', function () {
    $withOrganization = collect(Schema::getTableListing(Schema::getConnection()->getDatabaseName(), schemaQualified: false))
        ->filter(fn (string $table): bool => Schema::hasColumn($table, 'organization_id'))
        ->sort()->values()->all();

    expect(collect(app(PersonalDataMap::class)->tables())->sort()->values()->all())->toBe($withOrganization);
});

it('classifies the table of every organization-scoped model', function (string $class) {
    /** @var Model $model */
    $model = new $class;
    $rule = app(PersonalDataMap::class)->rule($model->getTable());

    expect($rule)->not->toBeNull("{$class} ({$model->getTable()}) is not in PersonalDataMap")
        ->and($rule->person !== null || $rule->notAboutTenant !== null)->toBeTrue("{$model->getTable()} says neither how a tenant's rows are found nor why it holds none");
})->with(OrganizationScopedModels::dataset());

it('never exports an encrypted column as it is', function (string $class) {
    /** @var Model $model */
    $model = new $class;
    $rule = app(PersonalDataMap::class)->rule($model->getTable());

    $exposed = collect($model->getCasts())
        ->filter(fn (string $cast, string $column): bool => str_starts_with($cast, 'encrypted') && ! in_array($column, $rule->masked, true) && ! $rule->omits($column))
        ->keys()->all();

    expect($exposed)->toBe([], "{$model->getTable()} exports encrypted columns as they are");
})->with(OrganizationScopedModels::dataset());

it('says why each table is left out of a tenant\'s export, and erases every table it exports', function () {
    foreach (app(PersonalDataMap::class)->rules() as $table => $rule) {
        expect($rule->person === null ? $rule->notAboutTenant : 'listed')->not->toBeNull($table)
            ->and($rule->person === null || $rule->erase !== null)->toBeTrue("{$table} is a tenant's but has no erase rule");
    }
});

it('works out a delete order for the purge from the foreign keys', function () {
    $order = app(OrganizationPurge::class)->deleteOrder();

    // Children before parents.
    expect(array_search('bill_items', $order, true))->toBeLessThan(array_search('bills', $order, true))
        ->and(array_search('leases', $order, true))->toBeLessThan(array_search('tenant_profiles', $order, true))
        ->and(array_search('units', $order, true))->toBeLessThan(array_search('properties', $order, true))
        ->and($order)->not->toContain('platform_invoices', 'subscriptions', 'data_requests', 'impersonation_sessions');
});
