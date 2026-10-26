<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Tenancy\CrossOrganizationWrite;
use App\Tenancy\MissingOrganizationContext;
use App\Tenancy\OrganizationContext;
use App\Tenancy\OrganizationScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Fixtures\Models\ScopedRecord;
use Tests\Support\TenancyFixture;

/*
 * P00-07 acceptance: querying an organization-owned model without an
 * organization context fails, and with one it only ever sees that
 * organization's rows (ARCHITECTURE.md § 4, AGENT.md § 3 rule 1).
 */

beforeEach(function () {
    TenancyFixture::create();
    $this->context = app(OrganizationContext::class);
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty']);

    $this->context->run($this->acme, fn () => ScopedRecord::query()->create(['name' => 'Acme flat']));
    $this->context->run($this->other, fn () => ScopedRecord::query()->create(['name' => 'Other flat']));
});

afterEach(function () {
    $this->context->clear();
    TenancyFixture::drop();
});

it('refuses to query without an organization context', function (Closure $query) {
    expect($query)->toThrow(MissingOrganizationContext::class, ScopedRecord::class.' belongs to an organization, but no organization context is set.');
})->with([
    'get' => [fn () => ScopedRecord::all()],
    'count' => [fn () => ScopedRecord::query()->count()],
    'find' => [fn () => ScopedRecord::query()->find(1)],
    'first' => [fn () => ScopedRecord::query()->first()],
    'exists' => [fn () => ScopedRecord::query()->where('name', 'Acme flat')->exists()],
    'update' => [fn () => ScopedRecord::query()->update(['name' => 'x'])],
    'delete' => [fn () => ScopedRecord::query()->delete()],
]);

it('refuses to create without an organization context', function () {
    ScopedRecord::query()->create(['name' => 'Orphan']);
})->throws(MissingOrganizationContext::class);

it('sees only the current organization\'s rows', function () {
    $this->context->set($this->acme);

    expect(ScopedRecord::query()->pluck('name')->all())->toBe(['Acme flat'])
        ->and(ScopedRecord::query()->count())->toBe(1);

    $this->context->set($this->other);

    expect(ScopedRecord::query()->pluck('name')->all())->toBe(['Other flat']);
});

it('cannot find another organization\'s row by its id', function () {
    $this->context->set($this->other);
    $otherId = ScopedRecord::query()->value('id');

    $this->context->set($this->acme);

    expect(ScopedRecord::query()->find($otherId))->toBeNull()
        ->and(fn () => ScopedRecord::query()->findOrFail($otherId))->toThrow(ModelNotFoundException::class);
});

it('cannot update or delete another organization\'s rows in bulk', function () {
    $this->context->set($this->acme);
    ScopedRecord::query()->update(['name' => 'renamed']);
    ScopedRecord::query()->delete();

    $this->context->set($this->other);

    expect(ScopedRecord::query()->pluck('name')->all())->toBe(['Other flat']);
});

it('fills organization_id from the context', function () {
    $record = $this->context->run($this->acme, fn () => ScopedRecord::query()->create(['name' => 'New']));

    expect($record->organization_id)->toBe($this->acme->id);
});

it('refuses to create a row in another organization', function () {
    $this->context->set($this->acme);

    ScopedRecord::query()->create(['name' => 'Smuggled', 'organization_id' => $this->other->id]);
})->throws(CrossOrganizationWrite::class);

it('refuses to move a row to another organization', function () {
    $this->context->set($this->acme);
    $record = ScopedRecord::query()->firstOrFail();
    $record->organization_id = $this->other->id;

    expect(fn () => $record->save())->toThrow(CrossOrganizationWrite::class, 'cannot be moved');
});

it('restores the previous context after run(), even on failure', function () {
    $this->context->set($this->acme);

    expect(fn () => $this->context->run($this->other, fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class)
        ->and($this->context->get()->is($this->acme))->toBeTrue()
        ->and($this->context->run($this->other, fn (Organization $organization) => $organization->slug))->toBe('other-realty')
        ->and($this->context->id())->toBe($this->acme->id);
});

it('starts empty for every request and job', function () {
    $this->context->set($this->acme);

    app()->forgetScopedInstances();

    expect(app(OrganizationContext::class)->has())->toBeFalse()
        ->and(fn () => app(OrganizationContext::class)->get())->toThrow(MissingOrganizationContext::class);
});

it('lets the Platform namespace read across organizations explicitly', function () {
    expect(ScopedRecord::query()->withoutGlobalScope(OrganizationScope::class)->count())->toBe(2);
});
