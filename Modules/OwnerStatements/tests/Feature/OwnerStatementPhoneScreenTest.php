<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\NarrowScreen;

require_once __DIR__.'/statement-world.php';

/*
 * P08-05: the owner portal's statement pages at 360 px (UX_DESIGN.md § 1;
 * the owner pages' check, P03-15): opened as the owner's browser gets them,
 * then laid out in real Chrome (Gotenberg's screenshot route) at 360 CSS
 * pixels: no sideways scrolling and no visible tap target smaller than
 * 44 × 44 px. Skipped locally only when Gotenberg does not answer; on CI an
 * unreachable Gotenberg fails the test. Also: the pages cost the same
 * queries however much money the month holds (no N+1).
 */

beforeEach(function () {
    Notification::fake();
    statementWorld();
    setStatementTerms($this->acme, $this->owner, $this->ngoziAtAcme, ['commission' => '10', 'shareTenantContacts' => true]);
    $this->travelTo('2027-02-02 10:00:00');
    settleStatement($this->acme, $this->owner, $this->ngoziAtAcme, '2026-12', '2027-01-05', 'TRF-NGOZI-DEC');
});

afterEach(fn () => billsFreshRequest());

it('acceptance: lays out the owner\'s statement pages on a 360 px screen: no sideways scrolling, 44 px targets', function () {
    $gotenberg = requireGotenberg();
    $nobody = User::factory()->create(['name' => 'Nobody Owns', 'email' => 'nobody.owns@example.com']);

    $pages = [
        'statements' => ownerPortalPage($this->ngozi, '/statements'),
        'unsettled month' => ownerPortalPage($this->ngozi, "/statements/{$this->ngoziAtAcme->id}/2027-01"),
        'settled month' => ownerPortalPage($this->ngozi, "/statements/{$this->ngoziAtAcme->id}/2026-12"),
        'another agency\'s month' => ownerPortalPage($this->ngozi, "/statements/{$this->ngoziAtBello->id}/2027-01"),
        'no statements' => ownerPortalPage($nobody, '/statements'),
        'lease with contacts' => ownerPortalPage($this->ngozi, "/leases/{$this->lease->id}"),
    ];
    $failing = [];

    foreach ($pages as $name => $page) {
        expect($page->status())->toBe(200, $name);
        $screen = NarrowScreen::inspect($gotenberg, NarrowScreen::standalone((string) $page->getContent()), 360);

        if ($screen['viewport'] !== 360 || $screen['overflow'] !== 0 || $screen['small'] !== 0) {
            $failing[$name] = $screen;
        }
    }

    expect($failing)->toBe([]);
});

it('loads the owner\'s month and statements without a query per payment, bill or expense (no N+1)', function () {
    $count = function (string $path): int {
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            $queries += preg_match('/\b(bills|payments|payment_allocations|receipts|tenant_credit_entries|expenses|properties|units|leases|tenant_profiles|owner_profiles|owner_statement_terms|owner_statement_settlements)\b/', $query->sql) === 1 ? 1 : 0;
        });
        ownerPortalPage($this->ngozi, $path)->assertOk();

        return $queries;
    };

    $add = function (int $day): void {
        $this->travelTo(sprintf('2027-02-%02d 11:00:00', $day));
        confirmedPayment($this->acme, $this->owner, $this->chuka, '1,000', [], [$this->chukaFebRent->id => '1,000']);
        recordExpense($this->acme, $this->owner, ['propertyId' => (string) $this->marina->id, 'description' => "Repair {$day}", 'amount' => '1,000', 'chargedToOwner' => true]);
    };

    // One payment and one charged expense in February, then ten more of each.
    $add(2);
    $month = "/statements/{$this->ngoziAtAcme->id}/2027-02";
    $oneMonth = $count($month);
    $oneIndex = $count('/statements');

    foreach (range(3, 12) as $day) {
        $add($day);
    }

    $this->travelTo('2027-02-12 12:00:00');
    expect(statementOf($this->acme, $this->ngoziAtAcme, '2027-02')->statement->totals->expenses->kobo)->toBe(1_100_000);
    expect($oneMonth)->toBeGreaterThan(5)
        ->and($count($month))->toBe($oneMonth)
        ->and($count('/statements'))->toBe($oneIndex);
});
