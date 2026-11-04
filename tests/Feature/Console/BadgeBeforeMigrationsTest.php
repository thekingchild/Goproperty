<?php

declare(strict_types=1);

use App\Platform\Filament\Concerns\BadgeBeforeMigrations;
use App\Platform\Filament\Resources\DataBreaches\DataBreachResource;
use App\Platform\Filament\Resources\DataRequests\DataRequestResource;
use Illuminate\Database\QueryException;

/*
 * Navigation badges are drawn on the Version page and the upgrade step,
 * which run against the previous release's database until its migrations
 * have run (INSTALLER.md § 10; P09-subtasks default 59). A badge counting a
 * table the release adds shows nothing while that table is missing; any
 * other database error still surfaces. The upgrade drill proves the pages.
 */

final class BadgeBeforeMigrationsProbe
{
    use BadgeBeforeMigrations;

    /** @param  Closure(): int  $count */
    public static function badge(Closure $count): ?string
    {
        return self::countBadge($count);
    }
}

function queryFailure(string $sqlState): QueryException
{
    $pdo = new PDOException('SQLSTATE['.$sqlState.']');
    $pdo->errorInfo = [$sqlState, 1146, 'failure'];

    return new QueryException('mariadb', 'select count(*) from data_breaches', [], $pdo);
}

it('shows nothing while the counted table does not exist yet', function () {
    expect(BadgeBeforeMigrationsProbe::badge(fn (): int => throw queryFailure('42S02')))->toBeNull();
});

it('still fails on any other database error', function () {
    BadgeBeforeMigrationsProbe::badge(fn (): int => throw queryFailure('42000'));
})->throws(QueryException::class);

it('shows the count, or nothing at zero', function () {
    expect(BadgeBeforeMigrationsProbe::badge(fn (): int => 3))->toBe('3')
        ->and(BadgeBeforeMigrationsProbe::badge(fn (): int => 0))->toBeNull();
});

it('guards the data protection badges', function () {
    expect(class_uses(DataRequestResource::class))->toContain(BadgeBeforeMigrations::class)
        ->and(class_uses(DataBreachResource::class))->toContain(BadgeBeforeMigrations::class)
        ->and(DataRequestResource::getNavigationBadge())->toBeNull()
        ->and(DataBreachResource::getNavigationBadge())->toBeNull();
});
