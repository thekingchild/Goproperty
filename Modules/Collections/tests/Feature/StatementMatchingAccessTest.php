<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Enums\BillStatus;
use Modules\Collections\Enums\MatchConfidence;
use Modules\Collections\Enums\MatchStatus;
use Modules\Collections\Matching\LineMatches;
use Modules\Collections\Models\BankStatementImport;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Payments\PaymentService;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/matching-helpers.php';

/*
 * P03-07b: who matches statement credits (product owner decision 10).
 * Owners and administrators (`statements.match`) look for matches again,
 * confirm high-confidence matches in bulk and match the rest by hand,
 * never while GoProperty staff impersonate nor while the organization is
 * suspended. Agents never see a statement: they meet a matched credit as
 * a payment waiting for confirmation in the payments queue, narrowed to
 * their tenants, showing only the line's date, amount and narration, and
 * confirm or reject it there. Another organization's lines, tenants,
 * bills and references are never reached.
 */

beforeEach(function () {
    holdInvoicePdfs();
    holdReceiptPdfs();
    Storage::fake('media');
    seedOrganizationRoles();
    $this->travelTo('2026-12-25 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    $this->otherOwner = joinAs(User::factory()->create(['name' => 'Bola Other']), $this->other);
    $this->account = statementAccount($this->acme, $this->owner);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->oak = buildingIn($this->acme, ['name' => 'Oak Lodge', 'slug' => 'oak-lodge']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348030000001', 'email' => 'kemi@example.test']);
    $this->obinna = tenantIn($this->acme, ['first_name' => 'Obinna', 'last_name' => 'Okafor', 'phone' => '+2348030000003', 'email' => 'obinna@example.test']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']), $this->kemi, ['billingCycle' => 'monthly', 'rent' => '100,000', 'deposit' => '0']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->oak, ['unit_label' => 'Oak 1']), $this->obinna, ['billingCycle' => 'monthly', 'rent' => '120,000', 'deposit' => '0']);
    $this->stranger = tenantIn($this->other, ['first_name' => 'Zainab', 'last_name' => 'Bello', 'phone' => '+2348030000009', 'email' => 'zainab@example.test']);
    activeLease($this->other, $this->otherOwner, unitIn($this->other, buildingIn($this->other, ['name' => 'Foreign Court'])), $this->stranger, ['billingCycle' => 'monthly', 'rent' => '90,000', 'deposit' => '0']);
    runBillGeneration();
    $this->kemiBill = billsOfTenant($this->acme, $this->kemi)->sole();
    $this->obinnaBill = billsOfTenant($this->acme, $this->obinna)->sole();
    $this->foreignBill = billsOfTenant($this->other, $this->stranger)->sole();

    // Our statement: Kemi's and Obinna's rent by reference, a cash deposit nobody matched.
    $this->statement = matchingUpload($this->acme, $this->owner, $this->account, [
        ['23/12/2026', 'FT1', '', '100,000.00', '1,100,000.00', 'TRF KEMI ADEBAYO '.$this->kemiBill->payment_reference],
        ['23/12/2026', 'FT2', '', '120,000.00', '1,220,000.00', 'OBINNA OKAFOR '.$this->obinnaBill->payment_reference],
        ['24/12/2026', 'FT3', '', '45,000.00', '1,265,000.00', 'CASH DEPOSIT BY AMAKA LEKKI'],
    ]);
    [$this->kemiLine, $this->obinnaLine, $this->openLine] = matchingLines($this->acme, $this->statement)->all();
    $this->kemiPayment = storedPayment($this->acme, (int) $this->kemiLine->payment_id);
    $this->obinnaPayment = storedPayment($this->acme, (int) $this->obinnaLine->payment_id);

    // Theirs: Zainab's rent by reference.
    $theirAccount = statementAccount($this->other, $this->otherOwner, '0987654321', '044');
    $this->foreign = uploadStatement($this->other, $this->otherOwner, $theirAccount, statementCsv(gtStatementRows([
        ['23/12/2026', 'ZX001', '', '90,000.00', '990,000.00', 'ZAINAB BELLO '.$this->foreignBill->payment_reference],
    ], '0987654321'), 'their-statement.csv'));
    $this->foreignLine = matchingLines($this->other, $this->foreign)->sole();
});

afterEach(fn () => billsFreshRequest());

it('lets only org-owners and org-admins match statement credits, classed for both central guards', function (OrganizationRole $role, bool $allowed) {
    $user = joinAs(User::factory()->create(), $this->acme, $role);

    expect(inOrganization($this->acme, fn (): array => [
        Gate::forUser($user)->allows('match', BankStatementImport::class),
        Gate::forUser($user)->allows('match', storedStatement($this->acme, $this->statement)),
    ]))->toBe([$allowed, $allowed])
        ->and(OrganizationPermission::MatchStatements->allowedWhileSuspended())->toBeFalse()
        ->and(OrganizationPermission::MatchStatements->blockedWhileImpersonating())->toBeTrue()
        ->and(OrganizationRole::Agent->permissions())->not->toContain(OrganizationPermission::MatchStatements);
})->with([
    'org-owner' => [OrganizationRole::Owner, true],
    'org-admin' => [OrganizationRole::Admin, true],
    'agent' => [OrganizationRole::Agent, false],
    'property-owner' => [OrganizationRole::PropertyOwner, false],
    'tenant' => [OrganizationRole::Tenant, false],
]);

it('shows agents matched lines only as pending payments of their narrowed tenants, never the statement', function () {
    $agent = joinAs(User::factory()->create(['name' => 'Dayo Agent']), $this->acme, OrganizationRole::Agent);
    assignIn($this->acme, $agent, $this->palm);
    $ownerPage = matchingSnapshot($this->owner, "/{$this->statement->id}");
    $ownerMatch = matchingSnapshot($this->owner, "/{$this->statement->id}/lines/{$this->openLine->id}/match");

    // Kemi's (Palm Court) waits in the agent's queue; Obinna's (Oak Lodge) is not theirs.
    expect(listedPayments(paymentsPage($agent, '?status=pending_confirmation')))->toBe([$this->kemiPayment->id]);
    paymentsPage($agent, "/{$this->obinnaPayment->id}")->assertNotFound();

    // The payment shows only the line's date, amount and narration: no account, balance or other line.
    paymentsPage($agent, "/{$this->kemiPayment->id}")->assertOk()
        ->assertSee('TRF KEMI ADEBAYO '.$this->kemiBill->payment_reference)->assertSee('23/12/2026')->assertSee('₦100,000')->assertSee('Bank statement')
        ->assertDontSee('1,100,000')->assertDontSee('Guaranty Trust')->assertDontSee('CASH DEPOSIT')->assertDontSee('OBINNA')
        ->assertSee('data-test="confirm-payment"', false);

    // Never the statement, its lines or matching.
    exportPage($agent, '')->assertOk()->assertDontSee('data-nav="statements"', false);
    statementsPage($agent, "/{$this->statement->id}")->assertForbidden();
    matchPage($agent, $this->statement, $this->openLine)->assertForbidden();
    statementsUpdate($agent, $ownerPage, [], [['confirmHighConfidence']])->assertForbidden();
    statementsUpdate($agent, $ownerPage, [], [['findMatches']])->assertForbidden();
    statementsUpdate($agent, $ownerMatch, ['tenantId' => (string) $this->kemi->id], [['save']])->assertForbidden();
    expect(fn () => matchByHand($this->acme, $agent, $this->openLine, $this->kemi->id))->toThrow(AuthorizationException::class)
        ->and(fn () => inOrganization($this->acme, fn () => app(LineMatches::class)->confirmHighConfidence($agent, storedStatement($this->acme, $this->statement))))->toThrow(AuthorizationException::class);

    // The agent confirms Kemi's in the queue: her bill is paid, the line matched.
    $show = paymentsSnapshot($agent, "/{$this->kemiPayment->id}");
    paymentsUpdate($agent, $show, [], [['confirm']])->assertOk();
    expect(storedPayment($this->acme, $this->kemiPayment)->status)->toBe(PaymentStatus::Confirmed)
        ->and(storedBill($this->acme, $this->kemiBill)->status)->toBe(BillStatus::Paid)
        ->and(matchingLine($this->acme, $this->kemiLine)->match_status)->toBe(MatchStatus::Matched)
        ->and(matchingLine($this->acme, $this->kemiLine)->matched_by_user_id)->toBe($agent->id);

    // An unassigned agent sees both; rejecting Obinna's sends the line back to the owner, to match by hand.
    $unassigned = joinAs(User::factory()->create(['name' => 'Chidi Agent']), $this->acme, OrganizationRole::Agent);
    expect(listedPayments(paymentsPage($unassigned, '?status=pending_confirmation')))->toBe([$this->obinnaPayment->id]);
    paymentsUpdate($unassigned, paymentsSnapshot($unassigned, "/{$this->obinnaPayment->id}"), ['reason' => 'Not in the account'], [['reject']])->assertOk();
    expect(matchingLine($this->acme, $this->obinnaLine)->match_status)->toBe(MatchStatus::Unmatched)
        ->and(storedBill($this->acme, $this->obinnaBill)->paid_kobo->kobo)->toBe(0);
    statementsPage($this->owner, "/{$this->statement->id}")->assertSee('data-test="match-by-hand-'.$this->obinnaLine->id.'"', false);
});

it('sub: bulk confirm is blocked during impersonation, and so is matching by hand, in either mode', function (ImpersonationMode $mode) {
    seedStaffRoles();
    $ownerPage = matchingSnapshot($this->owner, "/{$this->statement->id}");
    $ownerMatch = matchingSnapshot($this->owner, "/{$this->statement->id}/lines/{$this->openLine->id}/match");
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    $handOff = startSupport($staff, $this->owner, $this->acme, $mode);
    enterSupport($handOff)->assertRedirect('/o/acme-homes');

    // Staff may look at the statement and its matches, without any matching action.
    $show = asSupport()->get("/o/acme-homes/statements/{$this->statement->id}")->assertOk()
        ->assertSee('data-test="statement-matching"', false)->assertSee('Kemi Adebayo')
        ->assertDontSee('data-test="confirm-high-confidence"', false)
        ->assertDontSee('data-test="find-matches"', false)
        ->assertDontSee('data-test="match-by-hand-'.$this->openLine->id.'"', false);
    asSupport()->get("/o/acme-homes/statements/{$this->statement->id}/lines/{$this->openLine->id}/match")->assertForbidden();

    foreach ([LivewireHttp::snapshot($show), $ownerPage] as $snapshot) {
        freshRequest();
        LivewireHttp::update(test(), $snapshot, [], [['confirmHighConfidence']])->assertForbidden();
        freshRequest();
        LivewireHttp::update(test(), $snapshot, [], [['findMatches']])->assertForbidden();
    }

    freshRequest();
    LivewireHttp::update(test(), $ownerMatch, ['tenantId' => (string) $this->kemi->id], [['save']])->assertForbidden();

    expect(storedPayment($this->acme, $this->kemiPayment)->status)->toBe(PaymentStatus::PendingConfirmation)
        ->and(storedPayment($this->acme, $this->obinnaPayment)->status)->toBe(PaymentStatus::PendingConfirmation)
        ->and(matchingLine($this->acme, $this->openLine)->match_status)->toBe(MatchStatus::Unmatched)
        ->and(storedBill($this->acme, $this->kemiBill)->paid_kobo->kobo)->toBe(0)
        ->and(AuditEntry::query()->whereIn('event', ['statement.matches_confirmed', 'statement.matching_requested', 'payment.confirmed'])->exists())->toBeFalse();

    if ($mode === ImpersonationMode::Write) {
        expect(AuditEntry::query()->where('event', 'impersonation.blocked')->where('subject_id', $handOff->session->id)->exists())->toBeTrue();
    }
})->with([
    'read-only' => [ImpersonationMode::ReadOnly],
    'write' => [ImpersonationMode::Write],
]);

it('never reaches another organization\'s lines, tenants, bills or references', function () {
    // Their statement's credit is matched in their organization only.
    expect($this->foreignLine->match_confidence)->toBe(MatchConfidence::High)
        ->and(storedPayment($this->other, (int) $this->foreignLine->payment_id)->tenant_profile_id)->toBe($this->stranger->id);

    // Their bill's reference in our narration finds nothing here.
    $ours = matchingUpload($this->acme, $this->owner, $this->account, [
        ['24/12/2026', 'FT9', '', '90,000.00', '1,355,000.00', 'ZAINAB BELLO '.$this->foreignBill->payment_reference],
    ], 'second.csv');
    $line = matchingLines($this->acme, $ours)->sole();
    expect($line->match_status)->toBe(MatchStatus::Unmatched)->and($line->payment_id)->toBeNull();

    // Their line by id: 404 on the pages, unknown to the service.
    matchPage($this->owner, $this->foreign, $this->foreignLine)->assertNotFound();
    matchPage($this->owner, $this->statement, $this->foreignLine)->assertNotFound();
    matchPage($this->owner, $this->statement, 999999)->assertNotFound();
    matchPage($this->otherOwner, $this->statement, $this->openLine, 'other-realty')->assertNotFound();
    matchPage($this->otherOwner, $this->statement, $this->openLine)->assertNotFound();
    expect(fn () => matchByHand($this->acme, $this->owner, $this->foreignLine, $this->kemi->id))->toThrow(ValidationException::class, 'No such statement line.')
        // Their tenant, their bill in a split.
        ->and(fn () => matchByHand($this->acme, $this->owner, $this->openLine, $this->stranger->id))->toThrow(ValidationException::class, 'Choose a tenant.')
        ->and(fn () => matchByHand($this->acme, $this->owner, $this->openLine, $this->kemi->id, [$this->foreignBill->id => '45,000']))->toThrow(ValidationException::class);

    // The page offers neither their tenant, and a tampered line id answers 419 (production: debug off).
    $page = matchPage($this->owner, $this->statement, $this->openLine)->assertOk()->assertDontSee('Zainab Bello');
    $snapshot = matchingSnapshot($this->owner, "/{$this->statement->id}/lines/{$this->openLine->id}/match");
    statementsUpdate($this->owner, $snapshot, ['tenantId' => (string) $this->stranger->id], [['save']])->assertOk();
    config(['app.debug' => false]);
    $data = json_decode($snapshot, true);
    $data['data']['lineId'] = $this->foreignLine->id;
    statementsUpdate($this->owner, (string) json_encode($data), ['tenantId' => (string) $this->kemi->id], [['save']])->assertStatus(419);

    // Bulk confirmation of ours never touches theirs.
    inOrganization($this->acme, fn () => app(LineMatches::class)->confirmHighConfidence($this->owner, storedStatement($this->acme, $this->statement)));
    expect(storedPayment($this->other, (int) $this->foreignLine->payment_id)->status)->toBe(PaymentStatus::PendingConfirmation)
        ->and(matchingLine($this->other, $this->foreignLine)->match_status)->toBe(MatchStatus::Suggested)
        ->and(storedBill($this->other, $this->foreignBill)->paid_kobo->kobo)->toBe(0)
        ->and(matchingLine($this->acme, $this->openLine)->match_status)->toBe(MatchStatus::Unmatched)
        ->and(storedPayment($this->acme, $this->kemiPayment)->status)->toBe(PaymentStatus::Confirmed);

    // A member of both organizations matches each one's own.
    joinAs($this->owner, $this->other);
    matchPage($this->owner, $this->foreign, $this->foreignLine, 'other-realty')->assertOk()->assertSee('data-test="match-closed"', false);
    statementsPage($this->owner, "/{$this->foreign->id}", 'other-realty')->assertOk()->assertSee('Zainab Bello')->assertDontSee('Kemi Adebayo');
});

it('keeps matching read-only for members of a suspended organization, while the matcher still records payments to confirm', function () {
    // Amaka's first bill owes the deposit's amount; the matcher has not run since.
    $amaka = tenantIn($this->acme, ['first_name' => 'Amaka', 'last_name' => 'Lekki', 'phone' => '+2348030000005', 'email' => 'amaka@example.test']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 5']), $amaka, ['billingCycle' => 'monthly', 'rent' => '45,000', 'deposit' => '0']);
    runBillGeneration();
    $ownerPage = matchingSnapshot($this->owner, "/{$this->statement->id}");
    $ownerMatch = matchingSnapshot($this->owner, "/{$this->statement->id}/lines/{$this->openLine->id}/match");
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    statementsPage($this->owner, "/{$this->statement->id}")->assertOk()->assertSee('data-test="statement-matching"', false)
        ->assertDontSee('data-test="confirm-high-confidence"', false)->assertDontSee('data-test="find-matches"', false)
        ->assertDontSee('data-test="match-by-hand-'.$this->openLine->id.'"', false);
    matchPage($this->owner, $this->statement, $this->openLine)->assertForbidden();
    statementsUpdate($this->owner, $ownerPage, [], [['confirmHighConfidence']])->assertForbidden();
    statementsUpdate($this->owner, $ownerPage, [], [['findMatches']])->assertForbidden();
    statementsUpdate($this->owner, $ownerMatch, ['tenantId' => (string) $this->kemi->id], [['save']])->assertForbidden();
    expect(fn () => matchByHand($this->acme, $this->owner, $this->openLine, $this->kemi->id))->toThrow(AuthorizationException::class)
        ->and(fn () => inOrganization($this->acme, fn () => app(LineMatches::class)->confirmHighConfidence($this->owner, storedStatement($this->acme, $this->statement))))->toThrow(AuthorizationException::class)
        ->and(fn () => inOrganization($this->acme, fn () => app(PaymentService::class)->confirm($this->owner, $this->kemiPayment)))->toThrow(AuthorizationException::class)
        ->and(storedPayment($this->acme, $this->kemiPayment)->status)->toBe(PaymentStatus::PendingConfirmation)
        ->and(matchingLine($this->acme, $this->openLine)->match_status)->toBe(MatchStatus::Unmatched);

    // The matcher is system work (as after an import): the payment it records waits for reactivation.
    runMatcher($this->acme, $this->statement);

    $line = matchingLine($this->acme, $this->openLine);
    expect($line->match_status)->toBe(MatchStatus::Suggested)
        ->and($line->tenant_profile_id)->toBe($amaka->id)
        ->and(storedPayment($this->acme, (int) $line->payment_id)->status)->toBe(PaymentStatus::PendingConfirmation);
});
