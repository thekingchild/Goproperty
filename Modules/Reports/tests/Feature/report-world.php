<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Illuminate\Testing\TestResponse;
use Modules\Reports\Reports\PortfolioReport;
use Modules\Reports\Reports\PortfolioReports;
use Modules\Reports\Reports\ReportFilters;
use Tests\Support\LivewireHttp;

/*
 * The Reports module's tests (P08-01) run on the owner statements' world
 * (OwnerStatements/tests/Feature/statement-world.php: Adeyemi Realty's
 * Palm Court, Marina Heights, Cedar House and Office Block, Bello Homes as
 * the other organization, and a January 2027 of money made through the
 * real writers: a part payment, an overpayment whose credit is refunded,
 * credit paid ahead and used, a voided bill), plus what each test adds.
 * The hand-computed figures are in each test.
 */

require_once __DIR__.'/../../../OwnerStatements/tests/Feature/statement-world.php';
require_once __DIR__.'/../../../../tests/Feature/Exports/helpers.php';

/** The report as the member sees it now, inside the organization. */
function reportOf(Organization $organization, User $user, array $filters = []): PortfolioReport
{
    return inOrganization($organization, fn (): PortfolioReport => app(PortfolioReports::class)->all($user, ReportFilters::fromInput($filters)));
}

/** The Reports page as the member, in a fresh request ($query: "from=…&to=…"). */
function reportsPage(User $user, string $query = '', string $slug = 'acme-homes'): TestResponse
{
    exportsFreshRequest();

    return test()->actingAs($user)->get("/o/{$slug}/reports".($query === '' ? '' : '?'.$query));
}

/** The PDF download as the member, in a fresh request. */
function reportsPdf(User $user, string $query = '', string $slug = 'acme-homes'): TestResponse
{
    exportsFreshRequest();

    return test()->actingAs($user)->get("/o/{$slug}/reports/pdf".($query === '' ? '' : '?'.$query));
}

/** A report's rows exported from the page (header first). */
function reportRows(User $user, string $list, string $query = '', string $format = 'csv'): array
{
    return exportRows($user, '/reports'.($query === '' ? '' : '?'.$query), $list, $format);
}

/** One Livewire request to the Reports page the member opened: the updates, then the calls. */
function reportsCall(User $user, string $query, array $updates, array $calls = []): TestResponse
{
    $snapshot = LivewireHttp::snapshot(reportsPage($user, $query)->assertOk());
    exportsFreshRequest();

    return LivewireHttp::update(test()->actingAs($user), $snapshot, $updates, $calls);
}

/** Kobo of each amount, for comparing figures. */
function kobos(array $amounts): array
{
    return array_map(static fn (Money $amount): int => $amount->kobo, $amounts);
}

/** A property of the Reports page as the Livewire response left it. */
function reportState(TestResponse $response, string $property): mixed
{
    $snapshot = json_decode(LivewireHttp::next($response->assertOk()), true);

    return is_array($snapshot) ? ($snapshot['data'][$property] ?? null) : null;
}
