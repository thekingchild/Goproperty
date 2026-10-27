<?php

declare(strict_types=1);

namespace Modules\Collections\Matching;

use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Contracts\BillBalance;
use Modules\Billing\Contracts\BillLedger;
use Modules\Collections\Enums\MatchConfidence;
use Modules\Collections\Enums\MatchStatus;
use Modules\Collections\Jobs\MatchStatementLines;
use Modules\Collections\Models\BankStatementImport;
use Modules\Collections\Models\BankStatementLine;
use Modules\Collections\Statements\StatementImports;
use Modules\Payments\Contracts\PaymentDraft;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Contracts\PaymentRecorder;
use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Contracts\RecordedPayment;

/**
 * The only writer of a statement line's match (P03-07b;
 * ONBOARDING_COMPLIANCE.md § 4.1; product owner decision 10). Payments are
 * recorded and confirmed only through the Payments module's
 * Contracts\PaymentRecorder (source `statement_match`, linked to the line,
 * the money as the line shows it: its date, amount and narration — what an
 * agent sees in the confirmation queue), so PaymentService's rules, locks,
 * receipts and audit apply.
 *
 * - matchAutomatically(): the matcher (Jobs\MatchStatementLines, after an
 *   import and on demand) records a payment **waiting for confirmation**
 *   for a credit MatchFinder matched (high or medium confidence); the line
 *   becomes `suggested`. Owners, administrators and agents (narrowed by
 *   tenant) confirm or reject it in the payments confirmation queue.
 * - confirmHighConfidence(): owners and administrators confirm a
 *   statement's high-confidence matches in bulk.
 * - matchByHand(): owners and administrators match a credit nobody
 *   matched to a tenant, optionally split across that tenant's bills: one
 *   payment, recorded and confirmed at once (they are looking at the money
 *   on the statement), with an explicit split when given.
 * - paymentConfirmed() / paymentRejected(): the line follows its payment
 *   (Listeners\FollowStatementPayments, inside the payment's transaction):
 *   confirmed → `matched`; rejected → back to `unmatched`, for matching by
 *   hand (the matcher never suggests it again).
 *
 * **A line is matched once:** every attempt locks the line's row and
 * re-reads it, matches only a credit still `unmatched`, and records its
 * payment under the key `statement-line:{id}` (`statement-line:{id}:{n}`
 * for a later attempt after a rejection; BankStatementLine::paymentKey),
 * unique per organization in `payments`, while `bank_statement_lines.
 * payment_id` is unique: two attempts at once make one payment.
 *
 * Member actions need `statements.match` (BankStatementImportPolicy::match:
 * owners and administrators; dropped while the organization is suspended;
 * never while staff impersonate) and, through the recorder, the payment
 * permissions. Audited under log `statements` (ids, amounts and
 * confidence; never narrations).
 */
final readonly class LineMatches
{
    public function __construct(
        private MatchFinder $finder,
        private PaymentRecorder $recorder,
        private BillLedger $ledger,
    ) {}

    /**
     * Ask for matching of a statement's credits nobody has tried yet
     * (bills issued since the import, a tenant's name corrected).
     */
    public function requestMatching(User $by, BankStatementImport $statement): void
    {
        Gate::forUser($by)->authorize('match', $statement);

        MatchStatementLines::dispatch($statement->organization_id, $statement->id);

        activity(StatementImports::LOG)
            ->event('statement.matching_requested')
            ->performedOn($statement)
            ->causedBy($by)
            ->log('Matching of a bank statement requested');
    }

    /**
     * The matcher's work on one line: when MatchFinder finds its bill,
     * record a payment waiting for confirmation and mark the line
     * `suggested`. Lines already matched, or tried before (a rejected
     * suggestion), are left alone. Returns what was recorded, or null.
     */
    public function matchAutomatically(int $lineId): ?MatchSuggestion
    {
        $line = BankStatementLine::query()->find($lineId);

        if (! $line instanceof BankStatementLine || ! $line->isOfferedForMatching() || $line->match_attempts > 0) {
            return null;
        }

        $suggestion = $this->finder->find($line);

        if ($suggestion === null) {
            return null;
        }

        return DB::transaction(function () use ($lineId, $suggestion): ?MatchSuggestion {
            $line = $this->locked($lineId);

            if (! $line instanceof BankStatementLine || ! $line->isOfferedForMatching() || $line->match_attempts > 0) {
                return null;
            }

            $attempt = $line->match_attempts + 1;

            try {
                $recorded = $this->recorder->record($this->draft($line, $attempt, $suggestion->tenantProfileId, $suggestion->billId));
            } catch (ValidationException) {
                // The bill or tenant changed since it was read (voided, …): leave the line for later or by hand.
                return null;
            }

            $this->link($line, $recorded, $attempt, $suggestion->confidence, $suggestion->billId, $suggestion->tenantProfileId, null);

            return $suggestion;
        }, 3);
    }

    /**
     * Confirm the statement's high-confidence matches still waiting, as the
     * member (each its own transaction: one refused leaves the others).
     */
    public function confirmHighConfidence(User $by, BankStatementImport $statement): BulkConfirmation
    {
        Gate::forUser($by)->authorize('match', $statement);

        $lines = BankStatementLine::query()
            ->where('bank_statement_import_id', $statement->id)
            ->where('match_status', MatchStatus::Suggested->value)
            ->where('match_confidence', MatchConfidence::High->value)
            ->whereNotNull('payment_id')
            ->orderBy('id')
            ->get();
        $confirmed = 0;
        $problems = [];

        foreach ($lines as $line) {
            if ($line->tenant_profile_id === null) {
                continue;
            }

            try {
                // The line's payment by its key: confirmed if it still waits (the line follows, paymentConfirmed()).
                $recorded = $this->recorder->recordConfirmed($this->draft($line, max(1, $line->match_attempts), $line->tenant_profile_id, null, by: $by));
            } catch (ValidationException $refused) {
                $problems[] = $refused->getMessage();

                continue;
            }

            if ($recorded->isConfirmed() && BankStatementLine::query()->find($line->id)?->match_status === MatchStatus::Matched) {
                $confirmed++;
            }
        }

        activity(StatementImports::LOG)
            ->event('statement.matches_confirmed')
            ->performedOn($statement)
            ->causedBy($by)
            ->withProperties(['confirmed' => $confirmed, 'refused' => count($problems)])
            ->log('High-confidence matches confirmed');

        return new BulkConfirmation($confirmed, count($problems), $problems);
    }

    /**
     * Match a credit nobody has matched to the tenant, as the member: one
     * payment recorded and confirmed at once — split exactly as given
     * (bill id => amount; the rest to the tenant's credit), or without a
     * split the tenant's open bills oldest first, the rest to credit.
     *
     * @param  array<int, Money>|null  $split
     *
     * @throws ValidationException when the credit is matched already, or the payment is refused (keys `line`, `tenantId`, `payment`, …)
     */
    public function matchByHand(User $by, int $lineId, int $tenantId, ?array $split): RecordedPayment
    {
        Gate::forUser($by)->authorize('match', BankStatementImport::class);

        return DB::transaction(function () use ($by, $lineId, $tenantId, $split): RecordedPayment {
            $line = $this->locked($lineId);

            if (! $line instanceof BankStatementLine) {
                throw ValidationException::withMessages(['line' => __('No such statement line.')]);
            }

            if (! $line->isOfferedForMatching()) {
                throw ValidationException::withMessages(['line' => $line->match_status === MatchStatus::Ignored
                    ? __('A debit is money leaving the account: it is never matched.')
                    : __('This credit is matched already: its payment is :status.', ['status' => mb_strtolower(__($line->match_status->label()))])]);
            }

            // Lock order: the line, then the bills the confirmation may settle (id
            // order, as Billing's ledger always takes them), then the recorder's
            // payment and audit chain. Taking the bills before the recorder's first
            // audit entry means a confirmation never waits for a bill while it holds
            // the audit chain, which a matcher recording a payment for that bill
            // (its foreign-key lock on the bill held) may be waiting for.
            $this->ledger->lock(array_values(array_unique([
                ...array_map(static fn (BillBalance $bill): int => $bill->id, $this->ledger->openBills($tenantId)),
                ...array_map('intval', array_keys($split ?? [])),
            ])));
            $attempt = $line->match_attempts + 1;
            $recorded = $this->recorder->recordConfirmed($this->draft($line, $attempt, $tenantId, null, $split === [] ? null : $split, $by));
            $billId = $split === null || $split === [] ? null : (int) array_key_first($split);
            $this->link($line, $recorded, $attempt, MatchConfidence::Manual, $billId, $tenantId, $by);

            return $recorded;
        }, 3);
    }

    /** The line of a payment that was just confirmed (inside its transaction) is matched. */
    public function paymentConfirmed(int $paymentId, ?int $byUserId): void
    {
        $line = $this->lineOfPayment($paymentId);

        if (! $line instanceof BankStatementLine || $line->match_status === MatchStatus::Matched) {
            return;
        }

        $line->match_status = MatchStatus::Matched;
        $line->matched_at = CarbonImmutable::now();
        $line->matched_by_user_id = $byUserId;
        $line->save();
        $this->countMatched($line);

        $this->audit($line, 'statement_line.match_confirmed', 'Statement line matched', $byUserId);
    }

    /**
     * The line of a payment that was just rejected (inside its
     * transaction) is offered for matching again — by hand only: it keeps
     * its attempt count, so the matcher does not suggest it again.
     */
    public function paymentRejected(int $paymentId, ?int $byUserId): void
    {
        $line = $this->lineOfPayment($paymentId);

        if (! $line instanceof BankStatementLine || $line->match_status !== MatchStatus::Suggested) {
            return;
        }

        $this->audit($line, 'statement_line.match_rejected', 'Statement line match rejected', $byUserId);

        $line->match_status = MatchStatus::Unmatched;
        $line->match_confidence = null;
        $line->suggested_bill_id = null;
        $line->tenant_profile_id = null;
        $line->payment_id = null;
        $line->matched_at = null;
        $line->matched_by_user_id = null;
        $line->save();
    }

    /**
     * The payment the line shows: its date, its amount and its narration
     * (the queue's reference, cut to 100 characters; the whole narration
     * as the note when longer), nothing else of the statement.
     *
     * @param  array<int, Money>|null  $split
     */
    private function draft(BankStatementLine $line, int $attempt, int $tenantId, ?int $billId, ?array $split = null, ?User $by = null): PaymentDraft
    {
        $narration = trim($line->narration);

        return new PaymentDraft(
            tenantProfileId: $tenantId,
            amount: $line->amount_kobo,
            method: PaymentMethod::BankTransfer,
            source: PaymentSource::StatementMatch,
            idempotencyKey: $line->paymentKey($attempt),
            paidAt: CarbonImmutable::parse($line->transaction_date->format('Y-m-d').' 12:00:00', 'Africa/Lagos'),
            reference: $narration === '' ? null : (mb_strlen($narration) > 100 ? mb_substr($narration, 0, 99).'…' : $narration),
            note: mb_strlen($narration) > 100 ? mb_substr($narration, 0, 500) : null,
            billId: $billId,
            allocations: $split,
            bankStatementLineId: $line->id,
            by: $by,
        );
    }

    /** The line's state after its payment was recorded (and perhaps confirmed). */
    private function link(BankStatementLine $line, RecordedPayment $recorded, int $attempt, MatchConfidence $confidence, ?int $billId, int $tenantId, ?User $by): void
    {
        $line->match_attempts = max($line->match_attempts, $attempt);

        if ($recorded->status === 'rejected') {
            // Only when an earlier call with the same key was rejected meanwhile: nothing to link.
            $line->save();

            return;
        }

        $line->match_status = $recorded->isConfirmed() ? MatchStatus::Matched : MatchStatus::Suggested;
        $line->match_confidence = $confidence;
        $line->suggested_bill_id = $billId;
        $line->tenant_profile_id = $tenantId;
        $line->payment_id = $recorded->paymentId;
        $line->matched_at = CarbonImmutable::now();
        $line->matched_by_user_id = $by?->id;
        $line->save();

        if ($line->match_status === MatchStatus::Matched) {
            $this->countMatched($line);
        }

        $this->audit($line, 'statement_line.matched', 'Statement line matched to a payment', $by?->id, [
            'confidence' => $confidence->value,
            'bill_id' => $billId,
            'tenant_profile_id' => $tenantId,
            'attempt' => $attempt,
            'amount_kobo' => $line->amount_kobo->kobo,
            'payment_status' => $recorded->status,
        ]);
    }

    /**
     * One more of the statement's lines is matched (`lines_matched`). A
     * matched line stays matched (a confirmed payment is never undone
     * here), so the count only grows. The statement's row is the last lock
     * a confirmation takes, after the payment, bills, credit and line.
     */
    private function countMatched(BankStatementLine $line): void
    {
        BankStatementImport::query()->whereKey($line->bank_statement_import_id)->increment('lines_matched');
    }

    /**
     * The line the payment was recorded from, locked by its primary key.
     * Found with a plain read first: a locking read by `payment_id` could
     * scan (and lock) other lines of a small table, which a matcher waiting
     * for the audit chain holds — a deadlock.
     */
    private function lineOfPayment(int $paymentId): ?BankStatementLine
    {
        $id = BankStatementLine::query()->where('payment_id', $paymentId)->value('id');
        $line = $id === null ? null : $this->locked((int) $id);

        return $line instanceof BankStatementLine && $line->payment_id === $paymentId ? $line : null;
    }

    private function locked(int $lineId): ?BankStatementLine
    {
        $line = BankStatementLine::query()->whereKey($lineId)->lockForUpdate()->first();

        return $line instanceof BankStatementLine ? $line : null;
    }

    /** @param  array<string, mixed>  $extra */
    private function audit(BankStatementLine $line, string $event, string $description, ?int $byUserId, array $extra = []): void
    {
        $log = activity(StatementImports::LOG)
            ->event($event)
            ->performedOn($line)
            ->withProperties(['statement_id' => $line->bank_statement_import_id, 'payment_id' => $line->payment_id] + $extra);

        if ($byUserId !== null) {
            $log->causedBy(User::query()->find($byUserId));
        }

        $log->log($description);
    }
}
