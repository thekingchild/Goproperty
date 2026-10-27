<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Enums\BillStatus;
use Modules\Collections\Enums\MatchConfidence;
use Modules\Collections\Enums\MatchStatus;
use Modules\Collections\Matching\LineMatches;
use Modules\Collections\Matching\NameSimilarity;
use Modules\Collections\Matching\NarrationReferences;
use Modules\Payments\Contracts\PaymentDraft;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Contracts\PaymentRecorder;
use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\Receipt;
use Modules\Payments\Payments\PaymentService;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/matching-helpers.php';

/*
 * P03-07b: statement matching (ONBOARDING_COMPLIANCE.md § 4.1; product
 * owner decision 10). A credit with a bill's payment reference in its
 * narration is matched with high confidence; the amount a bill owes and a
 * name like the tenant's is a suggestion; either becomes a payment waiting
 * for confirmation in the payments queue (source statement_match, key
 * statement-line:{id}), and the line follows that payment. Owners and
 * administrators match the rest by hand, one credit split across bills
 * too, recorded and confirmed at once. A line is matched once; a debit
 * never. Access rules are in StatementMatchingAccessTest; concurrent
 * attempts in tests/Drill/StatementMatchingDrillTest.php.
 */

beforeEach(function () {
    holdInvoicePdfs();
    holdReceiptPdfs();
    Storage::fake('media');
    seedOrganizationRoles();
    $this->travelTo('2026-12-25 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    $this->account = statementAccount($this->acme, $this->owner);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348030000001', 'email' => 'kemi@example.test']);
    $this->tunde = tenantIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare', 'phone' => '+2348030000002', 'email' => 'tunde@example.test']);
    // Monthly leases from 1 Jan 2027: January's bills are issued today (7 days' lead).
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']), $this->kemi, ['billingCycle' => 'monthly', 'rent' => '100,000', 'deposit' => '0']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 2']), $this->tunde, ['billingCycle' => 'monthly', 'rent' => '180,000', 'deposit' => '0']);
    runBillGeneration();
    $this->kemiBill = billsOfTenant($this->acme, $this->kemi)->sole();
    $this->tundeBill = billsOfTenant($this->acme, $this->tunde)->sole();
});

afterEach(fn () => billsFreshRequest());

it('acceptance: a credit with the bill reference in its narration is matched with high confidence', function () {
    $narration = 'TRF FROM K ADEBAYO/'.$this->kemiBill->payment_reference.'/RENT JAN';
    $statement = matchingUpload($this->acme, $this->owner, $this->account, [
        ['24/12/2026', 'FT26358A1', '', '100,000.00', '1,100,000.00', $narration],
    ]);
    $line = matchingLines($this->acme, $statement)->sole();

    // The line is matched with high confidence to Kemi's bill, through a payment waiting for confirmation.
    expect($line->match_status)->toBe(MatchStatus::Suggested)
        ->and($line->match_confidence)->toBe(MatchConfidence::High)
        ->and($line->suggested_bill_id)->toBe($this->kemiBill->id)
        ->and($line->tenant_profile_id)->toBe($this->kemi->id)
        ->and($line->match_attempts)->toBe(1)
        ->and($line->matched_by_user_id)->toBeNull();

    $payment = storedPayment($this->acme, (int) $line->payment_id);
    expect($payment->status)->toBe(PaymentStatus::PendingConfirmation)
        ->and($payment->source)->toBe(PaymentSource::StatementMatch)
        ->and($payment->method)->toBe(PaymentMethod::BankTransfer)
        ->and($payment->idempotency_key)->toBe("statement-line:{$line->id}")
        ->and($payment->bank_statement_line_id)->toBe($line->id)
        ->and($payment->bill_id)->toBe($this->kemiBill->id)
        ->and($payment->tenant_profile_id)->toBe($this->kemi->id)
        ->and($payment->amount_kobo->kobo)->toBe(10_000_000)
        ->and($payment->paid_at->toDateString())->toBe('2026-12-24')
        // Only the line's date, amount and narration: no payer, account or balance.
        ->and($payment->reference)->toBe($narration)
        ->and($payment->note)->toBeNull()
        ->and($payment->payer_name)->toBeNull()
        ->and($payment->recorded_by_user_id)->toBeNull()
        ->and(storedBill($this->acme, $this->kemiBill)->paid_kobo->kobo)->toBe(0);

    // It waits in the payments confirmation queue, as a bank statement payment.
    expect(listedPayments(paymentsPage($this->owner, '?status=pending_confirmation')))->toBe([$payment->id]);
    paymentsPage($this->owner, "/{$payment->id}")->assertOk()->assertSee($narration)->assertSee('Bank statement')->assertSee('24/12/2026')
        ->assertSee('data-test="confirm-payment"', false)->assertDontSee('0123456789')->assertDontSee('1,100,000');

    $audit = AuditEntry::query()->where('event', 'statement_line.matched')->sole();
    expect($audit->properties['confidence'])->toBe('high')
        ->and($audit->properties['bill_id'])->toBe($this->kemiBill->id)
        ->and($audit->properties['payment_status'])->toBe('pending_confirmation')
        ->and(json_encode($audit->properties))->not->toContain('ADEBAYO');

    // Confirming it settles the bill; the line is matched.
    confirmPayment($this->acme, $this->owner, $payment);
    $line = matchingLine($this->acme, $line);
    expect(storedBill($this->acme, $this->kemiBill)->status)->toBe(BillStatus::Paid)
        ->and($line->match_status)->toBe(MatchStatus::Matched)
        ->and($line->matched_by_user_id)->toBe($this->owner->id)
        ->and(inOrganization($this->acme, fn () => Receipt::query()->where('payment_id', $payment->id)->count()))->toBe(1);

    statementsPage($this->owner, "/{$statement->id}")->assertOk()
        ->assertSee('data-test="line-payment-'.$line->id.'"', false)->assertSee('Kemi Adebayo')->assertSee('Reference in the narration');
});

it('finds the reference whatever its case, spaces and dashes', function (string $written) {
    $reference = $this->tundeBill->payment_reference;
    $code = substr($reference, 3);
    $narration = match ($written) {
        'lower case' => 'transfer '.strtolower($reference).' flat 2',
        'without the dash' => 'NIP TRF GP'.$code,
        'with spaces' => 'GP '.substr($code, 0, 3).' '.substr($code, 3),
        'stuck to other words' => 'TUNDE/GP'.$code.'RENTJAN',
        'with an en dash' => 'RENT GP–'.$code,
    };

    $statement = matchingUpload($this->acme, $this->owner, $this->account, [
        ['24/12/2026', 'FT1', '', '50,000.00', '1,050,000.00', $narration],
    ]);
    $line = matchingLines($this->acme, $statement)->sole();

    // A part payment is still Tunde's money: matched to his bill.
    expect($line->match_confidence)->toBe(MatchConfidence::High)
        ->and($line->suggested_bill_id)->toBe($this->tundeBill->id)
        ->and(storedPayment($this->acme, (int) $line->payment_id)->amount_kobo->kobo)->toBe(5_000_000);
})->with(['lower case', 'without the dash', 'with spaces', 'stuck to other words', 'with an en dash']);

it('reads candidate references from a narration', function () {
    expect(NarrationReferences::in('TRF GP-7KQ2M9X RENT'))->toBe(['GP-7KQ2M9X'])
        ->and(NarrationReferences::in('gp 7kq 2m9x and gp-3hw8d2t'))->toBe(['GP-7KQ2M9X', 'GP-3HW8D2T'])
        ->and(NarrationReferences::in('POS PURCHASE SHOPRITE'))->toBe([])
        ->and(NarrationReferences::in('GP-12'))->toBe([])
        ->and(NarrationReferences::in(str_repeat('GP-7KQ2M9X GP-AAAAAAA GP-BBBBBBB GP-CCCCCCC GP-DDDDDDD GP-EEEEEEE ', 2)))->toHaveCount(NarrationReferences::MAX);
});

it('suggests a match from the amount a bill owes and a payer name like the tenant\'s', function () {
    $statement = matchingUpload($this->acme, $this->owner, $this->account, [
        // Tunde's exact amount, his name cut short by the bank: suggested.
        ['22/12/2026', 'FT1', '', '180,000.00', '1,180,000.00', 'NIP TRF FROM BAKARE TUND FLAT RENT'],
        // Tunde's amount, nobody's name: not matched.
        ['22/12/2026', 'FT2', '', '180,000.00', '1,360,000.00', 'TRF FROM CHIOMA EZE'],
        // Kemi's name, an amount no bill owes: not matched.
        ['23/12/2026', 'FT3', '', '95,000.00', '1,455,000.00', 'TRF FROM KEMI ADEBAYO'],
    ]);
    [$suggested, $nobody, $wrongAmount] = matchingLines($this->acme, $statement)->all();

    expect($suggested->match_status)->toBe(MatchStatus::Suggested)
        ->and($suggested->match_confidence)->toBe(MatchConfidence::Medium)
        ->and($suggested->suggested_bill_id)->toBe($this->tundeBill->id)
        ->and($suggested->tenant_profile_id)->toBe($this->tunde->id)
        ->and(storedPayment($this->acme, (int) $suggested->payment_id)->status)->toBe(PaymentStatus::PendingConfirmation)
        ->and($nobody->match_status)->toBe(MatchStatus::Unmatched)
        ->and($nobody->payment_id)->toBeNull()
        ->and($wrongAmount->match_status)->toBe(MatchStatus::Unmatched)
        ->and(paymentsOf($this->acme))->toHaveCount(1);

    // The page tells the two kinds apart, and offers matching by hand for the rest.
    statementsPage($this->owner, "/{$statement->id}")->assertOk()
        ->assertSee('Same amount, similar name')
        ->assertSee('data-test="match-by-hand-'.$nobody->id.'"', false)
        ->assertDontSee('data-test="match-by-hand-'.$suggested->id.'"', false)
        ->assertDontSee('data-test="confirm-high-confidence"', false);
});

it('leaves a credit unmatched when the amount and name fit two tenants, or the references name two tenants', function () {
    // A second Bakare whose bill also owes ₦180,000.
    $sade = tenantIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare-Lawal', 'phone' => '+2348030000004', 'email' => 'sade@example.test']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 3']), $sade, ['billingCycle' => 'monthly', 'rent' => '180,000', 'deposit' => '0']);
    runBillGeneration();

    $statement = matchingUpload($this->acme, $this->owner, $this->account, [
        ['22/12/2026', 'FT1', '', '180,000.00', '1,180,000.00', 'TRF FROM TUNDE BAKARE'],
        ['23/12/2026', 'FT2', '', '280,000.00', '1,460,000.00', 'RENT '.$this->kemiBill->payment_reference.' AND '.$this->tundeBill->payment_reference],
    ]);

    expect(matchingLines($this->acme, $statement)->pluck('match_status')->all())->toBe([MatchStatus::Unmatched, MatchStatus::Unmatched])
        ->and(paymentsOf($this->acme))->toHaveCount(0);
});

it('compares names simply: words in any order, titles ignored, a bank\'s cut-short word', function (string $tenant, string $payer, bool $similar) {
    expect(NameSimilarity::similar($tenant, $payer))->toBe($similar);
})->with([
    'same words, any order' => ['Kemi Adebayo', 'TRF FROM ADEBAYO KEMI RENT', true],
    'cut short by the bank' => ['Oluwaseun Adebayo', 'NIP/OLUWASE ADEBAY/RENT', true],
    'title ignored' => ['Chief Emeka Obi', 'MR EMEKA OBI', true],
    'accents ignored' => ['Adébáyọ̀ Kẹ́mi', 'KEMI ADEBAYO', true],
    'one word only' => ['Kemi Adebayo', 'TRF FROM KEMI', false],
    'initial only' => ['Kemi Adebayo', 'K ADEBAYO', false],
    'another person' => ['Kemi Adebayo', 'CHIOMA EZE', false],
    'short prefix not enough' => ['Ada Obi', 'ADAEZE OBIORA', false],
    'single-word company' => ['Zenovia', 'ZENOVIA LTD RENT', true],
    'title only' => ['Mr', 'MR', false],
]);

it('acceptance: one credit split across two bills settles both', function () {
    // Kemi also rents a shop: two open bills.
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Shop 1']), $this->kemi, ['billingCycle' => 'monthly', 'rent' => '40,000', 'deposit' => '0']);
    runBillGeneration();
    [$flat, $shop] = [$this->kemiBill, billsOfTenant($this->acme, $this->kemi)->firstWhere('id', '!=', $this->kemiBill->id)];
    $statement = matchingUpload($this->acme, $this->owner, $this->account, [
        ['24/12/2026', 'FT1', '', '140,000.00', '1,140,000.00', 'CASH DEPOSIT LEKKI BRANCH'],
    ]);
    $line = matchingLines($this->acme, $statement)->sole();
    expect($line->match_status)->toBe(MatchStatus::Unmatched);

    // The owner matches it by hand on the statement page: ₦100,000 to the flat, ₦40,000 to the shop.
    statementsPage($this->owner, "/{$statement->id}")->assertSee('data-test="match-by-hand-'.$line->id.'"', false);
    $snapshot = matchingSnapshot($this->owner, "/{$statement->id}/lines/{$line->id}/match");
    $chosen = statementsUpdate($this->owner, $snapshot, ['tenantId' => (string) $this->kemi->id])->assertOk();
    expect(LivewireHttp::html($chosen))->toContain($flat->number)->toContain($shop->number);
    $saved = statementsUpdate($this->owner, LivewireHttp::next($chosen), ['amounts.'.$flat->id => '100,000', 'amounts.'.$shop->id => '40,000'], [['save']])->assertOk();

    expect(LivewireHttp::redirect($saved))->toContain("/o/acme-homes/statements/{$statement->id}");

    // One payment, confirmed, split exactly: both bills are paid.
    $line = matchingLine($this->acme, $line);
    $payment = storedPayment($this->acme, (int) $line->payment_id);
    expect($payment->status)->toBe(PaymentStatus::Confirmed)
        ->and($payment->idempotency_key)->toBe("statement-line:{$line->id}")
        ->and($payment->confirmed_by_user_id)->toBe($this->owner->id)
        ->and(allocationsOf($this->acme, $payment))->toEqual([$flat->id => 10_000_000, $shop->id => 4_000_000])
        ->and(storedBill($this->acme, $flat)->status)->toBe(BillStatus::Paid)
        ->and(storedBill($this->acme, $shop)->status)->toBe(BillStatus::Paid)
        ->and(creditOf($this->acme, $this->kemi)->kobo)->toBe(0)
        ->and($line->match_status)->toBe(MatchStatus::Matched)
        ->and($line->match_confidence)->toBe(MatchConfidence::Manual)
        ->and($line->matched_by_user_id)->toBe($this->owner->id)
        ->and(paymentsOf($this->acme))->toHaveCount(1)
        ->and(inOrganization($this->acme, fn () => Receipt::query()->where('payment_id', $payment->id)->count()))->toBe(1)
        ->and(storedStatement($this->acme, $statement)->lines_matched)->toBe(1);

    statementsPage($this->owner, "/{$statement->id}")->assertOk()->assertSee('Matched by hand');
});

it('puts what a split leaves to the tenant\'s credit, and without a split pays the oldest bills first', function () {
    $statement = matchingUpload($this->acme, $this->owner, $this->account, [
        ['24/12/2026', 'FT1', '', '130,000.00', '1,130,000.00', 'CASH DEPOSIT IKEJA'],
        ['24/12/2026', 'FT2', '', '200,000.00', '1,330,000.00', 'CASH DEPOSIT YABA'],
    ]);
    [$split, $whole] = matchingLines($this->acme, $statement)->all();

    $first = matchByHand($this->acme, $this->owner, $split, $this->kemi->id, [$this->kemiBill->id => '100,000']);
    $second = matchByHand($this->acme, $this->owner, $whole, $this->tunde->id);

    expect($first->allocations[$this->kemiBill->id]->kobo)->toBe(10_000_000)
        ->and($first->credit->kobo)->toBe(3_000_000)
        ->and(creditOf($this->acme, $this->kemi)->kobo)->toBe(3_000_000)
        ->and($second->allocations[$this->tundeBill->id]->kobo)->toBe(18_000_000)
        ->and($second->credit->kobo)->toBe(2_000_000)
        ->and(storedBill($this->acme, $this->tundeBill)->status)->toBe(BillStatus::Paid);
});

it('refuses a split that does not fit, recording nothing', function () {
    $statement = matchingUpload($this->acme, $this->owner, $this->account, [
        ['24/12/2026', 'FT1', '', '100,000.00', '1,100,000.00', 'CASH DEPOSIT'],
    ]);
    $line = matchingLines($this->acme, $statement)->sole();

    // More than the bill owes; more than the credit; another tenant's bill.
    expect(fn () => matchByHand($this->acme, $this->owner, $line, $this->tunde->id, [$this->tundeBill->id => '180,001']))->toThrow(ValidationException::class)
        ->and(fn () => matchByHand($this->acme, $this->owner, $line, $this->tunde->id, [$this->tundeBill->id => '150,000']))->toThrow(ValidationException::class, 'more than the payment')
        ->and(fn () => matchByHand($this->acme, $this->owner, $line, $this->kemi->id, [$this->tundeBill->id => '50,000']))->toThrow(ValidationException::class, 'not this tenant')
        ->and(paymentsOf($this->acme))->toHaveCount(0)
        ->and(matchingLine($this->acme, $line)->match_status)->toBe(MatchStatus::Unmatched)
        ->and(matchingLine($this->acme, $line)->match_attempts)->toBe(0);

    // The page shows why, and a typed amount that is no amount.
    $snapshot = matchingSnapshot($this->owner, "/{$statement->id}/lines/{$line->id}/match");
    $chosen = statementsUpdate($this->owner, $snapshot, ['tenantId' => (string) $this->tunde->id]);
    $refused = statementsUpdate($this->owner, LivewireHttp::next($chosen), ['amounts.'.$this->tundeBill->id => '150,000'], [['save']])->assertOk();
    expect(LivewireHttp::html($refused))->toContain('more than the payment');
    $typo = statementsUpdate($this->owner, LivewireHttp::next($chosen), ['amounts.'.$this->tundeBill->id => 'ten naira'], [['save']])->assertOk();
    expect(LivewireHttp::errors($typo))->toHaveKey('amounts.'.$this->tundeBill->id)
        ->and(paymentsOf($this->acme))->toHaveCount(0);
});

it('sub: a line cannot be matched twice', function () {
    $statement = matchingUpload($this->acme, $this->owner, $this->account, [
        ['24/12/2026', 'FT1', '', '100,000.00', '1,100,000.00', 'RENT '.$this->kemiBill->payment_reference],
        ['24/12/2026', 'FT2', '', '60,000.00', '1,160,000.00', 'CASH DEPOSIT'],
    ]);
    [$matched, $byHand] = matchingLines($this->acme, $statement)->all();
    $payment = storedPayment($this->acme, (int) $matched->payment_id);

    // Waiting for confirmation: not matched again by hand, nor by the matcher run again.
    expect(fn () => matchByHand($this->acme, $this->owner, $matched, $this->tunde->id))->toThrow(ValidationException::class, 'matched already');
    runMatcher($this->acme);
    runMatcher($this->acme, $statement);
    expect(paymentsOf($this->acme)->pluck('id')->all())->toBe([$payment->id])
        ->and(matchPage($this->owner, $statement, $matched)->assertOk()->getContent())->toContain('data-test="match-closed"')->not->toContain('data-test="save-match"');

    // The same key through the recorder gives back the same payment (unique per organization).
    $again = inOrganization($this->acme, fn () => app(PaymentRecorder::class)->record(new PaymentDraft(
        tenantProfileId: $this->tunde->id,
        amount: Money::naira(1),
        method: PaymentMethod::BankTransfer,
        source: PaymentSource::StatementMatch,
        idempotencyKey: "statement-line:{$matched->id}",
        paidAt: now()->toImmutable(),
    )));
    expect($again->created)->toBeFalse()
        ->and($again->paymentId)->toBe($payment->id)
        ->and(paymentsOf($this->acme))->toHaveCount(1);

    // Matched by hand once; the second time is refused, even from a page opened before.
    $page = matchingSnapshot($this->owner, "/{$statement->id}/lines/{$byHand->id}/match");
    matchByHand($this->acme, $this->owner, $byHand, $this->tunde->id);
    $late = statementsUpdate($this->owner, $page, ['tenantId' => (string) $this->kemi->id])->assertOk();
    $late = statementsUpdate($this->owner, LivewireHttp::next($late), [], [['save']])->assertOk();
    expect(LivewireHttp::html($late))->toContain('matched already')
        ->and(fn () => matchByHand($this->acme, $this->owner, $byHand, $this->kemi->id))->toThrow(ValidationException::class, 'matched already')
        ->and(paymentsOf($this->acme))->toHaveCount(2);

    // The database refuses one payment linked to two lines.
    expect(fn () => inOrganization($this->acme, fn () => DB::table('bank_statement_lines')->where('id', $byHand->id)->update(['payment_id' => $payment->id])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('returns a rejected match to the owner for matching by hand, never suggesting it again, and keys the new payment apart', function () {
    $statement = matchingUpload($this->acme, $this->owner, $this->account, [
        ['22/12/2026', 'FT1', '', '180,000.00', '1,180,000.00', 'TRF FROM TUNDE BAKARE'],
    ]);
    $line = matchingLines($this->acme, $statement)->sole();
    $suggested = storedPayment($this->acme, (int) $line->payment_id);

    // Rejected in the queue (it was Tunde's brother paying for another flat).
    inOrganization($this->acme, fn () => app(PaymentService::class)->reject($this->owner, $suggested, 'Not Tunde\'s rent'));
    $line = matchingLine($this->acme, $line);
    expect($line->match_status)->toBe(MatchStatus::Unmatched)
        ->and($line->payment_id)->toBeNull()
        ->and($line->match_confidence)->toBeNull()
        ->and($line->tenant_profile_id)->toBeNull()
        ->and($line->match_attempts)->toBe(1)
        ->and(storedPayment($this->acme, $suggested)->bank_statement_line_id)->toBe($line->id)
        ->and(AuditEntry::query()->where('event', 'statement_line.match_rejected')->exists())->toBeTrue();

    // The matcher leaves it alone; the page says why and offers matching by hand.
    runMatcher($this->acme, $statement);
    expect(paymentsOf($this->acme))->toHaveCount(1);
    statementsPage($this->owner, "/{$statement->id}")->assertSee('data-test="line-rejected-'.$line->id.'"', false)->assertSee('data-test="match-by-hand-'.$line->id.'"', false);

    // Matched by hand to Kemi: the second payment has its own key; both name the line.
    $recorded = matchByHand($this->acme, $this->owner, $line, $this->kemi->id);
    expect(storedPayment($this->acme, $recorded->paymentId)->idempotency_key)->toBe("statement-line:{$line->id}:2")
        ->and(inOrganization($this->acme, fn () => Payment::query()->where('bank_statement_line_id', $line->id)->orderBy('id')->pluck('status')->map->value->all()))->toBe(['rejected', 'confirmed'])
        ->and(matchingLine($this->acme, $line)->match_status)->toBe(MatchStatus::Matched)
        ->and(matchingLine($this->acme, $line)->match_attempts)->toBe(2);
});

it('sub: debit lines are never matched, whatever their narration and amount', function () {
    $statement = matchingUpload($this->acme, $this->owner, $this->account, [
        // A refund to Kemi naming her bill, for exactly what it owes.
        ['24/12/2026', 'FT1', '100,000.00', '', '900,000.00', 'REFUND TO KEMI ADEBAYO '.$this->kemiBill->payment_reference],
    ]);
    $debit = matchingLines($this->acme, $statement)->sole();

    runMatcher($this->acme);
    expect(matchingLine($this->acme, $debit)->match_status)->toBe(MatchStatus::Ignored)
        ->and(matchingLine($this->acme, $debit)->payment_id)->toBeNull()
        ->and(paymentsOf($this->acme))->toHaveCount(0)
        ->and(inOrganization($this->acme, fn () => app(LineMatches::class)->matchAutomatically($debit->id)))->toBeNull()
        ->and(fn () => matchByHand($this->acme, $this->owner, $debit, $this->kemi->id))->toThrow(ValidationException::class, 'never matched')
        ->and(paymentsOf($this->acme))->toHaveCount(0);

    statementsPage($this->owner, "/{$statement->id}")->assertOk()->assertDontSee('data-test="match-by-hand-'.$debit->id.'"', false);
    matchPage($this->owner, $statement, $debit)->assertNotFound();
});

it('looks for matches again on demand, for bills issued after the import', function () {
    $statement = matchingUpload($this->acme, $this->owner, $this->account, [
        ['24/12/2026', 'FT1', '', '75,000.00', '1,075,000.00', 'TRF FROM AMAKA OBI DEPOSIT'],
    ]);
    $line = matchingLines($this->acme, $statement)->sole();
    expect($line->match_status)->toBe(MatchStatus::Unmatched);

    // Amaka's lease is signed afterwards; her first bill owes ₦75,000.
    $amaka = tenantIn($this->acme, ['first_name' => 'Amaka', 'last_name' => 'Obi', 'phone' => '+2348030000005', 'email' => 'amaka@example.test']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 5']), $amaka, ['billingCycle' => 'monthly', 'rent' => '75,000', 'deposit' => '0']);
    runBillGeneration();

    $page = matchingSnapshot($this->owner, "/{$statement->id}");
    $pressed = statementsUpdate($this->owner, $page, [], [['findMatches']])->assertOk();
    expect(LivewireHttp::html($pressed))->toContain('Looking for matches');

    $line = matchingLine($this->acme, $line);
    expect($line->match_status)->toBe(MatchStatus::Suggested)
        ->and($line->match_confidence)->toBe(MatchConfidence::Medium)
        ->and($line->tenant_profile_id)->toBe($amaka->id)
        ->and(AuditEntry::query()->where('event', 'statement.matching_requested')->where('causer_id', $this->owner->id)->exists())->toBeTrue();
});

it('confirms a statement\'s high-confidence matches in bulk for owners and administrators, leaving suggestions alone', function () {
    $admin = joinAs(User::factory()->create(['name' => 'Bisi Admin']), $this->acme, OrganizationRole::Admin);
    $statement = matchingUpload($this->acme, $this->owner, $this->account, [
        ['24/12/2026', 'FT1', '', '100,000.00', '1,100,000.00', 'RENT '.$this->kemiBill->payment_reference],
        ['24/12/2026', 'FT2', '', '180,000.00', '1,280,000.00', 'TRF FROM TUNDE BAKARE'],
    ]);
    [$high, $medium] = matchingLines($this->acme, $statement)->all();

    $page = statementsPage($admin, "/{$statement->id}")->assertOk()->assertSee('data-test="confirm-high-confidence"', false);
    expect(htmlOf($page))->toContain('Confirm 1 match with the reference');
    $pressed = statementsUpdate($admin, LivewireHttp::snapshot($page), [], [['confirmHighConfidence']])->assertOk();
    expect(LivewireHttp::html($pressed))->toContain('1 payment confirmed.');

    expect(matchingLine($this->acme, $high)->match_status)->toBe(MatchStatus::Matched)
        ->and(storedPayment($this->acme, (int) $high->payment_id)->confirmed_by_user_id)->toBe($admin->id)
        ->and(storedBill($this->acme, $this->kemiBill)->status)->toBe(BillStatus::Paid)
        ->and(matchingLine($this->acme, $medium)->match_status)->toBe(MatchStatus::Suggested)
        ->and(storedPayment($this->acme, (int) $medium->payment_id)->status)->toBe(PaymentStatus::PendingConfirmation)
        ->and(AuditEntry::query()->where('event', 'statement.matches_confirmed')->sole()->properties['confirmed'])->toBe(1)
        ->and(storedStatement($this->acme, $statement)->lines_matched)->toBe(1);

    // Pressed again: nothing left to confirm.
    $result = inOrganization($this->acme, fn () => app(LineMatches::class)->confirmHighConfidence($this->owner, storedStatement($this->acme, $statement)));
    expect($result->confirmed)->toBe(0)->and(paymentsOf($this->acme)->where('status', PaymentStatus::Confirmed))->toHaveCount(1);
    statementsPage($this->owner, "/{$statement->id}")->assertDontSee('data-test="confirm-high-confidence"', false);
});

it('tells the tenant about a statement match only once the agency confirms it, never about a guess it rejects', function () {
    $statement = matchingUpload($this->acme, $this->owner, $this->account, [
        ['24/12/2026', 'FT1', '', '100,000.00', '1,100,000.00', 'TRF/'.$this->kemiBill->payment_reference],
        ['22/12/2026', 'FT2', '', '180,000.00', '1,280,000.00', 'TRF FROM TUNDE BAKARE'],
    ]);
    [$referenced, $guessed] = matchingLines($this->acme, $statement)->sortBy('row_number')->values()->all();
    $notices = fn (int $paymentId): array => DB::table('payment_notices')->where('payment_id', $paymentId)->orderBy('id')->pluck('event')->all();

    // Recording a match tells nobody: it is the agency's reading of its own statement.
    expect($notices((int) $referenced->payment_id))->toBe([])
        ->and($notices((int) $guessed->payment_id))->toBe([]);

    // Confirmed: the tenant hears once, with the receipt.
    confirmPayment($this->acme, $this->owner, storedPayment($this->acme, (int) $referenced->payment_id));
    // Rejected (someone else's money): still nobody is told.
    inOrganization($this->acme, fn () => app(PaymentService::class)->reject($this->owner, storedPayment($this->acme, (int) $guessed->payment_id), 'Not a tenant payment'));

    expect($notices((int) $referenced->payment_id))->toBe(['payment.confirmed'])
        ->and($notices((int) $guessed->payment_id))->toBe([]);
});
