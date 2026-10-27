<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationBankAccount;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Modules\Collections\Jobs\MatchStatementLines;
use Modules\Collections\Matching\LineMatches;
use Modules\Collections\Models\BankStatementImport;
use Modules\Collections\Models\BankStatementLine;
use Modules\Payments\Contracts\RecordedPayment;
use Tests\Support\LivewireHttp;

/*
 * Helpers for statement matching (P03-07b): statements uploaded through
 * the real writer and read by the real job, matched by the real matcher
 * (Jobs\MatchStatementLines, queued after the import; the queue is sync in
 * tests), bills from the scheduled `bills-generate` job and payments
 * through the Payments module (its helpers), pages over HTTP and Livewire.
 */

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../Payments/tests/Feature/helpers.php';
require_once __DIR__.'/../../../../tests/Support/impersonation.php';

/**
 * Uploads a GTBank-style statement of the account with these transactions
 * ([date, reference, debit, credit, balance, narration]): read and matched.
 *
 * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>  $transactions
 */
function matchingUpload(Organization $organization, User $by, OrganizationBankAccount $account, array $transactions, string $name = 'gtbank-december.csv'): BankStatementImport
{
    return uploadStatement($organization, $by, $account, statementCsv(gtStatementRows($transactions), $name));
}

/** The statement's lines in file order. @return Collection<int, BankStatementLine> */
function matchingLines(Organization $organization, BankStatementImport $statement): Collection
{
    return inOrganization($organization, fn () => BankStatementLine::query()->where('bank_statement_import_id', $statement->id)->orderBy('row_number')->get());
}

/** The line as stored now. */
function matchingLine(Organization $organization, BankStatementLine|int $line): BankStatementLine
{
    return inOrganization($organization, fn (): BankStatementLine => BankStatementLine::query()->findOrFail($line instanceof BankStatementLine ? $line->id : $line));
}

/** Runs the matcher over the organization's (or a statement's) untried credits, as the queue would. */
function runMatcher(Organization $organization, ?BankStatementImport $statement = null): void
{
    (new MatchStatementLines($organization->id, $statement?->id))->handle(app(OrganizationContext::class), app(LineMatches::class));
}

/**
 * Matches the line by hand as the member, through the service; split bill id => naira.
 *
 * @param  array<int, string|int>|null  $split
 */
function matchByHand(Organization $organization, User $by, BankStatementLine|int $line, int $tenantId, ?array $split = null): RecordedPayment
{
    $money = $split === null ? null : array_map(fn (string|int $naira): Money => Money::naira($naira), $split);

    return inOrganization($organization, fn (): RecordedPayment => app(LineMatches::class)->matchByHand($by, $line instanceof BankStatementLine ? $line->id : $line, $tenantId, $money));
}

/** The match page of a line, as the user. */
function matchPage(User $user, BankStatementImport|int $statement, BankStatementLine|int $line, string $slug = 'acme-homes'): TestResponse
{
    $statementId = $statement instanceof BankStatementImport ? $statement->id : $statement;
    $lineId = $line instanceof BankStatementLine ? $line->id : $line;

    return statementsPage($user, "/{$statementId}/lines/{$lineId}/match", $slug);
}

/** The Livewire snapshot of a statements-area page the user can open ($path after /statements). */
function matchingSnapshot(User $user, string $path, string $slug = 'acme-homes'): string
{
    $snapshot = LivewireHttp::snapshot(statementsPage($user, $path, $slug)->assertOk());
    exportsFreshRequest();
    test()->actingAs($user);

    return $snapshot;
}
