# GoProperty — Progress Ledger

The status of every task in [IMPLEMENTATION_PLAN.md § 3](../IMPLEMENTATION_PLAN.md). Rules for updating it are in [IMPLEMENTATION_PLAN.md § 3.1](../IMPLEMENTATION_PLAN.md) and [AGENT.md § 6](../AGENT.md).

Status values: `Not started` · `In progress` · `Blocked` · `In review` · `Complete (YYYY-MM-DD)`.

## Current state

- **In progress:** P03-03b (lease contract PDF) and P03-10a (PaymentService), each in its own worktree. Done in P03: P03-01, P03-02, P03-03a, P03-04, P03-05a, P03-05b (Chrome visual diff awaits its first CI run) (`docs/plans/P02-P03-subtasks.md`); the product owner asked for P03 to start with default answers to its open questions, recorded in that plan
- **In review:** P00-02 (CI): all four database jobs are green in GitHub Actions; branch protection on `main` is off by the product owner's decision (2026-10-27) while P02 and P03 are built, so tasks keep merging straight to `main`; turn it on afterwards (`docs/runbooks/ci.md` § 3) and mark P00-02 complete. P00-12 (backups): built and drilled locally; needs one real run to an S3-compatible bucket and a Google Shared Drive (PRE-03 accounts)
- **Next task:** P03 waves 2–14 in order, two at a time; PRE-01 to PRE-04 continue with the product owner
- **Blocked:** P00-13 (staging): the deploy scripts, the deploy on merge and the deploy drill are built and drilled locally; it needs a staging host (PRE-04). Until then the Deploy workflow fails at "Check deploy settings" on every push to `main`

## Board

| Phase | Tasks | Complete | Status |
| --- | --- | --- | --- |
| PRE | 5 | 1 | In progress |
| P00 | 13 | 10 | In progress |
| P01 | 9 | 9 | Complete (2026-10-27) |
| P02 | 7 | 7 | Complete (2026-10-27) |
| P03 | 15 | 3 | In progress |
| P04 | 3 | 0 | Not started |
| P05 | 6 | 0 | Not started |
| P06 | 11 | 0 | Not started |
| P07 | 4 | 0 | Not started |
| P08 | 4 | 0 | Not started |
| P09 | 8 | 0 | Not started |
| **Total** | **85** | **30** | |

## Ledger (newest first)

Each entry: task ID and status, date, what was delivered, tests run and results, follow-ups, next task.

### P03-04b — Complete (2026-10-27): Billing module: `bills` and `bill_items`, rent bill generation from leases (`bill:generate`, once per period, gap-free invoice numbers, lead window, the calculator's periods), the bill ledger for Payments, bill pages with export, void with a reason, the daily `bills:flag-overdue` job and the OVERDUE badge

With it the parent **P03-04 is Complete** (P03-04a numbering + P03-04b bill generation: both P03-04 ACs — "rerunning the job creates no duplicate bills" and "numbers are gap-free under concurrency" — pass).

**Delivered** ([ARCHITECTURE.md § 5 "Bills", § 6.3, § 6.4 "How rent billing is built", § 6.13, § 7, § 8](../ARCHITECTURE.md); [DATABASE_SCHEMA.md § 4](../DATABASE_SCHEMA.md) `bills`, `bill_items`; AGENT.md § 3 rules 2, 3, 5, 6 and § 4; `docs/plans/P02-P03-subtasks.md` P03-04b):
- **Module:** `Modules/Billing` (`module:make`, trimmed like Leasing: no controller, seeder, event provider, config or factories), in `modules_statuses.json`. `BillingServiceProvider` binds `Contracts\BillLedger`, registers `BillPolicy`, the Livewire namespace `billing::`, the sidebar item "Bills" (order 460, after Leases), the list export, the organization setting `billing.lead_days` (integer 0–90, default `CycleCalculator::DEFAULT_LEAD_DAYS` = 7) and the two jobs.
- **Schema (module migrations, one change each):** `2026_11_09_040201_create_bills_table` (DB § 4 columns plus `property_id`/`unit_id` (narrowing without joins), `paid_kobo` (the ledger), `issued_at`, `voided_by_user_id`, `void_reason`, `created_by_user_id`, `external_ref`; `billable_type` as a short key `lease`/`unit`; FKs restrict to organizations, leases, tenant_profiles, properties, units; unique `payment_reference` (global), `(organization_id, number)`, `verification_code`, **`(lease_id, type, period_start)`** (the natural key), `(organization_id, external_ref)`; indexes for status/due date, billable, type/status, building, tenant, due date, import batch); `…040202_create_bill_items_table` (`organization_id`, `bill_id` FK restrict, label, note, `amount_kobo` = the line total, quantity, own period, meta json).
- **Models and enums:** `Models\Bill` (organization-scoped, soft deletes, nothing mass assignable, money via `MoneyCast`, `outstanding()`), `Models\BillItem` (organization-scoped; refuses update and delete), `Enums\BillStatus` (unpaid, partially_paid, paid, overdue, void — the values of Core's `InvoiceStatus`; `for(amount, paid, due, today)`: paid in full → paid, else past due (Lagos) → overdue, else partly paid / unpaid; void final), `Enums\BillType` (rent, utility, opening_balance, other), `Enums\BillableType` (lease, unit). Both models added to `tests/Support/OrganizationScopedModels.php` (all `CrossTenantAccessTest` probes).
- **`Bills\BillIssuer` — the only place bills are created** (inside the caller's transaction; refused outside one): amount = sum of the lines (kobo, positive), invoice number `DocumentNumberer::next(DocumentType::Invoice, now, snapshot branding)`, verification code `VerificationCodes::issue('invoice')` (also in `document_verifications`), payment reference from `Bills\PaymentReferences` (`GP-` + 7 Crockford base-32 characters, CSPRNG; each try saved in a savepoint, a collision on the global unique index draws again), branding snapshot `BrandingSnapshot::toArray()`, status from `BillStatus::for` (a catch-up bill past its due date is overdue at once), lines, audit `bill.issued` (log `bills`, no causer for the job), event `BillIssued`.
- **`Bills\RentBillGenerator`** (current organization): pages through `LeaseBillingSchedule::due(today, lead_days)` 100 leases at a time; per lease, one transaction per period (`DB::transaction(…, 3)`): `LeaseBillingSchedule::lock()` (new, see below) → the lease as it is now; stop when its next period's bill date (`CycleCalculator::billingDate`) is after today (Lagos); a plain read of the natural key (sees every bill committed before the lock was released) → issue the bill unless it exists → `advance()`. Period and due date from the calculator only (`periodContaining`, `dueDate`); one rent line (label "Rent", note "{cycle} rent, lease {contract}", the period, meta cycle/contract/period index). A duplicate-key error on the natural key is caught and the lease looked at again (max 3). A failing lease is reported (`report()`) and the others continue; in the jobs, a failing organization is reported and the next ones continue. Branding snapshot taken once per organization per run, only when something is due. Stops when the job's budget says so; the next run carries on.
- **Jobs:** `Jobs\GenerateBills` (`bill:generate`; `Schedule::job` daily 00:10, `billing` queue, name `bills-generate`; `ShouldBeUniqueUntilProcessing` (queued once at a time) + a cache lock `billing:generate-bills` while it works (one copy at a time; a copy finding it taken leaves the work to the first); every organization except cancelled ones, each in `OrganizationContext::run`; 40 s budget, then queues itself again from the organization it was on). `Jobs\FlagOverdueBills` (`bills:flag-overdue`; daily 01:00, `billing` queue, name `bills-flag-overdue`; reads only the `organization_id` of organizations with unpaid/partly paid bills past due (its own table), then `BillService::flagOverdue()` in each: each bill locked and re-checked, moved to overdue once, `BillOverdue` event; 40 s budget, re-queues).
- **`Bills\BillService`:** `void(User, Bill, reason)` (BillPolicy::void checked for the actor; reason required 3–500; bill row locked; refuses a void bill and one with money applied (`BillRefused`, shown on the page); sets void, `voided_at`, `voided_by_user_id`, `void_reason`; audit `bill.voided` with reason, amount and previous status; `BillVoided`), `flagOverdue()`, `leadDays()`.
- **`Contracts\BillLedger`** (for Payments P03-10a and Collections P03-07b; implemented by `Bills\Ledger`, bound in the provider) + `BillBalance` + `BillNotPayable` (reasons void / unknown / not_positive / too_much): `balance`, `findByReference` (as typed: case, spaces, dashes, O/I/L ignored), `openBills($tenant)` (oldest due first), `lock($ids)` (one locking read in id order), `apply($id, $amount)`, `release($id, $amount)` — each change locks and re-reads the bill inside the caller's transaction (LogicException outside one), never settles beyond the amount, refuses a void bill, recomputes the status. The caller audits its payment.
- **Events** (`Modules\Billing\Events`, plain ids: bill, organization, tenant, lease, type, number, amount kobo): `BillIssued`, `BillVoided`, `BillOverdue`, sent inside the transaction.
- **Pages (`billing::`, routes in the module):** `/o/{slug}/bills` (`BillIndex`: 25 a page, latest due first, debounced search over number, payment reference (normalised) and tenant (TenantDirectory::search), filters status (incl. overdue) / type / building / lease / tenant in the URL, names via one lookup per page — no N+1; status badges incl. OVERDUE) and `/bills/{id}` (`BillShow`: status badge, tenant, unit, period, due, payment reference, issued, lease link, lines, total/paid/outstanding, void form with reason; refused void shows the reason). **Export:** `Lists\BillList` (implements `ListExport`, rows via `BillParties`), registered from the provider. The lease page gets a "Bills" button (`/bills?lease={id}`) once the lease is activated.
- **`Policies\BillPolicy`:** viewAny/view/export (`bills.view`), void (`bills.manage`, not void, no money applied); 404 outside a narrowed agent's buildings (`BuildingAccess` on `bills.property_id`). No new permission (`bills.view`/`bills.manage` already existed and were classed: `bills.manage` blocked while impersonating and dropped while suspended).
- **Leasing (through its contract):** `Contracts\LeaseBillingSchedule::lock($leaseId)` added (lease row `FOR UPDATE` until the caller's transaction ends; returns the lease as it is now if billable, else null; LogicException outside a transaction), implemented in `LeaseRecords`.
- **Docs:** ARCHITECTURE.md § 5 (new "Bills" paragraph), § 6.3 (`lock`), § 6.4 ("How rent billing is built"), § 6.13, § 7 (both job rows), § 8; DATABASE_SCHEMA.md § 4 `bills` and `bill_items` as built (+ handover note), § 8A; PLATFORM_CONSOLE.md § 6 (bills export allowed, void blocked); ONBOARDING_COMPLIANCE.md § 4.1 and BRANDING_DOCUMENTS.md § 2 (reference example `GP-7KQ2M9X`); docs/runbooks/ci.md (the bill generation drill).

**Tests and evidence (local, MariaDB 11.4):**
- `composer ci` on the final commit: Pint passed, Larastan 0 errors, Pest **2,330 tests: 2,302 passed, 28 skipped (the Chrome halves of the P03-05 visual diffs and 360 px checks, no Gotenberg locally), 25,775 assertions**, 0 failed (`--parallel`, drills included).
- New tests with `APP_DEBUG=true`: the Billing module, `BillGenerationDrillTest`, `DocumentNumberingDrillTest`, `LeaseBillingScheduleTest`, `LeasePagesTest`, `ListExportCoverageTest`, `WorkspaceNavigationTest`, `ModuleSkeletonTest`, `CrossTenantAccessTest`, `CycleMathBoundaryTest`: 227 tests, 227 passed, 3,286 assertions.
- **AC: rerunning the job creates no duplicate bills:** `Modules/Billing/tests/Feature/RentBillGenerationTest.php` › "acceptance: creates no duplicate bills when the job runs again, the same day or later" (the scheduled `bills-generate` event run 3× the same day, then on later days: still one bill per period; February's bill only when its window opens; `BillIssued`, `bill.issued` audits and `invoice` verification codes once per bill; numbers `INV-ADR-2026-000001/2`, `INV-ADR-2027-000001`; the lease moved to 1 Mar) and › "acceptance: never bills a period twice, even for a lease that was not moved on, and the database refuses a second bill" (lease's `next_billing_date` put back: the rerun finds the bill and only moves the lease on; a raw insert of the same `(lease_id, type, period_start)` throws `UniqueConstraintViolationException`).
- **AC (also under concurrent runs — the unique key must hold) and numbers gap-free under concurrency at job level:** `tests/Drill/BillGenerationDrillTest.php` › "acceptance: bills every period once when several runs of bill generation work on the same leases at once, numbers without gaps": 4 PHP processes on their own connections (`tests/Fixtures/Processes/bill-generation.php`, released together through a file barrier) run `RentBillGenerator` straight (no job lock) on 12 leases behind by several periods (weekly, daily, monthly, annual: 78 periods due); asserts the processes overlapped, none reported an error, exactly 78 bills, 78 distinct `(lease, period)`, every bill issued by exactly one process, 78 verification codes and payment references, every lease moved past the window, invoice sequences exactly 1..78 and the counter at 78; a second pair of concurrent runs issues nothing. Ran 4× green. **Negative controls (temporary, reverted):** with the lease lock replaced by an unlocked read, the database's unique key still refused every duplicate (the generator reported `Duplicate entry … bills_lease_type_period_unique` and the test failed on "nothing reported"); with the lock *and* the unique index removed, 312 bills instead of 78 (duplicates) — the drill catches both. P03-04a's numbering drill still passes.
- **Sub: annual, weekly and semi-annual leases get the calculator's periods (time travel over several cycles):** › "bills annual, weekly and semi-annual leases with exactly the calculator's periods, each on its billing day, until the lease ends" (dataset: annual from 29 Feb 2028 (4 periods, 28 Feb in common years), semi-annual from 31 Aug 2027 with due offset 3 (6 periods, 31 Aug → 29 Feb → 31 Aug → 28 Feb clamping), weekly from Wed 6 Jan 2027 with due offset 2 (8 periods); expected periods written out literally and checked equal to the calculator's; for every period the job runs the day before its billing day (nothing) and on it (exactly that period); nothing after the end; amounts = rent per period).
- **Sub: bills only inside the lead window:** › "bills a period only inside the organization's lead window, each organization by its own setting" (three organizations, lead 7 (default) / 14 / 0, daily runs 14 Jan–1 Feb for a 1 Feb period: first billed 25 Jan / 18 Jan / 1 Feb, one bill each).
- **Sub: `paid_until` respected (no past bills):** › "respects paid until: no bill for a period already paid, the first bill is the period after it" (yearly lease from 2026 paid to 31/12/2026 and monthly from Oct 2026 paid to 31/12/2026: first and only bills are January 2027's). Also › "bills every period a lease is behind by, oldest first, the ones already due as overdue" (no `paid_until`: Oct–Dec billed, overdue).
- **Sub: void is audited and blocked while impersonating:** `BillAccessTest` › "voids a bill with a reason, audited, and the void bill accepts nothing afterwards" (reason required; whitespace normalised; `bill.voided` with causer, reason, previous status, amount; `BillVoided` once; a second void refused; `BillLedger::apply` refused with `void`), › "refuses to void a bill with payments applied, and changes nothing" (service-level refusal under the lock past any policy; page shows the reason), › "lets impersonating staff look at and export bills, but never void one, in either mode" (read-only and write sessions: no Void button, void call 403, export works, `impersonation.blocked` audited in write mode).
- **Sub: overdue flagging:** `OverdueBillsTest` › "flags bills unpaid or partly paid after their due date as overdue, the day after it in Lagos, once" (the scheduled `bills-flag-overdue` event at 22:30 UTC 1 Jan (not yet) and 23:30 UTC (2 Jan in Lagos: flagged); paid and void untouched; due-offset bill later; reruns flag nothing again; `BillOverdue` once per bill), › "shows the OVERDUE badge on the bills list and the bill page", › "flags each organization's bills in its own context, suspended ones too", › "leaves a bill paid meanwhile alone, and a paid overdue bill is paid".
- **Sub: cross-org probes:** `CrossTenantAccessTest` (datasets `Bill`, `BillItem`); `BillAccessTest` › "answers 404 for another organization's bill guessed by ID, on pages, lists and actions" (show 404, unknown/malformed ids; never listed, searched by name/number/reference or filtered by building/lease/tenant; tampered `billId` → 419 with debug off; the writer refuses a foreign bill; a member of both organizations sees each one's own); `BillLedgerTest` › "never reaches another organization's bills" (apply → unknown, lock/balance/findByReference/openBills empty).
- **Sub: narrowed agents:** `BillAccessTest` › "shows an agent assigned to buildings only those buildings' bills, and 404 for the others" (list, building filter, page 404, `void` 404 from the writer, export rows, own building's bill voided; an unassigned agent sees all).
- **Sub: suspended org:** › "shows bills read-only while the organization is suspended, and still exports them" (no Void, void call 403, export works); bill generation and overdue flagging still run for suspended organizations, not for cancelled ones (`RentBillGenerationTest` › "keeps billing suspended and restricted organizations, and bills nothing for a cancelled one").
- **Sub: list exports:** `BillPagesTest` › "exports the bills the page shows, in Excel and CSV, with its filters, audited" (exact rows incl. void and partly paid, Lagos times, money as naira text); `ListExportCoverageTest` (a bill row; `bills` in the registry).
- **Bill ledger (for P03-10a):** `BillLedgerTest` (parts → partially_paid → paid; release back; past due stays overdue until paid in full; refusals change nothing: too much, zero/negative, release beyond paid, unknown, second apply in one transaction; void accepts nothing; lock in id order; open bills oldest due first; reference as typed); drill › "never settles a bill beyond its amount when payments to it are confirmed at once" (6 processes × 10 payments of ₦3,000 on a ₦100,000 bill: exactly 33 applied, 27 refused `too_much`, paid ₦99,000, the applied running totals exactly 3,000…99,000); drill › "refuses to issue, lock or settle bills, or lock a lease for billing, outside a transaction".
- Others: `RentBillGenerationTest` › bill contents (number, reference format, verification kind, description, amount, due offset, issued time, branding snapshot company and prefix, the rent line and its meta (compared with `toEqual`), audit properties; lines refuse change and delete), › per-organization gap-free numbers and 12 distinct references, › payment reference collision draws again (seeded randomizer), › a failing lease is reported and the others billed, and a failing organization is reported and the next ones billed (`Exceptions::fake`), › stop-and-resume billing each period once, › one copy at a time (cache lock) and resume from an organization, › schedule (00:10 / 01:00, `billing` queue, queued once); `BillPagesTest` (25 a page, filters status/type/building/lease/tenant, search by tenant/number/typed reference with fixed contact data, clear filters, no N+1 (same query count for 1 and 21 bills), empty states, bill page content and lease ↔ bills links); `BillAccessTest` › role matrix (5 roles × 5 abilities) and pages for owner/admin/agent vs property owner/tenant/stranger (404); `LeaseBillingScheduleTest` › "locks a billable lease for Billing inside a transaction…".
- Existing tests changed (none weakened): `WorkspaceNavigationTest` (sidebar gains `bills` for owner, admin, agent), `ListExportCoverageTest` (a lease of the same flat from today and its bill; `bills` in the expected registry), `OrganizationScopedModels` (+`Bill`, `BillItem`), `LeaseBillingScheduleTest` (+1 test).
- MySQL JSON key order: no new test compares a map read from a JSON column with `toBe` (the bill line's `meta` uses `toEqual`; audit properties single values).
- Not run: MySQL 8 (unique natural key and locking reads tested on MariaDB 11.4 only); release/install drills (`InstallCommandTest` covers module migrations); no browser smoke (HTTP/Livewire tests only).

**Orchestrator review:** merged `main` (with P03-01b) into the branch; resolved one ARCHITECTURE.md § 5 conflict by keeping both paragraphs. `composer ci` locally (MariaDB 11.4): Pint passed, Larastan 0 errors, Pest 2,371 tests: 2,343 passed, 28 skipped (Gotenberg). GitHub Actions on the branch then **caught a real bug on MariaDB 10.6** (run [40531063853](https://github.com/thekingchild/Goproperty/actions/runs/40531063853)): with `explicit_defaults_for_timestamp` off, `bills.issued_at` (the first NOT NULL TIMESTAMP) got an implicit `ON UPDATE CURRENT_TIMESTAMP`, so voiding a bill rewrote its issue date. Fixed before merging: the column has an explicit default (`useCurrent()`), and a new `tests/Feature/Core/TimestampColumnsTest.php` fails on any self-updating timestamp column in any table (reproduced locally with the session setting off; no other table had one). Re-run [40533475554](https://github.com/thekingchild/Goproperty/actions/runs/40533475554): green on all four databases.

**Decisions to confirm** (orchestrator decisions relied on, and choices made here):
- Relied on **decision 1:** periods, due dates and bill dates come only from `CycleCalculator` (`periodContaining`, `dueDate`, `billingDate`; the interval-maths scan covers `Modules/Billing` and stays green); `billing.lead_days` organization setting (default 7, 0–90; 0 = billed on the period's first day); `due_day_offset` = days after the period start.
- Relied on **decision 5:** `bills:flag-overdue` daily (01:00, ARCH § 7), `Schedule::job`, per organization context; drives the OVERDUE badge.
- Relied on **decision 8 (gating):** BILLING § 1.2 gates nothing here (rent billing is in every plan): nothing for P06-01.
- Relied on **decision 9 (narrowing):** bills narrow through `BuildingAccess` on `bills.property_id` in the policy, list, export and writer.
- Relied on the **product owner rule on handovers:** bills are history (see below); the only live billing state is the lease's `next_billing_date`, which lives in Leasing.
- **Catch-up billing:** a lease whose `next_billing_date` is in the past (activated late with no `paid_until`, or the job did not run) is billed for every period behind, oldest first, in one run; bills already past due are `overdue` from issue. Never before `next_billing_date` (so `paid_until` and imports' "never back-bill" hold). Product owner: confirm, or cap catch-up (e.g. only the current period).
- **Last period of a lease that is not a whole number of periods:** billed as the calculator's whole period at the full rent (period end may fall after the lease end). Pro-rating changes money semantics, so it is not invented here (P03-03a follow-up still open).
- **Suspended organizations keep being billed** (their tenants keep their bills; the portal keeps accepting proofs while suspended, ONBOARDING § 3); cancelled organizations are not. Restricted ones are ("existing bills keep working").
- **Payment reference:** `GP-` + 7 characters (35 bits, ~34 billion) instead of the docs' 5-character example (`GP-7KQ2M`): the public `/pay/{reference}` page (P03-05c) must not be guessable; still short enough for a bank narration. The `GP` prefix is a constant (the docs specify it), not the agency's.
- **`paid_kobo` on `bills`** (not in DB § 4): the ledger keeps a bill's settled amount on its own row under its lock, because Billing cannot read `payment_allocations` (Payments' table) and concurrent confirmations need one row to serialise on. `property_id`, `unit_id`, `issued_at`, `voided_by_user_id`, `void_reason`, `created_by_user_id`, `external_ref` also added.
- **`bill_items.amount_kobo` is the line total** (`quantity` is shown, not multiplied); `organization_id` added to `bill_items` (every tenant table carries it).
- **Void:** allowed for org-owner/org-admin/agent (`bills.manage`, as the role map already had it), only while no money is applied (reverse payments first); final; the rent period stays billed (the lease does not move back): correcting a bill needs manual bills (not built).
- **Verification resolver for `invoice` not registered here** (left to P03-05c as its row says): `tests/Feature/Documents/VerifyPageTest.php` registers a fake `invoice` resolver, so registering one now would break it; codes are issued and recorded with kind `invoice` already.
- **Natural key covers rent;** P03-06b's utility bills must use their own key (e.g. leave `lease_id` null or widen the key), or two utilities of one lease in the same period would collide.
- **`LeaseBillingSchedule::lock()` added** to the Leasing contract (the brief listed `due`/`find`/`advance`): reading the lease's next period under its row lock is what makes two runs on one lease safe without relying on the unique key alone.
- **No audit per overdue flag** (a status flag, not money; the `BillOverdue` event is the record for P05); generation and void are audited.

**Management handover (P03-14) — what this task's tables need:**
- Nothing in `bills` or `bill_items` moves: issued bills (paid, open or void) stay with the organization that issued them, with their numbers, references, verification codes and branding snapshots (the old organization's receivables). `document_counters` and `document_verifications` stay too.
- The live billing state of a moved lease is `leases.next_billing_date` (Leasing's table): it moves with the lease, so the receiving organization's next `bill:generate` bills the next period with its own numbers and branding.
- Bills keep the ids of moved buildings, units and leases (foreign keys hold: the rows keep their ids), but the issuing organization can no longer read those names through the contracts (they are scoped to the new organization): the old organization's bill pages would show "Removed building" / "Archived unit". P03-05c (which snapshots the invoice) or P03-14 should store the party names on the bill if those pages must keep them.
- Open bills of moved leases: decide whether the tenant keeps paying the old organization (as built: yes, they are its bills) or they are cancelled/re-issued — a product-owner question for P03-14.

**Follow-ups:**
- P03-05c: build `InvoiceDocument` from a bill (`InvoiceStatus::from($bill->status->value)`, `BrandingSnapshot::fromArray($bill->branding_snapshot)`), register the `invoice` verification resolver (and change `VerifyPageTest`'s fake `invoice` kind), render/store the PDF on `BillIssued`, `/pay/{reference}` via `PaymentReferences::normalize` + an unscoped `payment_reference` → organization lookup (decision 2 pattern).
- P03-10a: use `BillLedger` (`lock` all bills of a payment first, then `apply`; excess to credit; `release` on reversal); apply credit on `BillIssued`.
- P03-06b: plug `UtilityBillGenerator` into `GenerateBills`; its own natural key (see above).
- P03-13a/b: imported leases bill from `next_billing_date` as built; opening balances through `BillIssuer` (type `opening_balance`, `billable` unit or lease, provenance columns exist).
- Tenants module (P03-01b's area, not edited here): the tenant record page's "Leases and bills" section can link `/bills?tenant={id}` (filter exists).
- Manual bills / correcting a voided rent period; pro-rating rule for a lease's last partial period; settings page for `billing.lead_days`.
- P05: reminders on `BillOverdue` / due dates; P06-01: nothing gated here.
- MySQL 8.0/8.4 not run locally: watch the natural-key unique index, the drill and the locking reads on the first CI run.

**Next:** P03-01b runs alongside; then wave 5 (P03-10a Payments ‖ P03-03b contract PDF).

### P03-01b — Complete (2026-10-27): tenant portal access: invitations by email, the agency's WhatsApp (stand-in sender) and link / QR, acceptance without account takeover, "confirmed in person", one-time sign-in links, resend/revoke, rate limits

With P03-01a done, this completes the parent **P03-01 — Complete (2026-10-27)**.

**Delivered** ([ONBOARDING_COMPLIANCE.md § 2](../ONBOARDING_COMPLIANCE.md) "Tenants" and the new "How tenant invitations behave", [UX_DESIGN.md § 3.3](../UX_DESIGN.md), [ARCHITECTURE.md § 4, § 5, § 6.1, § 6.7, § 10](../ARCHITECTURE.md), [DATABASE_SCHEMA.md § 1](../DATABASE_SCHEMA.md); `docs/plans/P02-P03-subtasks.md` P03-01b):
- **Schema (3 migrations):**
  - Core `2026_11_08_010101`: `users.phone_verified_at`, `phone_verification_method` (`whatsapp_link` / `agency_confirmed`, enum `App\Enums\PhoneVerificationMethod`), `phone_confirmed_by_user_id` (FK, null on delete). These columns were in DB § 1 but did not exist yet.
  - Core `2026_11_08_010102`: `invitations.user_id` (the account a sign-in link opens; null on delete).
  - Tenants module `2026_11_08_010103`: `invitations.tenant_profile_id` (FK, restrict) + index `(organization_id, tenant_profile_id, kind, status)`. This follows the precedent of SaasBilling's `billing_transfer_id`.
- **Core:**
  - `InvitationKind::Tenant` and `::SignInLink`; `InvitationChannel::WhatsApp` and `::Link` (with labels).
  - New permission `tenants.portal_access` (org-owner, org-admin, agent). It is **not** allowed while suspended and **blocked while impersonating**.
  - `App\Auth\PhoneVerification` (`byWhatsAppLink`, `confirmedInPerson`: only the account's current number, only once).
  - `User::hasVerifiedEmail()`: a phone-only account counts as verified, so `/portal` and Settings open for it. `OrganizationPolicy::create` now needs an email, so phone-only accounts cannot sign up an organization.
  - `App\Tenancy\PortalLanding` registry. `WorkspaceLanding` and `/dashboard` send someone with no workspace to their portal. Before this, tenants were sent to the signup wizard (the P01-01 behaviour assumed tenants were members).
- **`App\WhatsApp`** (decision 3):
  - `WhatsAppSender` contract (`available`, `send`) and `WhatsAppMessage` (event + placeholder variables, so P05-06 can map Meta templates).
  - `SendWhatsAppMessage` job: queue `notifications`, encrypted, 3 tries, after commit.
  - `LogWhatsAppSender` (driver `log`, the default): sends nothing, keeps the messages in memory for tests, and logs only the event and the masked number. It is never `available()` in production.
  - `NoWhatsAppSender` (driver `none`). Config `services.whatsapp.driver` / `WHATSAPP_DRIVER` (commented in `.env.example`).
- **Tenants module, `Portal\*`:**
  - `TenantInvitations` (invite / resend by any channel / revoke):
    - one live invitation per tenant; none for a tenant with an account or an archived tenant;
    - 14 days, single use, only the hash is stored;
    - email notification `TenantPortalInvitation` (mail only, encrypted on the queue); WhatsApp through the contract; link / QR returned to be shown once.
  - `PortalInvitationAcceptance`: token → organization (P01-04 pattern), then `OrganizationContext::run`. It links `tenant_profiles.user_id` and never writes `organization_user` or roles. It refuses in a suspended organization.
  - `SignInLinks`: 24 h, single use; a new link cancels the previous one; WhatsApp or link / QR. GET changes nothing; POST signs the tenant in, with an optional new password. Creation is in the authentication log (`agency_signin_link_created`, an existing event); create, use and revoke are audited.
  - `PhoneConfirmations` ("confirmed in person").
  - `LinkedTenantProfiles`: the ARCH § 4 "linked to my user" read, raw, of `organization_id` only.
  - `PortalAccessAudit`: log `tenants`, the tenant as subject, never the link.
- **Account-takeover rules** (in `PortalInvitationAcceptance`; ARCH § 6.1 as built):
  - The link is bound to what it was sent to: the email address (email channel) or the tenant's number (WhatsApp and link channels).
  - An account that already holds that address or number must sign in first. The link never opens an existing account.
  - A signed-in account may join only if it is that holder, or if it has no address / number yet and nobody else holds it (it then gains it). Anyone else gets a 403.
  - A new account gets only what the link proves. An emailed link stores the profile's phone only if no account has it.
- **Sign-in link eligibility:** a link can be made only for an account that is phone-only, has no 2FA, has no `organization_user` row anywhere and is not a tenant of another organization. This is checked when the link is made and again when it is used.
- **Pages:**
  - `/portal/invitations/{token}` and `/portal/sign-in/{token}` (sign-in split layout; `#[BlockedWhileImpersonating]`; 30 GET / 10 POST a minute per IP). Unknown, used, expired and revoked links get one identical 404 page; the wrong account gets a 403 page.
  - `/portal/welcome`: optional passkey via Settings → Security, with the password marked confirmed in the session.
  - "Portal access" section on the tenant record (`Livewire\TenantPortalAccess`, nested in `TenantShow`):
    - invite (email / WhatsApp / "Show link and QR code" with copy and print), resend, revoke;
    - account and phone status, "Phone number confirmed in person";
    - sign-in link create/revoke, or the reason it is refused.
  - Every action carries `#[BlockedWhileImpersonating]` and is checked by `TenantProfilePolicy::managePortalAccess` (narrowed, active tenants only).
- **Docs:**
  - DATABASE_SCHEMA.md § 1: users, invitations (the two new kinds), tenant_profiles writer of `user_id`.
  - ARCHITECTURE.md: § 4 (portal path and landing), § 5 "Tenant portal access", § 6.1 "How tenant invitations and sign-in links are built", § 6.7 (the WhatsApp contract), § 10.
  - ONBOARDING_COMPLIANCE.md § 2 (as built).
  - UX_DESIGN.md § 3.3.
  - PLATFORM_CONSOLE.md § 6 (always blocked).
  - `.env.example`.

**Orchestrator review:** the branch already contained `main` (`a59aef1`). **GitHub Actions** run [40523642256](https://github.com/thekingchild/Goproperty/actions/runs/40523642256) on the branch: green on MySQL 8.0/8.4, MariaDB 10.6/11.4 and the old-PHP guard. Read `PortalInvitationAcceptance`: a new account is refused while anyone holds the invited email/phone (they must sign in), an existing account accepts only an invitation bound to its own address/number, and acceptance locks the invitation and tenant rows in one transaction.

**Decisions relied on (to confirm with the product owner):**
- **Orchestrator decision 3:** the `WhatsAppSender` contract with a fake (`log`) driver. My addition: the fake is never offered in production, so an agency is never told a message went out. No SMS anywhere.
- **Orchestrator decision 4:**
  - Sign-in links are Core `invitations` rows of kind `sign_in_link` (token hash, 24 h, single use). Tenant invitations are kind `tenant`.
  - Tenants never get `organization_user` rows (tested).
  - Needed for this: Core `invitations.user_id`, and `tenant_profile_id` added by the module.
- **Orchestrator decision 9:** narrowing through `TenantNarrowing` in `managePortalAccess`.
- **Orchestrator decision 8:** WhatsApp is Growth+ (BILLING § 1.2). This is not gated yet; P06-01 follow-up.
- **My decisions:**
  - **Sign-in links only for "pure" tenant accounts** (phone-only, no 2FA, no workspace membership, no tenancy elsewhere).
    - Reason: an agency-made link signs in without a password. Otherwise agency A's staff could open an account that also holds agency B's records or a workspace.
    - Consequence: a phone-only tenant of two agencies cannot be reset by either until P05 adds WhatsApp reset links.
  - **No 2FA bypass:** an account with 2FA gets no sign-in link.
  - **"Confirmed in person" only after acceptance,** on the record, for the account's current number when it equals the record's. It is not a tick on the invite form.
  - **Optional "Add an email" for phone-only tenants is deferred to P03-11a's portal profile.** UX § 3.3 puts it on the acceptance page. An unverified address would block the `verified` pages, and asking for one there needs its own verify flow.
  - **New permission `tenants.portal_access`** rather than reusing `tenants.manage`, so that it can be blocked while impersonating (`tenants.manage` is not). Agents hold it because they sign leases and hand over keys.
  - **Sign-in links still work in a suspended organization.** The tenant must still reach the portal for payment proofs (ARCH § 4). Creating links (a write) is refused while suspended. Invitation acceptance is refused while suspended (as for members).
  - Routes are under `/portal/...` rather than reusing `/invitations/{token}`, which stays member-only (as P02-04 did with `/billing-transfers/{token}`).
  - `User::hasVerifiedEmail()` override for null email (see Delivered).

**For P03-14 (management handover):** these tables hold nothing building-linked. `invitations` rows of kinds `tenant` and `sign_in_link` are history of the organization that wrote them. A moved tenant's *pending* invitation or sign-in link should be revoked by the old organization's side, and the receiving organization re-invites. The `tenant_profiles.user_id` link should be copied onto the receiving organization's copy of the tenant (as P03-01a's note says), so the tenant keeps portal access. Users' phone-verification columns are account-level and do not move.

**Tests and evidence (local, MariaDB 11.4, branch merged with `main` at a59aef1):**
- `composer ci` (after merging `main` with P03-03a): Pint passed; Larastan 0 errors; Pest **2,310 tests: 2,282 passed, 28 skipped, 23,636 assertions**, 0 failed. The 28 skips are all the Chrome/Gotenberg halves of the document visual diffs (no Gotenberg locally); `tests/Feature/Documents` alone shows the same 28.
- New tests are in `Modules/Tenants/tests/Feature/Portal/` (all through HTTP and Livewire update requests), with `helpers.php`:
  - `PortalInvitationsTest` (9)
  - `PortalSignInLinksTest` (13, incl. a 5-case dataset)
  - `PortalAccountSafetyTest` (19, incl. datasets)
- **AC: each invite channel produces a working one-time link:**
  - "acceptance: an emailed invitation link sets up a working account, once, and lands in the portal". Mail only, on `notifications`. The account's email is confirmed and the phone is stored unverified. It lands on `/portal/welcome`, and then `/portal` and Settings → Security open. `/o/acme-homes` answers 404 and there is no membership. Auth log and audit are written and the audit chain verifies. Reuse answers 404. Sign-in by email and by phone both land on `/portal`.
  - "acceptance: a WhatsApp invitation link works once and verifies the phone number (whatsapp_link)": a phone-only account; `/dashboard` → `/portal`; `/signup/organization` 403.
  - "acceptance: a copy link / QR code works once; the phone is not verified until the agency confirms it in person". The QR code is a PNG data URI; the link is shown once and only its hash is stored. "Confirmed in person" records who confirmed it and works only once.
- **AC: used or expired links rejected:** "rejects expired, used and revoked links, and unknown ones, all the same way". The link still works on its last day, fails 14 days + 1 minute later, and the status is recorded as expired. Revoked and unknown links get an identical page. The "acceptance" tests above also show used links rejected.
- **Sub-AC: the sign-in link dies after 24 h and after one use:**
  - `PortalSignInLinksTest` › "acceptance: a one-time sign-in link signs the tenant in once, may set a new password, and dies after use": GET does not use it up; a short password is refused without using it; a second use answers 404.
  - "kills a sign-in link after 24 hours" (23:59 works, 24:01 fails).
  - "replaces the previous sign-in link with a new one, and revokes one".
- **Sub-AC: no SMS sent anywhere:** `PortalInvitationsTest` › "never sends an SMS: only email and the agency's WhatsApp, and nothing leaves the server":
  - every notification channel is `['mail']`;
  - the WhatsApp fake recorded exactly the expected events;
  - `Http::assertNothingSent()`;
  - a static scan of `app/WhatsApp` and the module's Portal, Notifications and Livewire code finds no sms/termii/vonage/twilio.
- **Sub-AC: blocked during impersonation:** `PortalAccountSafetyTest` › "refuses every portal-access action while GoProperty staff impersonate, and the links do not open in that session", in read-only and write mode:
  - all 6 Livewire actions answer 403 and no buttons are shown;
  - `/portal/invitations/{token}` GET and POST answer 403;
  - the policy denies; nothing changes; `impersonation.blocked` is audited at least 8 times.
  - `ImpersonationGuardTest`'s classification list now includes `tenants.portal_access`.
- **Resend invalidates the previous token:** "sends an invitation again with a new link, by any channel: the old link stops working" (email → link → WhatsApp → expired → resend; send_count; audit).
- **Revoke works:** the expired/revoked test above, plus the sign-in link revoke test.
- **Cross-org:** "cross-org: an invitation or sign-in link of one organization never touches another organization's tenant":
  - a forged Acme invitation row pointing at Other's tenant answers 404;
  - the real link links Acme's tenant only, though Other has the same person;
  - Other's member gets 404 on the record and on a Livewire action, and 419 with a tampered locked `tenantId` (debug off).
- **Account takeover refused:**
  - "never opens an existing account from an emailed link…";
  - "never opens the account that has the tenant's phone number from a WhatsApp or shared link" (×2);
  - "refuses a signed-in account the link is not for" (×3);
  - "lets a signed-in account without that address or number join…";
  - "gives a new account only what the link proves…";
  - sign-in link: "refuses a sign-in link for an account the agency must not open" (×5: no account, has email, 2FA, workspace member, tenant elsewhere), "checks again when the link is used…", "refuses someone signed in with another account…".
- **Rate limits:**
  - "limits invitation sends per tenant, per member and per organization, and the links per address": 5 per tenant a day, 30/h per user, 50/h per organization; the 31st GET in a minute gets 429.
  - "limits sign-in links per tenant, per member and per organization": 5/day, 20/h, 30/h; the 11th POST in a minute gets 429.
- **Others:**
  - "never makes a tenant a member…" (no `organization_user` or `model_has_roles` rows; `/o/…`, the tenant list and the switch answer 404; login lands on `/portal`);
  - "changes no portal access in a suspended organization…";
  - the role matrix for 5 roles;
  - "keeps tenant invitations and sign-in links out of Settings → Members and the member invitation page".
- **Existing tests changed (none weakened):**
  - `ImpersonationGuardTest`: `tenants.portal_access` added to the blocked list.
  - `TenantPagesTest` › "never deletes a tenant row for good…": the scan still bans `forceDelete`/`truncate` everywhere and raw `DB::table('tenant_profiles')` everywhere except `Portal/LinkedTenantProfiles.php`. That file is allowed only while it contains no delete/update/insert/upsert/truncate/increment/decrement call.
- With `APP_DEBUG=true`: the whole `Modules/Tenants` suite (new and P03-01a/P03-03a tests), `ImpersonationGuardTest`, `OrganizationSwitcherTest` and `DashboardTest`: 163 passed, 1,973 assertions.
- MySQL JSON key order: no new assertion compares a map read from a JSON column by key order (roles `[]`, audit properties read one key at a time).
- Not run: release/install drills (3 new migrations; module migration ordering follows the file names), and no browser smoke (pages exercised over HTTP and Livewire only).

**Follow-ups:**
- **P03-11a (portal):**
  - replace `/portal` and `/portal/welcome`'s target;
  - "Add an email for easy password resets" for phone-only accounts, with its verification;
  - show the session `status` flash;
  - read-only checks per record use `LinkedTenantProfiles` + `OrganizationContext::run`.
- **P05-06:**
  - the real WhatsApp driver behind `WhatsAppSender::available()` (agency connection, template mapping for events `tenant_invitation` and `tenant_sign_in_link` with variables `tenant.first_name`, `organization.name`, `invitation_link` / `sign_in_link`);
  - delivery rows;
  - WhatsApp password-reset links (the forgot-password reply still says email only).
- **P05 (SMS):** send only to `users.phone_verified_at` numbers; the verification is in place.
- **P03-15 (owners):**
  - reuse `TenantInvitations`' pattern;
  - extend `SignInLinks::refusal()` with "is an owner somewhere";
  - register `/owner` in `PortalLanding`.
- **P06-01:** gate WhatsApp to Growth+.
- The agency logo on the acceptance pages (as for member invitations).
- "Mixed" users (agent who also rents) land in their workspace. The "My rentals" switcher entry is P03-11a's.

**Next:** P03 wave 4 continues (P03-04b alongside); then P03-11a (tenant portal) can build on this.

### P03-03a — Complete (2026-10-27): `LeaseService` + lease CRUD: leases and their deposit ledger, the state machine (draft/reserve → active → renew/terminate/expire), one active lease per unit under the unit lock, contract numbers at activation, unit status from lease events, the daily `leases:expire-and-flag` job, `LeaseBillingSchedule`, lease pages with export, tenant narrowing by lease

Parent **P03-03 stays In progress** (P03-03b, the lease contract PDF, remains; its AC "contract PDF renders with both drivers" is 03b's).

**Delivered** ([ARCHITECTURE.md § 5 "Leases", § 6.2, § 6.3 "How the lease engine is built", § 7, § 6.13](../ARCHITECTURE.md); [DATABASE_SCHEMA.md § 3](../DATABASE_SCHEMA.md) `leases`, `lease_deposit_entries`; AGENT.md § 3 rules 3 and 5; `docs/plans/P02-P03-subtasks.md` P03-03a):
- **Schema (module migrations, one change each):** `2026_11_08_030101_create_leases_table` (DB § 3 columns plus `property_id`, `reserved_until`, `activated_at`, `expired_at`, `ending_soon_flagged_at`, `created_by_user_id`; provenance `import_batch_id` (no FK) / `external_ref` unique per org; FKs to `properties`, `units`, `tenant_profiles` (restrict), self FK `renewal_of_lease_id`; unique `(organization_id, contract_number)`; indexes for status, unit, building, tenant, the job (`status, end_date`) and billing (`status, next_billing_date`)); `…030102_add_one_active_lease_per_unit_to_leases` (stored generated column `active_unit_id` = `unit_id` while active and not soft deleted, **unique**: the database also keeps one active lease per unit; MySQL 8 and MariaDB 10.6+); `…030103_create_lease_deposit_entries_table` (signed `amount_kobo`, type, note, recorded by).
- **Models and enums:** `Modules\Leasing\Models\Lease` (organization-scoped, nothing mass assignable, money via `MoneyCast`, dates `immutable_date`), `LeaseDepositEntry` (organization-scoped ledger: refuses update and delete), `Enums\LeaseStatus` (draft → active / terminated / expired; active → terminated / expired / renewed; `canMoveTo()`), `Enums\DepositEntryType` (held, carried_in, carried_out, refunded, kept). Both models added to `tests/Support/OrganizationScopedModels.php` (all `CrossTenantAccessTest` probes).
- **`Modules\Leasing\Leases\LeaseService` — the only writer** (each step `DB::transaction($cb, 3)`, policy checked for the actor, audited under log `leases`; lock order tenant row → unit row → lease rows):
  - `draft()`: tenant the member sees (`TenantDirectory::find($id, $by)`) and locked (`lockActive`, so archiving waits), unit locked through `UnitDirectory::lock()` in a building the member works on (`BuildingAccess`; 404 otherwise), not under maintenance/unavailable, building not archived; `reserved_until` = today (Lagos) + org setting `leasing.reservation_days` (default 14).
  - `update()` (draft terms only; tenant and unit fixed), `renew()` (new draft, `renewal_of_lease_id`, starts after the old end, one renewal at a time, deposit carried), `activate()` (unit row lock, refused while another lease is active on the unit except the lease a renewal replaces, which becomes `renewed`; contract number from `DocumentNumberer::next(DocumentType::LeaseContract)` inside the transaction; `next_billing_date` = start, or `CycleCalculator::nextPeriodStart()` after `paid_until`; deposit `held` entry or `carried_out`/`carried_in` pair), `terminate()` (active lease or cancel a draft, reason required), `expire()` (active after its end date; draft after its hold), `flagEndingSoon()`, `settleDeposit()` (refunded/kept with note, ended leases only, never more than held), `advanceBilling()`.
  - Refused moves throw `Leases\LeaseRefused` (a `ValidationException`, shown on the page) and change nothing.
  - `Leases\LeaseTerms` checks the terms on the server (dates, cycle, rent per period > 0, deposit, `due_day_offset` within the cycle's shortest period: daily 0, weekly ≤ 6, else ≤ 27, `paid_until` within the lease).
- **Events** `Modules\Leasing\Events\LeaseDrafted`, `LeaseActivated`, `LeaseRenewed`, `LeaseTerminated`, `LeaseExpired`, `LeaseEndingSoon` (abstract `LeaseEvent`: plain ids + `unitStatus`), sent inside the transaction.
- **Properties listener** `Modules\Properties\Units\FollowLeases` (registered in `PropertiesServiceProvider` for the five lease events): sets the unit status each event carries through `Units::setStatus` (audited `unit.status_changed`, reason `lease_activated:{id}`…). Leasing works the status out under the unit lock from every lease of the unit: occupied while one is active, reserved while only drafts wait, vacant once none holds it (a status people set is never overwritten with vacant). `UnitDirectory::inProperty()` added (the lease form's unit picker).
- **Daily job** `Modules\Leasing\Jobs\ExpireAndFlagLeases` (`Schedule::job`, `default` queue, 02:00, name `leases-expire-and-flag`): per organization with drafts or active leases, in its context: stale reservations released (drafts past their hold → expired), ended leases expired (Lagos date; `auto_renew` leases left active and flagged), leases ending within `leasing.ending_soon_days` (default 60) with no renewal flagged once (`LeaseEndingSoon`). Idempotent; 40 s budget, then re-queues itself.
- **Contracts:** `Contracts\LeaseBillingSchedule` (+ `BillableLease`) for P03-04b: `due($date, $lead, $afterId, $limit)` (active and renewed leases whose next period starts by date + lead and on or before the end date), `find()`, `advance($id, $periodStart)` (idempotent, through LeaseService); `Contracts\TenantLeases` (+ `LeaseSummary`) for the Tenants module; `CycleCalculator::daysAfter()` (day counts; the interval-maths ban stays green). Tenants module: `Modules\Tenants\Contracts\TenantDirectory` (+ `TenantSummary`; `find` optionally as a member, `findMany` incl. archived, `search`, `activeOptions` narrowed, `lockActive`), bound in `TenantsServiceProvider`.
- **Pages (`leasing::`, routes in the module):** `/o/{slug}/leases` (`LeaseIndex`: 25 a page, debounced search over contract number and tenant, filters status/building/cycle/ending soon in the URL, names via one lookup per page — no N+1), `/leases/create` (`?unit=`, `?tenant=` prefill; building → unit pickers; a yearly lease starts from the unit's asking rent), `/leases/{id}/edit` (drafts), `/leases/{id}/renew`, `/leases/{id}` (`LeaseShow`: terms, contract number, deposit ledger, renewal links, Activate / Edit draft / Renew / Cancel draft / End lease / record deposit). Sidebar item "Leases" (order 450). **Export:** `Lists\LeaseList` (implements `ListExport`; rows carry tenant/unit/building names looked up once per 500-row chunk), registered from `LeasingServiceProvider`.
- **`Modules\Leasing\Policies\LeasePolicy`:** viewAny/view/export (`leases.view`), create/update/activate/renew/terminate (`leases.manage`, state-aware), `settleDeposit` `#[BlockedWhileImpersonating]`; 404 outside a narrowed agent's buildings; restricted organizations make no new leases. No new permission (`leases.view`/`leases.manage` already existed and were classed for both guards).
- **Tenants module (through contracts only):** `TenantNarrowing::buildingsOf()` and the `tenants()` query limiter filled through `TenantLeases` (a narrowed agent sees tenants without a lease and tenants with a lease of any status in their buildings); `TenantProfiles::archive` refuses a tenant with a draft or active lease (key `archive`, shown on the list and the record page); the record page lists the tenant's leases (only those in the member's buildings) with "New lease"; `TenantsImportTarget::usedSince` also counts a lease; `TenantList::matching()` shared with the directory's search.
- **Docs:** ARCHITECTURE.md § 5 (new "Leases" paragraph; Tenants narrowing as built), § 6.2, § 6.3 ("How the lease engine is built"), § 6.13, § 7 (job row), § 8; DATABASE_SCHEMA.md § 3 `leases` as built, new `lease_deposit_entries`, domain map, § 8A; PLATFORM_CONSOLE.md § 6 (leases export allowed, deposit settlement blocked); docs/runbooks/ci.md (the lease activation drill).

**Tests and evidence (local, MariaDB 11.4):**
- `composer ci` on the final commit: Pint passed, Larastan 0 errors, Pest **2,141 tests: 2,138 passed, 3 skipped (the Chrome half of P03-05a's visual diff, no Gotenberg locally), 21,437 assertions**, 0 failed (`--parallel`, the drill included).
- New and changed tests with `APP_DEBUG=true` (Leasing module, the drill, `TenantLeasesTest`, `TenantRolesTest`, `UnitDirectoryTest`, `CrossTenantAccessTest`, `ListExportCoverageTest`, `WorkspaceNavigationTest`, `ModuleSkeletonTest`): 307 tests, 307 passed, 1,979 assertions.
- **AC: invalid lease transitions rejected:** `Modules/Leasing/tests/Feature/LeaseStateMachineTest.php` › "acceptance: refuses every lease move the state machine does not allow, and changes nothing" (full matrix, 20 cases: draft/active/expired/terminated/renewed × activate/renew/terminate/expire; the allowed moves succeed, every other is refused and the lease row, every lease's status, the unit status, deposit entries, document counters and the audit log are unchanged) and › "refuses the moves in the service itself, past any policy, and changes nothing" (`Gate::before` allows everything: the service's own state machine still refuses all 14 disallowed moves with `LeaseRefused`); › "refuses to expire a lease or a draft before its time".
- **AC: renewal links to the previous lease:** › "acceptance: links a renewal to the lease it renews, and carries the deposit over on activation" (`renewal_of_lease_id`, same tenant and unit, `renewalOf`/`renewals` both ways, unit stays occupied while the renewal waits; activation → previous `renewed`, renewal active with the next contract number, deposit `carried_out`/`carried_in`, audit `lease.renewed`, events in order `LeaseDrafted`, `LeaseRenewed`, `LeaseActivated`; the renewed lease still billable to its end; both pages link each other); › "starts a renewal after the lease it renews, and allows one renewal at a time"; `LeasePagesTest` › "renews from the lease page…" (start the day after, as many periods: 1 Mar 2027 → 29 Feb 2028).
- **Sub: two concurrent activations on one unit leave exactly one active lease:** `tests/Drill/LeaseActivationDrillTest.php` (real concurrency, as P03-04a's drill: 4 PHP processes on their own connections, released together through a file barrier, each trying to activate all 9 drafts of 3 units — 3 tenants waiting per unit — in its own order; asserts the processes overlapped, no errors, exactly 3 activations, one per unit, 3 active leases, 6 drafts left, every unit occupied, 3 deposit entries, contract numbers exactly 1..3). Ran 7 times green. **Negative controls (temporary, reverted):** with the unit lock, the active-lease locking read and the unique index all removed (+20 ms between read and write) the drill fails (6 active leases for 3 units); with only the unit lock kept it passes (3 runs) — the unit lock alone serialises activations; without the unit lock but with the active-lease locking read, InnoDB gap locks plus `DB::transaction(…, 3)` retries still keep it correct. Also `LeaseStateMachineTest` › "gives contract numbers per organization without gaps, refused and failed activations included".
- **Sub: unit status follows lease events:** `LeaseStateMachineTest` › "keeps the unit status in step with every lease event" (draft → reserved; a second waiting draft; cancel keeps reserved; activate → occupied; a draft waits on a let unit; ending → reserved for the waiting draft; cancel → vacant; every change through `unit.status_changed` with the event as reason) and › "changes the unit and the lease together or not at all" (a failing listener rolls back lease, unit, deposit and number); `LeaseExpiryJobTest` (expiry/release → vacant or reserved).
- **Sub: a stale reservation is released (time travel through the scheduled job):** `Modules/Leasing/tests/Feature/LeaseExpiryJobTest.php` › "releases a stale reservation through the scheduled job, the day after the draft's hold ends" (runs the scheduled `leases-expire-and-flag` event, `0 2 * * *`: still held on 3 Jan, expired and unit vacant on 4 Jan, audit `lease.reservation_released` with no causer, `LeaseExpired(wasDraft)`, re-run changes nothing); › "keeps the unit reserved for a draft still waiting…"; › "expires leases after their end date in Lagos…" (22:30 vs 23:30 UTC on 31 Dec); › "leaves an auto-renewing lease active past its end date and flags it instead"; › "flags a lease ending within the notice window once, unless it has a renewal"; › "uses each organization's notice window"; › "works organization by organization, suspended ones too, and never across them".
- **Sub: cross-org probes / ID guessing 404:** `CrossTenantAccessTest` (datasets `Lease`, `LeaseDepositEntry`); `LeaseAccessTest` › "answers 404 for another organization's lease guessed by ID, on every page" (show/edit/renew, unknown and malformed ids; never listed, searched, filtered; member of both orgs sees each org's own) and › "…on every action sent straight to the server" (locked `leaseId`/`renewingId` swapped → 419 with debug pinned off; the writer refuses a foreign lease model; a foreign unit or tenant on the form is not found; nothing changed on either side).
- **Narrowed agents:** `LeaseAccessTest` › "shows an agent assigned to buildings only those buildings' leases, and 404 for the others" (list, filter, pages, `terminate`/`activate`/`draft` on another building → 404 from the writer, the form offers own buildings only; unassigned agent sees all); `Modules/Tenants/tests/Feature/Pages/TenantLeasesTest.php` › "shows an agent assigned to buildings the tenants without a lease and the tenants of their buildings, on the list, the export and the record pages" and › "lists the tenant's leases on their record page, in the member's buildings only"; `TenantRolesTest` › "shows an agent narrowed to some buildings the tenants without a lease and the tenants of their buildings only (P03-03a)" (**the P03-01a pinned test, changed on purpose**).
- **Suspended org read-only:** `LeaseAccessTest` › "shows leases read-only while the organization is suspended, and still exports them" (no buttons; create/edit/renew pages 403; activate/terminate/settle/save calls 403; nothing changed; export works); the daily job still runs for suspended organizations (`LeaseExpiryJobTest`).
- **Impersonation:** `LeaseAccessTest` › "shows impersonating staff the leases read-only in a read-only session, and lets them export the list" and › "lets impersonating staff in write mode draft and activate leases, audited with both identities, but never settle a deposit" (`impersonation.blocked` recorded).
- **Role rules:** `LeaseAccessTest` › "enforces the ARCHITECTURE.md § 5 role rules in LeasePolicy" (5 roles × 11 abilities) and › "opens the lease pages for owners, administrators and agents, and 404 for property owners, tenants and strangers".
- **Contract numbers gap-free per organization:** `LeaseStateMachineTest` › "gives contract numbers per organization without gaps, refused and failed activations included" (`LS-ADR-2026-000001…4`, `LS-BEH-2026-000001…2`, counter at 4) + the drill.
- **List exports:** `LeasePagesTest` › "exports the leases the page shows, in Excel and CSV, with its filters, audited" (exact rows incl. renewal link and dates); `ListExportCoverageTest` (a lease row; `leases` in the expected registry).
- Others: `LeasePagesTest` (form flow building → unit → rent prefill; errors; `?unit`/`?tenant` prefill; draft edit; activation/refusal/termination/deposit on the page; list paging, filters, search by tenant and contract number; no N+1 (query count equal for 1 and 21 leases); empty states); `LeaseStateMachineTest` (draft reserves 14 days / org setting; activation fields; paid-until billing start; deposit settlement and immutable ledger; draft edit audited; server-side term checks (8 cases); unusable unit / archived tenant / archived building; restricted organization); `LeaseBillingScheduleTest` (due window, paging, prepaid/ended excluded, advance idempotent, LogicException for a period ahead, stops after the end); `TenantLeasesTest` (archive refused while a lease holds a unit; `TenantDirectory`; import rollback refused once a tenant has a lease); `UnitDirectoryTest` › `inProperty`; `CycleCalculatorExamplesTest` › `daysAfter`.
- Existing tests changed (none weakened): `TenantRolesTest` (pinned narrowing test replaced on purpose, docblock), `ListExportCoverageTest` (lease row, `leases` key), `WorkspaceNavigationTest` (sidebar gains `leases`), `OrganizationScopedModels` (+`Lease`, `LeaseDepositEntry`).
- MySQL JSON key order: no new test compares a map read from a JSON column with `toBe` (audit `properties[...]` single values and lists only).
- Not run: MySQL 8 (generated column + unique index tested on MariaDB 11.4 only); release/install drills (`InstallCommandTest` covers module migrations); no browser smoke (HTTP/Livewire tests only).

**Orchestrator review:** merged `main` (with P03-05b) into the branch with no conflicts; `composer ci` locally (MariaDB 11.4): Pint passed, Larastan 0 errors, Pest 2,269 tests: 2,241 passed, 28 skipped (Gotenberg). **GitHub Actions** run [40517263761](https://github.com/thekingchild/Goproperty/actions/runs/40517263761) on the branch: MySQL 8.0, MySQL 8.4 and MariaDB 10.6 green (the generated `active_unit_id` unique index and the activation drill run on MySQL); MariaDB 11.4 failed only on an unrelated start-up flake in `WebInstallWizardTest`'s SMTP sink, hardened on `main` in the same push.

**Decisions to confirm** (orchestrator decisions relied on, and choices made here):
- Relied on **decision 1** (cycle maths): `next_billing_date` via `CycleCalculator::nextPeriodStart`; renewal end via `period()`; day counts (holds, the day after an end) via the new `CycleCalculator::daysAfter()` — the interval-maths ban covers Leasing.
- Relied on **decision 5**: `leases:expire-and-flag` is `Schedule::job` per organization context, idempotent; deposits and refunds are **lease ledger entries only** (`lease_deposit_entries`: held, carried over on renewal, refunded/kept with a note after the lease ends); no money flow.
- Relied on **decision 8** (gating): BILLING § 1.2 gates nothing here (leases are in every plan): nothing for P06-01.
- Relied on **decision 9** (narrowing): leases narrow through `BuildingAccess` on `leases.property_id` in the policy, list, export, form pickers and the writer; tenant pages through the leases' buildings.
- **`property_id` on leases** (not in DB § 3): narrowing, list filters and the P03-14 move need the building without joining the Properties module's tables; a unit never changes building.
- **Extra columns** `reserved_until`, `activated_at`, `expired_at`, `ending_soon_flagged_at`, `created_by_user_id`, generated `active_unit_id` (unique) and the `lease_deposit_entries` table; DB § 3 updated.
- **A lapsed draft becomes `expired`** and a cancelled draft `terminated` (with the reason): DB § 3's status list has no `cancelled`; no new status invented.
- **Several drafts may wait for one unit** (the next tenant of a let unit, a backup tenant); the unit status is derived from all its leases (occupied > reserved > vacant). The first activation wins under the unit lock.
- **A draft past its hold but not yet expired by the job can still be activated** if the unit is free (the hold protects the unit from others; it does not punish the tenant).
- **Renewals:** start after the old lease ends; one at a time; the renewal draft has the same hold window (14 days) as any draft, then lapses; activating it marks the old lease `renewed`, which **still bills to its own end date** (`LeaseBillingSchedule` includes `renewed` leases), so early renewal loses no period. A renewal whose old lease meanwhile expired activates normally (deposit still carried); one whose old lease was terminated is refused. Deposit on a renewal = the balance carried, not typed.
- **`auto_renew`:** the job does not expire such a lease; it stays active (unit occupied) and is flagged; no automatic roll-over is built (it would create bills nobody agreed to: needs a product-owner rule). Billing stops at the end date either way.
- **"Ending soon"** = one flag at `leasing.ending_soon_days` (org setting, default 60) and a `LeaseEndingSoon` event; the 60/30/7 reminder ladder (ARCH § 6.3) is P05's, listening to that event.
- **Rent is per period of the billing cycle**; `due_day_offset` limited to the cycle's shortest period (P03-02 follow-up).
- **"Today" is the Lagos date** (as document numbers), compared with the date columns.
- **Restricted organizations** make no new leases: no draft, renewal or activation; ending leases and editing drafts continue.
- **Impersonation:** settling a deposit is blocked in both modes (a money record); drafting, activating, renewing and ending are allowed in write mode (business data, audited with both identities); the leases export is allowed (tenant names, no contact details).
- **Tenants tied to a building by a lease of any status** (a past tenant of the building stays visible to its agent); the tenant record page lists only the leases in the member's buildings.
- **Tenant archive refusal** asks Leasing through `TenantLeases::holdsUnit()` (draft or active) with the tenant row locked; drafting locks the same row first, so the two serialise.
- **Unit status is written by the Properties module** (listener on Leasing's events, inside the lease transaction) rather than Leasing calling `UnitDirectory::setStatus` — the task asked for a Properties listener; `setStatus` stays available.
- **No Lease factory:** a factory under `database/factories` needs a `Database\Factories` PSR-4 entry in `Modules/Leasing/composer.json` plus `composer dump-autoload` (composer files were off-limits for this task); tests make leases through `LeaseService` (and `OrganizationScopedModels` with `forceFill`).
- **Settings without UI:** `leasing.reservation_days` (1–90) and `leasing.ending_soon_days` (1–365) are declared organization settings with defaults; no settings page yet.

**Management handover (P03-14) — what must move for this task's tables:**
- `leases` rows of the moved buildings that are **live** (draft, active, and renewed ones still billing) move with their building: `organization_id`, with `property_id`/`unit_id` unchanged (the units move too) and `tenant_profile_id` re-pointed to the receiving organization's copy of the tenant (see the P03-01a entry). `renewal_of_lease_id` chains stay inside the moved set. `BelongsToOrganization` refuses changing `organization_id` (`CrossOrganizationWrite`), so P03-14 needs its sanctioned move path; `active_unit_id` is unaffected.
- `lease_deposit_entries` of the moved leases move with them (the deposit is a liability the receiving organization now holds).
- History stays: expired/terminated leases and their entries, `contract_number`s (issued by the landlord's organization; the receiving one numbers its next contracts from its own counter), audit entries. Decide whether ended leases of moved units move as read-only history or stay (suggest: stay, as history).
- Unit statuses move with the units as they are; no lease event is needed for a move.

**Follow-ups:**
- P03-03b: contract PDF on `LeaseActivated` (listener with `ShouldHandleEventsAfterCommit`, `pdf` queue), `document_media_id`; the lease page gains Print/Download.
- P03-04b: rent bills from `LeaseBillingSchedule::due()`/`advance()`; a last period that runs past `end_date` (lease not a whole number of periods) needs a pro-rating rule or a cap; `lead_days` org setting.
- P03-10: confirmed payments move `leases.paid_until` (through LeaseService).
- P03-13a: `LeaseService::importOngoing()` (provenance columns exist; never back-bill: `paid_until` → `next_billing_date` as here); extend `UnitsImportTarget::usedSince` to leases if needed (unit status already covers held units).
- P05 reminders: listen to `LeaseEndingSoon` (60/30/7 ladder); settings page for `leasing.reservation_days` and `leasing.ending_soon_days`.
- Product owner: `auto_renew` semantics (roll over automatically or keep flagging); `lease_renewal_offers` (tenant-facing offers) not built.
- Pickers load up to 1,000 tenants: a searchable tenant picker for very large agencies.
- Unit and building pages could link "New lease for this unit" (`/leases/create?unit=`), and the status board could show the lease.
- MySQL 8.0/8.4 not run locally (no MySQL here; GitHub Actions not starting jobs): the generated column + unique index and the drill should be watched on the first CI run.

**Next:** P03-05b runs alongside; then P03-04b (Billing) and P03-03b (contract PDF) per the waves.

### P03-05b — Complete (2026-10-27): invoice and receipt templates (Core `InvoiceDocument` / `ReceiptDocument`, three layouts each on one A4 page for up to 8 lines, status badges, VOID watermark, 360 px web view) and the public `/verify/{code}` page

Parent **P03-05 stays In progress** (P03-05c invoice issue + `/pay` remains).

**Delivered** ([BRANDING_DOCUMENTS.md § 1–3, § 5](../BRANDING_DOCUMENTS.md) incl. the new "How it is built (P03-05b)"; [ARCHITECTURE.md § 6.5](../ARCHITECTURE.md); [AGENT.md § 4](../AGENT.md); `docs/plans/P02-P03-subtasks.md` P03-05b):
- **Core DTOs** (`app/Documents`, both implement `Document`): `Invoice\InvoiceDocument` (number, issue/due dates, `InvoiceStatus`, `Parts\DocumentTenant`, `Parts\DocumentProperty`, `InvoiceLine`s with Money amounts, notes and a `Parts\DocumentPeriod` or a word like "Once" (the bill's period otherwise), credit and paid to date as Money, `BankTransfer`, payment reference, optional `OnlinePayment`, verification code, void reason; `subtotal()`, `amountDue()` never below zero and zero when void) and `Receipt\ReceiptDocument` (amount, tenant, property, `ReceiptPayment` — keeps only the last four digits of the account paid into —, `ReceiptAllocation`s, balances after payment, confirmed by/at, channels the copy went out on, void time + reason). View = `documents.{invoice|receipt}.{layout}`; file name = number (`…-void.pdf` for a voided receipt). `Parts\DocumentDates` prints Lagos time. `SampleDocuments` now returns these DTOs, so the Settings → Branding preview shows the designed templates.
- **Templates:** `resources/views/documents/{invoice,receipt}/{modern,classic,compact}.blade.php` extend the new `partials/page` and compose shared `sections/*` with the kit's table-only partials plus new `status-badge`, `signature`, `void-watermark`. The old `documents/invoice.blade.php` / `receipt.blade.php` are gone. Classic: boxed letterhead replaces the rule, white ruled panels, serif headings; compact: smaller type, same arrangement as modern.
- **One A4 page for up to 8 lines, all layouts (classic fixed):** the design stacked totals, how-to-pay, notes and verify; now how-to-pay sits beside the totals (signature line under the amount due), the online-pay button is inside the bank panel, the notes sit beside the verify box, and spacing is tightened (rule, item rows, meta rows, PDF bottom padding). Measured in Chrome on the fullest documents (8 noted lines, credit + part payment, bank + online, logo/signature/stamp, 600-char notes, 300-char footer, long names): content ends at 1059 px (modern invoice and receipt), 1069/1065 (classic), 902/883 (compact) of 1082.
- **Status badges** (`InvoiceStatus`, values = `bills.status` + `draft`): DRAFT, UNPAID, PARTLY PAID, PAID, OVERDUE, VOID, fixed colours ≥ 4.5:1. Paid/void/draft invoices print a one-line payment panel instead of bank details.
- **VOID:** translucent rotated "VOID" across the page (both drivers alike); receipt stamp becomes VOID and "voided on … Reason: …" replaces the "valid without a signature" statement and footer; a void invoice's reason replaces the notes.
- **Web view at 360 px:** web-target-only phone styles (columns stack, period/description move under the line, letterhead stacks, long words wrap). Checked in Chrome with no sideways scroll for every layout × document × issued/voided.
- **Drivers alike (kit styles):** pills set their own line height; fixed-width padded cells use `box-sizing: content-box` (dompdf read 150 px as content, Chrome as border box: 24 px shift); dompdf's footer text padded to the middle of the band (it was 12 px higher than Chrome's margin boxes since P03-05a).
- **`/verify/{code}` + `/verify`** (`App\Http\Controllers\Documents\VerifyDocumentController`, `pages/documents/verify*.blade.php`, sign-in layout + `SignInPageHeaders`, `throttle:30,1,verify` = one bucket per IP for both routes, `Cache-Control: no-store`, `X-Robots-Tag: noindex`): resolves through `DocumentVerifiers::find()`; shows issuer, title + number, issue date, amount, status valid / voided (+ note) / superseded (+ "replaced by"), the module's detail lines — only what `VerifiedDocument` carries. Non-canonical codes (dashes, lower case, O/I/L) → 301 to the canonical address; unknown, malformed, unresolvable, unregistered-kind codes → the same plain 404. `/verify` = code form (well-formed → redirect; not a code → 422 with a message). Core `VerificationStatus` gains `Superseded` (+ `label()`), `VerifiedDocument` gains `replacedBy` and documents that `details` must not carry people's or bank details.
- **Docs:** BRANDING_DOCUMENTS.md § 1 (preview, layouts), § 2 (DRAFT badge), § 3 (void invoices), § 5 "How it is built (P03-05b)"; ARCHITECTURE.md § 6.5; AGENT.md § 4 Documents; docs/runbooks/ci.md (Documents paragraph). `routes/web.php` comment and routes.
- **Tests support:** `requireGotenberg()` moved from `DocumentKitVisualDiffTest` into `tests/Support/documents.php` (shared); `fullBrand()`/`fullInvoice()`/`fullReceipt()`/`documentImage()` fixtures; new `tests/Support/NarrowScreen.php` (360 px probe through Gotenberg's screenshot route).

**Tests and evidence (local, MariaDB 11.4):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **2,157 tests: 2,129 passed, 28 skipped** (the Chrome halves with no Gotenberg locally: 3 kit sample + 12 invoice/receipt visual diffs + 13 phone-width checks), 19,647 assertions, 0 failed.
- **AC: invoice and receipt render identically (visual diff) with dompdf and Chrome, on one A4 page for up to 8 line items, all 3 layouts** — `tests/Feature/Documents/InvoiceReceiptTemplatesTest.php` › "acceptance: …" › "prints on one A4 page with dompdf, with every line and the footer" (3 layouts × 2 documents × 1 and 8 lines; always runs: 1 page, A4, Inter embedded, text incl. "Page 1 of 1" and the 8th line) and "prints the same with Chrome (Gotenberg) as with dompdf, within the threshold, on one page" (3 × 2 × issued/voided; **skipped locally without Gotenberg, runs in CI**; with `CI` set an unreachable Gotenberg fails). Locally I ran the Chrome half against a scratch stand-in for Gotenberg (Node driving the installed Chrome 156 over DevTools with Gotenberg's print and screenshot parameters, not committed): all pass, scores 0.001–0.27 % (threshold 0.4 %; worst classic invoice 0.26–0.27 %); also "prints a voided invoice and receipt on one page with dompdf too", "uses the organization's layout: one template per document and layout".
- **Sub: verify page shows valid or voided** — `tests/Feature/Documents/VerifyPageTest.php` › "shows a valid receipt…", "shows a voided receipt as voided", "shows a superseded invoice and what replaced it", "asks the module of the code's own organization…", "takes a code as printed or typed…" (301), "shows nothing about people or bank accounts…", "is never cached and never indexed", "works signed in or not…".
- **Sub: unknown code gets a plain 404** — `VerifyPageTest` › "answers 404 for a code nobody issued…", "answers the same 404 for every way a code can fail" (malformed, too short, resolver no longer knows it, no module for its kind, letters outside the alphabet), "does not reveal another organization's documents through a code of the wrong kind"; plus the `/verify` form (3 tests) and "is rate-limited per address, the form and the pages together" (31st request → 429).
- **Sub: web view works at 360 px** — `InvoiceReceiptTemplatesTest` › "lays out on a 360 px screen without sideways scrolling, in real Chrome" (3 × 2 × issued/voided: viewport 360, overflow 0; Gotenberg screenshot route; skipped locally without Gotenberg, verified locally with the stand-in), "catches a page that is wider than the screen (the probe works)" (negative control), "stacks the columns and moves hidden columns inline on phones, on web pages only" (always runs). Earlier tasks checked 360 px by hand in the browser; I also looked at screenshots of the invoice, voided receipt and verify page at 360 px.
- **VOID watermark on voided documents** — "marks a voided receipt and a void invoice with the watermark and the reason" (3 × 2: HTML, PAID → VOID, notice replaces statement/notes, and the dompdf page has measurably more ink across the middle than the same page without the watermark), "leaves documents that are not void unmarked".
- **Lint still passes** — `DocumentViewsLintTest` (walks every file under `resources/views/documents`, now including the new templates) and "finds no flex, grid or CSS variables in the invoice and receipt pages as rendered, for every layout and target" (18).
- Also: status badges (6 statuses, contrast ≥ 4.5, `from(bills.status)`, pay panel only while due), Money maths (subtotal/amount due in kobo, never negative, zero when void), periods, account masking (only last 4 digits kept, even in the serialized object), Lagos time, refusals (no lines, no code, half-voided receipt), file names, escaping, preview uses the templates per layout.
- New tests with `APP_DEBUG=true`: InvoiceReceiptTemplatesTest + VerifyPageTest + DocumentKitVisualDiffTest: 137 tests, 109 passed, 28 skipped (Chrome halves); with `APP_DEBUG=true` and the local Chrome stand-in, all of `tests/Feature/Documents`: 186/186 passed.

**Orchestrator review:** merged `main` into the branch; resolved one AGENT.md § 4 conflict by keeping both additions (the invoice/receipt documents and `DocumentNumberer::next()` in `DB::transaction($callback, 3)`). `composer ci` locally (MariaDB 11.4): Pint passed, Larastan 0 errors, Pest 2,177 tests: 2,149 passed, 28 skipped (Gotenberg). **GitHub Actions** run [40514405957](https://github.com/thekingchild/Goproperty/actions/runs/40514405957) on the branch: green on MySQL 8.0/8.4 and MariaDB 10.6/11.4, 2,177 passed with **none skipped** — the Chrome visual diffs and the 360 px checks ran against real Gotenberg.

**Decisions to confirm** (orchestrator decisions relied on, and choices made here):
- Relied on **decision 6:** the Chrome half (visual diff and the 360 px check) skips only when Gotenberg does not answer, fails on CI if it is unreachable; dompdf always tested. The 360 px check uses Gotenberg's `/forms/chromium/screenshot/html` (`width=360`, `clip=true`) with a probe square whose colour encodes overflow and viewport width — first real run is in CI (my local stand-in mimics the route; if real Gotenberg differs, the test fails loudly via the viewport check rather than passing).
- Relied on **decision 2's pattern** (through P03-05a's `DocumentVerifiers`): code → organization + kind by one unscoped read, then the module's resolver inside it.
- Relied on **decision 7:** `routes/web.php` comment kept accurate (pay page P03-05c).
- **Status badges follow BRANDING § 2, not the task prompt's list:** the prompt said DRAFT/ISSUED/PAID/PARTLY PAID/OVERDUE/VOID; BRANDING § 2 and `bills.status` have UNPAID (not ISSUED). Built UNPAID/PARTLY PAID/PAID/OVERDUE/VOID with enum values equal to `bills.status`, plus DRAFT for unissued previews; no ISSUED badge.
- **Layout departs from the approved design to fit one page:** how-to-pay beside the totals (design: below), online-pay inside the bank panel (design: a separate box beside it), notes beside the verify box, signature under the amount due, tighter spacing. Same components, colours and typography. Product owner to confirm.
- **One-page promise scope:** proven for 8 lines each with a note and one-line descriptions/notes (≤ ~40 characters), with every optional block at its maximum. Lines whose description wraps, or more than 8 lines, may run onto a second page (with "Page x of y").
- **Superseded** added to Core `VerificationStatus` (the task asked for valid/voided/superseded; § 3 named only valid/voided) with `VerifiedDocument::$replacedBy`.
- **Verify page on the sign-in layout** (platform brand, not the agency's): `VerifiedDocument` carries no branding; the agency's name is shown as the issuer. Rate limit 30/min per IP shared by `/verify` and `/verify/{code}` (`throttle:30,1,verify`, no provider change).
- **Void notices replace content** (receipt statement + footer, invoice notes) instead of adding a block, so voided documents also stay on one page.
- **Kit styles changed** (P03-05a partials): dompdf footer centred in its band; pills' line height; content-box widths for padded fixed cells; classic letterhead padding 10/14; tighter rule and item rows. The kit sample still passes both halves (stand-in).

**Management handover (P03-14):** nothing to move: no tables, no migrations. Documents rendered from these DTOs are history of the issuing organization.

**Follow-ups:**
- P03-05c: build `InvoiceDocument` from a bill (`InvoiceStatus::from($bill->status)`, snapshot, `BankTransfer` from the organization's P01-05 account, `OnlinePayment` only with own Paystack + within limit — a P06-01 plan gate), register the `invoice` resolver (return `Superseded` + `replacedBy` for a corrected bill if that exists), store the PDF with `DocumentRenderer::stored($bill, 'invoice', …)`.
- Payments (receipts): register the `receipt` resolver (voided → `Voided` with a note like "Voided on 15/12/2026" — no names; the void reason is on the document, not the public page), re-issue voided receipts under key `receipt-void`, `sentVia` from the channels actually used (no SMS in P03, decision 3).
- Verify-page and pay-page branding (agency logo/colours) would need branding in `VerifiedDocument`; left on the platform layout.
- On phones the invoice's how-to-pay panel comes before the totals (source order of the two columns); consider reordering for the web view.
- The settings preview frame still cannot load the bundled fonts (P03-05a follow-up).

**Next:** P03-05c (wave 6) once P03-04a/b are in.

### P03-04a — Complete (2026-10-27): document numbering: `document_counters`, `App\Documents\DocumentNumberer::next()` (row lock inside the issuing transaction, gap-free, MySQL and MariaDB), `DocumentType`, `{PREFIX}-{YYYY}-{seq}` from the branding prefixes

Parent **P03-04 stays In progress** (P03-04b Billing module + rent bill generation remains; its AC "rerunning the job creates no duplicate bills" is 04b's).

**Delivered** ([ARCHITECTURE.md § 6.5](../ARCHITECTURE.md); [BRANDING_DOCUMENTS.md § 1, § 5](../BRANDING_DOCUMENTS.md); [DATABASE_SCHEMA.md § 4](../DATABASE_SCHEMA.md); `docs/plans/P02-P03-subtasks.md` P03-04a):
- **Schema:** one migration `2026_11_07_040401_create_document_counters_table`: `organization_id` (FK, restrict), `doc_type` (string 40), `year` (unsigned smallint), `last_seq` (unsigned int, default 0), timestamps, unique `(organization_id, doc_type, year)`.
- **`App\Documents\DocumentCounter`** (organization-scoped, added to `tests/Support/OrganizationScopedModels.php`; `CrossTenantAccessTest` probes it), written only by the numberer, never deleted.
- **`App\Documents\DocumentType`** enum: `invoice` (rent and utility bills share it: one `bills` table, `number` unique per org), `receipt`, `lease_contract`; `label()`, `prefix(Branding)` → `invoicePrefix` / `receiptPrefix` / `leasePrefix` (P01-05).
- **`App\Documents\DocumentNumberer`**: `next(DocumentType $type, ?DateTimeInterface $issuedAt = null, ?Branding $branding = null): string` → `INV-ADR-2027-000142`; `format($prefix, $year, $seq)` (6 digits, a 7th after 999999), `yearOf($date)` (year of issue in Africa/Lagos). Needs an organization context (`MissingOrganizationContext`) and a transaction on the counter's connection (`NumberingRequiresTransaction`, a `LogicException`). Pass the issued document's `BrandingSnapshot->branding` so the prefix matches the print; default is the organization's current branding. `DocumentBranding::number()` now delegates to `format()`.
- **Locking (MySQL 8 / MariaDB 10.6+, InnoDB, RR or RC):** a plain read finds the counter → `SELECT … FOR UPDATE` → `last_seq + 1`, held until the caller commits; a rollback gives the number back. The locking read only ever targets a row that exists (a locking read of a missing row takes a gap lock, and two first numbers holding the same gap deadlock on insert). A year's first row is created **at 0 on a separate, short-lived connection** (fresh read, then `INSERT IGNORE` on the unique key; `innodb_lock_wait_timeout` 5 s on that session; purged after), committed at once, so a creator that rolls back never takes the row away from issues waiting on it. When that connection cannot see the organization (created in the same, uncommitted transaction — e.g. every `RefreshDatabase` test) it falls back to an in-transaction `INSERT … ON DUPLICATE KEY UPDATE last_seq = last_seq + 1` + locking read.
- **Docs:** DATABASE_SCHEMA.md § 4 `document_counters` (as built, history rule); ARCHITECTURE.md § 6.5 numbering "as built"; BRANDING_DOCUMENTS.md § 5 "How it is built" (Numbers); AGENT.md § 4 Documents (`DocumentNumberer::next()`, `DB::transaction($callback, 3)`); docs/runbooks/ci.md (the numbering drill).

**Tests and evidence (local, MariaDB 11.4.12, REPEATABLE READ):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **2,005 tests: 2,002 passed, 3 skipped (the Chrome half of the visual diff, no Gotenberg locally), 19,458 assertions**, 0 failed (`--parallel`, so the drill ran under the parallel runner on a worker database).
- **AC (from P03-04): numbers are gap-free under concurrency (parallel real connections):** `tests/Drill/DocumentNumberingDrillTest.php` › "acceptance: gives gap-free numbers to parallel processes, each on its own connection, rolled-back numbers included": 6 PHP processes (`tests/Fixtures/Processes/document-numbers.php`, started with the Process facade like the cron/backup drills, against the worker's database), released together through a file barrier on a counter that does not exist yet, each running 25 transactions that take an invoice number, hold the lock 0–2 ms, then a receipt number; the 1st, 5th, 9th… transaction of each process rolls back (so the race to create the year's rows has losers that roll back). Asserts: 6 distinct pids that overlapped in time; 108 committed / 42 rolled back; invoice and receipt sequences each exactly `range(1, 108)`, no duplicates, format `INV-ADR-YYYY-NNNNNN` / `RCT-ADR-…`; two counter rows at 108 for that organization. Ran it ~15 times locally: always green, **0 deadlock retries**. Negative controls (temporary, reverted): with an unlocked read-modify-write the sequences came out with duplicates and the test failed; with the earlier in-transaction-only row creation and first transactions rolling back, children hit `1213 Deadlock` repeatedly (some beyond 3 attempts) — which is why the row is now created on its own connection.
- **Sub-AC: a rolled-back transaction does not use up a number:** drill › "does not use up a number in a rolled-back transaction" (real commit/rollback: invoice 1 committed, 2 rolled back then given again; a year's first receipt rolled back leaves its row at 0 and the next receipt is 000001); feature › "does not use up a number in a rolled-back transaction (inside an outer one)" (savepoint).
- **Refused outside a transaction:** drill › "refuses to give a number outside a transaction" (transaction level 0, no row created).
- **Per organization / per year / per type / format:** `tests/Feature/Documents/DocumentNumbererTest.php` (12 tests): default prefixes from the name (`INV-ADR`, `RCT-ADR`, `LS-ADR`, `INV-BEH`), saved prefixes (`ADR-INV`, `R-01`, `TENANCY`), snapshot branding wins, six-digit padding and 1000000, interleaved organizations, separate types, year of issue in Lagos (23:30 UTC on 31 Dec → next year; a late document for last year continues last year's sequence), `now()` in Lagos, organization context required, unique key in the database, enum values/prefixes/labels.
- New tests with `APP_DEBUG=true`: the drill, `DocumentNumbererTest` and `CrossTenantAccessTest`: 110 tests, 110 passed.

**Orchestrator review:** merged `main` (with P03-01a) into the branch with no conflicts; re-ran `composer ci` on MariaDB 11.4: Pint passed, Larastan 0 errors, Pest 2,049 tests: 2,046 passed, 3 skipped (Chrome visual diff, no Gotenberg locally). Read the numberer: the side connection only creates a missing counter row at 0 and commits it; every number is taken under `lockForUpdate` inside the caller's transaction. MySQL 8 not run (GitHub Actions not starting jobs).

**Decisions to confirm** (orchestrator decisions relied on, and choices made here):
- Relied on **decision 7:** numbers are per organization (`receipts.number` unique per `(organization_id, number)`): two organizations with the same code (Adeyemi Realty / Adekunle Realty → `ADR`) both issue `INV-ADR-2027-000001`; the verification code (globally unique) is what identifies a document publicly.
- **Format:** ARCHITECTURE.md § 6.5 said `{PREFIX}-{ORG}-{YYYY}-{seq}`, the task `{PREFIX}-{YYYY}-{seq}`: the same thing, because the P01-05 branding prefix already carries the organization's code (`INV-ADR`, BRANDING § 1). ARCH now says so.
- **Column `last_seq` (DATABASE_SCHEMA § 4 wins)** — the task said "next value"; the row holds the last number given out.
- **No credit note type:** the docs only have platform-side credit notes, post-MVP (`credit_notes`, BILLING § 8), with no branding prefix. Types: invoice, receipt, lease contract; a new numbered document adds a case and a prefix.
- **Year = year of issue in Africa/Lagos** (the caller passes the issue time; default now). Rent and utility bills share the invoice sequence. A prefix change mid-year keeps the sequence going (numbers stay unique).
- **Counter row created on a second connection** (only for a year's first number of a type; the organization must be committed, else the in-transaction fallback). It costs one short connection per (organization, type, year).
- **Callers run the issuing transaction as `DB::transaction($callback, 3)`** (AGENT § 4): deadlocks are not expected any more, but the in-transaction fallback can still produce one, and InnoDB rolls the whole transaction back.

**Management handover (P03-14):** nothing to move. `document_counters` rows are history: they stay with the organization that issued the numbers (the receiving agency numbers its own documents from its own counters).

**Follow-ups:**
- P03-03a (`leases.contract_number`, `DocumentType::LeaseContract`), P03-04b (`bills.number`, `DocumentType::Invoice`), P03-10 (`receipts.number`, `DocumentType::Receipt`): call `next()` inside the issuing transaction with the snapshot's branding; numbers unique per `(organization_id, number)`.
- MariaDB 11.6.2+ turns `innodb_snapshot_isolation` on by default: a locking read of a row changed after the transaction's snapshot then fails with error 1020 "Record has changed since last read", which Laravel's `DB::transaction` does not retry. Outside today's matrix (10.6/11.4 default off) and it affects every row lock in the app, not only numbering; decide when MariaDB 11.8 joins CI.
- No GitHub Actions run yet for the MySQL 8.0/8.4 jobs (Actions billing); the IODKU fallback and `INSERT IGNORE` are plain MySQL syntax, but the drill has only run on MariaDB 11.4 here.

**Next:** P03-04b (Billing module + rent bill generation) once P03-01a and P03-03a/05a land as the waves say.

### P03-01a — Complete (2026-10-27): tenant pages (list with filters and export, add/edit, record page, archive/restore), audited writer, policies and narrowing hook

The parent P03-01 stays **In progress** until P03-01b (portal access and invitations) is done.

**Delivered** ([DATABASE_SCHEMA.md § 1](../DATABASE_SCHEMA.md) `tenant_profiles`, [ARCHITECTURE.md § 5](../ARCHITECTURE.md) "Tenants", § 6.13; `docs/plans/P02-P03-subtasks.md` P03-01a):
- **Pages (`Modules/Tenants`, Livewire namespace `tenants::`, routes in the module's `routes/web.php` behind `auth`, `verified`, `organization`):**
  - `/o/{slug}/tenants` (`Livewire\TenantIndex`): 25 a page on the server, debounced (400 ms) search over first/last/full name, phone (typed as `0803 …` or `+234 …`) and email, filters for KYC status, portal access (yes/no) and Active/Archived, all in the URL; Clear filters; empty states; Archive/Restore per row; Export button.
  - `/tenants/create` and `/tenants/{id}/edit` (`Livewire\TenantForm`): name, phone, email, address, occupation, next of kin (name, phone, relationship), ID type and number, KYC status. The ID number on file is never sent to the browser: it shows masked, and an empty field keeps it.
  - `/tenants/{id}` (`Livewire\TenantShow`): contact, next of kin, KYC badge, masked ID, "Your ID", portal access, and a dashed "Leases and bills" placeholder (`data-test="tenant-leases-bills"`) for P03-03a/P03-04b. An archived tenant's record opens read-only, with a banner and Restore; the edit page of an archived tenant answers 404.
  - Sidebar item "Tenants" (order 400, `viewAny TenantProfile`) through `WorkspaceNavigation`.
- **Writer `Modules\Tenants\Profiles\TenantProfiles`.** It is the only writer besides the import target. It covers create, update, archive (soft delete) and restore. Each call checks `TenantProfilePolicy` for the actor (`Gate::forUser`, so the suspension and impersonation guards apply). Rows are locked in a transaction. Changes are audited under log `tenants`:
  - `tenant.created`;
  - `tenant.updated` with `changed` fields and `kyc_from`/`kyc_to`, never the ID number;
  - `tenant.archived`, `tenant.restored`.
  - Saving with no changes writes nothing.
- **Phone uniqueness (P02 decision 3):** E.164 through `PhoneNumber`. An active tenant's number is refused with the message "This phone number belongs to {name}. Each tenant needs their own number." When an archived tenant's number is used to add someone, the writer throws `Profiles\ArchivedTenantMatch` (a `ValidationException`). The form then keeps the match id locked on the server and shows "Restore {name}" (`restoreArchivedMatch()`). This restores the record on file, audited, and opens it; the typed details are not applied. On edit, an archived holder's number is refused by name. A unique-index race is turned into the same field error.
- **KYC:** "Being checked" or "Verified" needs an ID on file. Changing the ID of a checked tenant resets them to "Not verified", unless the same save sets the status (this mirrors the P02-06b import rule).
- **Policy `TenantProfilePolicy`:**
  - New `export` ability (`= viewAny`, `#[BlockedWhileImpersonating]`: contact details).
  - `view`, `update`, `delete` and `restore` go through `Access\TenantNarrowing`, which answers 404 (`denyAsNotFound`) outside the member's reach.
  - Update and delete only on active tenants; restore only on archived ones.
  - No new permission: P02-06b's `tenants.view` and `tenants.manage` (org-owner, org-admin, agent) were already classed for the suspension and impersonation guards.
- **`Access\TenantNarrowing`** (on `Modules\Properties\Contracts\BuildingAccess`) has `allows()` and a `tenants()` query limiter. `buildingsOf()` is the one hook P03-03a fills (see decisions).
- **List/export `Lists\TenantList`** (registered from `TenantsServiceProvider`):
  - The page paginates the same query that the export streams.
  - Headers are the import's, so an export imports again as updates. "ID (masked)" holds e.g. "NIN ···2109". It also has "KYC status" (label), "Portal access" (Yes/No), "Added on" and "Archived on".
  - Order is last name, first name, id, following the `(organization_id, deleted_at, last_name, first_name)` index.
- **Docs:**
  - ARCHITECTURE.md § 5 "Tenants" (pages, writer, uniqueness/restore-instead, KYC, impersonation, narrowing decision), § 6.13 (TenantList; tenants among the contact-detail lists), § 8.
  - DATABASE_SCHEMA.md § 1 `tenant_profiles` (writers, KYC, no building link and what that means for P03-14).
  - PLATFORM_CONSOLE.md § 6 (the tenants export is refused while impersonating).
  - DATA_IMPORT.md § 6 (the tenants export re-imports).
  - The module's `module.json` description.

**Orchestrator review:** branch already contained `main` (`050df22`); re-ran `composer ci` on MariaDB 11.4: Pint passed, Larastan 0 errors, Pest 2,029 tests: 2,026 passed, 3 skipped (P03-05a's Chrome visual diff, no Gotenberg locally). GitHub Actions was not starting jobs, so the MySQL jobs have not run.

**Decisions relied on (orchestrator defaults and my own; to confirm with the product owner):**
- Orchestrator decision 9 (narrowing through `BuildingAccess`) and the still-open question "does an unassigned agent see everything" are not affected here. **My decision for P03-03a to revisit:** a tenant has a building only through a lease. Until leases exist, an agent narrowed to some buildings therefore sees **every tenant of the organization** and may add tenants.
  - It is built against the contract: `TenantNarrowing::allows()` already says a narrowed agent sees a tenant with no building, or one with a lease in their buildings. It returns 404 otherwise.
  - P03-03a fills `buildingsOf()` (through Leasing's contract) and the `tenants()` query limiter.
  - Rejected alternatives: "only tenants they created" needs a new `created_by` column and hides imported tenants. "None" locks agents out of signing leases.
  - Pinned by `TenantRolesTest` › "shows an agent narrowed to some buildings every tenant until leases tie tenants to buildings (P03-03a)". P03-03a must change this test on purpose.
- P02 decision 3 (soft deletes vs unique keys): the unique `(organization_id, phone)` index is unchanged. The page **offers** "restore instead" rather than restoring silently, unlike units, which auto-restore on create. A person's record should not be overwritten with typed details without a choice.
- Decision 4 (people and roles): "tenants never get `organization_user` rows" is untouched. Portal access is only *shown* here (`user_id` set); P03-01b links it.
- Decision 8 (plan gating): BILLING § 1.2 gates nothing here (tenant records and exports are in every plan).
- The export is blocked in both impersonation modes (P02-07 rule for contact details). Archiving is blocked as a `delete` ability. Editing in write mode is allowed and audited with both identities. Restore is not blocked (it is not a delete).
- Agents add, edit, archive and restore tenants (P02-06b grant, unchanged).
- An archived tenant's record page stays viewable (history will hang off it); owners' archived pages answer 404 instead.

**For P03-14 (management handover):** `tenant_profiles` has no building or unit link, so a handover moves no tenant row by itself. Live leases (P03-03a) reference a tenant, so P03-14's Tenants part must bring each moved lease's tenant into the receiving organization. Suggested approach: match by E.164 phone there (unique per organization), or create a copy with the same details, the `user_id` portal link and the encrypted ID. The lease is then re-pointed to it. The original profile stays in the landlord's organization, because issued bills, receipts, payments and audit entries (history) reference it and never move. `external_ref`/`import_batch_id` are the landlord's provenance and should not be copied as-is (they would collide or mean nothing there).

**Tests and evidence (local, MariaDB 11.4, after merging `main` at 050df22):**
- `composer ci`:
  - Pint passed; Larastan 0 errors.
  - Pest **2,029 tests: 2,026 passed, 3 skipped, 18,724 assertions**, 0 failed.
  - The 3 skips are P03-05a's `DocumentKitVisualDiffTest`/PDF comparisons: no Gotenberg or poppler on this machine (decision 6). They are not this task's.
- My new tests plus `ListExportCoverageTest`, `WorkspaceNavigationTest` and `ModuleSkeletonTest` also pass with `APP_DEBUG=true` (95 tests, 1,043 assertions).
- New test files in `Modules/Tenants/tests/Feature/Pages`: `TenantPagesTest.php` (24 incl. a 14-case dataset), `TenantCrossOrganizationTest.php` (5), `TenantRolesTest.php` (11 incl. a 5-role dataset), `TenantExportTest.php` (4), and helpers `helpers.php`. Everything goes through HTTP and Livewire updates.
- **Sub-AC — cross-org probes / ID guessing 404 on every page and action:** `TenantCrossOrganizationTest`:
  - "answers 404 for another organization's tenant guessed by ID, on every page": record and edit pages for active and archived foreign tenants, unknown and malformed ids; never listed or searched by name, phone or email, archived included.
  - "… on every action sent straight to the server": list archive/restore → 404. Locked `tenantId` (record page, edit form) and `archivedMatchId` (restore instead) swapped for foreign ids → 419, with debug pinned off. Foreign rows unchanged.
  - "keeps phone numbers per organization: another organization's tenant never blocks or is restored".
  - "shows a member of both organizations only the organization they are working in".
  - "never lets a stranger or another organization's member open the tenant pages".
  - `CrossTenantAccessTest` (`TenantProfile`, P02-06b) is still green.
- **Sub-AC — soft delete only (archive/restore audited):**
  - `TenantPagesTest` › "archives a tenant as a soft delete and restores them, from the list and the record page, audited": the row stays with `deleted_at`, it moves to Archived, the record is read-only, edit answers 404; 4 audit events in order.
  - `TenantPagesTest` › "never deletes a tenant row for good: the module archives (soft delete) and has no hard delete": a static scan of `Modules/Tenants/app` finds no `forceDelete`, `truncate` or raw `DB::table('tenant_profiles')`.
- **Sub-AC — the list exports (coverage test passes; masked ID; blocked while impersonating):**
  - `ListExportCoverageTest` (updated: a tenant row; the expected list keys now include `tenants`) passes.
  - `TenantExportTest`:
    - "exports the tenants the page shows, in Excel and CSV, with the ID masked and never the number": the plaintext and the ciphertext are absent, as are other organizations' and archived tenants.
    - "exports with the page's current filters: search, KYC status, portal access and archived" (URL and typed; other org gets only its own).
    - "audits every tenants export…".
    - "imports its own tenants export again, as updates of the same tenants" (0 errors, 0 creates).
  - Blocked while impersonating: `TenantRolesTest` read-only and write-mode tests (no button, `export()` 403, no export audit entry, `impersonation.blocked` recorded).
- **Role rules:**
  - `TenantRolesTest` › "enforces the ARCHITECTURE.md § 5 role rules in TenantProfilePolicy" (5 roles × 9 abilities, incl. export and update-of-archived).
  - "opens the pages for owners, administrators and agents, and 404 for property owners, tenants and strangers" (an agent adds a tenant, audited as themselves).
  - "shows tenants in the sidebar…"; `WorkspaceNavigationTest` (updated: `tenants` for owner, admin, agent).
- **Suspended org read-only:** `TenantRolesTest` › "shows tenants read-only while the organization is suspended, and still exports them". Create/edit pages answer 403; save, archive (list and record) and restore answer 403; nothing changes and no audit is written; export still works.
- **Impersonation rules:**
  - `TenantRolesTest` › "shows impersonating staff the tenants read-only in a read-only session, without the export".
  - "lets impersonating staff in write mode edit a tenant, audited with both identities, but never archive one or export the list".
- **Phone uniqueness and restore-instead:**
  - `TenantPagesTest` › "refuses a phone number another tenant has, naming them" (create and edit; the number written another way).
  - "offers to restore an archived tenant with the same phone number instead of adding them again": message and button, restore of the record on file audited (`tenant.restored`) with a redirect to it, no duplicate. A form without a match answers 404. Editing to an archived tenant's number is refused by name.
- Others in `TenantPagesTest`:
  - Adding a tenant (normalised, audited, nobody notified or mailed).
  - 14 invalid-input cases.
  - ID encrypted at rest and never in pages or snapshots.
  - ID kept, replaced or removed (the audit names the field only).
  - KYC rules.
  - Search by name, full name, phone (both forms), email; KYC and portal filters, unknown values ignored; server-side paging with ordering; Livewire search/filter/clear; empty states.
- Existing tests changed (none weakened): `ListExportCoverageTest` (a tenant row added; `tenants` in the expected registry), `WorkspaceNavigationTest` (sidebar gains `tenants`), and a docblock line in `TenantProfileTest`.
- Checked for MySQL JSON key order (orchestrator note): no new test compares a map read back from a JSON column with `toBe` (`emergency_contact` uses `toEqual`; audit `changed` values are lists).
- Not run: release/install drills (no migration added). No browser smoke: pages are exercised through HTTP and Livewire tests only.

**Follow-ups:**
- **P03-03a:**
  - Fill `TenantNarrowing::buildingsOf()` and `tenants()` through Leasing's contract, and revisit the "narrowed agent sees all tenants" decision. Update the pinned `TenantRolesTest` case.
  - Refuse archiving a tenant with an active lease (`TenantProfiles::archive`).
  - Fill the record page's "Leases and bills" section.
  - Add a `Modules\Tenants\Contracts` lookup (by id/phone) for Leasing (P02-06b follow-up, still open).
- **P03-01b:** the record page shows "Portal access"; the invite, resend and revoke actions belong there.
- **P03-14:** move tenants with their leases as described above.
- **P03-13a:** extend `TenantsImportTarget::usedSince` to leases, bills and payments.
- The phone is shown in E.164 (`+2348031234567`) on the list and record, as on the owner pages; a display format (`0803 123 4567`) would be a small shared helper.

**Next:** P03-04a runs alongside; then wave 3 (P03-03a ‖ P03-05b).

### P03-05a — Complete (2026-10-27): core document kit (`app/Documents`): branding snapshot, layouts, verification codes and resolver registry, QR codes, amount in words, table-only partials with "page x of y", bundled fonts, DocumentRenderer storing issued PDFs once; Gotenberg in CI and compose with a PDF visual diff

Parent **P03-05 stays In progress** (P03-05b templates + `/verify`, P03-05c invoice issue + `/pay` remain).

**Delivered** ([BRANDING_DOCUMENTS.md § 1, § 5](../BRANDING_DOCUMENTS.md) incl. the new "How it is built (P03-05a)"; [ARCHITECTURE.md § 6.5, § 9](../ARCHITECTURE.md); [AGENT.md § 4](../AGENT.md); `docs/plans/P02-P03-subtasks.md` P03-05a):
- **Package:** `bacon/bacon-qr-code` declared **`~3.1.1`** (already in the lock through Fortify; lock content-hash only, no download).
- **`DocumentRenderer`** (`web()`, `html($doc, $target)`, `pdf($doc, $driver)`, `stored($owner, $key, $doc)`, `kept()`): PDFs through `App\Pdf\PdfManager`; `stored()` renders an issued document once under a row lock on the owner and keeps it in the new media collection **`issued_documents`** (PDF, evidence) with `document_key`, driver and page count; later calls return the kept file. `Document` contract (view, data, `DocumentBranding`, file name) and `ViewDocument`.
- **Targets** (`RenderTarget` web/dompdf/chrome): same markup; fonts by URL / dompdf file path / data URIs for Gotenberg. PDFs reserve a 40 px bottom margin for the footer band "company · number · Page x of y" + "Generated with …": dompdf = fixed table (included right after `<body>`) with its page counter and the total from a first pass (`PdfPages::count`, second render only for multi-page documents); Chrome = `@page` margin boxes (`counter(page)`/`counter(pages)`), text CSS-escaped (`DocumentCss::string`). Web: footer once, no page numbers.
- **Partials** (table-only): `styles` (now per `DocumentLayout` metrics), `brand-bar`, `letterhead`, `footer` (`at` start/end), `verify-box` (real QR). Kit sample page `documents.kit-sample` (`KitSample`).
- **`DocumentLayout`** moved from `App\Enums` to `App\Documents` (+ base size, heading font, radius); 5 imports updated.
- **Fonts** in `public/fonts/documents` (built by `scripts/fonts/build-document-fonts.php` from `@fontsource/inter` WOFF → TTF and dompdf's DejaVu; OFL / Bitstream Vera licences beside them): Inter 400/600/700/800/400-italic as "Inter" (Latin) + "Inter Ext" (₦), DejaVu Sans Mono Bold, DejaVu Serif (+Bold, classic only). `DocumentFonts`. **dompdf fixes found here:** dompdf multiplies line-height by the font's height × `fontHeightRatio`; new config `pdf.dompdf.font_height_ratio` = 1000/1210 makes Inter lines exactly as tall as Chrome's (DompdfRenderer option); PDF stacks list only bundled faces (dompdf makes a line as tall as the tallest family in the list); padded/bordered tables use `border-collapse: separate` (Chrome ignores a collapsed table's padding).
- **`BrandingSnapshot`** / `BrandingSnapshots::take()`: all settings (`Branding::toArray()` + company name), product name, logo/signature/stamp as PNG data URIs, time taken; `toArray()`/`fromArray()` (only PNG data URIs accepted back); `Branding::fromSnapshot()`.
- **Verification** (`App\Documents\Verification`): `VerificationCodes` (10 chars Crockford base 32 from the CSPRNG = 50 bits, `issue($kind)` records in the new Core table **`document_verifications`**, unique across kinds, retries a collision up to 5 times; `normalize`, `format` `7KQ2M-X9P4D`, `url` = `route('documents.verify')`); `DocumentVerifiers` singleton registry (`register`, `resolver`, `kinds`, `find($code)`: unscoped read of org + kind by code, then the kind's resolver inside that organization); `VerificationResolver` contract, `VerifiedDocument` DTO, `VerificationStatus` (valid/voided). Model `DocumentVerification` (organization-scoped; added to `OrganizationScopedModels`).
- **`QrCode`**: bacon encoder (EC level M) + GD PNG data URI (no Imagick).
- **`AmountInWords`** + `Money::inWords()`: British "and", commas between groups, kobo, to the largest Money amount; "Zero naira only", "One kobo only".
- **Evidence media:** every `config/media.php` collection now has `kind` (`photos`, `imports` attachment; `documents`, `branding`, `issued_documents` evidence); `App\Media\Media` refuses deleting evidence (`EvidenceFileProtected`) except inside `Media::purging()` (for the NDPA tooling) — the ARCH § 9 promise "the removal code refuses a hard delete of an evidence collection (with a test)".
- **P01-05 preview moved onto the kit** (it was simple): `BrandingPreview` renders through `DocumentRenderer::web()`; invoice/receipt templates use brand bar, verify box (real QR), amount in words, the new footer. Measured with both drivers: sample invoice/receipt score 0.03–0.08 % in the visual diff; the sample invoice is now **one page** in modern and compact, **still two in classic** (P03-05b).
- **CI/compose:** `.github/workflows/ci.yml` runs `gotenberg/gotenberg:8.37.0-chromium` as a service on 3000 (`PDF_CHROME_URL`), installs `poppler-utils` if missing (not on the ubuntu-24.04 image); `compose.yaml` gains the `gotenberg` service. `routes/web.php` comment fixed (pay page P03-05c, verify P03-05b; decision 7).
- **Schema:** one migration `2026_11_06_050001_create_document_verifications_table`.
- **Docs:** BRANDING_DOCUMENTS.md § 1 (preview, layouts) and § 5 "How it is built"; ARCHITECTURE.md § 6.5 (document kit) and § 9 (collection kinds, `issued_documents`); DATABASE_SCHEMA.md (`document_verifications`, media collections, `branding_snapshot` shape); AGENT.md § 4 Documents; docs/runbooks/ci.md (Gotenberg, poppler, running the Chrome half locally); `.env.example` comment.

**Tests and evidence (local, MariaDB 11.4):**
- `composer ci` on the branch: Pint passed, Larastan 0 errors, Pest **1,855 tests: 1,852 passed, 3 skipped (the Chrome half of the visual diff, no Gotenberg locally), 16,894 assertions**, 0 failed.
- New tests are in `tests/Feature/Documents/` (`VerificationCodesTest`, `BrandingSnapshotTest`, `DocumentRendererTest`, `DocumentViewsLintTest`, `DocumentKitVisualDiffTest`), `tests/Feature/Media/EvidenceFilesTest.php` and `tests/Unit/Support/AmountInWordsTest.php`. Helpers are `tests/Support/PdfVisualDiff.php`, `DocumentCssLint.php` and `documents.php`.
- **Sub-AC: amount in words for kobo, millions, billions:** `AmountInWordsTest` › "acceptance: …" › writes kobo (1, 50, 99 kobo, naira + kobo), writes millions (sample receipt ₦2.9 m, every group), writes billions (1 bn, 25.5 bn, every group + kobo, 999 bn…), writes zero and exact naira; plus beyond billions, negatives.
- **Sub-AC: a sample page renders in both drivers within the diff threshold:** `DocumentKitVisualDiffTest` › "prints the sample page on one A4 page with dompdf, in the bundled fonts" (×3 layouts, always runs: 1 page, A4, Inter/DejaVu embedded, text incl. amount in words and "Page 1 of 1", brand bar pixels) and "prints the sample page with Chrome (Gotenberg) the same as dompdf, within the threshold" (×3 layouts; **skipped locally: no Docker/Gotenberg; runs in CI**). Locally I ran the Chrome half against a scratch stand-in for Gotenberg (a Node server driving the installed Chrome 156 over DevTools with Gotenberg's print parameters): 9/9 passed, scores 0.02–0.07 %. Real Gotenberg first runs in CI. Negative controls: "catches real differences: another layout, a missing row, a wrapped line"; "scores a page against itself as identical".
- **Sub-AC: lint bans flex, grid and CSS variables:** `DocumentViewsLintTest` › "finds none in any document view" (Core + `Modules/*/resources/views/documents`), "finds none in the pages as rendered, for every layout and target" (9), "catches each banned construct" (11), "leaves ordinary document CSS alone".
- **Sub-AC: a stored PDF is reused, not re-rendered:** `DocumentRendererTest` › "renders an issued document once and returns the kept file afterwards" (counting driver: 1 render over 3 calls, same media and bytes, `issued_documents`, sha256), "keeps one file per document key…", "never reuses another organization's file…", "keeps the stored PDF as evidence: it cannot be deleted".
- **Snapshot unaffected by later branding changes:** `BrandingSnapshotTest` › "prints an issued document the same after the branding, its images, the name and the plan change" (identical HTML from the stored json), "embeds the images themselves…" (prints with media rows/files gone), "keeps the product name…", "accepts only PNG data URIs as images…".
- **Verification codes unique and unguessable; resolver registry:** `VerificationCodesTest` › 2,000 codes unique/alphabet/distribution/CSPRNG engine, no structure between consecutive codes, collision redraw (seeded engine), DB unique index, give-up after 5, context required; registry finds through the kind's resolver inside the issuing organization (also while another org is current), voided, null for unknown/malformed/unregistered/unresolved, class-string via container, provider-style registration, duplicate kind refused.
- Also: footer "Page x of 3" on every page with dompdf (two-pass) and identical text with Chrome (checked locally); Chrome footer string escaping; web target (fonts by URL, no page numbers); QR finder patterns/size; evidence kinds for every collection; `CrossTenantAccessTest` probes `DocumentVerification`.
- Existing test changed: `BrandingSettingsTest` › "accepts a mid-contrast colour…" asserted `.topbar { … }`; the brand bar is now the `brand-bar` partial, so it asserts `.brand-bar td { height: 8px; background: #0891b2;` (same check).
- New tests with `APP_DEBUG=true`: 87 tests, 84 passed, 3 skipped (Chrome half); with `APP_DEBUG=true` and the local Chrome stand-in, `tests/Feature/Documents` 58/58 passed.

**Orchestrator review:** merged `main` (with P03-02) into the branch; resolved one conflict in `AppServiceProvider` by keeping both registrations (`ListExports` and `DocumentVerifiers`). Re-ran `composer ci` on MariaDB 11.4: Pint passed, Larastan 0 errors, Pest 1,985 tests: 1,982 passed, 3 skipped (the Chrome half of the visual diff, no Gotenberg locally). **Not yet verified in GitHub Actions:** the branch run [40500080471](https://github.com/thekingchild/Goproperty/actions/runs/40500080471) never started (the account's Actions billing stopped starting jobs again), so the Chrome half against real Gotenberg and the MySQL jobs are still to run; with `CI` set an unreachable Gotenberg fails the test. Re-run CI on `main` once Actions starts jobs.

**Decisions to confirm** (orchestrator decisions relied on, and choices made here):
- Relied on **decision 6:** Gotenberg as a CI service and in `compose.yaml`; the Chrome half skips locally only when Gotenberg does not answer `/health`, and **fails** on CI (`CI` set) if unreachable; dompdf always tested. Rasteriser: poppler `pdftoppm` (local has it; CI installs `poppler-utils` with apt only when missing — the runner image does not list it). Gotenberg cannot rasterise PDFs, so this one apt package on the CI runner was unavoidable.
- Relied on **decision 2's pattern** for `/verify`: code → organization + kind by one unscoped read, then `OrganizationContext::run`.
- Relied on **decision 7:** fixed the `routes/web.php` pay/verify comment.
- **Central `document_verifications` table** (not in DATABASE_SCHEMA before): codes must be unique across bills, receipts, leases… and the public page must find the issuing organization and module before reading anything; modules still keep `verification_code` on their rows.
- **Code format:** 10 Crockford base-32 characters (50 bits), printed `XXXXX-XXXXX`; the design samples showed `INV-7KQ2M` / `R8X4P2` (too short to be unguessable).
- **Snapshot embeds images** as data URIs per document (as the task asked); a bill row may carry ~0.1–1 MB of json. P03-05c may dedupe (content-hash store) if it matters.
- **Footer moved into the bottom page margin** (40 px) so "page x of y" works in both drivers; per-driver footer markup (fixed table vs `@page` margin boxes) is the one deliberate HTML difference between targets, besides font sources.
- **`pdf.dompdf.font_height_ratio` = 1000/1210** (dompdf default 1.1) so dompdf line heights equal CSS; affects every dompdf render (documents only use it).
- **Branding images collection classed as evidence** (they were already "kept" for issued documents).
- **Visual-diff threshold 0.4 %** at 50 dpi, ±2 px, 64 grey levels.
- **Chrome margins:** `ChromeRenderer` unchanged (Gotenberg default margins with `preferCssPageSize`); locally Chrome 156 let the CSS `@page` margins win. If CI shows otherwise, send zero margins plus the 40 px bottom.

**Management handover (P03-14):** nothing to move. `document_verifications` rows and `issued_documents` media are history and stay with the issuing organization.

**Follow-ups:**
- P03-05b: one A4 page for up to 8 lines in all layouts (classic sample invoice still 2 pages); `/verify/{code}` page through `DocumentVerifiers::find()` with rate limit; 360 px web view; VOID watermark (re-issue with key `receipt-void`).
- P03-05c: Billing registers its resolver, takes `BrandingSnapshots::take()` + `VerificationCodes::issue('invoice')` in the issue transaction, `DocumentRenderer::stored($bill, 'invoice', …)` on the `pdf` queue; downloads via `MediaUrls` after the policy check; decide snapshot image dedupe.
- P02-05 follow-up resolved here: snapshots embed image bytes, so the old `organization-branding/` P01-05 files are no longer needed by documents; pruning them (irreversible, `down()` of migration 050002 needs them) stays a separate decision.
- The settings preview's sandboxed `srcdoc` frame cannot load the bundled fonts (opaque origin, no CORS on static fonts); it falls back to Arial. Serving fonts with `Access-Control-Allow-Origin` would fix it.
- Glyphs outside Inter Latin/Latin Ext fall back to dompdf's DejaVu (taller lines) vs Chrome's system font; fine for Nigerian names in Latin script.
- NDPA erasure tooling: purge evidence through `Media::purging()`.

**Next:** P03-05b (wave 3, with P03-03a).

### P03-02 — Complete (2026-10-27): `Modules/Leasing` with `BillingCycle` and the pure `CycleCalculator` (month-end anchoring, due offset, lead window), property tests, interval-maths ban

**Delivered** ([ARCHITECTURE.md § 6.3–6.4](../ARCHITECTURE.md), AGENT.md § 3 rule 3, [DATABASE_SCHEMA.md § 3](../DATABASE_SCHEMA.md); `docs/plans/P02-P03-subtasks.md` P03-02):
- **Module `Modules/Leasing`:** made with `module:make Leasing`, then trimmed to provider, `module.json`, `composer.json` (no routes, config, views, seeders or factories yet) and an empty `database/migrations` for P03-03a. Added to `modules_statuses.json` (enabled). `LeasingServiceProvider` binds `Contracts\CycleCalculator` to `Modules\Leasing\CycleCalculator` as a singleton.
- **Public types (`Modules\Leasing\Contracts`, so Billing can use them across the module boundary):** `BillingCycle` enum (`daily`, `weekly`, `monthly`, `quarterly`, `semi_annual`, `annual`, `biennial`; labels Daily, Weekly, Monthly, Quarterly, Every six months, Yearly, Every two years; `days()` / `months()`; `HasValues`); `BillingPeriod` (readonly: `index`, `start`, `end`, `lengthInDays`, `contains()`); interface `CycleCalculator` (`periodStart`, `period`, `periodContaining`, `nextPeriodStart`, `periodsBetween`, `dueDate`, `billingDate`, `DEFAULT_LEAD_DAYS = 7`).
- **`Modules\Leasing\CycleCalculator`:** pure and immutable. It works on integer calendar days and month numbers and builds `CarbonImmutable` only for the result, with no Carbon add/sub. Arguments are calendar days: the time of day is ignored. Results are midnight in the argument's time zone. Period *n* is always counted from the anchor, so stepping period by period never drifts. Negative indexes (before the anchor) work. Daily/weekly cycles are 1- or 7-day counts. Month cycles land on the anchor day, clamped to the last day of a short month, and go back to the anchor day afterwards. A period ends on the next period's start − 1 day. `dueDate` = start + `due_day_offset` (negative is refused). `billingDate` = start − `lead_days` (negative is refused).
- **Docs:** ARCHITECTURE.md § 6.3 now has the namespace `Modules\Leasing`, the contract, `next_billing_date`, `due_day_offset` and the anchoring rules with worked examples, the lead window, due offset, calendar-day semantics and the scan. § 6.4 now uses `next_billing_date`, `lead_days`, `billingDate`/`dueDate`/`nextPeriodStart`. § 8 marks Leasing's calculator as built. DATABASE_SCHEMA.md § 3 `leases`: `due_day_offset` now reads "due N days after the period start" (was "bill N days before period start", which contradicted decision 1). `next_billing_date` names the calculator and `billing_cycle` names the enum.

**Orchestrator review:** branch already contained `main` (`995b40f`); re-ran `composer ci`: Pint passed, Larastan 0 errors, Pest 1,893 passed, 15,795 assertions.

**Decisions relied on (orchestrator defaults; to confirm with the product owner):**
- Decision 1, cycle maths: the namespace is `Modules\Leasing\CycleCalculator` (AGENT § 3.3 wins over ARCH's `App\Leasing`). The column is `next_billing_date` (DB § 3 wins over ARCH's `next_due_date`). Month-end anchoring clamps and then returns: 31 Jan → 28/29 Feb → 31 Mar. Period end = next start − 1 day. Daily/weekly cycles are plain day counts. A bill is generated `lead_days` (default 7) before its period starts. `due_day_offset` = days after the period start (default 0).
- My own reading, beyond decision 1: the calculator refuses a negative `due_day_offset` or lead, and does not clamp the due date to the period. A due date past a daily period's end is therefore allowed. P03-03a/04b should validate offsets against the cycle if they want that.
- `BillingCycle` and `BillingPeriod` live in `Contracts` (not `Enums`): Billing's utility cycles use the same enum (DB § 3 `utility_types.default_cycle`), and the module-boundary test only lets other modules use `Contracts`/`Events`.

**Tests and evidence (local, MariaDB 11.4):**
- `composer ci` on the final commit: Pint passed, Larastan 0 errors, Pest **1,893 tests: 1,893 passed, 15,795 assertions**, 0 failed (was 1,801 on `main`). The new tests also pass with `APP_DEBUG=true` (92 tests, 328 assertions).
- **AC — property-based tests:** `Modules/Leasing/tests/Unit/CycleCalculatorPropertiesTest.php` checks 2,601 anchors on 1999–2101. These are 2,000 random days from a fixed-seed `Xoshiro256StarStar` (seed 20261027) plus every 29 Feb, every 31 Dec/1 Jan, and the 1st and 28th–31st of every month in 1999, 2000, 2001, 2027, 2028, 2096, 2099, 2100 and 2101. Every cycle is covered. The oracle is PHP's own `DateTimeImmutable`, not the calculator's maths. Mismatches are collected and listed together, naming the anchor, cycle and period.
  - "ends every period the day before the next starts: start + cycle − 1 day, short months clamped" (×7 cycles): start/end/index/length against the oracle; for day cycles length = 1/7; and for anchor days ≤ 28 the literal `start + N months − 1 day`.
  - "lays periods end to end: contiguous, non-empty and non-overlapping" (×7).
  - "reaches the same start by stepping period to period as by counting from the anchor (no drift)" (×7).
  - "returns to the anchor day after a short month" (×5 month cycles).
  - "finds the period containing any date, before or after the anchor" (×7).
  - "counts the periods between two dates" (×7).
  - "puts the due date $offset days after the period start and the bill date $lead days before it".
  - Mutation check (by hand, reverted): breaking the leap-year rule (2100) or the return to the anchor day made these tests fail, with readable lists.
- **Documented examples:** `CycleCalculatorExamplesTest.php` covers:
  - 31 Jan monthly in a leap year (→ 29 Feb → 31 Mar …) and in a common year;
  - 29 Feb yearly (28 Feb in common years, 29 Feb in leap years), 2100 common and 2000 leap, 29 Feb every two years;
  - 30 Aug quarterly (→ 30 Nov → 28/29 Feb → 30 May) and 31 Aug six-monthly;
  - weekly from Sunday 27 Dec 2026 (Sunday–Saturday across the year end, 61 weeks);
  - daily through 29 Feb and the year end;
  - `periodContaining`/`nextPeriodStart`/`periodsBetween` (with the refused reversed range), `dueDate`/`billingDate` (default 7, negative refused);
  - time zone and time-of-day handling, labels and values.
- **Sub-AC — the interval-maths ban:** `CycleMathBoundaryTest.php` › "keeps interval maths in Leasing and Billing inside CycleCalculator".
  - It scans every PHP file, Blade views included, under `Modules/Leasing` and `Modules/Billing` except `tests/`. New files are covered automatically, and Billing is covered as soon as it exists.
  - Comments are ignored. `Modules/Leasing/app/CycleCalculator.php` is the only file exempt.
  - Banned: `->add…/sub…` by Day/Week/Month/Quarter/Year/Decade/Century/Millennium (with Real/UTC, plural and overflow variants); `addUnit`/`subUnit`/`rawAdd`/`rawSub`/`setUnitNoOverflow`; `add`/`sub` with a unit string or an interval; `plus`/`minus` with named date units; `modify()`/`date_modify`; `CarbonInterval`/`DateInterval`/`CarbonPeriod`/`DatePeriod`; `strtotime`/`date_add`/`date_sub`/`mktime`/`gmmktime`.
  - The test also includes "spots each banned form of interval maths" (32 cases, a Blade echo among them), "lets ordinary code and comments through" (`addMinutes`/`addHours`, `Money::plus`, `MessageBag::add`, comments) and "names the line of each violation".
  - Checked by hand: a probe `Modules/Billing/app/Probe.php` with `now()->addMonth()` failed the scan (probe removed).
- `Modules/Leasing/tests/Feature/LeasingModuleTest.php`: the module is enabled, and the contract resolves to the one calculator as a singleton. `ModuleSkeletonTest` (module boundaries) passes with the new module.

**For P03-14 (management handover):** nothing to move. P03-02 adds no tables. The calculator is pure, and lease rows (P03-03a) will keep `next_billing_date` as a plain date.

**Follow-ups:**
- The `lead_days` organization setting (default `CycleCalculator::DEFAULT_LEAD_DAYS` = 7) is not built yet. It belongs with bill generation (P03-04b), as do the `leases.due_day_offset` / `next_billing_date` columns (P03-03a).
- The scan list is `['Leasing', 'Billing']`. Add Reminders/Payments there if they start doing period maths.
- The property tests take ≈ 35 s of CPU in total, split over 41 dataset tests, so they run in parallel under `--parallel`.

**Next:** P03-03a (lease engine) builds on `Contracts\CycleCalculator` for `next_billing_date`. P03-04b uses `billingDate`/`dueDate`/`nextPeriodStart` for rent bills.

### P02 — Complete (2026-10-27): properties, units, owners, import part 1, exports

All seven P02 tasks are Complete (P02-01 as 01a/01b, P02-06 as 06a/06b/06c), each built in its own worktree, reviewed and re-run through `composer ci` by the orchestrator, and merged as one squashed commit; GitHub CI is green on MySQL 8.0/8.4 and MariaDB 10.6/11.4 after each merge. The exit check is in the P02-07 entry below: P01-05's live invoice/receipt preview counts as "branding renders on a sample document page" (new test in `BrandingSettingsTest`), so no new task ID was needed. Decisions to confirm are listed in each task's entry; the product owner's 2026-10-27 review of P02-01 to P02-04 replaced the billing transfer with the management handover (P03-14) and made evidence files undeletable. Still open: whether an agent with no building assignment sees every building (as built) or none behind an organization setting.

### P02-07 — Complete (2026-10-27): Excel/CSV export of every workspace list (Core `App\Exports`), streamed, audited, wired into every list; P02 exit check

**Delivered** ([DATA_IMPORT.md § 6](../DATA_IMPORT.md), [ARCHITECTURE.md § 6.13](../ARCHITECTURE.md) (new), [PLATFORM_CONSOLE.md § 6](../PLATFORM_CONSOLE.md), AGENT.md § 5; `docs/plans/P02-P03-subtasks.md` P02-07):
- **Core `App\Exports`:** `ListExport` (key, title, `filters()` cleaning, `authorize()`, `columns(User)`, `rows()`, `count()`), `QueryListExport` (an Eloquent query read with `lazy(500)`, `exportQuery()` adds eager loads), registry `ListExports` (singleton; Core registers its lists in `AppServiceProvider`, modules from their providers with `callAfterResolving`; classes made fresh each time), `ExportColumn`, `ExportCell` (cell formatting + formula neutralising), `ExportDate`, `ExportFormat` (csv/xlsx), `ListFilters` (search ≤ 100, flag, id, option, LIKE escaping), `ListExportRequests` (start + download), `ListExportWriter` (streaming), `ExportAudit`, `ListExportRefused`.
- **One query per list, shared by the page and the export:** each list's query moved out of its Livewire component into a list class that the page now paginates and the export streams: `Modules\Properties\Lists\OwnerList`, `PropertyList`, `UnitList` (also the building page's status board: building + status), `BuildingAgentList` (P02-03 assignments, names as on the page); `Modules\Import\Lists\ImportBatchList`; `Modules\SaasBilling\Lists\BillingTransfersMadeList`, `BillingTransfersReceivedList` (through `IncomingTransfers::all()`, new); Core `App\Members\MemberList`, `InvitationList` (Settings → Members). So filters, search, sort, organization scope and agent narrowing are the page's by construction.
- **Reusable Livewire action:** trait `App\Exports\Livewire\WithListExports` (`listExports()` returns each list on the page with its current filters; `export($list, $format)` marked `#[AllowedWhileSuspended]`; `canExport()`), Blade component `<x-list-export list="…" />` (Excel/CSV menu, shown only when the member may export, refusal shown under it). Wired into Owners, Buildings, Units, the building page (agents and units), Import history, Billing transfers (offers made and received), Members (members and invitations).
- **Download in two steps:** `export()` checks the list's policy, the rate limit (30 per member per organization per hour) and the size (≤ 50,000 rows; otherwise "narrow it down"), stores a one-time ticket in the cache (5 minutes; member, organization, list, format, cleaned filters: a search never goes into a URL) and redirects to `GET /o/{slug}/exports/{ticket}` (`App\Http\Controllers\Workspace\ListExportController`, `throttle:60,1`). The download is the member's own in that organization (404 otherwise; another member's attempt does not burn it), used once, re-checks the policy, writes the audit entry, then streams.
- **Streaming, nothing left behind:** `StreamedResponse`; CSV row by row (UTF-8 BOM, CRLF, `fputcsv` without escape char); Excel through OpenSpout into a private per-export folder under `storage/framework/exports`, zipped into the response, the folder deleted in a `finally` (a failed read sends no half workbook).
- **Cells:** money from kobo as naira with two decimals (`Money::toDecimalString`, text: no float), times in Africa/Lagos `Y-m-d H:i`, enums by `label()`, booleans Yes/No, whole numbers as numbers; Excel cells built as `StringCell`/`NumericCell` explicitly (OpenSpout's `Cell::fromValue` turns "=…" into a formula). Formula injection: text starting with = + - @ tab CR gets a leading apostrophe in both formats, headers too; plain numbers (`-1`, `+2348031234567`) are kept. No encrypted field is exported: the owners list has "ID (masked)" (`OwnerProfile::maskedId()`), no list has bank details. Owners/buildings/units headers are the import's, so an exported CSV imports back as updates (tested).
- **Policies (`export…` abilities, reads):** `OwnerProfilePolicy::export`, `PropertyPolicy::export`, `PropertyPolicy::exportAgents` (= `view` of the building: a narrowed agent's other building answers 404), `UnitPolicy::export`, `ImportBatchPolicy::export`, `BillingTransferRequestPolicy::export`, `MembershipPolicy::export`, `InvitationPolicy::export`. No new permission.
- **Audit:** log `exports`, event `list.exported`, organization as subject, member as causer (staff + session during impersonation): list, format, cleaned filters, row count ("Exported Units (CSV, 2 rows)").
- **AGENT.md § 5:** "Lists: every new workspace list uses the list export" (how, column rules, policy ability, the coverage test).
- **Docs:** ARCHITECTURE.md § 6.13 List exports (new); PLATFORM_CONSOLE.md § 6 (exports of contact details refused to impersonating staff); DATA_IMPORT.md § 6 (built; re-import of exports).

**Tests and evidence (local, MariaDB 11.4):**
- `composer ci` on the final commit: Pint passed, Larastan 0 errors, Pest **1,801 tests: 1,801 passed, 15,449 assertions**, 0 failed (was 1,763 on `main`). The new tests also pass with `APP_DEBUG=true` (37 tests, 829 assertions, plus the new branding test).
- New: `tests/Feature/Exports/ListExportCoverageTest.php` (4), `ListExportScopeTest.php` (6), `ListExportSafetyTest.php` (16 incl. datasets), `ListExportAccessTest.php` (8 incl. datasets), `ListExportReimportTest.php` (3), helpers `tests/Feature/Exports/helpers.php`, fixtures `tests/Fixtures/Exports/ProbeList.php`, `tests/Fixtures/Livewire/UnexportedListProbe.php`; one test added to `tests/Feature/OrganizationSettings/BrandingSettingsTest.php` (P02 exit). All exports go through the real page → Livewire update `export()` → download over HTTP.
- **AC: every list page exports:** `ListExportCoverageTest` › "gives every workspace list page a working export, in Excel and CSV" (walks `WorkspaceNavigation` (every sidebar page except Home, Transaction Tier, Settings, which are not lists) plus the building page; each page shows Export buttons; every button exports in both formats with the list's headers and rows; the set of lists offered equals the registry: 9 lists); "makes every Livewire component that pages through a list use the export" (static scan of Core and module Livewire components using `WithPagination`); "fails the check for a new list page without an export" (a fixture paginated page added to the sidebar is reported); "shows the Export button only to members who may export the list".
- **AC: exports respect the current filters and org scope:** `ListExportScopeTest` › "exports exactly the rows the page shows with its current filters, on every page and in its order, never another organization's" (30 units over 2 pages, same order as the page; filters typed on the page and from the URL; unknown values ignored as on the page; another org's building id gives nothing; the other org exports only its own; buildings by state/type/archived; building page status board), "exports the owners the page shows: search, archived, and never another organization's", "exports the billing offers made and received by this organization only" (an offer between two other organizations never appears).
- **Narrowed agent:** `ListExportScopeTest` › "gives a narrowed agent only the buildings assigned to them, in every list" (units, buildings, owners (and their building count), building agents; a crafted building filter gives nothing; another building's agents export 404; an unassigned agent sees all).
- **No encrypted values:** `ListExportSafetyTest` › "never exports an encrypted value: ID numbers are masked, in CSV and Excel" (plaintext NIN/CAC and the stored ciphertext absent; masked values present; no list has an ID/bank number column).
- **Formula injection:** `ListExportSafetyTest` › "neutralises formula injection in CSV and Excel, keeping plain numbers" (=HYPERLINK, -2+3+cmd|, @SUM, +cmd| prefixed in both formats; phone kept; no `<f>` in the sheet XML, cells `inlineStr`), plus an 11-case dataset on `ExportCell`.
- **Large export streams with bounded memory:** `ListExportSafetyTest` › "streams a 5,000-row export a chunk at a time, without holding the list in memory" (csv and xlsx: `StreamedResponse`; output counted as it leaves (5,001 CSV lines); ≥ 10 `limit 500` selects; peak memory below half of loading the list at once and below 16 MB (measured ≈ 2.3 MB CSV / 3.0 MB Excel against ≈ 19 MB); no temp file left).
- **No temp file:** `ListExportSafetyTest` › "leaves no temporary file behind when an Excel export fails half way, and sends no half workbook".
- **Audited:** `ListExportAccessTest` › "audits every export: who, which list and format, the filters and the row count" (hash-chained entry; pressing Export alone writes nothing).
- **Roles, tickets, impersonation, suspension, limits:** `ListExportAccessTest` › "hands out a one-time download for this member in this organization only, valid five minutes", "lets only members who may see the list export it, and checks again at the download", "keeps exports working in a suspended organization", "lets impersonating staff export lists without contact details, and refuses the rest" (read-only and write: units/buildings export, audited with staff + session; owners and members pages open without the button, `export()` 403, the owner's earlier owners ticket 403 for staff; 3 `impersonation.blocked` entries), "limits how many exports a member makes in an hour", "refuses a list too long for one export, asking to narrow it".
- **Re-import:** `ListExportReimportTest` › "imports its own owners, buildings and units exports again, as updates of the same records" (3-case dataset through the real import dry run: 0 errors, 0 creates).
- Existing list tests (unit list query count, owners, buildings, imports, billing transfers, members) pass unchanged on the refactored queries.

**Orchestrator review:** merged `main` (with the product owner's handover/evidence docs and the P03 defaults) into the branch with no conflicts, re-ran `composer ci`: Pint passed, Larastan 0 errors, Pest 1,801 passed, 15,449 assertions.

**Decisions to confirm:**
- Orchestrator decision 1 (modules): list classes live in the module that owns the list (`Modules\<Name>\Lists`), registered from the module's provider; Core `App\Exports` names no module (module-boundary test passes). Core's own lists (members, invitations) are in `App\Members`.
- Orchestrator decision 2 (cross-org lookups): the "offers to you" export reads through SaasBilling's existing `IncomingTransfers` (P01-04 pattern), new `all()`; no scope removed.
- Orchestrator decision 4 (plan gating): BILLING § 1.2 lists import & export on every plan, so exports are ungated; nothing for P06-01.
- **Impersonation (PLATFORM_CONSOLE § 6 / P01-08):** exports are reads (allowed in read-only sessions, as § 6 said), **except lists of people's contact details** (owners, members, invitations; tenants when P03-01a adds the list), refused in both modes by `#[BlockedWhileImpersonating]` on the `export…` policy ability (central Gate hook; refusals audited by `ResumeImpersonation`). Buildings (owner names only), units, a building's agents (names only, as on the page) and imports export, audited with both identities. PLATFORM_CONSOLE § 6 updated.
- **Download via a one-time ticket** rather than returning the file from the Livewire call (Livewire buffers a download in memory and base64-encodes it) or a plain filtered GET link (filters such as a phone-number search would be in URLs and logs).
- **Money as text "1500000.00"** in both formats (`Money::toDecimalString`): an Excel number cell would need a float (AGENT § 3 rule 2). Spreadsheets convert the CSV column to numbers on opening; in Excel the cells are text until converted. Whole numbers (bedrooms, counts) are numeric cells.
- **Dates** ISO-like `2026-10-27 14:05` in Africa/Lagos (the app runs in UTC), unambiguous in every spreadsheet locale, instead of the pages' `d/m/Y`.
- **Formula neutralising keeps plain numbers** (`-1`, `+2348031234567`): they cannot call anything, and prefixing every phone number would spoil the column.
- **Limits:** 30 exports per member per organization per hour; 50,000 rows per export (shared hosts' request time limits); the Excel header row is bold, no column widths.
- **Exports hold the whole list**, not the page: the members' invitations (page shows the latest 100) and billing transfers made (page shows 20) export all rows of the same query.
- Owners export counts only the buildings the member sees; the buildings export has the Owner column only for members who may see owners (`OwnerProfilePolicy::viewAny`), as the building page does.
- Not lists (no export, recorded in the coverage test): Home, Transaction Tier application, Settings forms (incl. bank accounts: secrets, owner-only, blocked while impersonating), an import's report page (its problems already download as an Excel file).

**P02 exit check** (IMPLEMENTATION_PLAN.md § 3: "all P02 tasks complete; branding settings (P01-05) render on a sample document page"):
- **All P02 tasks complete:** P02-01 (01a, 01b), P02-02, P02-03, P02-04, P02-05, P02-06 (06a, 06b, 06c) are Complete in the plan and the ledger; P02-07 completes with this entry (every AC above proven by a named test). The sub-task ACs of `docs/plans/P02-P03-subtasks.md` each map to a named test in their ledger entries.
- **Branding renders on a sample document page: yes, P01-05's live invoice/receipt preview counts; no new ID needed.** Settings → Branding (`/o/{slug}/settings/branding`, `App\Livewire\Settings\BrandingPage`) renders the sample invoice and receipt (`App\Documents\BrandingPreview` with `resources/views/documents/invoice.blade.php`/`receipt.blade.php`, sample data `App\Documents\SampleDocuments`) with the organization's branding: logo (now from the media library, P02-05), trading name, colours, letterhead, prefixes, notes, footer, layout. Evidence: `BrandingSettingsTest` › "renders the saved branding on the sample invoice and receipt when the page is opened again" (new: saved branding with a logo, a fresh GET of the page shows the trading name, colour, prefixes, notes, footer, classic layout font and the logo instead of the monogram), › "renders the sample invoice and receipt from the unsaved form, and saves nothing until Save", › "renders the sample invoice and receipt with dompdf in every layout" (PDF bytes from the saved branding, all three layouts). P03-05 reuses the same templates with real bills.
- **Also green:** `composer ci` (above), including `CrossTenantAccessTest` (P02-07 adds no model).

**Follow-ups:**
- P03-01a (tenants list), P03-03a (leases), P03-04b (bills), P03-12 (expenses) and every later list: use `WithListExports` and a `ListExport` (AGENT § 5; the coverage test fails otherwise). The tenants list holds contact details: mark `TenantProfilePolicy::export` `#[BlockedWhileImpersonating]`; never export `id_value` (masked only).
- P08-01 reports: "CSV + PDF export" of dashboards can reuse `ListExportWriter`/`ExportCell` (a report is a list of rows).
- The portals (`/portal`, `/owner`, P03/P04) set no organization context; if they get exports, they need their own scoping (not built).
- Excel money cells are text (see decisions); if the product owner wants numeric money cells, `Money` would need a sanctioned conversion for spreadsheets.
- The rate limit and row cap are constants (`ListExportRequests::PER_HOUR`, `MAX_ROWS`); make them settings if customers ask.

**Next:** P02 is complete (exit check above): mark the P02 phase heading and § 2 Status complete; then P03 wave 1 per `docs/plans/P02-P03-subtasks.md`.

### P02-06c — Complete (2026-10-27): owner, building and unit import targets and the full 4-sheet workbook; P02-06 complete

With P02-06a (engine) and P02-06b (tenants), the parent **P02-06 is complete**: every AC of its row passes through the real engine on a generated 2,000-row workbook.

**Delivered** ([DATA_IMPORT.md](../DATA_IMPORT.md) § 1 rows 1–3, § 2 "Re-running and updates", "Undo"; [DATABASE_SCHEMA.md](../DATABASE_SCHEMA.md) § 1, § 3, § 8A; [ARCHITECTURE.md § 6.12](../ARCHITECTURE.md); `docs/plans/P02-P03-subtasks.md` P02-06c):
- **Targets** in `Modules/Properties/app/Import`, registered from `PropertiesServiceProvider` into `Modules\Import\Contracts\ImportTargets` (`callAfterResolving`, contract only; bound as singletons so one dry run's memory of what the file adds is shared between sheets): `OwnersImportTarget` (key `owners`, "Owners", order 10), `PropertiesImportTarget` (key `properties`, sheet "Buildings", order 20), `UnitsImportTarget` (key `units`, "Units", order 30); Tenants stays at 40, so the commit order is owners → buildings → units → tenants (→ leases later). Shared cell checks in `ImportCells` (Your ID ≤ 100 and once per sheet, plain text, Nigerian state incl. "Abuja" → FCT).
  - **Owners:** Name (a person's "First Last", split on the last space; or a company name), Type (Person/Company; empty = Person or what is on file), Phone, Email, Address, State, LGA, ID type (NIN / CAC number / None), ID number (never echoed; NIN 11 digits, CAC like RC 1234567), Notes. Matching: Your ID, then phone (E.164), then display name; an owner on file with **another** phone is a namesake (new owner + warning), one with **no** phone is matched by name and gains the number; a name or phone shared by two owners on file is an error asking for Your ID or the phone. Duplicates in the file: same ID or phone, or the same name when either row has no phone.
  - **Buildings:** Building name, Owner (name, phone or Your ID; from the Owners sheet of the same file or on file; empty = the organization owns it), Type (labels plus "Flats", "Office", "Mixed"…), Address, State, LGA, Landmark, Amenities (comma separated, through `Amenities::normalize`, ≤ 30 × 40 chars), Year built, Description. Matching: Your ID, then name in the organization; a name used twice in the file, or by two buildings on file, is an error. New buildings get `Buildings\Properties::freeSlug()`. Type, address and state are required for a new building only.
  - **Units:** Building (name or Your ID), Unit, Type (labels plus "Self con", "Mini flat"…), Bedrooms, Bathrooms, Size (sq m), Floor, Rent per year, Service charge per year (kobo via `ImportRow::amountKobo`/`Money`, ≤ ₦10bn, never negative), Status, Description. Matching: Your ID, then building + label (without case, like the unique index); a unit never moves to another building by import; renaming through Your ID checks the label is free. New units need type and rent.
  - **Archived matches** (decision 3): soft-deleted owners, buildings (removed by a rollback) and units are restored, warned about in the dry run, audited one by one (`owner.restored` log `owners`; `property.restored`, `unit.restored` log `properties`, with batch id and row), carry the batch's id; restored units send `UnitCreated(restored: true)`.
  - **Empty cells keep what is on file**; creates and updates are audited by the batch (`import.committed` counts), as in 06b. Created/restored units send `UnitCreated`, rolled-back units `UnitDeleted` (P06 metering), like the units writer.
  - **Warnings:** "The rent on file is ₦1,200,000 a year; this import changes it to ₦1,350,000."; once per dry run "Each unit this import adds or brings back counts towards your GoProperty bill. You have N billable units now; …" (`UnitDirectory::countBillable()`). The review page's subscription effect now reads "This file adds X units to the N you have. On the <plan> plan, N+X units cost ₦… a month (before VAT), against ₦… now." (`PlanCatalogue` + `UnitDirectory::countBillable()` through the Properties contract, while the batch is not yet committed; decision 6).
  - **Unit status** (P02-02 rule): the sheet sets vacant / under maintenance / unavailable; "Occupied"/"Reserved" (also "Let", "Booked"…) import as vacant (or the status on file) with a warning; a unit a lease holds keeps its status (warning if the file says otherwise).
  - **Archived buildings** (status `archived`) are skipped with a warning, and so are their units' rows (also when archived between dry run and commit: listed as skipped on the report).
  - **`usedSince`:** units — a unit of the batch is occupied or reserved (a lease holds it); buildings — a building of the batch holds a unit that is not the batch's; owners — a building that is not the batch's names an owner of the batch. Rollback order units → buildings → owners never strands a record that is not the batch's.
  - **Later sheets:** the dry run remembers owners (`ref:`, `phone:`, `name:`, `name-twice:`), buildings (`ref:`, `name:`) and units (`ref:`, `label:<building>|<label>`) for the Leases sheet (P03-13a).
- **Template:** the downloadable template now has the four sheets Owners, Buildings, Units, Tenants (plus Instructions and the hidden Lists sheet) with dropdowns for Owner type, State, ID type; Building type, State; Unit type, Status; Tenant ID type. The example rows refer to each other (the example building names the example owner, the example unit the example building), and an unchanged template imports one record per sheet.
- **Docs:** DATA_IMPORT.md § 1 rows 1–3 as built (bank details not imported: owner profiles have no bank fields), § 2 matching rules, archived buildings, what "used" means; DATABASE_SCHEMA.md § 1 `owner_profiles`, § 3 `properties`/`units` (import writers), § 8A (provenance built for the four tables); ARCHITECTURE.md § 6.12 "Targets built so far".

**Tests and evidence (local, MariaDB 11.4):**
- `composer ci` on the final commit: Pint passed, Larastan 0 errors, Pest **1,763 tests: 1,763 passed, 14,570 assertions**, 0 failed (was 1,740 on `main`). The 23 new tests (262 assertions) and `ImportFilesTest` also pass with `APP_DEBUG=true`.
- New: `Modules/Properties/tests/Feature/Import/PortfolioImportTest.php` (2), `PortfolioCommitTest.php` (2), `PortfolioRollbackTest.php` (2), `PortfolioQueueTest.php` (2; the 2,000-row tests are split over four files so the parallel suite spreads them), `PortfolioImportRulesTest.php` (11 + a 5-role dataset = 15), helpers `helpers.php` (the 2,000-row fixture is generated in the test: 100 owners, 200 buildings, 1,500 units, 200 tenants; no binary committed). All through the real engine (`ImportBatches::upload` → `dryRun` → `commit` → `CommitImportSheet` → `rollback`).
- **AC: dry run of a 2,000-row workbook saves nothing and returns exact errors per row:** `PortfolioImportTest` › "dry runs a 2,000-row workbook without saving anything, and returns the exact errors of each row" (every SQL write recorded: none outside `import_batches`, the audit log, media and cache; the four tables empty; clean file passes with exact counts per sheet; with 10 bad cells across the 4 sheets, all 50 errors asserted as [row, header, letter, severity, message], including the 43 Units rows that name an invalid building and the 4 Buildings rows that name an invalid owner; commit refused).
- **AC: commit creates exactly the previewed records:** `PortfolioCommitTest` › "commits exactly the records the dry run previewed" (result counts per sheet = dry-run counts incl. an update of an owner on file; 100/200/1,500/200 rows; provenance; the 5 preview rows of each sheet equal the saved records; values and kobo amounts; `UnitCreated` × 1,500; `countBillable()` 1,500; no per-record audit for creates).
- **AC: re-importing the same file creates no duplicates:** `PortfolioCommitTest` › "creates no duplicates when the same workbook is imported again" (2,000 updates, 0 warnings, tables identical apart from `updated_at`, first batch's provenance kept; the same file without any Your ID matches owners by phone/name, buildings by name, units by building + label, tenants by phone: still 100/200/1,500/200).
- **AC: rollback removes the batch:** `PortfolioRollbackTest` › "rolls back the batch: what it created or restored is archived, everything else stays" (99 owners, 200 buildings, 1,500 units, 200 tenants soft deleted; the updated owner and the organization's own building and unit stay; `UnitDeleted` × 1,500; billable count back to 1; audit counts per target; importing again restores them all instead of colliding).
- **Each commit job under 50 s on the shared profile (in-process queue tick):** `PortfolioQueueTest` › "commits a 2,000-row workbook on the imports queue, each job well within 50 seconds on the shared profile" (database queue, `QueueTick::run()`, jobs in order owners → properties → units → tenants, none ≥ 35 s, no failed jobs).
- **Cross-org:** `PortfolioQueueTest` › "never matches another organization's owner, building or unit" (same names, phones, IDs and labels, some archived, in another org: unknown here, no restore warnings, 7 creates, their rows unchanged incl. after a rollback).
- **Archived matches restored:** `PortfolioRollbackTest` › "restores archived owners, buildings and units it matches, audited, and a rollback archives them again".
- Rules (`PortfolioImportRulesTest`): exact errors for every kind of bad cell; owner matching and namesakes; building/unit matching without case, ambiguous names, no moving units, renames; archived buildings skipped (dry run and archived after the dry run); unit statuses; rent and bill warnings and the page's plan effect; rollback refused once used (lease status, a unit added on the pages, a building added for an imported owner); the 4-sheet template with its dropdowns imports its examples; only org-owner/org-admin may import (5-role dataset); a one-sheet units CSV with an agency's own headers.
- Existing test changed (not weakened): `ImportFilesTest` › "offers a template with instructions, a sheet per target and dropdowns for fixed values": the real Owners, Buildings and Units sheets now join the fixture sheets in commit order, the hidden Lists sheet moved to index 7 and its first column is padded to the 37 states.
- Timing (local, sync queue): dry run of the 2,000 rows ≈ 7 s in the request; commit ≈ 16 s in total.

**Orchestrator review:** branch already contained `main` (`6087846`); re-ran `composer ci`: Pint passed, Larastan 0 errors, Pest 1,763 passed, 14,570 assertions.

**Decisions to confirm:**
- Orchestrator decision 1 (modules): the three targets live in `Modules/Properties/app/Import`; the Import page reads the current billable count through `Modules\Properties\Contracts\UnitDirectory` (allowed by the module-boundary test; skipped when the binding is missing).
- Orchestrator decision 2 (cross-org lookups): none; everything runs inside the batch's organization context, no scope removed (only `withTrashed()`).
- Orchestrator decision 3 (soft deletes vs unique keys): soft-deleted owners, buildings and units matched by Your ID or natural key are restored and audited; unique indexes unchanged.
- Orchestrator decision 4 (plan gating): BILLING § 1.2 gates nothing here (Excel import, owners, buildings, units are in every plan); assisted import is still a P06 follow-up (06a).
- Orchestrator decision 6 (imports): the bill effect uses `PlanCatalogue` (the signup plan, else the best plan for the total) priced for the current `countBillable()` plus the file's units.
- **Only org-owner and org-admin import** (`imports.manage`), and they are never narrowed (P02-03), so agent narrowing does not apply to imports; tested with a 5-role dataset.
- **Buildings archived on their page are skipped, not restored** (DATA_IMPORT § 2 says archived matches are brought back): for buildings, archiving is a status (P02-01b), restoring it would put its units back on the bill, and a rollback (soft delete) could not put it back to "archived". Only buildings soft-deleted by a rollback are restored. DATA_IMPORT § 2 updated.
- **Owner matching**: Your ID → phone → name; a namesake on file with another phone is a different owner (warning), one without a phone is matched and gains the number. Owner names are one "Name" column (split for people) plus Type, rather than separate first/last/company columns.
- **Building names must differ within a file** (units name their building by it); two buildings on file with one name need Your ID.
- **Unit statuses**: occupied/reserved in the sheet import as vacant (or keep what is on file) with a warning instead of an error, since DATA_IMPORT says status "can be left blank — derived from leases" and agencies' sheets often say "Occupied".
- **"Rent differs" warning** compares the file's rent with the unit's rent on file (the lease-vs-unit-default warning comes with leases, P03-13a). Expected yearly collections stay 0 for units (asking rents are not collections; leases add them).
- **What "used" means** (rollback): see Delivered. An import that names one of its **new** owners for a building already on file cannot be rolled back until that building's owner changes (safe, possibly strict).
- **Audit**: per-record entries only for restores; creates/updates are in `import.committed` (06b's choice for the job budget).
- **Bank details** in DATA_IMPORT § 1 row 1 are not imported: `owner_profiles` has no bank columns (DB § 1). Docs say so.

**Follow-ups:**
- P03-13a: Leases sheet can rely on `ImportRun::knows('units', 'label:<building key>|<label>')` / `ref:`; extend `UnitsImportTarget::usedSince` (and buildings/owners) to leases, bills and payments; "tenant phone already exists in another lease" and "rent differs from unit default" (lease rent vs unit rent) warnings.
- Owner bank details (DATA_IMPORT § 1 row 1) need columns on `owner_profiles` (or a payout-account table) first.
- P06-01/P06: plan gating unchanged; metering can count the `UnitCreated`/`UnitDeleted` the import now sends.
- P02-07: export on the import history (06a follow-up still open).
- Performance: ~8 ms a row on commit locally (2–3 lookups per row validated twice); fine for the 30 s job budget, worth a per-run lookup cache if 10,000-row unit sheets feel slow on shared hosts.

**Next:** P02-07 (exports), then the P02 exit check.

### P02-03 — Complete (2026-10-27): agent ↔ building assignment, agent narrowing in policies and every list, and the unit list (`/o/{slug}/units`)

**Delivered** ([DATABASE_SCHEMA.md § 3](../DATABASE_SCHEMA.md) `agent_building`, [ARCHITECTURE.md § 5](../ARCHITECTURE.md) "Agent assignment and narrowing", § 6.2, § 13; `docs/plans/P02-P03-subtasks.md` P02-03):
- **Schema:** module migration `Modules/Properties/database/migrations/2026_11_05_000101_create_agent_building_table.php`: `organization_id` (restrict), `user_id` and `property_id` (cascade on delete), timestamps, no soft deletes. Unique `(organization_id, user_id, property_id)` (the documented `(user_id, property_id)` pair, with `organization_id` leading per ARCH § 4; it also serves the "this agent's buildings" lookup); index `(organization_id, property_id)`.
- **Model:** `Modules\Properties\Models\AgentAssignment` (`BelongsToOrganization`, table `agent_building`), `AgentAssignmentFactory` (`of($user, $property)`); added to `tests/Support/OrganizationScopedModels.php` (all 5 `CrossTenantAccessTest` probes).
- **Permission:** new `properties.assign_agents` (`OrganizationPermission::AssignAgents`; org-owner, org-admin), `allowedWhileSuspended()` false, `blockedWhileImpersonating()` **true** (it decides what a member sees, like a role change; PLATFORM_CONSOLE.md § 6 updated). Seeding the role map is enough on existing installs (`InstallSeeder`).
- **Writer (audited, log `properties`, building as subject):** `Modules\Properties\Assignments\AgentAssignments`: `assign` / `unassign` (each checks `PropertyPolicy::assignAgents` for the actor; building row locked; assigning twice changes nothing; unique-index race returns the existing row), `assignable()` (members holding `agent` and neither `org-owner` nor `org-admin`), `agentsOf()` (current members only, flagged when an owner/admin role makes the assignment moot), `dropMember()`. Events `property.agent_assigned` / `property.agent_unassigned` (with `user_id`; `reason = member_removed` when dropped with a membership).
- **Narrowing:** `Modules\Properties\Assignments\AgentNarrowing` (implements the new contract `Modules\Properties\Contracts\BuildingAccess`: `limitedTo(User): ?list<int>`, `allows(User, id)`), plus `buildings()`, `units()`, `owners()` query limiters. Applied in:
  - `PropertyPolicy` (view/update/addPhotos/deletePhoto/delete/restore/assignAgents), `UnitPolicy` (view/create/update/delete/restore) and `OwnerProfilePolicy::view`: a record outside a narrowed agent's buildings answers **404** (`Response::denyAsNotFound()`), checked before the ability's own rule so even an archived building's existence is not given away. The writers (`Properties`, `PropertyPhotos`, `Units`, `CreateUnitsInBulk`) inherit it through their `Gate::forUser()->authorize()` calls.
  - Lists: buildings list (rows, archive/restore lookups, owner filter), owners list (rows and lookups), owner page (its buildings and their count), building form owner picker (+ the writer refuses an owner the actor may not `view`), the new unit list (rows and building filter), `PropertyDirectory::activeOptions(?User $for)` and `UnitDirectory::counts(?User $for)` (new optional parameter; `countBillable()` never narrows). The status board needs no extra limit: its building page is already 404 for unassigned buildings. `PropertyShow::render` and `OwnerProfileForm::render` re-check `view`, so an agent taken off a building loses an open page on the next update.
- **Membership removal:** Core `App\Members\Members::remove` now sends `App\Members\MemberRemoved` (plain ids) inside its transaction; the Properties module listens (`Assignments\DropRemovedMemberAssignments`) and deletes that member's assignments in that organization, audited — rolled back with the removal if anything later fails.
- **Building page:** "Agents" section (`data-test="building-agents"`): assigned members for everyone who sees the building; for `assignAgents` holders a select of the organization's unassigned agents, Assign and "Take off" (with confirm). Hidden controls while suspended/impersonating/archived.
- **Unit list** `Modules\Properties\Livewire\UnitIndex` at `/o/{slug}/units` (`workspace.units.index`), sidebar item "Units" (order 250, `viewAny Unit`): every live unit of every non-removed building, 25 a page on the server, debounced search (400 ms) over unit label and building name, filters building / status / type, all in the URL; ordered by building name, then label length and label ("Flat 2" before "Flat 10"); one query with the buildings eager loaded; edit link per unit of an active building when the member may edit (one policy check per page, not per row); units of archived buildings marked and read-only.
- **Docs:** DATABASE_SCHEMA.md § 3 `agent_building` as built; ARCHITECTURE.md § 5 (new "Agent assignment and narrowing" paragraph, Units paragraph, policy-rules bullet), § 6.2 (BuildingAccess), § 13 (unit list as built); PLATFORM_CONSOLE.md § 6 (assignment always blocked while impersonating).

**Tests and evidence (local, MariaDB 11.4):**
- `composer ci` on the final commit: Pint passed, Larastan 0 errors, Pest **1,691 tests: 1,691 passed, 13,867 assertions** (was 1,658 on `main`). The new tests (`Modules/Properties/tests/Feature/Assignments`, 28 tests, 367 assertions) and the `AgentAssignment` cross-tenant probes also pass with `APP_DEBUG=true`.
- Flaky, not this task: the first `composer ci` run failed once in `SignedMediaRouteTest` › "streams the original privately to anyone holding a valid link" (`Cache-Control: max-age=899` vs expected `900`: a second passed between signing the link and serving it under parallel load); it passed alone and in the second full run. Suggest asserting `max-age=89x|900` or freezing time there.
- New: `Modules/Properties/tests/Feature/Assignments/AgentAssignmentTest.php` (11 incl. dataset), `AgentNarrowingTest.php` (11), `UnitIndexTest.php` (6), helpers `helpers.php`; `CrossTenantAccessTest` dataset `AgentAssignment` (5).
- **AC: a unit of org B is not addressable by an org A user via ID guessing:** `UnitIndexTest` › "never shows or reaches a unit of another organization guessed by ID, on the unit list or any unit or building page and action" (unit list rows, `?building=<B id>`, search, Livewire filter; unit edit by foreign id under own/foreign slug and numeric id; create/bulk/show/edit of the foreign building; `archiveUnit`/`restoreUnit`/`unassignAgent` and buildings-list `archive`/`restore` with foreign ids → 404; locked `unitId` and `propertyId` swapped → 419 with debug pinned off; member of both orgs gets Acme-only rows; foreign rows unchanged). Existing `UnitPagesTest` › "answers 404 for another organization's unit…" and `CrossTenantAccessTest` (`Unit`, `AgentAssignment`) still green.
- **AC: a narrowed agent sees only assigned buildings:** `AgentNarrowingTest` › "shows a narrowed agent only the assigned buildings, on every list and page" (buildings list active/archived/search/owner filter, building pages 200 vs 404, edit pages, status board incl. filter, unit pages, unit list incl. building filter options and guessed ids, owners list and owner pages, owner page's buildings); "answers 404 to a narrowed agent for other buildings' actions sent straight to the server" (owner snapshots replayed: render, `makeCover`, building form save, unit form save → 404; `archiveUnit`/`restoreUnit` → 404; writers throw `AuthorizationException` with status 404; nothing changed; own unit saves); "answers the narrowed agent's policy checks per building, with 404 outside the assignments"; "narrows the directories other modules show to the member"; "offers a narrowed agent only their buildings' owners on the building form, and refuses any other"; "lets an agent with no assignment see every building…"; "never narrows owners or administrators…"; "counts only the current organization's assignments"; "shuts the building page on a narrowed agent who is taken off it while it is open".
- **Assignment only by org-owner/org-admin, audited:** `AgentAssignmentTest` › "lets owners and administrators assign the organization's agents to a building and take them off, audited" (dataset owner/admin), "grants properties.assign_agents to owners and administrators only, and classes it for the central guards", "lets only owners and administrators assign agents, by policy and on the server" (7 roles incl. other org and stranger; agent replaying the owner's snapshot → 403; writer refuses), "assigns only members who are agents here, and each one once", "refuses to assign agents to an archived building, even one archived after the page loaded", "lists a building's assigned members, current members only…".
- **Suspended org read-only:** `AgentAssignmentTest` › "shows assignments read-only while the organization is suspended" (list shown, no controls, calls 403, writer refuses; narrowing still applies); `AgentNarrowingTest` › "keeps the narrowing while the organization is suspended"; `UnitIndexTest` › "shows the unit list read-only while the organization is suspended".
- **Impersonation:** `AgentAssignmentTest` › "never lets impersonating staff assign agents, in write mode too…"; `AgentNarrowingTest` › "narrows impersonating staff exactly like the agent they act as" (write mode); `UnitIndexTest` › "shows impersonating staff the unit list, read-only in a read-only session".
- **Membership removal:** `AgentAssignmentTest` › "drops a removed member's assignments with the membership, audited, so a returning member starts unassigned" (other org's assignments kept); "keeps the assignments when the removal rolls back, as they go in its transaction".
- **Sub: unit list has no N+1:** `UnitIndexTest` › "loads the unit list without a query per unit or building (no N+1)" (queries on `units|properties|agent_building` counted for 1 unit vs 61 units across 13 buildings incl. an archived one, for an owner and a narrowed agent; equal). Checked: removing `->with('property')` makes it fail (28 vs 4).
- Unit list behaviour: `UnitIndexTest` › "lists every unit across the buildings, 25 a page on the server, with filters and a debounced search kept in the URL" (pages 25/5, order, URL filters, Livewire search resets the page, clear filters), "opens the unit list for workspace roles only, with edit links only where the member may edit".
- Existing tests changed (none weakened): `WorkspaceNavigationTest` (sidebar gains `units` for owner/admin/agent), `ImpersonationGuardTest` › "classes the money, bank, … permissions as always blocked" (+`properties.assign_agents`), `OrganizationScopedModels` (+`AgentAssignment`).
- Not run: release/install drills (new module migration only; `InstallCommandTest` covers module migrations). No browser smoke: pages exercised through HTTP/Livewire tests only.

**Orchestrator review:** merged `main` (with P02-04) into the branch; resolved the sidebar test conflict by keeping both new items (`units`, `billing-transfers`). Fixed the timing flake the agent reported in `SignedMediaRouteTest` (max-age 899 vs 900 under load) by freezing time in that test. Re-ran `composer ci`: Pint passed, Larastan 0 errors, Pest 1,740 passed, 14,308 assertions.

**Decisions to confirm:**
- Orchestrator decision 1 (modules): everything lives in `Modules/Properties` (Livewire `UnitIndex` in the module). Core gained only the `App\Members\MemberRemoved` event (Core never imports the module; the module listens).
- Orchestrator decision 2 (cross-org lookups): none needed; everything is inside the organization context, no scope removed.
- Orchestrator decision 3 (soft deletes vs unique keys): `agent_building` has no soft deletes; unassigning deletes the link row (audited). Not one of AGENT § 3 rule 7's records (org, tenant, bill, payment, subscription data).
- Orchestrator decision 4 (plan gating): agent assignment is built ungated; BILLING § 1.2 limits it to Scale (follow-up for P06-01).
- **Agent with no assignments sees every building** (ARCH § 5 "`agent` visibility *can be* narrowed … recommended for larger agencies"; the plan row says "optional narrowing"). Narrowing is per agent: one assignment narrows that agent; taking them off their last building widens them again. The alternative reading (ARCH § 5's table "manage assigned buildings" → an unassigned agent sees nothing) would lock every agent of every existing organization out until assigned.
- **Who is narrowed:** members holding `agent` and neither `org-owner` nor `org-admin` (role-based, not permission-based, so suspension or read-only impersonation never turns an owner into a narrowed member). Only such members can be assigned; assignments of an agent later promoted are kept but ignored (shown as "sees every building").
- **Owners are narrowed too:** a narrowed agent sees only the owners of their buildings (list, owner page 404 otherwise, owner page lists only their buildings, building-form picker and the writer's owner check). Owners are not assigned themselves (P02-01a follow-up).
- **404, not 403,** for another building/unit/owner of a narrowed agent (`Response::denyAsNotFound()`), so a guessed slug or id behaves like another organization's.
- **New permission `properties.assign_agents`** rather than reusing `properties.portfolio` or `members.manage`: owners/admins hold it; it is **blocked while impersonating** (a member-access change, AGENT § 4 "member or role change") and dropped while suspended.
- Assignment only on **active** buildings (an archived building is read-only); unassigning on an archived building waits for a restore.
- Unique index `(organization_id, user_id, property_id)` instead of the documented `(user_id, property_id)` (same uniqueness, since a building has one organization; satisfies ARCH § 4); DB § 3 updated.
- Membership removal drops assignments through a Core event sent inside `Members::remove`'s transaction (atomic), rather than only ignoring non-members (non-members cannot open the workspace anyway; the building page also lists current members only).
- Unit list: units of archived buildings listed (marked, read-only); units of buildings removed by an import rollback and archived units not listed (archived units stay on their building's page). Search covers label and building name.

**Follow-ups:**
- P06-01: gate agent ↔ building assignment to the Scale plan (BILLING § 1.2): `PropertyPolicy::assignAgents` / the Agents section. Decide what happens to existing assignments on a downgrade (suggest: keep them, stop narrowing).
- P02-07: export on the units list (`UnitIndex`) and on the building's agents list ("assignments" in the P02-07 row); exports must use the same narrowed queries.
- P03 (Leasing, Billing, Tickets, Building Groups): narrow lists and policies through `Modules\Properties\Contracts\BuildingAccess` (agents' leases, bills, payments to confirm, tickets of their buildings); P02-06b's `tenants.manage` for agents can be narrowed the same way (P03-01a).
- ARCH § 5 ticket rule "tenant ↔ assigned agent": P03's ticket module can read "assigned agent" from `BuildingAccess`/assignments (consider a contract method listing a building's agents then).
- The owner portal (`/owner`) is untouched by narrowing.
- Status board still shows every unit of a building (no paging), as P02-02 noted.

**Next:** P02-06c (wave 5).

### P02-04 — Complete (2026-10-27): SaasBilling module, org-to-org billing transfers and BillingTransferService

**Delivered** ([BILLING_PAYMENTS.md § 7](../BILLING_PAYMENTS.md), [ARCHITECTURE.md § 5, § 6.1, § 7, § 8](../ARCHITECTURE.md), [DATABASE_SCHEMA.md § 1, § 7](../DATABASE_SCHEMA.md); `docs/plans/P02-P03-subtasks.md` P02-04):
- **Module `Modules/SaasBilling`** (`module:make`, trimmed: no sample controller, seeder, event provider or config; in `modules_statuses.json`). `SaasBillingServiceProvider` registers `BillingTransferRequestPolicy`, the Livewire namespace `saasbilling::`, the sidebar item "Billing transfers" (order 950, owners only) and the hourly expiry job (`Schedule::job`, `billing` queue, `20 * * * *`). It reads units only through `Modules\Properties\Contracts\UnitDirectory::countBillable()` (the module-boundary test passes).
- **Schema:** module migration `2026_11_05_040101_create_billing_transfer_requests_table.php`: `organization_id` (the offering organization = current payer; owns the row), `to_organization_id`, `subscription_id` (nullable, no FK until P06-01), `status` (`pending`, `completed`, `declined`, `cancelled`, `expired`), `plan_key` / `billable_units` / `monthly_amount_kobo` (recorded on acceptance), `initiated_by_user_id`, `responded_by_user_id`, `responded_at`, `cancelled_at`, `expires_at`, `completed_at` (left empty for the P06-06 payer switch); named indexes `(organization_id, status, expires_at)`, `(to_organization_id, status)`, `(status, expires_at)`. Module migration `2026_11_05_040102` adds `invitations.billing_transfer_id` (FK, restrict). Core migration `2026_11_05_040001_create_notifications_table.php` (Laravel database notifications).
- **Model and enum:** `Modules\SaasBilling\Models\BillingTransferRequest` (`BelongsToOrganization`, `MoneyCast` amount, `isOpen()`, `isPastExpiry()`; in `tests/Support/OrganizationScopedModels.php`, all `CrossTenantAccessTest` probes); `Enums\BillingTransferStatus` (`canMoveTo()`: only pending moves, once; maps to the invitation status). `App\Enums\InvitationKind::OrgBillingTransfer`.
- **`Transfers\BillingTransferService`, the only writer** of transfers and of `org_billing_transfer` invitations: `initiate` (one open offer per organization, organization row locked; transfer + invitation with the link's token hash, both 14 days), `accept` (receiving owner checked in their own context, then written inside the offering organization's context; refuses if the monthly amount differs from the one the screen showed; records plan, units and amount), `decline`, `cancel` (offering organization only), `expire` (system, past date only), `expireDue`. Each step locks the row, refuses any transition the state machine does not allow with `BillingTransferRefused` and nothing changed, keeps the invitation's status in step, is audited (log `billing_transfers`, the transfer as subject, both organization ids) and sends one notification set (mail + database, queued on `notifications`, after commit): `BillingTransferOffered` → each owner of the receiving organization; `BillingTransferAnswered` (accepted / declined / expired) → the owner who offered, or the offering organization's owners if they are no longer one; `BillingTransferWithdrawn` → the receiving owners. No payer field is written; no membership or role is granted.
- **Lookups without a scope bypass (decision 2):** `Transfers\IncomingTransfers` (the receiving side's inbox and accept screen) reads `id, organization_id` of rows whose `to_organization_id` is the current organization (from `OrganizationContext`), then loads each inside the offering organization's context. `Transfers\TransferLinks` opens `/billing-transfers/{token}` exactly as P01-04 does (organization by token hash, one column; then load in context); only a workspace member of the receiving organization gets anywhere (else 404, nothing recorded); first opening recorded; a pending offer past its date is expired there (through the service). `Transfers\TransferTargets` finds the receiving organization by web address (`goproperty.ng/o/{slug}`, bare slug, old slug) or an owner's email (one organization only); unknown, ambiguous, suspended and cancelled organizations all get the same message.
- **Amount:** `Transfers\MonthlyBills` = the plan chosen at signup (`signup_plan_key`, else `PlanCatalogue::bestFor(units)`) `priceFor(countBillable())`, before VAT (7.5% shown separately).
- **Pages (Livewire in the module):** `/o/{slug}/billing-transfers` (`Livewire\BillingTransfers`): the bill today, the offer form (10 attempts an hour per organization), offers made (Withdraw while open), offers received (the invite inbox, Review/Details). `/o/{slug}/billing-transfers/{transfer}` (`Livewire\BillingTransferReview`): "You are taking over the GoProperty subscription bill for {amount}/month from {date}." with plan/units breakdown, VAT note, "no access to their buildings", Accept / Decline (confirm dialogs); closed offers show their outcome. `Http\Controllers\OpenTransferLinkController` for the emailed link. Actions are `#[BlockedWhileImpersonating]`.
- **Core changes:** `InvitationAcceptance` (link lookup and acceptance), `InvitationPolicy::resend/revoke` and Settings → Members (`Members::invitations()`, resend/revoke lookups) handle kind `user` only, so a transfer link can never be accepted as a member invitation or revoked from Members. `organization.billing`'s description now names billing transfers. `WorkspaceNavigationTest` owner list gains `billing-transfers` (new item, not a weakened check).
- **Docs:** DATABASE_SCHEMA.md § 1 (`billing_transfer_requests` as built incl. how the receiving side reads it; `invitations` kind/`billing_transfer_id`/writers), § 7 (`notifications`); ARCHITECTURE.md § 5 (Billing transfers paragraph), § 6.1 (how billing transfers are built), § 7 (hourly expiry row), § 8 (SaasBilling built from P02-04); BILLING_PAYMENTS.md § 7 ("As built" note).

**Tests and evidence (local, MariaDB 11.4):**
- `composer ci` on the final commit: Pint passed, Larastan 0 errors, Pest **1,704 tests: 1,704 passed, 13,897 assertions**, 0 failed.
- New: `Modules/SaasBilling/tests/Feature/BillingTransferServiceTest.php` (16 tests + datasets = 29 cases), `BillingTransferPagesTest.php` (10 tests + datasets = 12 cases), helpers `helpers.php`; `CrossTenantAccessTest` dataset `BillingTransferRequest` (5 probes). The module's tests and `CrossTenantAccessTest` also pass with `APP_DEBUG=true` (with `tests/Feature/Members`: 147 tests, 993 assertions).
- **AC: invite, accept and decline recorded through `BillingTransferService`:** `BillingTransferServiceTest` › "records an offer, an acceptance and a decline through BillingTransferService" (row + invitation of kind `org_billing_transfer` with token hash and target; accept records responder, ₦24,000 = 2,400,000 kobo, 30 units, `starter`, `completed_at` null; decline; four audit entries with causers); "records a withdrawal by the offering organization"; `BillingTransferPagesTest` › "offers the bill from the billing transfers page", "shows the offer in the receiving inbox and accepts it on the accept screen, which states the amount", "declines on the accept screen, and refuses an amount that changed while the screen was open".
- **AC: invalid transitions rejected:** "rejects invalid transitions and changes nothing" (11 cases: accept after decline, decline after accept, accept twice, accept after withdrawal, withdraw after accept/decline, expire after accept, accept after expiry, accept/decline/withdraw once past the date but not yet recorded; transfer and invitation rows byte-identical, no audit, no notification); "refuses to expire an offer before its 14 days"; "refuses an answer from the wrong organization and a withdrawal by the receiver"; "refuses an amount that changed since the accept screen showed it"; "refuses an offer to itself, to an organization that cannot take it, and a second open offer".
- **Sub: only org-owners initiate or respond:** "lets only org-owners offer and answer" (admin, agent, property owner, tenant); `BillingTransferPagesTest` › "shows the pages and the sidebar item to owners only" (403, no sidebar item).
- **Sub: expires after 14 days (time travel through the scheduled job):** "expires unanswered offers after 14 days through the scheduled job" (the `billing-transfers-expire` CallbackEvent run at 14 days − 1 minute: still pending; +2 minutes: expired, invitation expired, initiator told once; answered offer untouched; re-run changes nothing); `BillingTransferPagesTest` › "records the expiry when the link is opened after 14 days".
- **Sub: blocked during impersonation:** `BillingTransferPagesTest` › "blocks billing transfers while GoProperty staff impersonate" (read-only and write mode: pages, link and accept/decline/offer calls 403, `impersonation.blocked` audited, nothing changed).
- **Sub: no payer field written:** "writes no payer field and gives no access when an offer is accepted" (no `payer_organization_id` column, no `subscriptions` table, `completed_at` null, both organization rows unchanged, `organization_user` and `model_has_roles` unchanged, receiving owner gets 404 on the offering organization's buildings).
- **Cross-org probes:** `CrossTenantAccessTest` (`BillingTransferRequest`); `BillingTransferPagesTest` › "answers 404 for offers of, or to, another organization" (other org's owner, the offering org's own id, foreign workspace, withdraw with a foreign id, accept-screen snapshot replayed in another workspace); "opens the emailed link in the receiving workspace, and nowhere else" (guest → login; stranger, offering owner, unknown token → 404; `/invitations/{token}` → 404); "keeps the transfer link out of Settings → Members"; "keeps the incoming list to the offers made to this organization".
- **Notifications once per transition:** "notifies once per transition, by email and in the app" (offer → each of two receiving owners once; accept/decline → initiator once each; withdraw → receiving owners once; channels exactly mail + database); "tells the organization's owners when the owner who offered is no longer one"; "stores the in-app copy of a notification" (row in `notifications`, link to the workspace page, no token).
- Others: "keeps billing transfers read-only while the organization is suspended"; "works out the bill from the plan catalogue and the billable units"; "finds organizations on GoProperty by web address or an owner's email, and nothing else".
- Not run: release/install drills (no installer change; `InstallCommandTest` runs every module migration). No browser smoke: pages exercised through HTTP/Livewire tests only.

**Orchestrator review:** merged `main` (`cf6c1dd`) into the branch with no conflicts, re-ran `composer ci`: Pint passed, Larastan 0 errors, Pest 1,707 passed, 13,900 assertions. Read `IncomingTransfers`: the receiving side reads only `id` and `organization_id` of rows addressed to the current organization (raw query, no scope removal), then loads each row inside the offering organization's context — the P01-04 lookup pattern.

**Decisions to confirm:**
- Orchestrator decision 1 (modules): SaasBilling is a module with its Livewire components; ARCHITECTURE.md § 8 marks it built.
- Orchestrator decision 2 (cross-org lookups): the link uses P01-04's token-hash pattern; the receiving side's inbox reads only `id, organization_id` by `to_organization_id = current organization` then loads in the offering organization's context (`IncomingTransfers`). No `withoutGlobalScopes()`.
- Orchestrator decision 4 (plan gating): BILLING § 1.2 gates nothing built here (billing transfers are in every plan).
- Orchestrator decision 5 (P02-04 ahead of P06): `subscription_id` nullable without FK; statuses = BILLING § 7 (DB § 1 updated: `accepted` folded into `completed`, `expired` added); acceptance records only (no payer write, no access to buildings, no membership); only organizations already on GoProperty, by web address or an owner's email.
- **The row belongs to the offering organization** (`organization_id`, replacing DB § 1's `from_organization_id`): it is the current payer, its bill moves and it may withdraw. The receiving side reads it as above and writes only through the service.
- **No new permission:** `organization.billing` (org-owner only; blocked while impersonating) already covers billing transfers in its description and in PLATFORM_CONSOLE.md § 6. It stays allowed while suspended (paying), but offer/withdraw/accept/decline are writes, so a suspended organization's owners only view (central guard). Impersonating staff cannot open the page at all.
- **Who initiates:** any organization's owner may offer its own bill (ARCH § 6.1 "a property owner invites an agency, or vice versa"); the receiver always takes the bill over (BILLING § 7 rule 2).
- **`completed_at` stays empty** until P06-06 actually moves the payer (DB § 1's definition); status `completed` is set on acceptance (BILLING § 7).
- **Amount** = the offering organization's plan price for its billable units today, before VAT, monthly; "from {date}" = the day of acceptance. Accepting passes the amount shown and is refused if it changed. The amount is also in the offer email and audit entry.
- **One open offer per organization**; a pending offer past its date does not block a new one.
- **Expiry** is recorded by the hourly job or when the link is opened; accept/decline/withdraw past the date are refused without recording it (the job does).
- **Recipients:** offer and withdrawal → all owners (with email) of the receiving organization, one shared link; answers and expiry → the owner who offered (else the offering organization's owners). Withdrawal does not notify the offering side (it acted). Mail + database; no in-app list UI yet.
- **Target lookup:** owner email must be an `org-owner` of exactly one organization; unknown/ambiguous/suspended/cancelled all answer the same message; 10 attempts an hour per organization to limit probing.
- **Core:** member invitation code now ignores kinds other than `user` (otherwise a transfer link could create a user with no email through `/invitations/{token}`).

**Contradictions found (resolved by the decisions above):** DB § 1 (`from_organization_id`, `accepted`, no `expired`) vs BILLING § 7; BILLING § 7 (accept switches the payer in the same transaction) vs decision 5 (P06-06); DB § 1 said only Core writes `invitations` (now also BillingTransferService for its kind); plan row "property owner invites agency" vs ARCH § 6.1 "or vice versa".

**Follow-ups:**
- P06-01: FK on `billing_transfer_requests.subscription_id` and set it on offer; `MonthlyBills` reads the plans table/subscription instead of `PlanCatalogue` + `signup_plan_key`; annual plans.
- P06-06: the payer switch on acceptance (`subscriptions.payer_organization_id`, payment-method re-enrolment, `completed_at`), applied also to transfers completed before it ships (`status = completed`, `completed_at` null); reversal when the current payer is not the subscription's organization.
- Platform console: pending transfers for support (BILLING § 7 rule 4).
- Inviting an organization not yet on GoProperty (decision 5 follow-up).
- An in-app notifications list (rows are stored now); phone-only owners get no offer notification (`OrganizationOwners::withEmail`).
- `platform_covered` offering organizations: decide what handing over a platform-paid bill means (now shown at the plan price).

**Next:** P02 exit tasks (P02-06c, P02-07) after wave 4 merges.

### P02-06b — Complete (2026-10-27): Tenants module (tenant profiles, policy, permissions) and the tenants import target

The parent P02-06 stays **In progress** until P02-06c is done.

**Delivered** ([DATABASE_SCHEMA.md § 1](../DATABASE_SCHEMA.md) `tenant_profiles`, § 8A, [DATA_IMPORT.md](../DATA_IMPORT.md) § 1 row 4 and "Re-running and updates", [ARCHITECTURE.md § 5, § 6.12, § 8](../ARCHITECTURE.md); `docs/plans/P02-P03-subtasks.md` P02-06b):
- **Module `Modules/Tenants`** (`module:make`, trimmed: no controller, routes, views, config, seeder or event provider, since there are no pages until P03-01a; in `modules_statuses.json`). `TenantsServiceProvider` registers `TenantProfilePolicy` and the import target into `Modules\Import\Contracts\ImportTargets` (`callAfterResolving`, through the contract only; the module-boundary test passes).
- **Schema:** module migration `2026_11_03_070101_create_tenant_profiles_table.php`: DB § 1 columns, `user_id` nullable FK (null on delete), `emergency_contact` json `{name, phone, relationship}`, `kyc_status` default `unverified`, `id_type` default `none`, `id_value` text (encrypted), provenance `import_batch_id` (no FK: `import_batches` is the Import module's) / `external_ref`, soft deletes. Unique `(organization_id, phone)` and `(organization_id, external_ref)` (archived rows included); indexes `(organization_id, import_batch_id)`, `(organization_id, deleted_at, last_name, first_name)`, `(organization_id, email)`.
- **Model and enums:** `Modules\Tenants\Models\TenantProfile` (`BelongsToOrganization`, `SoftDeletes`, `id_value` encrypted and hidden, `maskedId()` = "NIN ···2109", `fullName()`, `user()`; on every save the phone is normalised to E.164 through `App\Support\PhoneNumber` (an invalid one throws) and the email lower-cased), `TenantProfileFactory` (`withNin()`), `KycStatus` (unverified, pending, verified), `TenantIdType` (nin, passport, drivers_licence, voters_card, none; `fromInput()` reads labels and common spellings such as "Intl passport", "Drivers license", "PVC"; `normalise()`; `accepts()` per format: NIN 11 digits, passport letter + 8 digits, driver's licence 3 letters + 7–12 letters/digits, voter's card 19 letters/digits; `formatHint()`). `TenantProfile` added to `tests/Support/OrganizationScopedModels.php` (all `CrossTenantAccessTest` probes).
- **Permissions and policy:** new `tenants.view` and `tenants.manage` (org-owner, org-admin, agent), classed in `allowedWhileSuspended()` (view true, manage false) and `blockedWhileImpersonating()` (both false; archiving is blocked as a `delete` ability). `TenantProfilePolicy`: viewAny/view (`tenants.view`), create/update/delete (archive)/restore (`tenants.manage`), record must be in the current organization; update/delete only active, restore only archived. Seeding the role map is enough on existing installs (`InstallSeeder`).
- **Import target** `Modules\Tenants\Import\TenantsImportTarget` (key `tenants`, label "Tenants", order 40: after owners/properties/units, before leases). Columns: Your ID, First name*, Last name, Phone*, Email, Address, Occupation, Next of kin, Next of kin phone, Next of kin relationship, ID type (dropdown), ID number, with aliases for agencies' own headers ("Tenant name", "Mobile", "E-mail", "NOK", "Means of ID", "NIN", …). A full name under First name is split when Last name is empty (last word = last name).
  - **Matching:** `external_ref` first, then phone (E.164), archived tenants included (decision 3): an archived match is restored (dry-run warning "This tenant was archived. Importing brings them back.", action `restore`, audited `tenant.restored` under log `tenants` with the batch id and row; carries the batch's id so a rollback archives it again). Created tenants carry the batch's id; updated ones keep theirs. Matching by ID with a new phone updates the phone, unless the number belongs to another tenant (error). Matching by phone with a different ID: warning, the ID changes.
  - **Exact per-row errors** (row, column, message): missing phone; bad phone (`"0803 12" is not a phone number. Enter it like 0803 123 4567 or +234 803 123 4567.`); duplicate phone in the file (`This phone number is already on row 2. …`); duplicate ID in the file; phone of another tenant; missing first name; missing last name; bad email; bad next-of-kin phone; unknown ID type; number without type; type without number; bad ID format (per type, never echoing the number); markup (`<`/`>`) and over-long text.
  - **Updates:** only cells the file fills in change; an empty cell keeps what is on file. KYC status is never imported (new tenants are `unverified`); a changed ID number on a checked tenant resets it to `unverified` (dry-run warning). Nobody is contacted.
  - **Rollback** soft deletes the batch's created/restored tenants; **usedSince** is true once one of them has portal access (`user_id` set).
  - The preview never contains the ID number (the report is plain JSON on the batch).
  - Later sheets: the dry run remembers each valid row under `tenants` and the E.164 phone (`ImportRun::remember`) for P03-13a's leases.
- **Template:** the downloadable `.xlsx` template now has a Tenants sheet (header, example row, ID type dropdown on column K) and its instructions; a downloaded template imports its example tenant.
- **Docs:** DATABASE_SCHEMA.md § 1 `tenant_profiles` as built; DATA_IMPORT.md § 1 row 4 (columns as built) and "Re-running and updates" (archived matches restored, empty cells keep values, ID change unverifies); ARCHITECTURE.md § 5 ("Tenants" paragraph), § 6.2 (see contradiction below), § 6.12 ("Targets built so far"), § 8 (Tenants built).

**Tests and evidence (local, MariaDB 11.4):**
- `composer ci` on the final commit: Pint passed, Larastan 0 errors, Pest **1,593 tests: 1,593 passed, 12,943 assertions**, 0 failed. The Tenants and Import module tests and `CrossTenantAccessTest` also pass with `APP_DEBUG=true`.
- New: `Modules/Tenants/tests/Feature/TenantsImportTest.php` (16 tests + a 12-case ID dataset = 28 cases), `TenantProfileTest.php` (12 incl. role dataset), helpers `helpers.php`. All imports run through the real engine (`ImportBatches::upload` → `dryRun` → `commit` (sync queue, `CommitImportSheet`) → `rollback`).
- **AC: exact per-row errors for a missing or bad phone:** `TenantsImportTest` › "reports a missing or bad phone, and every other bad cell, with its exact row, column and message" (11 issues asserted as [row, header, letter, severity, message]; blank row keeps numbering; batch `dry_run_failed`, commit refused, nothing saved); "lists the bad phones in the downloadable problems workbook, next to their rows".
- **AC: re-import by phone updates instead of duplicating:** "updates a tenant matched by phone when re-imported, instead of adding them twice" (phones written another way, no IDs: 5 updates + 1 create, still 6 tenants after a third run; empty email kept; updated tenants keep the creating batch); "matches by the agency's ID first, so a tenant's new phone number updates them"; "refuses a row whose ID is one tenant's and whose phone is another's, and warns when a tenant's ID changes".
- **Restore of an archived match:** "restores an archived tenant matched by phone, audited, and a rollback archives them again" (dry run counts restore 1 + warning; restored row carries the batch; `tenant.restored` audit; rollback archives created + restored, keeps the updated one with its changes; importing again restores both).
- **Dry run writes nothing:** "writes nothing during a dry run" (every SQL write during the dry run recorded: none outside `import_batches` and the audit log; `tenant_profiles` identical before and after).
- **Cross-org probe on `TenantProfile`:** `CrossTenantAccessTest` dataset `TenantProfile` (5 probes); `TenantsImportTest` › "never matches another organization's tenant with the same phone or ID" (another org's active and archived tenants with the same phone/ID: 3 creates, 0 warnings, their rows untouched, rollback never touches them, the same phone exists once in each org); `TenantProfileTest` › "refuses everything to a member of another organization and to strangers…", "allows one tenant per phone number in an organization, archived tenants included, but the same number in another".
- **`id_value` encrypted at rest:** `TenantsImportTest` › "keeps the ID number encrypted at rest, out of arrays and out of the dry-run report"; `TenantProfileTest` › "stores the ID number encrypted at rest and shows it only masked".
- Others: "creates the previewed tenants on commit, phones in E.164, provenance on each, KYC unverified, nobody contacted" (Notification/Mail fakes assert nothing sent); "maps an agency's own tenant list by its headers" (CSV with "Tenant name", "Mobile", "NOK", "Means of ID", "NIN"); "refuses a rollback once an imported tenant has been given portal access"; "makes a checked tenant unverified again when an import changes their ID number"; "checks each kind of ID number" (12 cases); "refuses markup and over-long text in tenant cells"; "offers a Tenants sheet in the import template, which maps itself when filled in"; policy matrix for the 5 roles; grants and guard classing; suspended organization read-only; phone/email normalised on save.
- Existing tests changed (none weakened): `ImportFilesTest` › "offers a template with instructions, a sheet per target and dropdowns for fixed values" (the real Tenants target now joins the fixture targets: sheets `Instructions, Contacts, Notes, Tenants`; the hidden Lists sheet moved to index 4 and its first column is padded to the longest list); `SuspendedOrganizationTest` kept list (+`tenants.view`); `OrganizationScopedModels` (+`TenantProfile`).
- Not run: release build / install drills (`InstallCommandTest` covers module migrations; the new migration is the newest module migration).

**Orchestrator review:** merged `main` (with P02-02) into the branch; resolved three conflicts by keeping both sides (ARCHITECTURE.md § 5 Units and Tenants paragraphs; permission labels for `properties.portfolio` and both tenant permissions; `Unit` and `TenantProfile` both join the cross-tenant probes). Re-ran `composer ci`: Pint passed, Larastan 0 errors, Pest 1,658 passed, 13,483 assertions.

**Decisions to confirm:**
- Orchestrator decision 1 (modules): Tenants is a module; no Livewire yet (pages are P03-01a); ARCHITECTURE.md § 8 marks it built.
- Orchestrator decision 2 (cross-org lookups): none needed; everything runs inside the batch's organization context, no scope removed (only `withTrashed()`).
- Orchestrator decision 3 (soft deletes vs unique keys): matching by `external_ref` and phone includes archived tenants, which are restored and audited (`tenant.restored`); both unique indexes stay as documented.
- Orchestrator decision 4 (plan gating): BILLING § 1.2 gates nothing built here (tenant import and tenant records are in every plan).
- **Agents get `tenants.manage`** (not only owners/admins as for owner profiles): ARCH § 5 gives agents leases, and a lease needs its tenant. P03-01a can narrow it (e.g. by building assignment, P02-03).
- **Last name is required** (DB § 1 does not mark it nullable); a full name under First name is split on the last space instead of adding a separate "Full name" column.
- **Empty cells keep what is on file** on re-import (the fixture target overwrote everything); "None" as ID type removes the ID.
- **ID types** `nin`, `passport`, `drivers_licence`, `voters_card`, `none` (DB § 1 named no values) with the formats above; numbers kept in capitals without spaces/dashes.
- **KYC:** never imported; a changed ID number on a `pending`/`verified` tenant resets it to `unverified`.
- **Audit:** only restores are audited per tenant (decision 3); creates and updates are covered by the batch's `import.committed` counts (P02-06a's choice, to keep 10,000-row commits within the job budget).
- **`usedSince`** = portal access (`user_id` set) until leases, bills and payments exist (P03-13a).
- **Contradiction, ARCHITECTURE.md § 6.2** said "the tenant profile is global", while DB § 1 and the task give `tenant_profiles` an `organization_id` and unique `(organization_id, phone)`, and § 4 says portal reads go through `tenant_profiles.user_id = me`. Built per organization; § 6.2 now says the user account is global and each organization keeps its own profile linked by `user_id`.
- `PhoneNumber::normalize` refuses "+234 (0) 803…" (the "(0)" trunk prefix); the target reports it as a bad phone. Core's helper is unchanged; consider accepting it there.

**Follow-ups:**
- P03-01a: tenant pages and an audited writer (`Modules\Tenants\…`, log `tenants`, which the import's `tenant.restored` already uses), routes and sidebar item; a `Contracts` interface for other modules (Leasing will look tenants up by phone/id).
- P03-13a: extend `TenantsImportTarget::usedSince` to leases, bills and payments through those modules' contracts; the leases sheet can rely on `ImportRun::knows('tenants', <E.164 phone>)`.
- P03-01b: `tenant_profiles.user_id` linking on invitation acceptance; consider a unique `(organization_id, user_id)`.
- P02-06c: owners/properties/units targets (orders below 40), the 4-sheet template (now 3 real sheets are missing; Tenants is in).
- DATA_IMPORT § 2.3 warning "tenant phone already exists in another lease" needs leases (P03-13a).

**Next:** P02-06c once P02-02 has merged (wave 5).

### P02-02 — Complete (2026-10-27): units (schema, bulk create, status board and occupancy counts on the building page, UnitPolicy, UnitDirectory contract, UnitCreated/UnitDeleted events); P02-02 complete

**Delivered** ([DATABASE_SCHEMA.md § 3](../DATABASE_SCHEMA.md) `units`, [ARCHITECTURE.md § 5](../ARCHITECTURE.md) "Units", § 6.2 "Built so far"; `docs/plans/P02-P03-subtasks.md` P02-02):
- **Schema:** module migration `Modules/Properties/database/migrations/2026_11_04_000101_create_units_table.php`: DB § 3 columns, `property_id` FK to `properties` (restrict), `base_rent_kobo` / `service_charge_kobo` unsigned big integers (default 0), `status` (default `vacant`), import provenance `import_batch_id` (no FK) / `external_ref`, soft deletes. Unique `(property_id, unit_label)` and `(organization_id, external_ref)`; indexes `(organization_id, import_batch_id)`, `(organization_id, property_id, status)`, `(organization_id, status)`.
- **Model and enums:** `Modules\Properties\Models\Unit` (`BelongsToOrganization`, `SoftDeletes`, rent and service charge through `App\Casts\MoneyCast`, `property()`), `UnitType` (apartment, self_contain, shop, office), `UnitStatus` (vacant, reserved, occupied, under_maintenance, unavailable; `manual()` = the three people set, `heldByLease()` = occupied/reserved), `UnitFactory`; `Property::units()`. `Unit` added to `tests/Support/OrganizationScopedModels.php` (all 5 `CrossTenantAccessTest` probes).
- **Writers (audited, log `properties`):** `Modules\Properties\Units\Units` (create, update, archive, restore, setStatus; each user action checks `UnitPolicy`; the building row is locked while units are added; a label is unique in its building ignoring case; adding a label of an archived unit restores that unit with the new details, audited `unit.restored`; archiving is a soft delete, refused while the unit is occupied or reserved; updates audit the changed columns and the status change). `Units\CreateUnitsInBulk` (label pattern with `{n}` once and a first number, 1–100 units, same details for all; one transaction; every label checked first and a label already in the building stops the whole add with one error naming each label (key `labels`) plus one per label (`labels.<position>`); a label taken by another request meanwhile hits the unique index and rolls back everything). `Units\UnitDetails` (shared server-side checks; naira as typed → `Money`, ≤ ₦10bn, at most 2 decimals; manual statuses only; an occupied/reserved unit's status is left to its lease).
- **Building archive:** `Buildings\Properties::archive` now refuses a building with an occupied or reserved unit (P02-01b follow-up); its units are left as they are and come back with it on restore. The list and the building page show the refusal.
- **Policy:** `UnitPolicy` on the existing building permissions (no new permission): viewAny/view `properties.view`; update `properties.manage` (unit of an active building); create(building)/delete(archive)/restore `properties.portfolio`; nothing is added to or changed in an archived building; create and restore refused while the organization is `restricted`. `properties.portfolio`'s description now says "buildings and units".
- **Pages:** building page (`PropertyShow`): "Units" section with occupancy ("6 of 10 units occupied (60%)"), a count per status that filters the status board (`?status=`), the status board (tile per unit: label, status badge, type, bedrooms, yearly rent; edit/archive), archived units on request with Restore; "Add unit" / "Add several units". New `UnitForm` (`/o/{slug}/buildings/{building}/units/create` and `/units/{id}/edit`, archive on the edit page; the unit must be the building's, else 404) and `UnitBulkForm` (`/units/bulk`, live preview "Adds 10 units: Flat 1 to Flat 10"). Buildings list rows show "10 units · 6 occupied" (withCount, same query).
- **Contract and events:** `Modules\Properties\Contracts\UnitDirectory` (`find`, `findMany` → `UnitSummary` with `Money` amounts; `countsForProperty`, `counts` → `UnitCounts`; `countBillable` = BILLING § 1.4; `setStatus(id, status, reason)` audited `unit.status_changed`; `lock(id)` = `SELECT … FOR UPDATE`, refused outside a transaction), bound to `Units\UnitDirectoryService`. `Modules\Properties\Events\UnitCreated` (`restored` flag) and `UnitDeleted`, `ShouldDispatchAfterCommit`, plain ids.
- **Docs:** DATABASE_SCHEMA.md § 3 `units` as built; ARCHITECTURE.md § 5 (Units paragraph), § 6.2 (statuses include `reserved`; units, bulk add, archive semantics, UnitDirectory, events; building archive rule).

**Tests and evidence (local, MariaDB 11.4):**
- `composer ci` on the final commit: Pint passed, Larastan 0 errors, Pest **1,554 tests: 1,554 passed, 12,890 assertions** (was 1,489; 65 new). The Properties module tests and `CrossTenantAccessTest` also pass with `APP_DEBUG=true` (201 tests, 1,350 assertions).
- New: `Modules/Properties/tests/Feature/Units/UnitBulkCreateTest.php` (19 incl. datasets), `UnitPagesTest.php` (23), `UnitRolesTest.php` (12), `UnitDirectoryTest.php` (6), helpers `helpers.php`; `CrossTenantAccessTest` dataset `Unit` (5).
- **AC: creating 10 units in one bulk action works:** `UnitBulkCreateTest` › "creates 10 units in one bulk action from the page, with the same details, audited, and sends UnitCreated for each" (Flat 1…Flat 10, 10 inserts, all one transaction level deeper than the request, 10 audit entries, 10 events after commit, building page "0 of 10 units occupied"); "rolls back all of them with a clear error naming each label the building already has" (Flat 7, flat 9 pre-existing → "Flat 7, Flat 9 already exist in Palm Court. Change the first number or the pattern: no unit was added." plus one line per label; nothing added, no audit, no event); "rolls back the units already added when a label is taken during the add, in one transaction" (a row inserted mid-add: unique index stops it at Flat 9, Flat 1–8 rolled back, error names Flat 9, no event); pattern/count/amount validation dataset (13); "brings back an archived unit whose label is added again…".
- **AC: unit statuses drive occupancy counts on the building page:** `UnitPagesTest` › "drives the occupancy counts on the building page from the units' statuses" (10 vacant → 6 occupied + 1 reserved via `UnitDirectory::setStatus`, 1 under maintenance + 1 unavailable via the edit page → "6 of 10 units occupied (60%)" and each status count; board filter; a lease ending and an archive → "5 of 9 units occupied (55%)"; other buildings' and organizations' units never counted; list shows "9 units · 5 occupied").
- **Sub: duplicate `unit_label` in one building gives a clear error:** above, plus `UnitPagesTest` › "gives a clear error for a label the building already has, and accepts it in another building" (case-insensitive) and "edits a unit, audits what changed, and keeps labels unique in the building" (taken label; archived unit's label → "restore it instead").
- **Sub: rent fields use `MoneyCast`:** `UnitPagesTest` › "keeps rent and service charge as integer kobo through MoneyCast" (₦2,500,000 → 250,000,000 kobo raw; ₦250,000.50 → 25,000,050; `Money` instances; casts are `MoneyCast`; assigning an int or string throws; edit page shows naira; empty service charge = zero).
- **Cross-org probes / ID guessing 404:** `CrossTenantAccessTest` dataset `Unit`; `UnitPagesTest` › "answers 404 for another organization's unit, or another building's, guessed by ID on every page and action" (edit pages by foreign id, foreign building's create/bulk/edit, another building's unit, archiveUnit/restoreUnit with foreign ids → 404; locked `unitId`/`propertyId` swapped → 419 with debug pinned off; member of both orgs gets 404 inside Acme; foreign rows unchanged); `UnitDirectoryTest` › "finds the current organization's live units only".
- **Role rules:** `UnitRolesTest` › policy matrix (5 roles × 9 abilities incl. archived building), other-org members and strangers refused, pages per role, agent's add/bulk/archive/restore sent to the server refused while the agent's edit saves.
- **Suspended org read-only:** `UnitRolesTest` › "shows units read-only while the organization is suspended" (board and counts visible, no buttons, create/bulk/edit 403, save/archive/restore/bulk calls 403, nothing changed). Restricted: "adds and restores no unit while the organization is restricted, but keeps editing and archiving".
- **Impersonation:** `UnitRolesTest` › "lets impersonating staff in write mode add and edit units, audited with both identities, but never archive one", "… read-only mode open units but change nothing".
- **Archived building refuses new units:** `UnitPagesTest` › "refuses new units in an archived building, and keeps its units read-only" (create/bulk/edit pages 403, no buttons, writers throw `AuthorizationException`; a building archived after the page loaded it is refused under the row lock with "Palm Court is archived. Restore it before adding units."); "refuses to archive a building while a lease holds one of its units".
- Others: "leaves an occupied or reserved unit's status to its lease, and never archives it"; "archives a unit as a soft delete, so it leaves the counts and the bill, and restores it, all audited" (UnitDeleted/UnitCreated(restored)); `UnitDirectoryTest` (counts per building/org, `countBillable` excludes archived units and archived buildings but counts `unavailable`; setStatus audited with reason, unknown status/unit refused; `lock` inside a transaction only, `FOR UPDATE` in the SQL; no context throws).
- Not run: release/install drills (no installer change; `InstallCommandTest` › "runs every module's migrations…" passes with `units`). No browser smoke: pages exercised through HTTP/Livewire tests only.

**Orchestrator review:** merged `main` (with P02-06a) into the branch with no conflicts, refreshed `vendor/`, re-ran `composer ci`: Pint passed, Larastan 0 errors, Pest 1,613 passed, 13,261 assertions.

**Decisions to confirm:**
- Orchestrator decision 1 (modules): units live in `Modules/Properties`, Livewire components in the module.
- Orchestrator decision 3 (soft deletes vs unique keys): `(property_id, unit_label)` stays unique incl. soft-deleted rows; adding (one or in bulk) a label of an archived unit restores that unit with the new details, audited `unit.restored`, and sends `UnitCreated` with `restored = true`. Renaming a unit to an archived unit's label is refused with "restore it instead".
- Orchestrator decision 4 (plan gating): BILLING § 1.2 gates nothing built here (units are in every plan). The bulk form says each unit counts towards the bill; the plan/price effect is not shown (P06).
- **Unit archive = soft delete, not a status** (buildings use a status). The reason is the same one P02-01b followed, applied to units: BILLING § 1.4 meters "units that are not soft-deleted and whose property is not archived", and counts `unavailable` units on purpose; an `archived` unit status would either still be billed (pointless) or change the metering rule (a money change the docs do not sanction). DB § 3's status list has no `archived`. Soft deletes are also what import rollback uses (as for owners). Archiving is refused while the unit is occupied or reserved.
- **Permissions reused, none added:** units use `properties.view` / `properties.manage` / `properties.portfolio`; adding, archiving and restoring units is `properties.portfolio` (org-owner, org-admin) because each unit is billed, as P02-01b decided for buildings; agents edit units (details and manual status). `UnitPolicy::delete` is blocked during impersonation by the central guard (a `delete` ability); adding and editing are allowed in write mode.
- **Restricted organizations** add and restore no units (ONBOARDING § 5.5 "no new … units"); editing and archiving continue.
- **Who sets which status:** people choose `vacant`, `under_maintenance`, `unavailable`; `occupied` and `reserved` only through `UnitDirectory::setStatus` (Leasing, P03-03a) or an import of leases (DATA_IMPORT § 1 "status … derived from leases"). An occupied/reserved unit's status cannot be changed on the form.
- **Rent and service charge are yearly** asking amounts (labelled "per year"); base rent required (₦0 allowed), service charge optional (0 = none). DB § 3 does not say the period; leases keep their own amount and cycle.
- **Occupancy = occupied ÷ all units** (not soft-deleted), rounded down; reserved, under maintenance and unavailable are shown separately.
- **Archiving a building** is refused while one of its units is occupied or reserved; its other units are left as they are (out of the bill through the building's status) and come back with it.
- `size_sqm` is whole square metres (integer); `floor` is short text ("Ground", "2").
- `UnitCreated`/`UnitDeleted` are sent once per unit after commit (10 events for a 10-unit add).

**Follow-ups:**
- P02-03: narrow `UnitPolicy::view/update` and the building page's board to assigned buildings for narrowed agents (the policy notes where); the unit index (pagination, filters, search) is P02-03's; agent assignment is Scale-only (BILLING § 1.2) — gate in P06-01.
- P02-04: unit count for the accept screen via `UnitDirectory::countBillable()`.
- P02-06c: `UnitsImportTarget` matches by `external_ref` incl. soft-deleted rows and by property + label (case-insensitive; the unique index already treats it so); reuse `Units\Units::place()` or the same restore rule; status left blank = vacant (leases set it).
- P03-03a: `LeaseService` calls `UnitDirectory::lock()` then `setStatus()` inside its transaction; listener for lease events sets `occupied`/`reserved`/`vacant`.
- P06: listen to `UnitCreated`/`UnitDeleted` for `subscription_unit_events`; restoring or archiving a **building** sends no unit event (its units leave/return to the count through the building's status) — P06 should either count on the billing day only (BILLING § 1.4) or Properties should add a building event.
- P02-07: export on the units list (P02-03's index).
- Status board shows every unit of a building (no paging); fine for hundreds of units, revisit for very large estates.

**Next:** P02 wave 4 (P02-03 ‖ P02-04) once wave 3 has merged.

### P02-06a — Complete (2026-10-27): import engine core (Modules/Import): targets contract and registry, streaming reader, mapping with saved mappings, template, dry run and error workbook, queued commit per sheet, 7-day rollback, 30-day upload purge

The parent P02-06 stays **In progress** until P02-06b and P02-06c are done.

**Delivered** ([DATA_IMPORT.md](../DATA_IMPORT.md) § 1–5, [DATABASE_SCHEMA.md § 8A](../DATABASE_SCHEMA.md), [ARCHITECTURE.md § 6.12](../ARCHITECTURE.md); `docs/plans/P02-P03-subtasks.md` P02-06a):
- **Package:** `spatie/simple-excel` **`~3.11.0`** (Laravel 13 / PHP 8.4 compatible; added with `--minimal-changes`, nothing else in the lock moved). Its reader, OpenSpout 4.32, was already installed through Filament. Writing (template, error workbook) uses simple-excel; reading uses OpenSpout's readers directly, because simple-excel cannot keep empty CSV rows, and exact row numbers need them.
- **Module `Modules/Import`** (`module:make`, trimmed like Properties; in `modules_statuses.json`). `ImportServiceProvider` binds the target registry (singleton), the policy, the Livewire namespace `import::`, the sidebar item "Import" (order 700, `imports.view`) and the daily purge (`Schedule::job`, registered when the scheduler is built, so the queue tick stays last).
- **Contract other modules use** (`Modules\Import\Contracts`): `ImportTarget` (key, label, order, instructions, columns, `validate`, `simulate`, `upsert` by `external_ref` or natural key, `rollback`, `usedSince`), `ImportTargets` (registry: `register`, `all` in commit order, `find`), and value objects `ImportColumn` (label, required, aliases, example, help, options; `ImportColumn::externalRef()`), `ImportRow` (cells by column key with the spreadsheet row number; `text`, `date` (DD/MM/YYYY, D/M/YYYY, DD-MM-YYYY, YYYY-MM-DD, Excel date cells), `amountKobo` (₦/N/NGN and commas, through `App\Support\Money`, never rounding kobo), `integer`, `choice`), `ImportRun` (dry run or commit; remembers the keys a dry run would create, so later rows and sheets can refer to them), `RowIssue`/`IssueSeverity`, `RowPlan`, `UpsertResult`, `ImportAction` (create, update, restore, skip), `InvalidCellValue`. Targets register from their own provider; Import never reads another module's tables (the boundary test passes).
- **Schema:** `import_batches` and `import_column_mappings` (module migrations `2026_11_03_060001/2`), DB § 8A updated as built (`file_type`, `file_name`, `dry_run_at`, `rows_total`/`rows_done`, `commit_cursor`, `failure_reason`, `file_purged_at` added; `kind` = `workbook` or a target key). Models `ImportBatch` (`BelongsToOrganization`, `HasMedia` via `HasOrganizationMedia`) and `ImportColumnMapping`, both in `tests/Support/OrganizationScopedModels.php`. Enums `ImportStatus` (DB § 8A's seven states), `ImportFileType`.
- **Uploads** go into the media library through `MediaUploads` as media of the batch, collection **`imports`** (`config/media.php`: `.xlsx` and `.csv`, 20 MB). `UploadValidator` gained content checks (finfo reports `.xlsx` as a zip and `.csv` as text): `.xlsx` only when the file opens as a zip with `[Content_Types].xml` naming a spreadsheet workbook, `xl/workbook.xml`, no `vbaProject.bin`/macro-enabled type, ≤ 5,000 entries and ≤ 256 MB unpacked; `.csv` only when it is text with no NUL bytes and no `<?`, `<script`, `<html`, `<body`, `<iframe`, `<svg`, `<object`, `<embed`, `<!doctype`. Anything else keeps its sniffed type and is refused; SVG is still refused first. `CollectionRules::EXTENSIONS` gained `xlsx`/`csv`; `config/media-library.php`'s `allowed_extensions` now derives from it.
- **Reading** (`Reading\SpreadsheetFile`): OpenSpout streaming, empty rows kept so row numbers are exact, header = first non-empty row in the first 20, hidden sheets skipped, formulas read as computed values; CSV delimiter (comma/semicolon/tab) from the header line and Windows-1252 when the file is not UTF-8. `Reading\LocalCopy` copies the media file (local or S3) to a temp file for the readers and deletes it after.
- **Mapping** (`Mapping\MappingSuggester`, `SheetMapping`): sheet → target by sheet name, else by most shared headers (≥ 2); columns from the organization's saved mapping for that target first (the one matching most headers), then label, key, aliases (headers normalised to letters and digits). "Save this matching as …" stores one mapping per mapped target (unique per org, kind, name; saving again replaces it). One sheet per target; one file column per target column.
- **Dry run** (`Batches\DryRunner`): mapped sheets in commit order, each row through `validate` then `simulate`, inside a transaction that is always rolled back. Report on the batch: per sheet rows, counts by action, error/warning counts with the first 100 of each (row, column key, file header, column letter, message), a 5-row preview, units and expected yearly collections; sheet-level errors for an unmapped required column, an unregistered target and more than 10,000 rows. Passed = rows > 0 and no errors (warnings accepted).
- **Error workbook** (`Batches\ErrorWorkbook`, `GET /o/{slug}/imports/{batch}/problems`): the dry run again, writing every mapped sheet's rows as the file had them plus "Problems" (header, letter, message for each error and warning) and "Row in your file", and a last "All problems" sheet (sheet, row, column, kind, problem). Always complete (the report keeps only the first 100).
- **Template** (`Templates\TemplateWriter`, `GET /o/{slug}/imports/template`): "Instructions" (steps, then per target its columns: required, allowed values, notes), one sheet per registered target (headers + example row), and dropdowns for fixed values: OpenSpout cannot write data validation, so values go to a hidden "Lists" sheet and `<dataValidations>` are added to each sheet's XML afterwards. A filled template maps itself.
- **Commit** (`Jobs\CommitImportSheet`, queue `imports`, timeout 50, 3 tries, 30 s backoff, inside the batch's organization): one sheet per job in dependency order; rows in chunks of 100, each chunk in one transaction with the batch's `rows_done` and `commit_cursor`, after a row lock and a cursor check, so a retried or repeated job resumes at the last saved chunk and never writes a row twice; after 30 s (`import.job_seconds`) the job queues the rest of its sheet; at the end it queues the next sheet or marks the batch `committed`. Rows are validated again against the database; failing ones are skipped and listed (first 200) with the reason. `failed()` marks the batch `partially_failed` with the row it stopped at (rollback still possible).
- **Rollback** (`ImportBatches::rollback`): `committed` or `partially_failed`, within 7 days of the commit (`import.rollback_days`), refused if any target's `usedSince` is true; targets in reverse order soft delete what the batch created or restored, in one transaction; audited with the counts per target.
- **Purge** (`Jobs\PurgeImportUploads`, daily 03:40, `imports` queue): uploads older than 30 days (`import.keep_uploads_days`) are deleted (media row and file); the batch keeps its report and records `file_purged_at`; batches still committing keep their file; organization by organization inside each one's context (no scope removed); re-queues itself when out of time; idempotent.
- **Service** `Batches\ImportBatches` is the only writer (upload, map, dryRun, commit, rollback), checks `ImportBatchPolicy` for the acting user and audits each step (log `imports`: `import.uploaded`, `import.mapped`, `import.dry_run`, `import.commit_started`, `import.committed`, `import.failed`, `import.rolled_back`, `import.file_purged`; counts only, never cell values).
- **Permissions:** new `imports.view` (org-owner, org-admin; kept while suspended; not blocked while impersonating); existing `imports.manage` (org-owner, org-admin; not kept while suspended; blocked while impersonating, as P01-08 classed it) now gates upload, mapping, dry run, commit and rollback. `ImportBatchPolicy::commit`/`rollback` also carry `#[BlockedWhileImpersonating]`, as do the Livewire methods.
- **Pages** (Livewire, `Modules/Import/resources/views/livewire`): `/o/{slug}/imports` (history, newest first; "New import", "Download template"), `/o/{slug}/imports/new` (upload; for a CSV optionally what it holds), `/o/{slug}/imports/{batch}` (step bar Upload → Map columns → Dry run → Review → Commit → Report; mapping with suggestions and "save as"; report with counts, problems table, preview, subscription effect from `PlanCatalogue` (decision 6) and yearly collections; commit with confirmation; progress bar polling every 5 s while committing; report with skipped rows and rollback until the deadline).
- **Docs:** DATABASE_SCHEMA.md § 8A (both tables as built, provenance rule) and `media` collections; ARCHITECTURE.md § 6.12 ("How it is built"), § 7 (purge in the schedule table), § 8 (Import built), § 9 (spreadsheet checks, `imports` collection); AGENT.md § 4 (targets register through the contract); DATA_IMPORT.md § 2.4 (chunked commit) and § 4 (formats, `.xls`).

**Tests and evidence (local, MariaDB 11.4):**
- `composer ci` on the final commit, after merging `main` (`0858309`): Pint passed, Larastan 0 errors, Pest **1,492 tests: 1,492 passed, 0 skipped, 12,243 assertions**, 0 failed. The new tests also pass with `APP_DEBUG=true` (as GitHub CI runs).
- New: `Modules/Import/tests/Feature/ImportDryRunTest.php` (10), `ImportCommitTest.php` (9), `ImportAccessTest.php` (11 incl. role dataset), `ImportFilesTest.php` (4), `tests/Feature/Media/SpreadsheetUploadTest.php` (13 incl. datasets); fixture targets `Modules/Import/tests/Fixtures/ContactsTarget.php` and `NotesTarget.php` (Notes depends on Contacts), registered only in tests, on TEMPORARY tables (`ImportFixtures`: created with their indexes in one statement, so the test transaction is never committed).
- **Dry run writes nothing:** `ImportDryRunTest` › "writes nothing during a dry run" (every SQL statement during the dry run recorded: no insert/update/delete/DDL outside `import_batches`, the audit log and its chain head; fixture tables identical before and after; report counts 30 new + 1 update).
- **Commit creates exactly the previewed records:** `ImportCommitTest` › "commits exactly the records the dry run previewed" (result counts per sheet equal the dry-run counts; 40 contacts, 2 notes; values, provenance on created rows only); "creates no duplicates when the same file is imported again, and restores archived matches".
- **Each commit job under 50 s on the shared profile (in-process queue tick):** `ImportCommitTest` › "commits sheet by sheet in dependency order on the imports queue, each job well within 50 seconds on the shared profile" (database queue, job on `imports` with `timeout` 50, `QueueTick::run()` drains it; 2,000 contacts + 300 notes with the Notes sheet first in the file; Contacts took several jobs (budget forced to 1 s), Notes ran after Contacts; every job timed with queue events, asserted < 25 s; no failed jobs).
- **Resume / idempotency:** "carries on from the last saved chunk after a failure, never writing a row twice"; "marks the batch as stopped part-way when a job gives up, and it can still be rolled back"; "commits only after a passed dry run, and only once".
- **Rollback within 7 days; refused after 7 days or once records are used:** "rolls back within 7 days: what the batch created or restored is archived, updates stay, audited"; "refuses a rollback after 7 days" (7 days + 1 minute); "refuses a rollback once a record of the batch has been used".
- **Only org-owner/org-admin can import (permission classed in both guard methods):** `ImportAccessTest` › "lets only org-owners and org-admins import" (5 roles × policy abilities and the four pages: owner/admin 200, agent 403, property owner/tenant 404); "refuses an agent's upload, dry run, commit and rollback sent straight to the services"; "grants the import permissions to org-owners and org-admins, classed for both central guards"; `SuspendedOrganizationTest` and `ImpersonationGuardTest` lists.
- **Commit and rollback refused during impersonation (DATA_IMPORT § 5):** `ImportAccessTest` › "refuses commit and rollback while GoProperty staff impersonate, but shows them the results" (write-mode session: both pages open with the report, `commit` and `rollback` Livewire calls 403, the policy denies too, nothing changed, `impersonation.blocked` audited).
- **Uploads purged after 30 days:** `ImportFilesTest` › "deletes uploaded files after 30 days, keeping the batch and its report" (the scheduled `import-uploads-purge` event run as cron runs it: a `CallbackEvent` at `40 3 * * *`; 31-day-old files gone from disk and table in both organizations, the 2-day-old one kept, audited, idempotent, the batch refuses to commit and the problems download 404s); "keeps the file of a batch still committing". `ScheduleTest` (all events in-process, queue tick last) still passes.
- **Cross-org probes on `ImportBatch`:** `CrossTenantAccessTest` datasets `ImportBatch` and `ImportColumnMapping`; `ImportAccessTest` › "answers 404 for another organization's import guessed by ID" (page and problems download; a member of both still gets 404 in the wrong workspace).
- **Upload validation (renamed script, non-OOXML zip refused):** `SpreadsheetUploadTest` › "refuses a renamed script, whatever spreadsheet name it has" (PHP as .csv/.xlsx, `<?=` in CSV, shell script, HTML, `<script>` in CSV, NUL bytes), "refuses a zip that is not an Excel workbook" (plain zip, Word document, macro-enabled workbook), "refuses SVG renamed as a spreadsheet", "accepts an Excel workbook and a CSV file, typed and named from their content" (incl. Windows-1252 CSV), "keeps spreadsheets to the imports collection and images out of it".
- **Pages:** `ImportAccessTest` › "runs the pages from upload to report: upload, map, dry run, review, commit, report, rollback" (Livewire file upload, the dry run's exact row/column/message on the page, preview and subscription effect, commit, report, rollback); "keeps imports read-only while the organization is suspended".
- **Streaming:** `ImportDryRunTest` › "streams a large workbook without holding it in memory" (5,000 rows read with < 16 MB peak growth); template and error workbook content checked cell by cell in `ImportFilesTest`.
- Existing tests changed (none weakened): `WorkspaceNavigationTest` (owners and admins now also see "Import"), `SuspendedOrganizationTest` kept list (+`imports.view`), `OrganizationScopedModels` (+2 models); `SignupGuardsTest` › "lets later features tick checklist items off…" asserted a "Coming soon" badge, which was the Data item: its route `workspace.imports.create` now exists, so the test asserts the Start link instead; `WebInstallWizardTest` › "offers to empty the database after a failed migration" read the newest migration from `database/migrations` only, and the newest is now a module's (`2026_11_03_060002`), so it looks in `Modules/*/database/migrations` too (same check).
- **Setup checklist:** a committed import ticks the onboarding checklist's "Your properties" item (`ChecklistItem::Data`, whose route is now the import page), asserted in "commits exactly the records the dry run previewed".

**Orchestrator review:** merged `main` (with P02-01b) into the branch; resolved four conflicts by keeping both sides (Admin keeps `properties.portfolio` and both import permissions; the sidebar test expects buildings and imports; `Property`, `ImportBatch` and `ImportColumnMapping` all join the cross-tenant probes; the failed-migration test takes `main`'s equivalent lookup). Added `xmlreader` and `xmlwriter` to the installer's required extensions (the XLSX reader and writer need them; INSTALLER.md § 4 and the shared-hosting runbook updated). Re-ran `composer ci`: Pint passed, Larastan 0 errors, Pest 1,548 passed, 12,745 assertions. Checked that both queued jobs run inside `OrganizationContext::run` and that the module has no scope bypass.

**Decisions to confirm:**
- Decision 1 (modules): Import is a module with its Livewire components inside it; ARCHITECTURE.md § 8 marks it built.
- Decision 2 (cross-org lookups): the purge job walks organizations and works inside each one's context; no scope is removed anywhere.
- Decision 3 (soft deletes vs unique keys): the contract says matching includes archived rows and restores them (`ImportAction::Restore`, the row then carries the batch's id so a rollback archives it again); the fixture target does this and it is tested.
- Decision 4 (plan gating): Excel import is in every plan (BILLING § 1.2); nothing gated here. Follow-up below for assisted import.
- Decision 6 (imports): the subscription effect uses `PlanCatalogue` only: the organization's signup plan priced for the units in the file ("units you already have are added to this"); no current unit count yet.
- **`imports.manage` stays wholly blocked while impersonating** (as P01-08 classed it), so staff can open imports and reports (new `imports.view`) but cannot upload or run a dry run either, not just commit/rollback. DATA_IMPORT § 6 "assisted import" (staff prepare the file and dry run) would need a separate non-blocked "prepare" permission.
- **Dry run runs in the request** (synchronously), not queued: it only reads, and a queued dry run would need the in-file memory (`ImportRun`) kept between jobs. Fine for 2,000 rows (the 2,000-row commit test's dry run takes a few seconds locally); revisit if 10,000-row sheets hit a host's request time limit.
- **Contradiction 1, DATA_IMPORT § 2.4** "each sheet is committed in its own database transaction" vs the 50-second job limit (AGENT § 4): a 10,000-row sheet cannot be written in one job. Chosen: chunks of 100 rows, each in its own transaction with the progress and resume point; a stopped commit resumes without duplicates and a failed one is `partially_failed` and can be rolled back. DATA_IMPORT § 2.4 updated to say so.
- **Contradiction 2, DATA_IMPORT § 4** lists `.xls`: neither OpenSpout nor simple-excel reads `.xls` (and a streaming `.xls` reader would need another package such as PhpSpreadsheet, which loads whole workbooks into memory). Chosen: `.xlsx` and `.csv` only; the upload page and DATA_IMPORT § 4 tell agencies to save `.xls` as `.xlsx`.
- `committed_at` is when the commit was started (the 7-day window counts from it); `kind` is a string (`workbook` or a target key) rather than an enum, because targets come from other modules; its documented values are listed in DB § 8A.
- Records a batch only updated keep their own `import_batch_id` and their changes on rollback (DATA_IMPORT says rollback "soft-deletes the batch's records"; updates cannot be undone without history).
- Uploads cap 20 MB (the media library's backstop); a CSV that is not UTF-8 is read as Windows-1252 instead of refused.

**Follow-ups:**
- P02-06b/06c: implement `ImportTarget` in Tenants and Properties (`Modules/<Name>/app/Import`) and register from the providers; match including archived rows; real `usedSince` (P03-13a extends it to bills and payments, as planned); warnings "rent differs", "unit count raises the bill"; the 4-sheet template and the 2,000-row fixture workbook.
- `ext-xmlreader` (OpenSpout's XLSX reader) is not in the installer's requirement list (`RequirementsChecker::EXTENSIONS`); it is almost always present, but should be added.
- Assisted import (BILLING § 1.2: Scale and Enterprise; DATA_IMPORT § 6) needs a staff-side "prepare" permission and P06-01 gating.
- P02-07: add the export action to the import history list.
- The onboarding wizard's "Your properties" step (Data) could also link to `/imports`; the dashboard checklist already does (its route name was waiting for this page).
- Release build / install drill not re-run for this task (the suite's `InstallCommandTest` covers module migrations in installs); run them when P02 closes.
- If dry runs of 10,000-row sheets are too slow for a shared host's request limit, move the dry run to the queue (keeping `ImportRun`'s memory in the batch between jobs).

**Next:** P02 wave 3 (P02-02 ‖ P02-06b).

### P02-01b — Complete (2026-10-27): buildings CRUD (schema, policy, list/add/edit/show/archive pages, photos through the media library, PropertyDirectory contract); P02-01 complete

With P02-01a already done, this completes the parent **P02-01** (owner profiles; buildings CRUD with amenities and photos; AC "cross-org access tests pass for owners and buildings").

**Delivered** ([DATABASE_SCHEMA.md § 3](../DATABASE_SCHEMA.md) `properties`, [ARCHITECTURE.md § 5](../ARCHITECTURE.md) "Buildings", § 6.2 "Built so far", § 9 "Building photos"; `docs/plans/P02-P03-subtasks.md` P02-01b):
- **Schema:** module migration `Modules/Properties/database/migrations/2026_11_03_000101_create_properties_table.php`: DB § 3 columns, `owner_profile_id` nullable FK to `owner_profiles` (restrict), `photo_media_id` (cover; no FK, like `organizations.logo_media_id`), `status` (`active`/`archived`), import provenance `import_batch_id` (no FK) / `external_ref`, soft deletes. Unique `(organization_id, slug)` and `(organization_id, external_ref)`; indexes `(organization_id, import_batch_id)`, `(organization_id, status, name)`, `(organization_id, owner_profile_id)`.
- **Model and enums:** `Modules\Properties\Models\Property` (`BelongsToOrganization`, `SoftDeletes`, `HasMedia` + `App\Media\HasOrganizationMedia`, route key = slug, `owner()` incl. archived owners, `coverPhoto()`, `photos()`), `PropertyType` (6 types), `PropertyStatus` (active, archived), `PropertyFactory`; `OwnerProfile::properties()`. `Property` added to `tests/Support/OrganizationScopedModels.php` (all 5 `CrossTenantAccessTest` probes).
- **Writers (audited, log `properties`):** `Modules\Properties\Buildings\Properties` (create, update, archive, restore; each checks `PropertyPolicy` for the acting user; server-side validation: plain text, state from `NigerianStates`, owner must be an owner profile of this organization and not archived unless it is already the building's owner, year 1800…now+5, amenities ≤ 30 × 40 chars). Slug made from the name, next free `-2`, `-3`… among all rows incl. archived and soft-deleted, never `create`; kept on rename; a unique-index race retries. Adding the first building ticks the setup checklist item `data` ("Add your properties", ONBOARDING § 2 step 6). Archive/restore = status change under a row lock. `Buildings\PropertyPhotos` (add / makeCover / remove): files only through `App\Media\MediaUploads` into collection `photos` (already in `config/media.php`: JPEG/PNG/WebP, 10 MB, `thumb` + `large`); every file of an upload is checked by `UploadValidator` before the first is stored; ≤ 10 per upload, ≤ 20 per building; first photo becomes the cover, removing the cover moves it to the next photo; a photo is looked up among that building's own photos (404 otherwise). `Buildings\Amenities` (suggested list + free text, normalised, de-duplicated).
- **Permissions and policy:** existing `properties.view` / `properties.manage` reused; one new permission **`properties.portfolio`** ("Add, archive and restore buildings"; org-owner, org-admin), classed in `allowedWhileSuspended()` (false) and `blockedWhileImpersonating()` (false). `PropertyPolicy`: viewAny/view (`properties.view`, archived too), update/addPhotos/deletePhoto (`properties.manage`, active buildings only), create/delete(archive)/restore (`properties.portfolio`); create and restore refused while the organization is `restricted` (ONBOARDING § 5.5). Archive (`delete`) and photo removal (`deletePhoto`) are blocked during impersonation by the central guard's ability names.
- **Pages** (`Modules/Properties/resources/views/livewire`, routes in the module's workspace group): `/o/{slug}/buildings` (`PropertyIndex`: 25 a page on the server, debounced search over name/address/landmark/LGA, filters type/state/owner, Active/Archived switch, all in the URL; signed cover thumbnails; archive with confirmation, restore; eager loads, no N+1), `/buildings/create` and `/buildings/{building}/edit` (`PropertyForm`: owner picker of the organization's active owners plus the current one if archived since, "No owner on file", link to add an owner; amenities checkboxes + other), `/buildings/{building}` (`PropertyShow`: details, owner link, amenities, photo gallery with signed `thumb`/`large` links, upload checked on choice and on save, make cover, remove, archive/restore; archived building read-only with a banner). Sidebar item "Buildings" (order 200).
- **Owner follow-ups from P02-01a:** archiving an owner who still owns an active building is refused with a message on the Owners list (`OwnerProfiles::archive`, ValidationException `archive`); an owner's page lists their buildings (20, then a link to the list filtered by owner).
- **Contract:** `Modules\Properties\Contracts\PropertyDirectory` (`find`, `findMany`, `isActive`, `activeOptions`, `countActive`) returning `Contracts\PropertySummary` (scalars only), bound in `PropertiesServiceProvider` to `Buildings\PropertyDirectoryService`; current organization only, throws without one; archived buildings found with `archived = true`, soft-deleted ones not found.
- **Docs:** DATABASE_SCHEMA.md § 3 `properties` as built; ARCHITECTURE.md § 5 (Buildings paragraph, owner archive rule), § 6.2 ("Built so far": archive semantics, PropertyDirectory), § 9 (building photos).

**Tests and evidence (local, MariaDB 11.4):**
- `composer ci` on the final commit (`156c0aa`, after merging main `0858309`): Pint passed, Larastan 0 errors, Pest **1,489 tests: 1,489 passed, 12,374 assertions**. The module and installer tests also pass with `APP_DEBUG=true` (91 tests).
- New: `Modules/Properties/tests/Feature/Buildings/PropertyPagesTest.php` (21 incl. datasets), `PropertyRolesTest.php` (13), `PropertyPhotosTest.php` (13), `PropertyDirectoryTest.php` (2), helpers `helpers.php`; `CrossTenantAccessTest` dataset `Property` (5).
- **AC: cross-org access tests pass for owners and buildings:** `CrossTenantAccessTest` datasets `OwnerProfile` and `Property` (no context, own rows only, no cross-org update/delete, no cross-org create/move, 404 on URL binding); `PropertyPagesTest` › "answers 404 for another organization's building guessed by slug or ID, on every page and action" (show/edit by foreign slug and by numeric id 404; same slug in both orgs shows each its own; list archive/restore with a foreign id 404; the locked `propertyId` swapped on the show page and the form → 419 with debug pinned off; member of both orgs gets 404 inside Acme's workspace; foreign rows unchanged); `PropertyPhotosTest` › "answers 404 for a photo of another building or another organization" (removePhoto/makeCover); `PropertyDirectoryTest` › "answers for the current organization only"; owners' existing `OwnerProfilePagesTest` › "answers 404 for another organization's owner…" still green.
- **Sub: archive is soft only (AGENT § 3.7):** `PropertyPagesTest` › "archives a building as a status change, never a delete, keeps it readable, and restores it, all audited" (row present, `status = archived`, `deleted_at` null; listed under Archived; page read-only; edit 403; restore and archive again; audit `property.archived` / `property.restored`).
- **Sub: photo uploads go through `UploadValidator`:** `PropertyPhotosTest` › "refuses a script renamed .jpg, and an SVG, whatever the browser says, storing nothing" (PHP as `.jpg`, PHP sent as `image/jpeg`, SVG, SVG as `.png`, PDF; refused on choice, on save and by the writer; no media row, no file), "refuses the whole upload when one file is refused", "stores a valid photo through the media library with its conversions, makes it the cover and shows it through signed links" (collection `photos`, sniffed mime, random file name, sha256, `thumb` and `large` made by the media-queue job, cover set, audit; the page's signed thumb link serves `image/webp` without a session, a tampered signature and a link 16 min old get 404; list thumbnail), "queues the conversions on the media queue", cover/remove, limits (10/20).
- **Role rules (ARCH § 5):** `PropertyRolesTest` › policy matrix for the 5 roles × 10 abilities; grants and guard classing of `properties.view/manage/portfolio`; other-org members and strangers refused; pages per role (agents edit but don't add/archive/restore; property owners, tenants, strangers 404); agent's add/archive/restore sent to the server refused, agent's edit saved.
- **Suspended org read-only:** `PropertyRolesTest` › "shows buildings read-only while the organization is suspended" (pages open, no buttons, edit/create 403, save/archive/restore calls 403, nothing changed). Restricted: "adds and restores no building while the organization is restricted, but keeps editing and archiving".
- **Impersonation:** `PropertyRolesTest` › "lets impersonating staff in write mode edit a building, audited with both identities, but never archive one" and "… read-only mode open buildings but change nothing"; `PropertyPhotosTest` › "never lets impersonating staff remove a photo, even in write mode".
- Others: slugs (`PropertyPagesTest` › "gives each building a slug unique in its organization, and keeps it when the building is renamed"), owner picker, validation dataset (12), search/filters/paging, "loads the list without a query per building (no N+1)" (checked: fails when the `owner` eager load is removed), owner page and owner-archive refusal.
- Existing tests changed (none weakened): `WorkspaceNavigationTest` (sidebar now has `buildings`), `WebInstallWizardTest` › "offers to empty the database after a failed migration" (it read the newest migration's file from `database/migrations` only; module migrations are now the newest, so it also looks in `Modules/*/database/migrations`; same check).
- Not run: release build / verify / install drills (not in this row's AC; `InstallCommandTest` › "runs every module's migrations, building the same schema as a plain migrate" passes with `properties`). No browser smoke: the pages are only exercised through HTTP/Livewire tests.

**Orchestrator review:** branch already contained `main` (`0858309`); re-ran `composer ci`: Pint passed, Larastan 0 errors, Pest 1,489 passed, 12,374 assertions. Checked the module for scope bypasses (none) and that every photo action authorizes through `PropertyPolicy`. Note: removing a photo deletes the media row and its files (a hard delete); photos are attachments, not domain records, so AGENT.md § 3 rule 7 is read as not applying — confirm.

**Decisions to confirm:**
- Orchestrator decision 1 (modules): buildings live in `Modules/Properties`, Livewire components in the module.
- Orchestrator decision 3 (soft deletes vs unique keys): slugs are generated, so a new building never collides with a soft-deleted one (it gets the next free slug); `(organization_id, external_ref)` stays unique incl. soft-deleted rows; restoring a trashed building on re-import is for P02-06c.
- Orchestrator decision 4 (plan gating): BILLING § 1.2 gates nothing built here (properties are in every plan).
- **Archive = `status` change, not a soft delete** (owners use soft delete): DB § 3 has both `status` and soft deletes, and BILLING § 1.4 meters "units not soft-deleted and whose property is not archived", so archiving is the status; soft deletes stay for import rollback. An archived building's page stays open read-only (an archived owner's page is 404).
- **New permission `properties.portfolio`** (add/archive/restore buildings: org-owner, org-admin) rather than letting agents do it with `properties.manage`: ARCH § 5 gives agents "assigned buildings", and adding/archiving changes what the organization is billed for. Agents keep editing buildings and their photos. Not blocked during impersonation (archive is, as a `delete` ability).
- **Restricted organizations** (ONBOARDING § 5.5 "no new properties"): adding and restoring buildings are refused; editing and archiving continue.
- `owner_profile_id` is **nullable** (an owner's own organization owns its buildings without an owner profile); an archived owner stays linked to their buildings and can be kept, not newly chosen.
- Slug is kept on rename (links keep working); not editable.
- Photos use the existing `photos` collection (`config/media.php` already lists "Building, unit and Building Group photos"), max 20 per building and 10 per upload (module constants, not config). Removing a photo deletes the media row and files (media rows have no soft deletes, ARCH § 9) and is blocked while impersonating (`deletePhoto`).
- Adding the first building completes the setup checklist item `data`; its link (`workspace.imports.create`) is left to P02-06a.
- An owner with active buildings cannot be archived (refused, not warned).

**Follow-ups:**
- P02-02: units on the building page; refuse archiving a building with occupied units / active leases (P03-03a for leases); decide whether archiving a building should touch its units' statuses (metering already excludes them by the property's status).
- P02-03: narrow `PropertyPolicy::view/update/addPhotos/deletePhoto` and the list query to assigned buildings for narrowed agents (the policy notes where); agent ↔ building assignment is Scale-only (BILLING § 1.2) — gate in P06-01.
- P02-06c: `PropertiesImportTarget` matches by `external_ref` incl. soft-deleted rows (restore, audited) and by name; use `Buildings\Properties::freeSlug()` for imported rows.
- P02-07: export on the buildings list.
- Owner picker and owner filter are plain selects of all owners; switch to a searchable picker if organizations get hundreds of owners.
- Original photos keep their EXIF (P02-05 follow-up); building photos are a likely place to strip GPS before tenants see them.
- The `/owner` portal (owners seeing their buildings) remains unowned (P02/P03 plan item 13); `properties.view` is already granted to `property-owner`.

**Next:** P02 wave 3 (P02-02 ‖ P02-06b).

### P02-05 — Complete (2026-10-27): media library (Core): private media disk, organization-scoped media, upload checks, queued GD/WebP conversions, signed links; branding images moved in

**Delivered** ([ARCHITECTURE.md § 9](../ARCHITECTURE.md) "Media library", § 10; `docs/plans/P02-P03-subtasks.md` P02-05):
- **Package:** `spatie/laravel-medialibrary` **`~11.23.11`** (the only composer change of wave 1; brings `spatie/image` 3.9.9, `spatie/image-optimizer`, `maennchen/zipstream-php`, `composer/semver`). Needs `ext-exif` and `ext-fileinfo`, already blocking in the installer's server check.
- **Disks** (`config/filesystems.php`): private `media` (local, `storage/app/media`) and `media-s3` (private bucket; the installer's `AWS_*` bucket under `media/` unless `MEDIA_S3_*` names its own). `MEDIA_DISK` picks one and defaults to `media-s3` when `FILESYSTEM_DISK=s3` (the runbook's shared-hosting setting). Local `storage/app/media` added to the nightly files backup.
- **Schema:** migration `2026_11_02_050001_create_media_table` = Spatie's stub + `organization_id` (FK, restrict) with indexes `(organization_id, model_type, model_id, collection_name)` and `(organization_id, collection_name)`. `App\Media\Media` extends Spatie's model with `BelongsToOrganization`; added to `tests/Support/OrganizationScopedModels.php` (all five `CrossTenantAccessTest` probes run on it).
- **Owners and uploads:** `App\Media\HasOrganizationMedia` (for `HasMedia` models; `Organization` now uses it for branding images). `App\Media\MediaUploads::add()` is the one way in: inside an organization only, refuses an owner of another organization (`CrossOrganizationWrite`), stores under a random name with the extension of the sniffed type, keeps the uploader's name as a label only, and the sha256, width and height in `custom_properties`. Paths are `{organization id}/{media uuid}/…` (`MediaPathGenerator`).
- **Checks** (`App\Media\UploadValidator`, rules per collection in the new `config/media.php`): finfo sniff of the bytes (the browser's type and the file name are ignored), only JPEG/PNG/WebP/PDF are storable types at all (`CollectionRules::EXTENSIONS`), SVG refused even as `.png`/`.jpg` or when a collection lists it, size cap per collection (`photos` 10 MB, `documents` 10 MB, `branding` 2 MB), images must decode as the sniffed type, ≤ 8000 px a side and ≤ `MEDIA_MAX_MEGAPIXELS` (25). `check()`, `problem()`, and `rule()` for Livewire rules / Form Requests.
- **Conversions:** `thumb` (400 px) and `large` (1600 px) WebP via GD, `Fit::Max` (never upscaled), `nonOptimized()` and `image_optimizers` empty (no jpegoptim/cwebp binaries), image generators Image + WebP only (no PDF/SVG/video). Queued on a new **`media` queue**, added to the queue tick's bulk lane (`pdf`, `media`, `imports`). `App\Media\PerformMediaConversions` (and the responsive-images job) run inside the media row's organization (`RunsInMediaOrganization`), timeout 50 s, 3 tries, 30 s backoff, idempotent; a deleted organization means nothing to do.
- **Signed route** `GET /media/{organization}/{media uuid}/{conversion?}` (`App\Http\Controllers\MediaController`, name `media.show`): no session/cookies; invalid, tampered or expired signature → 404; organization from the signed URL → `OrganizationContext::run` → `Media` looked up by uuid through the normal scope (decision 2's pattern; no `withoutGlobalScopes()`); unknown/other-organization file, conversion not made yet, file missing on disk → the same 404. Streams privately: `Content-Type` from the sniffed type, `nosniff`, `default-src 'none'; sandbox`, `no-referrer`, `noindex`, `Cache-Control: private, max-age=<seconds left on the link>`; images inline, other files as attachments. `App\Media\MediaUrls` makes links (`MEDIA_URL_LIFETIME`, 15 min; `preview()` falls back to the original until a conversion exists); Spatie's `getUrl()` / `getTemporaryUrl()` return the same signed links (`SignedMediaUrlGenerator`). `media` added to `AdminPath::RESERVED`.
- **Branding images absorbed** (ARCH § 9's hand-over): `BrandingFiles` keeps every P01-05 check and the PNG re-encoding, then stores through `MediaUploads` into the organization's `branding` collection (role in a custom property). `Branding` now carries `logoMediaId` / `signatureMediaId` / `stampMediaId`; `organizations.branding` has `logo_media_id` / `signature_media_id` / `stamp_media_id` and `BrandingSettings` also sets the `organizations.logo_media_id` column in the same save. The workspace route is now `/o/{slug}/settings/branding/files/{role}/{media uuid}` (same permissions). Data migration `2026_11_02_050002_move_branding_images_to_media` (query builder and disks only) copies each existing `*_file` into a media row and rewrites the json; its `down()` goes back to the paths; the original files are left on the branding disk.
- **Docs:** ARCHITECTURE.md § 2 (queues, media row), § 7 (`media` queue), § 9 (media library "How it is built", branding images, local-disk note), § 10; DATABASE_SCHEMA.md (`media` table, `organizations.branding` keys, conventions line); BRANDING_DOCUMENTS.md § 1 (images); AGENT.md § 2 / § 4 (`media` queue) and § 5 (uploads go through `MediaUploads` / `UploadValidator`, links through `MediaUrls`); INSTALLER.md (bulk lane); PLATFORM_CONSOLE.md (queue list); runbooks shared-hosting.md (media settings, private bucket, memory) and restore.md (`media/` folder); `.env.example` (`MEDIA_*`); config comments in `branding.php`.

**Tests and evidence (local, MariaDB 11.4):**
- `composer ci` on the branch: Pint passed, Larastan 0 errors, Pest **1,392 tests: 1,391 passed, 1 skipped (the existing module-boundary check), 11,558 assertions**, 0 failed.
- New: `tests/Feature/Media/UploadValidationTest.php` (24 incl. datasets), `SignedMediaRouteTest.php` (15), `MediaConversionsTest.php` (7), `BrandingImagesMoveTest.php` (2); helpers `tests/Support/media.php` (real GD images). `CrossTenantAccessTest` now probes `Media` (5 cases).
- **AC: uploads validated (mime sniff, size):** `UploadValidationTest` › "mime sniffing" (rejects other files dressed up as images ×4, unreadable JPEG, names the stored file after the sniffed type, keeps a collection to its own types, accepts JPEG/PNG/WebP and PDF) and "size caps" (refuses a file over its collection's cap, has a cap for each collection, measures the file itself not what the browser claimed, empty files, megapixel and side limits), "gives Laravel validation the same answer"; nothing refused reaches the disk or the table.
- **Sub: a renamed `.php`→`.jpg` is rejected:** "rejects a PHP script renamed .jpg" (finfo says `text/x-php`), "… even when the browser says it is a JPEG".
- **SVG rejected:** "rejects SVG images, whatever they are called" (`.svg`, renamed `.png`/`.jpg`, with a doctype), "refuses SVG in every collection, even one configured to list it".
- **AC: signed URLs expire:** `SignedMediaRouteTest` › "expires signed URLs" (200 at 14 min, 404 at 16 min via `travel`), "lives as long as config/media.php says".
- **Sub: a tampered signature gets 404:** "answers 404 to a tampered signature" (one character changed, emptied), "answers 404 when anything the signature covers is changed" (expiry, uuid, organization, conversion), "answers 404 without a signature".
- **AC: another org's media is not reachable:** "never reaches another organization's media through the route" (their own link works; a correctly signed link pairing Acme with their uuid, and the reverse, are 404), "never reaches another organization's media through the model scope" (pluck/find/where-uuid/`getMedia()` in Acme's context; no context throws), "keeps each organization's files under its own folder", plus the `CrossTenantAccessTest` dataset for `Media`.
- **Sub: conversions finish within the 50 s job budget on the shared profile:** `MediaConversionsTest` › "converts a 12-megapixel phone photo in the shared profile's queue tick, well within the 50-second job budget" (database queue, job on `media` with `timeout` 50 in its payload, `QueueTick::run()` drains it in the bulk lane, thumb 400×300 and large 1600×1200 WebP; measured **0.9 s**), "converts the largest photo allowed within the budget and the tick's memory" (25 MP: measured **1.8 s, ~110 MB** extra peak; asserted < 25 s and < 160 MB), "queues the conversions on the media queue instead of making them during the upload", "runs the conversion inside the file's organization, with no context of its own", "can run again without harm", "does nothing when the organization is gone", "makes no conversions for a PDF or for branding images".
- Branding: `BrandingSettingsTest` updated for media storage (stored row, `logo_media_id` column and json, uuid route, signature uuid not served as a logo, removal keeps the media row); `BrandingImagesMoveTest` (migration up/down with real files; unmovable paths left as they were).
- Existing test changed: `WebInstallWizardTest` › "offers to empty the database after a failed migration" re-ran the newest migration expecting its table to exist; the newest is now a data-only migration, so it now picks the newest migration that creates a table (same check).

**Orchestrator review:** merged `main` (with P02-01a) into the branch, resolving two conflicts by keeping both sides (AGENT.md § 5 module Livewire rule + media upload rule; `OrganizationScopedModels` gains both `Media` and `OwnerProfile`), then re-ran `composer ci`: Pint passed, Larastan 0 errors, Pest 1,435 passed, 11,852 assertions. Read `MediaController`: the signature is checked before any lookup, the organization comes only from the signed URL, the file is found by uuid inside `OrganizationContext::run`.

**Decisions to confirm** (orchestrator's P02 decisions relied on, and choices made here):
- Relied on **decision 2** (public / cross-org lookups): the signed organization sets the context, the file is found by uuid inside it; no scope removed. The queued conversion job restores its media row the way Laravel restores any queued model (by key, without scopes), then works inside that row's organization.
- Relied on **decision 4** (plan gating): nothing in the media library is plan-gated in BILLING § 1.2.
- **The signed link is the permission** to see a file until it expires (no session or policy check when the file is served). Whoever builds a link must check the owning record's policy first (written in `MediaUrls`, ARCH § 9, AGENT § 5). Links last 15 minutes (`MEDIA_URL_LIFETIME`).
- **Expired and tampered links both get 404** (the AC asks 404 for tampered; expired is the same so the route reveals nothing).
- **`media-s3` reuses the installer's `AWS_*` bucket under `media/`** unless `MEDIA_S3_*` is set, and is the default whenever `FILESYSTEM_DISK=s3`.
- **New queue `media`** in the bulk lane, before `imports`, rather than a lane of its own (a new lane would change the existing tick tests' per-lane counts).
- **Branding images moved now** (not just planned): the move is a reversible data migration; **old P01-05 files are not pruned** (the P01-05 follow-up said "prune unreferenced files"): deleting them is irreversible, `down()` needs them, and P03-05 has not yet decided what document snapshots point at.

**Follow-ups:**
- P02-01a/b (property photos, `properties.photo_media_id`): implement `HasMedia` with `HasOrganizationMedia`, add files with `MediaUploads` (`photos`), validate in Livewire with `UploadValidator::rule('photos')`, show with `MediaUrls::preview($media, 'thumb')` after the policy check.
- P03-07a (bank statements): add CSV/XLSX to `CollectionRules::EXTENSIONS` with content checks (finfo reports CSV as `text/plain` and XLSX as a zip), and a `statements` collection.
- P03 payment proofs (`payment_proofs.media_id`): `documents` collection; the ARCH § 10 rate limit on proof uploads belongs to that task.
- P03-05: decide whether issued PDFs are media rows (generated, so `MediaUploads`' checks would need a separate entry point) and what branding snapshots reference; then prune the old `organization-branding/` files.
- Original photos keep their EXIF (including GPS) and are served as uploaded; conversions are re-encoded WebP without it. Consider stripping EXIF from originals or serving only conversions to tenants (privacy, NDPA).
- The sha256 is stored but not re-checked when a file is served (compliance documents do check theirs); add a check where integrity matters (statements, proofs).
- VPS enhancement: redirect to an S3 presigned URL instead of streaming through PHP.
- Spatie's `media-library:regenerate/clean/clear` commands stop at the organization scope; a per-organization regenerate command would be needed if conversion settings change.
- Livewire temporary uploads must stay on a local disk (the validator reads `getRealPath()`).
- NDPA erasure tooling can delete an organization's files by its `{organization id}/` prefix.

**Next:** P02 wave 2.

### P02-01a — Complete (2026-10-27): Properties module (the first module) and owner profiles

**Delivered** ([ARCHITECTURE.md § 8](../ARCHITECTURE.md) "How a module is built", § 5 "Property owners", [DATABASE_SCHEMA.md § 1](../DATABASE_SCHEMA.md) `owner_profiles`):
- **First module, `Modules/Properties`** (`module:make`, then trimmed: no sample controller, seeder, event provider or config). `modules_statuses.json` at the root. `PropertiesServiceProvider` registers the policy, the Livewire namespace `properties::` (classes in `Modules\Properties\Livewire`, so components work on Livewire update requests) and the module's sidebar item; `RouteServiceProvider` loads `routes/web.php` (workspace routes in the same `auth, verified, organization` + `o/{organization}` group as Core).
- **Modules in the quality gate:** `phpunit.xml` runs `Modules/*/tests/Unit` and `Modules/*/tests/Feature` in the Unit and Feature suites (and covers `Modules/*/app` as source); `tests/Pest.php` gives module feature tests the same `Tests\TestCase` + `RefreshDatabase` (`->in('Feature', '../Modules/*/tests/Feature')`); `phpstan.neon` analyses `Modules/` (tests excluded, like `tests/`), checked with a deliberate type error and a `withoutGlobalScopes()` in a module: both reported. `resources/css/app.css` scans `Modules/*/resources/views` (`@source`) so module views share the one Vite build.
- **Module boundary test switched on** (`tests/Feature/Core/ModuleSkeletonTest.php`): no longer skipped; it fails when another module **or Core (`App`)** uses a module's internals (anything outside `Contracts`/`Events`). Checked by adding a Core class that used `OwnerProfile`: the test failed; with `Modules\Properties\Contracts\…` it passed. The generator test now checks that the app's own `modules_statuses.json` is unchanged (it used to check the file did not exist).
- **Cross-tenant probe sees module models:** `CrossTenantAccessTest` mapped `Modules/<Name>/app/…` files to the wrong class name (`Modules\<Name>\app\…`), so a module model would never have been found; it now strips `app/`. `OwnerProfile` is in `tests/Support/OrganizationScopedModels.php`.
- **Release:** `scripts/release/stage.sh` removes `Modules/*/tests` (with `tests/`); the package ships `Modules/Properties` (no tests) and `modules_statuses.json`, and the package's own autoloader maps `Modules\Properties\` (merge plugin under `--no-dev`).
- **Workspace nav registry** (`App\Navigation\WorkspaceNavigation`, `WorkspaceNavItem`, singleton in `AppServiceProvider`): replaces the hard-coded items in `resources/views/layouts/app/sidebar.blade.php`. Core registers Home, Transaction Tier (`viewAny TierApplication`), Members (`viewAny Membership`) and Settings (profile, or payment collection for owners who may only see that) with the same permission checks as before; modules add items with `callAfterResolving`. Items are ordered, skipped when their route does not exist, and a duplicate key throws. The phone bottom bar is unchanged.
- **Owner profiles:** migration `Modules/Properties/database/migrations/2026_11_02_000101_create_owner_profiles_table.php` (DB § 1 columns plus `type`, `display_name`, import provenance `import_batch_id` (no FK) / `external_ref`, soft deletes; unique `(organization_id, external_ref)`; indexes `(organization_id, import_batch_id)`, `(organization_id, deleted_at, display_name)`, `(organization_id, phone)`, `(organization_id, email)`). `Modules\Properties\Models\OwnerProfile` (`BelongsToOrganization`, `SoftDeletes`, `id_value` encrypted and hidden, `maskedId()` = "NIN ···2109", `display_name` set on save), enums `OwnerType` (person, company) and `OwnerIdType` (nin, cac, none, with the NIN 11-digit and CAC `RC 1234567` patterns), `OwnerProfileFactory`.
- **`Modules\Properties\Owners\OwnerProfiles`**, the only writer: create, update, archive (soft delete), restore; checks `OwnerProfilePolicy` for the acting user, validates on the server (names required per type, plain text only, email, phone normalised to E.164 through `App\Support\PhoneNumber`, state from `NigerianStates`, NIN/CAC format, notes ≤ 2000), and audits under log `owners` (`owner.created/updated/archived/restored`; updates list the changed fields, never the ID number).
- **Permissions** (both classed in `allowedWhileSuspended()` and `blockedWhileImpersonating()`): `owners.view` (org-owner, org-admin, agent; kept while suspended; not blocked) and `owners.manage` (org-owner, org-admin; not kept while suspended; not blocked). Property owners and tenants hold neither. `OwnerProfilePolicy`: viewAny/view (`owners.view`), create/update/delete (archive)/restore (`owners.manage`), record must be in the current organization; archive is refused while staff impersonate (a `delete` ability), editing is allowed in write mode and audited with both identities.
- **Pages** (`Modules/Properties/resources/views/livewire`): `/o/{slug}/owners` (`OwnerProfileIndex`: server-side pagination 25 a page, debounced search over name, email and phone (`0803 111` finds `+234803111…`), Active/Archived switch kept in the URL, archive with confirmation, restore, empty states with a next step); `/o/{slug}/owners/create` and `/o/{slug}/owners/{owner}` (`OwnerProfileForm`: person/company switch, contact, address, ID; read-only for agents). The ID number never goes back to the browser: the page shows it masked and an empty field keeps it; changing the ID type needs a new number; "None" removes it. Sidebar item "Owners" for `owners.view`.
- **Docs:** ARCHITECTURE.md § 8 (module list: Properties, Import, Tenants, Payments, Collections, OnlinePayments, Expenses added; "How a module is built"), § 5 (property owners); DATABASE_SCHEMA.md § 1 `owner_profiles` as built; AGENT.md § 4 (Core never imports module internals; modules plug in from their provider) and § 5 (module Livewire components live in the module).

**Tests and evidence (local, MariaDB 11.4):**
- `composer ci` on the final commit: Pint passed, Larastan 0 errors, Pest **1,382 tests: 1,382 passed, 0 skipped, 11,558 assertions** (was 1,338 passed + 1 skipped: the boundary test now runs). 43 new tests.
- **Cross-org probes for `OwnerProfile` (ID guessing returns 404):** `CrossTenantAccessTest` dataset `OwnerProfile` (5 probes: no context, own rows only, no cross-org update/delete, no cross-org create/move, 404 on URL binding); `Modules/Properties/tests/Feature/Owners/OwnerProfilePagesTest.php` › "answers 404 for another organization's owner guessed by ID, on every page and action" (GET page 404; Livewire `archive`/`restore` with a foreign id 404; a tampered locked `ownerId` refused (419); a member of both organizations still gets 404 inside the other workspace; foreign rows untouched).
- **ARCH § 5 role rules enforced by the policy:** `OwnerProfileRolesTest.php` › "enforces the ARCHITECTURE.md § 5 role rules in OwnerProfilePolicy" (org-owner, org-admin, agent, property-owner, tenant × viewAny/view/create/update/delete/restore), "grants the owner permissions to the roles ARCHITECTURE.md § 5 names, and classes them for the central guards", "refuses everything to a member of another organization and to strangers", "opens the pages only for the roles allowed, and 404 outside the workspace", "refuses an agent's save, archive and restore sent straight to the server", "shows owners read-only while the organization is suspended", "lets impersonating staff in write mode edit an owner, audited with both identities, but never archive one".
- **`id_value` encrypted at rest:** `OwnerProfilePagesTest.php` › "stores the ID number encrypted at rest and never shows it whole" (raw column is not and does not contain the number, `Crypt::decryptString` returns it, hidden from `toArray()`, page and Livewire snapshot carry only `NIN ···2109`); "keeps the ID number on file when the field is left empty, and replaces or removes it on purpose" (audit lists `id_value` as changed but never the number).
- **Module tests and Larastan in `composer ci`:** the 32 module tests ran inside the suite above (1,339 before + 32 module tests + 11 new Core/installer/probe tests = 1,382); Larastan reports errors placed in `Modules/` (checked, then removed); `ModuleSkeletonTest` › "keeps modules apart: nothing outside a module uses its internals"; `tests/Feature/Core/WorkspaceNavigationTest.php` (5: each role's sidebar items, Core and module alike; links; ordering, missing routes, duplicate keys).
- **Module migrations in install, schema equal to a plain migrate:** `tests/Feature/Installer/InstallCommandTest.php` › "runs every module's migrations, building the same schema as a plain migrate" (every `Modules/*/database/migrations` file is in the installed `migrations` table, `owner_profiles` exists, snapshot equal to a plain `migrate`).
- **Release build and drills:** `scripts/release/build-package.sh 0.7.0-dev.1` built the zip (no `Modules/*/tests` entries; module and `modules_statuses.json` included; autoloader maps `Modules\Properties\`). `scripts/release/verify-drill.sh` passed ("Release drill passed"; run with `APP_KEY` unset, see below). `scripts/release/install-drill.sh` passed on MariaDB 11.4 (command line and web wizard, "identical (command line and web): 373 columns and tables compared"; was 353 before `owner_profiles`' 20 columns) with the P00-06 local `intl` workaround: a scratch copy of the script drops `intl` from the unpacked requirements checker and restores it before `verify-package`; the repository script and the zip are unchanged.
- **In a browser** (`php -S`, seeded throwaway database `gp_smoke`, dropped afterwards; assets rebuilt): Owners list empty state, Add owner as a company with a CAC number (saved, redirected, "Ikoyi Holdings Ltd was added.", phone normalised, CAC shown as "On file: CAC number ···4321"), list at 375 px (rows stack into cards, no horizontal scroll), live search to the "No owner matches" state. Found and fixed two things: the owner-type control shared a row with "First name", and Tailwind did not scan module views.
- Existing tests changed (none weakened): `ModuleSkeletonTest` (statuses file unchanged instead of absent; boundary test un-skipped and extended to Core), `CrossTenantAccessTest` (module class names), `SuspendedOrganizationTest` kept list (+`owners.view`).

**Orchestrator review:** merged `main` (`baf99a0`) into the branch and re-ran `composer ci`: Pint passed, Larastan 0 errors, Pest 1,382 passed, 0 skipped, 11,558 assertions. Deploy drill (`scripts/deploy/deploy-drill.sh`) passed with the new seeding step.

**Decisions to confirm:**
- Orchestrator decision 1 (modules): Properties is a module; its Livewire components live in the module (AGENT.md § 5 updated); the planned modules are listed in ARCHITECTURE.md § 8.
- Orchestrator decision 3 (soft deletes vs unique keys): `(organization_id, external_ref)` stays unique as documented and includes archived rows; restoring a trashed owner on re-import is for P02-06c. The pages create no other unique key.
- Orchestrator decision 4 (plan gating): BILLING § 1.2 gates nothing built here (owners are in every plan).
- New permissions `owners.view` / `owners.manage` rather than reusing `properties.*`: agents look owners up but do not keep the records (only owners and administrators add, edit, archive, restore); property owners and tenants get neither.
- `owner_profiles` gained `type`, `display_name` and soft deletes (not in DB § 1 before; the task asks for archive-only). `import_batch_id` is indexed as `(organization_id, import_batch_id)`.
- The ID number is never sent to the browser (as P01-05 does for bank numbers): masked last four digits only, even for owners and administrators; an empty field keeps it.
- Archiving is blocked while staff impersonate (it is a delete); editing an owner is allowed in write mode.
- An archived owner's page answers 404; it is listed under Archived with Restore.

**Follow-ups:**
- P02-01b: owner picker on the building form; refuse (or warn on) archiving an owner who still owns active buildings; show an owner's buildings on the owner page.
- P02-06c: `OwnersImportTarget` matches by `external_ref` including archived rows (restore, audited) and by `display_name`/phone (indexed).
- P02-07: export on the owners list.
- P02-03: decide whether a narrowed agent sees all owners or only owners of assigned buildings (owners are not building-assigned today).
- Fixed by the orchestrator before merging: existing installs only gained new permissions when `OrganizationRolesSeeder` ran. `scripts/deploy/remote-deploy.sh` now runs the idempotent `InstallSeeder` after `migrate`, and the shared-hosting runbook, INSTALLER.md § 10 (zip upgrade, including the future web upgrade step) and ARCHITECTURE.md say so. The deploy drill passed with it (the second release shows the seeders running).
- Local drills: the task env file exports `APP_KEY`, which `php -S` inherits, so `verify-drill.sh` fails at "fresh upload" unless run with `env -u APP_KEY`; not a package problem.
- The owner portal (`/owner`) and linking owner profiles to users remain unowned (P02/P03 plan item 13).

**Next:** P02 wave 2 (P02-01b ‖ P02-06a) once P02-05 has merged.

### P00-04 — Complete (2026-10-27): release package built and verified in GitHub Actions

Test Release run [40457943170](https://github.com/thekingchild/Goproperty/actions/runs/40457943170) on `main` (`95a27cb`, version `0.1.0-rc.2`, not published): the quality gate on all four databases, the install drill on all four, then "Build, verify and publish". The release drill verified the zip with the package's own artisan and both unpacked layouts, and failed it as expected for a changed file, an added file and a changed zip entry; both layouts served pages and still verified after running; the old-PHP guard stopped PHP 7.4 and 8.1. The zip is kept as the run's artifact. A tag push runs the same jobs and publishes the GitHub release.

### GitHub Actions running — P00-03, P00-06, P01-09 Complete; P01 Complete (2026-10-27)

The product owner fixed the account's Actions billing. The first real runs (CI [40452156457](https://github.com/thekingchild/Goproperty/actions/runs/40452156457), test Release [40454924892](https://github.com/thekingchild/Goproperty/actions/runs/40454924892)) found three problems that local MariaDB 11.4 runs could not show. All three are fixed in this commit:

- **Installer, every database:** `goproperty:install` stopped after the migrations with "Unknown database 'laravel'". CI has no `DB_DATABASE`, so the default connection pointed at Laravel's default name, and the permission cache had already bound the database cache store to that connection. Locally `DB_DATABASE=testing` existed, so the seeders silently read the cache from the wrong database. `InstallationSteps::useDatabase()` now rebuilds a database cache store (and the permission registrar's cache) on the owner's database. Regression test: `InstallCommandTest` "installs when the starting database does not exist and the cache uses it" (fails without the fix).
- **MySQL reorders JSON object keys** (MariaDB keeps the written order). Publishing the theme compared decoded arrays with `!==`, so an unchanged FAQ was logged and re-saved as a change. New `App\Support\JsonValues::same()` ignores key order in maps (lists keep their order) and `ThemeManager` uses it for drafts and publishing. Four tests that compared maps by key order (`WebInstallWizardTest`, `StaffRolesTest`, `SettingsStoreTest`, `ConsoleSettingsPagesTest`) now compare by value.
- **Flaky queue tick in long parallel runs:** `ScheduleTest` hit the tick's 192 MB memory guard (as `QueueTickTest` and `OrganizationRolesTest` had). `phpunit.xml` now sets `QUEUE_TICK_MEMORY_MB=2048` for the whole suite; the two per-test overrides are gone.

**Tests and evidence:**
- Local, MariaDB 11.4: `composer ci` passed (Pint passed, Larastan 0 errors, Pest 1,339 tests: 1,338 passed, 1 skipped, 11,264 assertions). The install drill passed on a fresh package with `DB_DATABASE` unset (command line and web wizard, schema identical to a plain migrate, 353 columns and tables).
- GitHub Actions on branch `task/fix-drill`: CI [40457029228](https://github.com/thekingchild/Goproperty/actions/runs/40457029228) green on MySQL 8.0, MySQL 8.4, MariaDB 10.6, MariaDB 11.4 and the old-PHP guard (1,338 passed, 1 skipped on each); Install drill [40457030052](https://github.com/thekingchild/Goproperty/actions/runs/40457030052) green on all four databases (command line + web wizard with file-check unlock and TOTP, `/install` closed afterwards, schema equal to a plain migrate).

**Status changes:**
- **P00-03 Complete:** CI runs the full suite on the shared-hosting profile, including `tests/Drill/CronDrillTest.php` (cron-started worker with `exec`/`proc_open` disabled).
- **P00-06 Complete:** install drill green on MySQL 8 and MariaDB 10.6 (and 8.4, 11.4).
- **P01-09 Complete:** the web install drill is green on MySQL 8 and MariaDB 10.6. With it, **P01 is Complete**.
- **P00-02 In review** (was Blocked): both database families are green in CI. Branch protection on `main` (`docs/runbooks/ci.md` § 3) is not set yet: it would make every change go through a pull request, so it waits on the product owner.
- **P00-04** stays In review until a Release run is green on `main` (the test Release above failed only because of the installer bug).
- **P00-13** stays Blocked on the staging host (PRE-04) only.

**Next:** test Release run on `main` for P00-04, then P02 wave 1.

### P01 — all tasks built; phase In review (2026-10-27)

Eight of nine P01 tasks are Complete; P01-09 (web installer) is In review only because its Install drill must also pass on MySQL 8 and MariaDB 10.6 in GitHub Actions, which has no runners yet (P00-02). The phase exit check "cross-tenant tests cover every Phase-1 model" passes: `CrossTenantAccessTest` fails if any `BelongsToOrganization` model is missing from `tests/Support/OrganizationScopedModels.php`, and every P01 model is listed. Mark the phase Complete when the Install drill is green in Actions.

How P01 was built: one task per git worktree with its own MariaDB 11.4, at most two at a time, each reviewed and re-run through `composer ci` by the orchestrator, merged into `main` as one squashed commit per task with its ledger entry. P01-08's scope was changed by the product owner before work started (no organization consent setting, no notification to the organization; permissions and a GoProperty-side audit trail instead).

**Decisions made during P01 for the product owner to confirm** (details in each task's entry below):
- P01-01: property owners use `/owner`, not the agency workspace; tenants never open `/o/{slug}`.
- P01-02: signup Turnstile in `after_failures` mode shows from the 4th signup per IP in 15 minutes; the chosen plan is kept in `organizations.signup_plan_key` until P06-01.
- P01-03: tier stays unchanged when a document expires (flag and email only); `organization.compliance` is blocked while impersonating; compliance files are encrypted with the app key.
- P01-05: bank accounts are org-owner only; the account-name check uses GoProperty's own Paystack key (no money moves); a primary colour below 3:1 is rejected and 3:1–4.5:1 is darkened for text.
- P01-06: cancelled organizations are read-only like suspended ones; billing stays allowed while suspended so a customer can pay.
- P01-07: a lone super-admin may grant the first second super-admin without approval (bootstrap); default role names cannot be renamed; reactivating staff restores their roles without approval.
- P01-08: in-house impersonation instead of `lab404/laravel-impersonate`; `bills.manage` and `payments.submit_proof` blocked as money actions.

### P01-05 — Complete (2026-10-27): organization settings: profile, bank accounts with name check, branding with live invoice and receipt preview

**Delivered** ([BRANDING_DOCUMENTS.md § 1](../BRANDING_DOCUMENTS.md) "How it is built", [ONBOARDING_COMPLIANCE.md § 4.1](../ONBOARDING_COMPLIANCE.md) "How the bank accounts are built"):
- **Settings area in the workspace:** a sidebar item "Settings" and tabs Profile · Payment collection · Branding · Members (`x-workspace.settings-nav`; Members is P01-04's page). Each tab shows only to members who may open it; the pages check again.
- **Permissions** (two new, both classed in `allowedWhileSuspended()` and `blockedWhileImpersonating()`): `settings.view` (owner, admin; kept while suspended; not blocked) and `payment_settings.view` (owner only; kept while suspended; **always blocked while impersonating**). Saving profile/branding uses `organization.update` (owner, admin); bank accounts use `organization.payment_settings` (owner only, already blocked). `OrganizationPolicy::viewSettings/updateProfile/updateBranding/viewPaymentSettings`, new `OrganizationBankAccountPolicy`.
- **Profile** (`/o/{slug}/settings/profile`, `App\Livewire\Settings\OrganizationProfilePage`, service `App\Organizations\OrganizationProfile`): name, web address, address, state, LGA, CAC number (encrypted; audited as "changed" only). A new web address must be free (`OrganizationSlug`), the old one goes into `previous_slugs` (redirects keep working via `SetOrganizationContext`), an organization may take back its own old address, at most 10 old addresses. From Tier 2 the registered name and CAC number are locked (compliance checked them).
- **Bank accounts** (`/o/{slug}/settings/collections`, the checklist's collections route; `App\Livewire\Settings\PaymentCollectionPage`, marked `#[BlockedWhileImpersonating]`; service `App\Banking\BankAccounts`, the only writer): add, edit (empty number keeps the stored one; numbers never go back to the browser), check name again, make primary, deactivate, reactivate, remove (soft delete). Up to 10 accounts; exactly one primary among the usable ones. Account numbers are `encrypted` casts, shown masked (`···6789`), with `account_number_last4` and an HMAC `account_fingerprint` for duplicates. Audited under log `payment_settings` (bank and masked number only). First usable account ticks the checklist's collections item. Bank list: `config/banks.php` (Paystack codes, `App\Banking\BankCatalogue`).
- **Account-name check (decision):** `App\Banking\BankAccountNameResolver` (container-bound to `PaystackBankAccountNameResolver`, `GET /bank/resolve`) with **GoProperty's own Paystack secret** (`PAYSTACK_SECRET_KEY`, `config/services.php`): the lookup moves no money, so invariant 8 holds, and every organization needs the check before it has its own Paystack. Outcomes: matched (saved with the bank's spelling), mismatched (refused, nothing saved, "Use “BANK NAME”" offered), not found (refused), unavailable (no key, Paystack down → saved as "Name not checked", usable, "Check name" later). A later check finding another name makes the account unusable and moves the primary. `App\Banking\AccountNameMatcher` ignores case, word order, punctuation and company suffixes, and accepts a bank name cut short. 10 lookups an hour per organization.
- **Branding** (`/o/{slug}/settings/branding`, `App\Livewire\Settings\BrandingPage`, service `App\Branding\BrandingSettings`, value object `App\Branding\Branding` over `organizations.branding`): trading name, logo, signature, stamp, primary and accent colours, letterhead (address, RC number, phone, email, website), invoice/receipt/lease prefixes, invoice notes (≤ 600), receipt footer (≤ 300), layout modern/classic/compact, email sender name, hide the "Generated with" line (Scale/Enterprise only, from `signup_plan_key` until P06-01). Every rule is enforced on the server.
- **Contrast (decision; BRANDING § 1 says "darker shade used", the AC says "rejected"):** a primary below 3:1 against white is rejected with the ratio in the message; 3–4.5:1 is accepted and documents use the nearest darker shade reaching 4.5:1 for text and text-bearing panels (`App\Branding\BrandColours`, reusing `App\Theme\Color`); an accent closer than 3:1 to the primary is rejected.
- **Uploads:** `App\Branding\BrandingFiles`: ≤ 1 MB, finfo-sniffed PNG/JPEG/WebP only (no SVG), ≤ 5000 px a side, minimum width (logo 300 px), re-encoded to PNG (drops payloads and EXIF), trimmed, scaled down, content-hash names on the private `BRANDING_DISK` (default `local`, `organization-branding/{org}/{role}/`; already in the files backup). Served by `/o/{slug}/settings/branding/files/{role}/{hash}` (`BrandingFileController`: logo for workspace members, signature/stamp only with `settings.view`; `nosniff`, sandbox CSP); data URIs in PDFs. Old files kept for issued documents. P02-05 absorbs them (ARCHITECTURE.md § 9 says how).
- **Live preview** from the unsaved form: `App\Documents\BrandingPreview` renders `resources/views/documents/invoice.blade.php` and `receipt.blade.php` (partials `styles` with the three layouts, `letterhead`, `footer`; `App\Documents\DocumentBranding`, sample data `App\Documents\SampleDocuments`) into script-less `srcdoc` frames scaled to the column; a chosen logo shows before saving (Livewire temporary URL, after the server checks it). Table layouts, hex colours and simple CSS only (§ 5); page 794 × 1122 px, footer `position: fixed`. P03-05 reuses the templates with real data.
- **Schema:** one migration, `organization_bank_accounts` (organization-scoped, in `OrganizationScopedModels`).
- **Docs:** BRANDING_DOCUMENTS.md § 1 "How it is built", ONBOARDING_COMPLIANCE.md § 4.1, DATABASE_SCHEMA.md (`organizations.branding` keys, `organization_bank_accounts`), ARCHITECTURE.md § 4 (kept permissions), § 5 (Settings permissions), § 9 (branding images, P02-05 hand-over), § 10 (bank accounts), PLATFORM_CONSOLE.md § 6 (`payment_settings.view` blocked), `.env.example` (`PAYSTACK_SECRET_KEY`).

**Tests and evidence (local, MariaDB 11.4):**
- Orchestrator's review run (branch with latest `main`): `composer ci` Pint passed, Larastan 0 errors, Pest **1,336 tests: 1,335 passed, 1 skipped, 11,254 assertions**.
- `composer ci` on the branch merged with main (P01-04): Pint passed, Larastan 0 errors, Pest **1,336 tests: 1,335 passed, 1 skipped (the existing module-boundary check), 11,254 assertions**, 0 failed.
- New: `tests/Feature/OrganizationSettings/BankAccountsTest.php` (19), `BrandingSettingsTest.php` (37), `OrganizationProfileTest.php` (10), `tests/Unit/Branding/BrandingRulesTest.php` (22), helpers `tests/Support/organization-settings.php`. All Paystack HTTP faked (`Http::preventStrayRequests()`).
- **AC: bank numbers encrypted at rest:** `BankAccountsTest` › "stores the account number encrypted, decrypts it for the app, and never shows it whole" (raw column is not and does not contain the number, `Crypt::decryptString` gives it back, the model decrypts it, hidden from arrays, page shows `···6789` only, no audit row holds it).
- **AC: low-contrast colours rejected:** `BrandingSettingsTest` › "rejects a primary colour too light for white paper, on the server, saving nothing" (4 colours), "refuses a low-contrast colour sent straight to the server, past the page" (real Livewire update over HTTP), "rejects an accent too close to the primary", "accepts a mid-contrast colour and uses a darker shade for text on it"; unit cases in `BrandingRulesTest`.
- **AC: preview reflects unsaved changes:** `BrandingSettingsTest` › "renders the sample invoice and receipt from the unsaved form, and saves nothing until Save" (Livewire test: trading name, colour, prefixes, notes, footer, classic layout all in the preview; organization, audit log and checklist untouched; then Save persists), "keeps showing the saved colour while a colour is half typed", "shows a chosen logo in the preview before it is saved…".
- Also: name check matched (request URL, params, bearer key asserted) / mismatched (service and page, "Use the bank's name") / not found / unavailable (no key: nothing sent; 503) and checking later; later mismatch moves the primary; lookup limit; duplicates and malformed numbers; primary, deactivate, reactivate, remove (soft delete) with the audit event list; edit keeps the number; admins and agents 403, tenants/property owners/strangers 404; other organization's account ids 404 on every action; impersonating staff (both modes) get 403 on the page with nothing shown, Livewire calls refused, Settings menu hides it; suspended: page read-only, writes 403. Branding: every validation rule, Scale-only footer, upload refusals (PHP renamed .png, SVG, SVG renamed .png, HTML renamed .jpg, over 1 MB, too narrow) on the service and on choosing the file, stored re-encoded PNG and its route (agent sees logo not signature, strangers and other organizations 404), remove logo, admins can save / agents 403, suspended read-only, read-only support cannot save, dompdf renders the invoice and receipt in all three layouts. Profile: save with CAC encrypted and audited, web address change with redirect and taking back an old address, taken/reserved/malformed addresses, Tier 2 lock, roles, suspended, settings links.
- Existing tests changed: `SuspendedOrganizationTest` kept list (+`settings.view`, `payment_settings.view`), `ImpersonationGuardTest` blocked list (+`payment_settings.view`).
- **In a browser** (built-in browser, `php -S`, seeded demo data in a throwaway database, dropped afterwards; assets built with `npm run build`): Settings tabs; Branding at 1440 px with the preview beside the form and at 375 px below it (no horizontal scroll); typing a trading name and a yellow primary updated the preview live, showed both contrast messages, and Save was refused on the server; receipt tab; Payment collection: adding a GTBank account with no Paystack key saved it as "Name not checked", primary, masked.

**Follow-ups:**
- P03-05: register Inter with dompdf (with DejaVu Sans the full sample invoice spills onto a second page in modern/classic), QR codes, amount in words, branding snapshot (`Branding::toArray()`), numbering from `document_counters` with the prefixes; decide what a prefix change means for counters.
- P02-05: absorb `logo_file` / `signature_file` / `stamp_file` into media rows (`logo_media_id`, `signature_media_id`, new `stamp_media_id` in the json) and prune unreferenced files.
- P03-07: statement import and matching on `organization_bank_accounts`; its "org bank accounts with Paystack account-name check" part is done here.
- P03-08: add the Paystack connection to Settings → Payment collection (same permissions).
- P06-01: read the footer-line plan rule from the subscription instead of `signup_plan_key`.
- Compliance can now see whether the bank account name matches the CAC name ("Business bank account in the CAC-registered name", P01-03 follow-up) once the console shows bank accounts (not built here).
- APP_KEY rotation changes bank account fingerprints (duplicate detection) as well as the encrypted numbers; add to the key-rotation runbook.
- P01-04's invitation acceptance page can show the logo with `app(BrandingFiles::class)->dataUri($organization, Branding::fromOrganization($organization)->logoFile)` (works for guests, no route needed).

**Next:** P01 phase exit.

### P01-04 — Complete (2026-10-27): member invitations (email + token), roles, removal, acceptance page

**Delivered** ([ONBOARDING_COMPLIANCE.md § 2](../ONBOARDING_COMPLIANCE.md), [ARCHITECTURE.md § 5–6.1](../ARCHITECTURE.md)):
- **Schema** (migration `2026_11_01_040001`): `invitations` (`organization_id`, `kind` `user`, `email`, `phone`, `roles` json (one or more roles; replaces the single `role` column first planned), `target_organization_id`, `token_hash` (SHA-256, unique), `channel` `email`, `status` `pending/accepted/declined/expired/cancelled`, `expires_at` (14 days from the last send), `sent_at`, `send_count`, `opened_at`, `invited_by_user_id`, `accepted_at`, `accepted_by_user_id`, `cancelled_at`; indexes `(organization_id, status, expires_at)`, `(organization_id, email)`, `(target_organization_id, status)`). `App\Models\Invitation` (organization-scoped, in `OrganizationScopedModels`), enums `InvitationStatus`, `InvitationKind` (`user`), `InvitationChannel` (`email`). Built so P02-04 adds `kind = org_billing_transfer` + `billing_transfer_id`, and P03-01b adds the `whatsapp` / `link` channels, on the same table and acceptance.
- **Who grants which role** (`App\Members\RoleGrants`, decided here and written into ARCHITECTURE.md § 5): Settings → Members manages the workspace roles only (`org-owner`, `org-admin`, `agent`); every change needs `members.manage`; only an `org-owner` grants or takes away `org-owner` (an administrator never invites, changes, removes an owner, nor resends/revokes an owner's invitation); the organization always keeps at least one owner.
- **Services:** `App\Members\MemberInvitations` (invite, resend = new link + new 14 days, old link dies; revoke = `cancelled`); `App\Members\Members` (change roles, remove: deletes `organization_user` and that organization's `model_has_roles` / `model_has_permissions` rows only, the P01-01 follow-up; last-owner check under an organization row lock); `App\Members\InvitationAcceptance`. Each service checks its policy for the acting user (`InvitationPolicy`, `MembershipPolicy`, so the suspended and impersonation guards apply) and audits (log `members`, organization as subject: `invitation.sent/resent/revoked/accepted`, `member.roles_changed`, `member.removed`). Members and addresses with a waiting invitation are refused; sends limited per organization (50/h), per inviting user (30/h), per address (5/day).
- **Email:** `MemberInvitation` notification, mail only, queued on `notifications` after commit, identical whether or not the address has an account.
- **Settings → Members** (`/o/{slug}/settings/members`, `App\Livewire\Workspace\Members`, sidebar item for `members.view`): members with workspace roles, waiting/expired invitations, invite form (role checkboxes limited to grantable roles), Send again, Revoke, Change roles, Remove (with confirm). Write methods carry `#[BlockedWhileImpersonating]`.
- **Acceptance page** `/invitations/{token}` (`AcceptInvitationController`, sign-in split layout, `#[BlockedWhileImpersonating]`, throttled 30/min GET, 10/min POST): bound to the invited address. New person: name, optional mobile number (Nigerian rule; a number another account holds is silently not stored, as at signup), password (signup rule incl. breached check in production); account created with the address confirmed, signed in, lands in the workspace. Existing account: "Sign in to accept" (intended URL back to the link), then Join. Another signed-in account: 403 "for another account". Unknown/used/revoked/expired: one identical 404 page. Suspended organization: refused through `OrganizationWriteGuard`. Records `invitation_accepted` (auth log) + `invitation.accepted` (audit). The token → organization lookup reads `organization_id` by token hash (one unscoped column), then loads the invitation inside that organization's context (answers decision 4 of the P02/P03 plan for invitations).
- `invitations` added to `AdminPath::RESERVED`.
- **Fix outside the task (P00-10):** `user_devices.token_hash` was unique table-wide while `KnownDevices` reuses the browser's token for a second account, so any second account signing in from the same browser got a 500 (found in the browser smoke: a new member accepting in a browser the owner had used). New migration `2026_11_01_040002` makes it unique per `(token_hash, user_id)`; regression test added; DATABASE_SCHEMA.md updated.
- **Docs:** DATABASE_SCHEMA.md (`invitations`, `user_devices`), ARCHITECTURE.md § 5 (role grants) and § 6.1 (how invitations are built), ONBOARDING_COMPLIANCE.md § 2 (team members), UX_DESIGN.md § 3.3 (accept invitation as built).

**Tests and evidence (local, MariaDB 11.4):**
- Orchestrator's review run (branch with latest `main`): `composer ci` Pint passed, Larastan 0 errors, Pest **1,243 tests: 1,242 passed, 1 skipped, 10,589 assertions**.
- `composer ci` on the branch merged with main (P01-03): Pint passed, Larastan 0 errors, Pest **1,243 tests: 1,242 passed, 1 skipped (the existing module-boundary check), 10,589 assertions**, 0 failed. 26 tests are new (`tests/Feature/Members/`, helpers in `tests/Support/members.php`).
- **AC: an invited `agent` sees only what ARCHITECTURE.md § 5 grants:** `InvitationAcceptanceTest` › "gives an invited and accepted agent exactly what ARCHITECTURE.md § 5 grants an agent, and refuses the rest" (owner invites through the page, guest accepts through the link, then every `OrganizationPermission` in the catalogue is checked through `hasPermissionTo` and the Gate and must equal `OrganizationRole::Agent->permissions()`; workspace opens, members page 403, other organization 404; auth log, audit entry, audit chain verifies).
- **AC: expired/used tokens rejected:** "rejects expired, used and revoked links, and unknown ones, all the same way" (14 days + 1 minute; GET and POST 404 with the same page; a used link also fails for its own account; statuses recorded), "still works on the last day of the 14", "works once even when accepted twice", "makes the old link stop working when the invitation is sent again".
- Also: wrong signed-in account refused (403), existing account signs in and joins (roles elsewhere untouched), signup password/phone rules, phone of another account not stored, no account enumeration (same reply and same email), mail only on `notifications` (no SMS), token stored hashed, second account in one browser; `MembersPageTest`: page access (owner/admin 200, agent 403, strangers/other org 404), owner grants any role / admin never owner (UI, Livewire and service), agent and non-workspace roles refused, duplicate member/invitation refused, per-address limit, **other organization's invitations and members 404**, resend/revoke audited, admin cannot touch owners, **last-owner protection**, **removal drops roles and direct permissions in that organization only**, **everything refused while suspended** (Livewire 403, services, acceptance) and **while impersonating in write mode** (all six Livewire actions and the invitation link 403, blocked entries audited, nothing sent).
- **In a real browser** (php -S, seeded throwaway database, dropped afterwards; desktop and 375 px): members page, invitation sent, link opened while signed in as the owner → "for another account", signed out → join form, account created → landed as Agent; members page 403 for the agent; owner changed the agent's roles to Administrator + Agent; used link shows the "no longer works" page; no horizontal scroll at 375 px.

**Follow-ups:**
- P02-04 adds `InvitationKind::OrgBillingTransfer`, `billing_transfer_id` and its own acceptance through `BillingTransferService`; P03-01b adds `whatsapp`/`link` channels, phone-only invitees and the tenant role (Settings → Members ignores tenant/property-owner roles).
- Passkey enrolment is not offered on the acceptance page (UX § 3.3 says optional); members add one under Settings → Security. The agency logo on the page waits for branding (P01-05).
- Expired invitations are marked `expired` when their link is opened; the page shows any pending one past its date as expired. No scheduler sweep (none needed yet).
- The members page shows its confirmation at the top of the page; on phones a toast would be nicer once the workspace UI kit has one.
- If signing in fails after a new account is accepted (an unexpected error), the account and membership exist and the person can sign in normally; the link is used.

**Next:** P01-05 (organization settings and branding).

### P01-03 — Complete (2026-10-27): Transaction Tier applications, compliance documents, review queue, document expiry

**Delivered** ([ONBOARDING_COMPLIANCE.md § 5.3](../ONBOARDING_COMPLIANCE.md) "How it is built", [PLATFORM_CONSOLE.md § 1C](../PLATFORM_CONSOLE.md)):
- **Owner's page** `/o/{slug}/compliance/tier` (`workspace.compliance.tier`, Livewire `App\Livewire\Compliance\TierApplicationPage`; the checklist's Tier item and the workspace menu link to it). Start an application for a tier above the approved one (the signup `requested_tier` is suggested, `declared_annual_volume_kobo` copied), change the tier while draft, upload documents (number, holder name, issue/expiry dates where they apply), name the Tier 3 compliance contact, submit, see compliance's requests and rejected-document notes, answer and submit again. One open application per organization (`open_organization_id` unique). The organization keeps its approved tier meanwhile.
- **Permissions:** new `compliance.view` (see the application; kept while suspended; org-owner only). Changing uses the existing `organization.compliance` (org-owner only). `TierApplicationPolicy` (viewAny/view/create/update). Administrators and agents 403; tenants, property owners and strangers 404.
- **Impersonation (P01-08 merged):** `organization.compliance` is classed **always blocked while impersonating** (it changes compliance standing: staff never apply, upload or submit for a customer; this also blocks the signup wizard's Tier step during a session); `compliance.view` is not blocked. PLATFORM_CONSOLE.md § 6 updated; `ImpersonationGuardTest`'s blocked list updated.
- **Required documents** (`App\Compliance\RequiredDocuments`, from `config/tiers.php` `required_documents` / `optional_documents` until P06-08): Tier 2 = owner ID, CAC certificate, CAC status report, SCUML certificate, proof of address (≤ 3 months), directors' IDs; Tier 3 adds beneficial owners, AML policy statement, state regulator registration, named compliance contact. Submitting is refused (service `ApplicationIncomplete` and page) while anything is missing.
- **Uploads / storage** (`App\Compliance\ComplianceFiles`): own private disk `compliance` (local `storage/app/compliance`) or `compliance-s3` (`COMPLIANCE_DISK`, `COMPLIANCE_S3_*`, `config/compliance.php`); type sniffed with finfo (PDF/JPEG/PNG only), 8 MB cap, file encrypted with the app key under a random name, sha256 kept; document number, holder name and file name are encrypted casts. Livewire's plain temp upload is deleted right after. Kept separate from the P02-05 media library by design. Local compliance folder added to the nightly files backup; restore runbook updated.
- **Console → Compliance** (`App\Platform\Filament`): **Tier applications** queue (`/tier-applications`, `tiers.review`; waiting first, oldest submission first, badge), application page with missing-items line, documents relation (number/name/Open only with `compliance.view_documents`), history; actions Start review, Request more information (`tiers.review`), Approve / Reject (`tiers.approve`; reason required for the audit log, optional note to the owner); per-document Accept / Reject (note shown to the owner). **Document expiry** list (`/document-expiry`). Read models `ReviewApplication`, `ReviewDocument` (read-only, audit morph = the customer model); policies `TierReviewPolicy`, `ReviewDocumentPolicy`.
- **Decisions** (`App\Platform\Compliance\TierReview`): re-checks permission and state under a row lock inside the organization's context; approval accepts pending documents, moves `organizations.transaction_tier` through the one writer `App\Compliance\OrganizationTier` (audit `organization.tier_changed` with staff, reason, old/new; model's own entry suppressed), ticks the checklist Tier item; refused while a required document is rejected/missing. Rejection leaves the tier. Owners emailed on every decision/request (`TierApplicationDecided`, queued `notifications`, after commit; quotes only the note to the owner).
- **Document files for staff:** `/{ADMIN_PATH}/compliance-documents/{id}/file` (`filament.console.compliance-document`): signed, 5-minute link + staff session + 2FA + `compliance.view_documents`; decrypted, checksum-checked (409 on mismatch), `no-store`, `nosniff`, `no-referrer`; every view audited (`compliance_document.viewed`).
- **Expiry tracking** (`App\Platform\Compliance\DocumentExpiryCheck`, `Schedule::call` daily 03:15 `compliance-document-expiry`): documents of open/approved applications within 30 days → `expiring_notified_at`; past expiry → status `expired`; once per stage, audited, one email per organization per run (`ComplianceDocumentsExpiring`). **Tier is not changed on expiry** (docs only ask for reminders/flags).
- **Audit:** log `compliance` for every owner and staff step (`tier_application.started/.tier_changed/.contact_saved/.submitted/.resubmitted/.review_started/.more_info_requested/.approved/.rejected`, `compliance_document.uploaded/.removed/.accepted/.rejected/.viewed/.expiring/.expired`, `organization.tier_changed`); no document numbers or names in entries.
- **Schema** (2 migrations): `tier_applications`, `compliance_documents` (both organization-scoped, in `OrganizationScopedModels`). `App\Tenancy\OrganizationOwners` extracted from `OrganizationSuspension` (reused for emails).
- **Docs:** DATABASE_SCHEMA.md § 1 / § 9, ONBOARDING_COMPLIANCE.md § 2 / § 5.3, PLATFORM_CONSOLE.md § 1A / new § 1C / § 6, ARCHITECTURE.md § 4 / § 7 / § 9, runbooks shared-hosting.md and restore.md, `.env.example`.

**Tests and evidence (local, MariaDB 11.4):**
- Orchestrator's review run (branch with latest `main`): `composer ci` Pint passed, Larastan 0 errors, Pest **1,212 tests: 1,211 passed, 1 skipped, 10,033 assertions**. A first run failed only in `FreshCopyDrillTest`, whose `php -S` took more than 5 s to listen while two suites ran at once; the drill now waits up to 20 s.
- `composer ci` on the branch merged with main (P01-08): Pint passed, Larastan 0 errors, Pest **1,212 tests: 1,211 passed, 1 skipped (existing module-boundary check), 10,033 assertions**.
- New: `tests/Feature/Compliance/TierApplicationTest.php` (27), `tests/Feature/Compliance/DocumentExpiryTest.php` (6), `tests/Feature/Console/TierReviewTest.php` (17 incl. datasets), helpers `tests/Support/compliance.php`.
- **AC: a Tier 2 application with missing SCUML cannot be submitted:** `TierApplicationTest` › "refuses to submit a Tier 2 application without the SCUML certificate (service)" and "… (workspace page)" (real Livewire update over HTTP; then succeeds once uploaded), plus "refuses each missing Tier 2 document" (dataset of all six).
- **AC: an approved one changes the org's tier and is audited:** `TierReviewTest` › "approves from the console: Tier 2, audited with the staff member and reason, owners emailed" (console action; tier 1→2, `tier_application.approved` + `organization.tier_changed` with staff causer and reason, no duplicate model entry, `goproperty:audit-verify` passes, checklist done, owner emailed), "moves a Tier 3 application to Tier 3", "does not change the tier on rejection".
- Negative paths: non-owner members 403 / outsiders 404 / admin with a stolen snapshot 403; other organization's application and documents 404; suspended organization read-only (page visible, submit/contact/remove 403); impersonating staff (write mode) cannot submit or save; staff without permission (operations-manager, finance-admin, support-agent, technical-admin, analyst) 403 on queue, page, expiry list and file, service refuses; reviewer without `tiers.approve` cannot decide, without `compliance.view_documents` sees no numbers and cannot open files; file route refuses missing/tampered/expired signatures, customer sessions; disk holds ciphertext, not served; checksum mismatch 409; more-info loop; document rejection loop; expiry flagging once per stage, expired document stops counting, scheduler registration; upload sniffing (PHP/SVG/HTML refused), size cap, detail checks, proof of address age, Tier 3 requirements; cross-tenant probes for both new models.
- Existing tests changed: `SuspendedOrganizationTest` kept-permission list (+`compliance.view`), `ImpersonationGuardTest` blocked list (+`organization.compliance`), `StaffTwoFactorEnforcementTest` walker (fills the new `record`/`document` parameters, signs the document link).
- Not checked in a browser (the pages render in tests).

**Follow-ups:**
- Compliance files are encrypted with the app key. Rotating `APP_KEY` must re-encrypt them (or keep the old key in `APP_PREVIOUS_KEYS`); add this to the key-rotation runbook when one is written.
- `auth.require_2fa_for` (Tier 2/3 owners) still not enforced: it touches sign-in rules (remember-me not offered, passkeys counting as two factors, where to send the user, Livewire requests), so it deserves its own change rather than a bolt-on here.
- P06-08: move `required_documents` to `transaction_tiers` with the Tier settings screen (compliance-officer proposes, super-admin approves).
- P06-09: `compliance_flags` for `document_expiring` / `document_expired` (now recorded on the document), yearly re-verification and a renewal upload flow for documents of an approved application (the owner can't replace an expired document after approval yet).
- "Business bank account in the CAC-registered name" is checked by compliance by hand until bank accounts exist (P01-05 / P03-07).
- Staff are not notified when an application is submitted (the queue badge shows it).
- Document view audit entries have the document as subject, so they show in the global audit log, not in the application's History tab.

**Next:** P01-04, P01-05.

### P01-08 — Complete (2026-10-27): support impersonation with reason, staff-role permissions, two-person write mode, 30-minute limit, banner, blocked actions, audit trail and console impersonation log

**Delivered** (PLATFORM_CONSOLE.md § 6 as rewritten by the product owner on 2026-10-26):
- **No customer consent, no customer notification:** `organizations.allow_support_access` is dropped by a new migration (reversible); it is gone from `App\Models\Organization` (docblock, fillable, defaults, casts). Nothing in a session emails or notifies the organization or the user (no sign-in event, no new-device email, no session summary).
- **In-house, not `lab404/laravel-impersonate`:** the package signs the impersonator in as the user on the same guard and session; here staff and customers have separate guards, tables and cookies and § 6 wants a one-time hand-off into a separate time-limited session, so a small implementation is simpler and safer. No new packages.
- **Schema** (three migrations): drop `organizations.allow_support_access`; new `impersonation_sessions` (staff, user, organization, reason, mode, `approval_request_id` unique, `started_at`, `expires_at`, hand-off token hash / expiry / `handed_off_at`, `ended_at`, `ended_reason` `manual`/`timeout`/`ended_by_staff`/`revoked`, `ended_by_staff_id`, IP, user agent); index `activity_log (impersonator_staff_id, id)` for the timeline.
- **Service** `App\Platform\Impersonation\Impersonations` (only writer): `start()` (permission by mode, reason required ≤ 500, user must belong to the chosen organization, a customer account with a staff email is never impersonated, one session per staff member), `requestWrite()` (opens a `write_impersonation` approval through `ApprovalService`; new handler `WriteImpersonationApproval` re-checks the user and membership on approval), `approvedWriteRequest()` (own, approved, unused, within an hour of approval), `enter()` (signed relative link, 60 s, token hash, once), `resume()` (each request: 30-minute limit → `timeout`; staff lost permission/deactivated or user left → `revoked`), `end()`, `endByStaff()` (super-admin), `endExpired()` (scheduler `impersonation-timeout`, every minute; also closes unused links). Every refusal is audited.
- **Customer side:** `App\Http\Middleware\ResumeImpersonation` (web group, before `auth`): acts as the user per request with `Auth::setUser` (never signs in; nothing in the session that survives the row), sets `AuditContext::impersonatedBy(staff, session)`, applies the guard, records pages/actions/refusals, injects the banner ("{product} support (Ada O.) is viewing this account · mode · ends at · End session") into every HTML page; sign-out ends the session. Routes `impersonation.enter` (hand-off; wipes the browser's customer session and "remember me") and `impersonation.end` (banner) in `routes/impersonation.php`; ending returns to the user's page in the console. `impersonation` added to `AdminPath::RESERVED`.
- **Blocked actions** (`App\Tenancy\ImpersonationGuard`, the same four enforcement points as P01-06's `OrganizationWriteGuard`): HTTP (account-security and sign-in routes, `#[BlockedWhileImpersonating]` actions, read-only: every non-GET except Livewire updates and `#[AllowedWhileSuspended]` exports), Livewire `before('call')` (`RefuseLivewireCallsWhileImpersonating`), `HasOrganizationRoles::hasPermissionTo`, and the `Gate::before` in `TenancyServiceProvider` (blocked permissions, `delete…/forceDelete…/destroy…/remove…/purge…/void…` abilities, marked policy methods, read-only non-reads, other organizations → 404). New exhaustive classification `OrganizationPermission::blockedWhileImpersonating()` (blocked: `organization.delete`, `organization.billing`, `organization.payment_settings`, `members.manage`, `bills.manage`, `payments.confirm`, `payments.submit_proof`, `imports.manage`) and attribute `App\Tenancy\BlockedWhileImpersonating` (class or method). Settings → Security, the 2FA set-up and recovery-code components and account deletion are marked; the profile's email change and verification email are marked.
- **Audit trail:** log `impersonation`, impersonated user as causer, `impersonator_staff_id` set, session/organization/mode/IP/user agent in properties: `started`, `entered`, `page_viewed`, `action` (non-GET and Livewire method calls, names only), `blocked`, `ended`, `refused` (incl. staff without permission), `log_viewed`. `AuditEntry` now adds `impersonation_session_id` to every entry written during a session (business changes too), filling the P00-11 hook.
- **Console:** Customers → Users → user → **Impersonate** (organization, mode, reason; read-only opens at once, write asks for approval) and **Start approved write session**; `CustomerUserPolicy::impersonate/impersonateReadOnly/impersonateWrite`. New **Customers → Impersonation log** (`/impersonation-log`, `ImpersonationSessionPolicy`, `impersonation.view_log`): filters staff, organization, user, mode, start date, active now; active-session badge; session page with reason, approval and the full timeline (session entries plus business changes); super-admin **End session**; list and session views are audited.
- **Docs:** PLATFORM_CONSOLE.md § 6 "How it is built (P01-08)" (incl. why the `Path=/` staff cookie still meets "never sent to customer pages": it is never read there), § 1B, § 10 reserved words; ARCHITECTURE.md § 4 "Impersonation limits" (enforcement points and how a feature declares itself); AGENT.md § 4; DATABASE_SCHEMA.md `impersonation_sessions`, `activity_log`.

**Tests and evidence (local, MariaDB 11.4.12):**
- Orchestrator's review run after merging P01-02 into the branch (two one-line conflicts in `Organization` fillable and `AdminPath::RESERVED`, both sides kept, `allow_support_access` stays removed): `composer ci` Pint passed, Larastan 0 errors, Pest **1,153 tests: 1,152 passed, 1 skipped, 9,283 assertions**.
- `composer ci`: Pint passed, Larastan 0 errors, Pest **1,036 tests: 1,035 passed, 1 skipped (the existing module-boundary check), 7,976 assertions**, 0 failed. 42 tests are new (`tests/Feature/Impersonation/`).
- Existing test changed: `StaffTwoFactorEnforcementTest::consolePageUrls()` fills the `record` of the new `/impersonation-log/{record}` route with a support session (the route walk found it).
- **AC: impersonating staff cannot confirm a payment or view bank/Paystack keys:** `tests/Feature/Impersonation/ImpersonationGuardTest.php` › "stops impersonating staff from confirming a payment or viewing bank and Paystack keys, in both modes" (an org-owner can; during a read-only and a write session the payment-settings page and the confirm POST are 403 with nothing shown or written, a Livewire `confirmPayment` (permission check) and a `#[BlockedWhileImpersonating]` method are 403 and never run, four `impersonation.blocked` entries with both identities). Also: exact permission sets per mode (permissions and Gate), the blocked list, delete/void abilities and marked policy methods, write mode changes business data with `impersonator_staff_id` + session on the organization's own audit entry while read-only refuses it (exports and paging still work), account security pages and Fortify `user/*` routes refused in both modes, the password / passkey / 2FA / recovery-code / account-deletion components refused, the session limited to its organization.
- **AC: staff without the impersonate permission cannot start a session:** `ImpersonationSessionTest` › "refuses staff without the impersonate permission, and records the attempt" (finance-admin, compliance-officer, analyst, technical-admin, content-manager, no role: refused, no row, `impersonation.refused` audited with both identities, the console action hidden), "refuses write mode without impersonate.write", "needs a reason, a member of the organization, and never a staff account"; allowed roles start from the console ("starts a read-only session from the user's page…": support-agent, operations-manager, super-admin).
- **AC: start, end, reason, pages viewed and actions recorded with both identities in the hash chain and shown in the log:** `ImpersonationSessionTest` › "records start, end, reason, pages and actions with both identities in the hash chain, and shows them in the log" (ordered events, causer user, `impersonator_staff_id`, session/org/IP/UA, `AuditChain::verify()` and `goproperty:audit-verify` pass, list page, session page with reason, timeline relation with pages and the blocked attempt), "records actions taken in a write-mode session, with the approval…", "filters the log by staff member, organization, user, mode and date, and audits every read", "opens the log only for staff with impersonation.view_log", "lets a super-admin end an active session…".
- **AC: no email or notification reaches the organization or user:** `ImpersonationSessionTest` › "sends no email or notification to the organization or the user across whole sessions" (`Notification::fake()` + `Mail::fake()`; read-only from the console with pages, a refused verification email and the banner's end; write mode with request, approval, an action and a timeout; a super-admin's end; an unused link closed by the scheduler; the user has a known device so a real sign-in would have emailed) → nothing sent.
- Session mechanics: one-time 60-second link (reuse, expiry, changed token, broken signature refused and audited), separate customer-side session (no `login_web_*` key, no device record, "remember me" cookie expired), banner on workspace, dashboard, settings, portal, owner and home pages, end from the banner back to the console with nothing signed in, 30-minute timeout on the next request and by the scheduler (also unused links), `revoked` on staff deactivation, one session per staff member, write mode two-person flow (requester cannot approve, support-agent cannot approve, a second super-admin approves, start once, approval older than an hour or for another user refused, approval refused once the user left).
- Schema: the column is gone and not fillable.
- **In a real browser** (built-in browser, `php -S`, seeded data in a throwaway database, dropped afterwards): staff sign-in with 2FA → Users → Test User → Impersonate (organization, mode, reason) → landed on `/o/demo-realty` with the banner; banner checked at desktop and 375 px (it is now fixed to the top: the app layout's body is a CSS grid that squeezed it into a cell at first); Settings → Security answered 403 with the banner kept; End session returned to the user's console page; the session page showed reason, status and the timeline (pages, the blocked attempt, the end).

**Follow-ups:**
- Operations dashboard (§ 2, Security row) should list active sessions from `impersonation_sessions` when that dashboard is built; the log already filters "Active now" and badges the count.
- Portals (P03 `/portal`, P04 `/owner`): while `CurrentImpersonation` is active they must list only the session's organization's records (they read across organizations).
- Features built later (payments P03-10a, Paystack P03-08, bank accounts, billing transfers P02-04, imports P02-06a, invitations P01-04, sign-in links P03-01b) get the block from their permissions; anything not behind a blocked permission or a `delete…`/`void…` ability must carry `#[BlockedWhileImpersonating]` (AGENT.md § 4). P01-04 (member invitations/removal) should use `members.manage` so it is blocked.
- Refused start attempts are in the audit log (log `impersonation`, event `impersonation.refused`) but not listed on the impersonation log page (it lists sessions); the audit-log viewer (P06-07) will show them.
- `app/Installer/Finishing/AdminPath.php` and `app/Models/Organization.php` are also edited by P01-02 (one line each): expect a small merge.

**Next:** P01-02 merge, then P01-03, P01-04, P01-05.

### P01-02 — Complete (2026-10-27): registration wizard

**Delivered** ([ONBOARDING_COMPLIANCE.md § 2](../ONBOARDING_COMPLIANCE.md)):
- **Account step** (`/register`, rebuilt on the sign-in split layout with the seven steps in the brand panel; `/register/check-email`). `RegisterController` replaces Fortify's register post; `App\Onboarding\RegisterAccount` + `CreateNewUser::validate()`:
  - name, email, Nigerian mobile number (`PhoneNumber::nigerianMobile()`, `App\Rules\NigerianMobile`, stored +234…, never sent an SMS), password under the customer rule (10 characters, breached-password check in production);
  - **no account enumeration:** every valid form ends on the same "check your email" page and nobody is signed in. A taken email gets `SignupForExistingAccount` (sign in / reset) and the account is untouched; the password is still hashed for equal timing. A phone number used by another account is not stored, and the organization step asks for one again;
  - **Turnstile** per Sign-in security (`SignupBotCheck`): `always` on every signup, `after_failures` from the 4th signup from one IP in 15 min. Rate limiter `register`: 5/min and 30/h per IP, 6/h per email.
- **Verification link** (`VerifyEmailController` replaces Fortify's route): in the browser that signed up (session holds the new account id) it confirms and signs in; anywhere else the person signs in first and comes back to the link; never signs in an account with 2FA; wrong hash, expired, or someone else's link → 403.
- **Organization step** (`/signup/organization`, Livewire `App\Livewire\Onboarding\CreateOrganization` + `OrganizationForm`): type, name, slug suggested from the name (`OrganizationSlug`: format, reserved words, free now and in `previous_slugs`), state (`NigerianStates`), LGA, optional CAC number (encrypted). `OrganizationSignup::start()` creates the organization (`status` onboarding, Tier 1, `platform_covered` for `property_owner`), makes the user `org-owner` with `joinOrganization()`, remembers it as default, and opens the wizard state and checklist. One signup at a time per user (row lock); 10 tries a minute per user.
- **Wizard inside the workspace** (`/o/{slug}/setup`, `App\Livewire\Onboarding\SetupWizard`, `App\Onboarding\OnboardingWizard`): Tier (stated yearly collections, suggested tier from `config/tiers.php` launch values via `TierGuide`; Tier 1 active at once; Tier 2/3 recorded as `requested_tier` with its checklist item pending, `organizations.transaction_tier` stays 1) → plan (`PlanCatalogue`; `organizations.signup_plan_key`, interim until P06-01; `trial_ends_at` 14 days from the first choice; "covered" note for platform-covered orgs) → collections ("Set up later") → data ("Go to my dashboard", org becomes `active`). "Back" to any earlier step including the organization details. Steps are saved in order (`StepOutOfOrder`; a stale tab gets a notice, nothing saved twice). Every action checks `OrganizationPolicy::continueSignup` (per-step permission: `organization.update`, `.compliance`, `.billing`, `.payment_settings`, `imports.manage`). The component locks its organization id and refuses any other context.
- **Resume:** state is in `organization_onboardings.step`. Sign-in lands on the confirm-email page, the organization step (`/dashboard` sends users with no organization at all there, `DashboardController`), or `/o/{slug}`, which sends whoever can continue to the saved step (`WorkspaceHomeController`).
- **Checklist** on the workspace dashboard (`x-onboarding.checklist`, `onboarding_checklist_items`, `App\Onboarding\OnboardingChecklist::start/complete/reopen/isOpen`): tier, plan, collections, data, branding; progress bar; shown to members with `organization.update` until all done; an item links to its page once `ChecklistItem::routeName()` is registered ("Coming soon" until then).
- **Schema** (3 migrations): `organizations.signup_plan_key`; `organization_onboardings` (unique `organization_id`, `step`, `declared_annual_volume_kobo`, `requested_tier`, `completed_at`, audited under log name `onboarding`); `onboarding_checklist_items` (unique `(organization_id, item)`). Both new models use `BelongsToOrganization` and are in `OrganizationScopedModels`.
- `AdminPath::RESERVED` gains `register`, `signup`, `email`.
- **Docs:** DATABASE_SCHEMA.md § 1, ONBOARDING_COMPLIANCE.md § 2 ("How the wizard behaves"), ARCHITECTURE.md § 4 and § 6.1, BILLING_PAYMENTS.md § 2, PLATFORM_CONSOLE.md § 9.6 (Turnstile on signup), UX_DESIGN.md § 3.3.

**Tests and evidence (local, MariaDB 11.4.12):**
- Orchestrator's review run (branch with latest `main`): `composer ci` Pint passed, Larastan 0 errors, Pest **1,111 tests: 1,110 passed, 1 skipped, 8,326 assertions**.
- `composer ci` on the branch merged with main (P01-06, P01-07): Pint passed, Larastan 0 errors, Pest **1,111 tests: 1,110 passed, 1 skipped, 8,326 assertions**.
- New: `tests/Feature/Onboarding/SignupWizardTest.php`, `SignupGuardsTest.php`, `SignupAccountTest.php`, `tests/Unit/Onboarding/SignupRulesTest.php`, more cases in `tests/Unit/Support/PhoneNumberTest.php`. `tests/Support/LivewireHttp.php` drives components through Livewire's real update endpoint with the full middleware stack (Livewire::test skips route middleware); `tests/Support/signup.php` holds the step helpers.
- **AC: a new signup reaches the dashboard as `org-owner`:** `SignupWizardTest` › "takes a new signup to the dashboard as org-owner" (register → link in the same browser → organization → tier → plan → collections later → finish → `/o/adeyemi-realty` with the checklist; role `org-owner`, status active; signing in again lands there).
- **AC: leaving mid-wizard resumes at the same step:** "resumes at the same step after leaving mid-wizard" (after email confirmation, at the plan step, after going back to Tier with answers kept; each time a fresh session after sign-out) and "resumes at email confirmation when the link was not opened".
- **AC: property-owner signup sets `platform_covered`:** "sets platform_covered when a property owner signs up", "leaves platform_covered off for agencies and hybrids", "follows a type changed during signup".
- Also: Tier 1/2/3 recording (and audit entry), bad amounts, plan + 14-day trial not restarted, unknown plan, pending items; slug rules, CAC encrypted, phone re-ask, one signup at a time; admins/agents 403 on steps, strangers/tenants/property owners 404, Livewire update context and non-member 404 replay, agent update 403, stale tab, suspended organization read-only, checklist completion by later features and visibility; account step: phone formats, breached password (production, HTTP faked), identical reply for existing emails, Turnstile in both modes, both rate limits, verification link cases.
- Existing tests changed for the new behaviour: `Auth/RegistrationTest` "new users can register" (phone required, no sign-in until the link, verification sent) and `DashboardTest` (the visiting user now belongs to an organization; new case for users with none).
- **In the browser (local, 1440 px and 375 px):** full signup as a property owner: field error for a landline, check-email page, link opened in the same browser → organization step with slug suggested live → Tier 2 with "Suggested" badge and review note → plan with the "covered" note → collections → properties → dashboard checklist "1 of 5 done" with the Tier 2 note. No horizontal scroll at 375 px.

**Follow-ups:**
- P01-03: read `organization_onboardings.requested_tier` / `declared_annual_volume_kobo` to start the Tier 2/3 application; on approval complete `ChecklistItem::Tier`; register `workspace.compliance.tier` (or change `ChecklistItem::routeName()`).
- P01-05 / P03-07 / P03-08 / P02 / branding: call `OnboardingChecklist::complete()` for collections, data and branding and register the routes named in `ChecklistItem::routeName()`.
- P06-01: create the `trialing` subscription from `signup_plan_key` + `trial_ends_at`; then retire `signup_plan_key`. Monthly vs annual is not asked at signup.
- `auth.require_2fa_for` (Tier 2/3 or all owners) is still not enforced; it belongs with P01-03 once tiers can be approved.
- Logo upload at the organization step waits for the media library (P02-05); branding stays a checklist item.
- Password reset and email verification do not end a user's other sessions (no `AuthenticateSession` on the web guard); "Log out other sessions" from P00-10b would also close the pre-registered-account case.
- The slug check necessarily tells a signed-in, verified user whether a web address is taken (rate-limited).

**Next:** P01-03 (Transaction Tier applications), P01-04, P01-05.

### P01-06 — Complete (2026-10-27): console customers list, users lookup, organization suspend/reactivate, read-only suspended organizations

**Delivered:**
- **One central rule: suspended organizations are read-only** (`App\Tenancy\OrganizationWriteGuard`, ARCHITECTURE.md § 4). While `organizations.status` is `suspended` (or `cancelled`) members can sign in, read and export; every write is refused with a 403 saying so, from the next request (the status comes from the organization `SetOrganizationContext` loads each request; nothing caches it). Applied in four places so later features need no check of their own:
  1. **HTTP:** `SetOrganizationContext` refuses any non-GET request inside `/o/{organization}` unless the controller method carries `#[AllowedWhileSuspended]` (`App\Tenancy\AllowedWhileSuspended`, for exports and writes the docs allow).
  2. **Livewire:** `SetOrganizationContext` is now Livewire persistent middleware (the P01-01 follow-up), and a `Livewire\before('call')` hook (`App\Tenancy\Livewire\RefuseLivewireWritesWhileSuspended`) refuses every component method call before it runs, except paging, `$refresh`/`$set`/`$sync`/`$commit` and `#[AllowedWhileSuspended]` methods; event listeners (`__dispatch`) are judged by the method they run.
  3. **Permissions:** `HasOrganizationRoles::hasPermissionTo` drops every customer permission whose new `OrganizationPermission::allowedWhileSuspended()` is false (exhaustive match: a new permission must be classed). Kept: `workspace.access`, the `*.view` permissions, `organization.activity`, `organization.billing` (pay the subscription; a confirmed payment reactivates) and `payments.submit_proof`.
  4. **Policies:** a `Gate::before` hook (new `App\Providers\TenancyServiceProvider`) denies customers every ability that is not a read (`view…`, `export…`, `download…`, `switchTo`) in a suspended organization (the one passed to the check, else the context), with the message. Staff checks are never affected.
  - `OrganizationStatus::blocksWrites()` (suspended, cancelled) and `canBeSuspended()` (onboarding, active, restricted). `restricted` stays writable; the features it limits check it themselves.
  - Workspace layout shows "This organization is suspended" with what still works (`<x-organization-status-notice />` in `layouts/app.blade.php`).
- **Schema:** `organizations.suspended_at`, `status_before_suspension` (one migration). Reactivation returns to the status it left (a restricted organization stays restricted).
- **Suspend / reactivate** (`App\Platform\Customers\OrganizationSuspension`): permission `customers.suspend` (already in the P01-07 catalogue: `operations-manager`, `super-admin`), reason required (max 500), optional note to the owner (quoted in the email; the reason is not), row lock, valid transitions only. One audit entry per change (log `customers`, `organization.suspended` / `organization.reactivated`, staff causer, reason column, status before/after; the model's own `updated` entry is suppressed). Every org-owner with an email gets `OrganizationSuspendedNotice` / `OrganizationReactivatedNotice` (queued, `notifications`, after commit, platform brand name, link to the workspace).
- **Console → Customers** (`App\Platform\Filament`):
  - **Organizations** (`/customers`): name and slug, type, status, tier, member count, owner (name, email), created and trial-end dates; search by name, slug or owner name/email/phone; filters status/type/tier; server-side pagination (25/50/100). Page: profile incl. "suspended since" and "reactivation returns to", Users relation (each member's roles there), History relation (audit entries with staff member and reason), Suspend / Reactivate actions (confirmation, reason + note fields).
  - **Users** (`/customer-users`): users lookup by name, email or phone; user page with every organization, its status and the user's roles there.
  - Read models `App\Platform\Customers\Customer` (organizations) and `CustomerUser` (users), read-only (saving/deleting throw), with `#[UsePolicy]` → `CustomerPolicy` (`customers.view`; `suspend`/`reactivate` need `customers.suspend` and the right status; create/update/delete always false) and `CustomerUserPolicy` (`customers.view`). `analyst`, `technical-admin`, `content-manager` get 403.
  - `ActsAsStaff::attempt()` also shows `CustomerChangeRefused` as a red notification.
- **Docs:** ARCHITECTURE.md § 4 (the read-only rule, its four enforcement points, the `updated*` hook caveat, jobs not blocked, and how the P03 tenant portal must stay read-only for a suspended organization's records while still accepting payment proofs), AGENT.md § 4 (one line), PLATFORM_CONSOLE.md new § 1B Customers, DATABASE_SCHEMA.md `organizations`, BILLING_PAYMENTS.md § 2 (P05's `SubscriptionService` must set/restore `organizations.status`; paying stays allowed), ONBOARDING_COMPLIANCE.md § 5.5 (pointer).

**Tests and evidence (local, MariaDB 11.4):**
- Orchestrator's review run: `composer ci` Pint passed, Larastan 0 errors, Pest **994 tests: 993 passed, 1 skipped, 6,995 assertions**.
- `composer ci`: Pint passed, Larastan 0 errors, Pest **994 tests: 993 passed, 1 skipped (the existing module-boundary check), 6,995 assertions**, 0 failed. 37 tests are new.
- **AC: suspending an org blocks its users' write actions within one request; reactivating restores them:** `tests/Feature/Tenancy/SuspendedOrganizationTest.php` › "refuses every write from the next request after suspension, and reactivation restores them" (dataset org-owner / org-admin / agent: a write succeeds, `OrganizationSuspension::suspend()` runs, the very next POST/PUT/PATCH/DELETE and a closure-route POST are 403 with the message and nothing is written; GET, an `#[AllowedWhileSuspended]` export and the workspace page (with the notice) still work; after `reactivate()` the next write succeeds). Also: "affects only the suspended organization for a member of two" (writes and permissions in the other organization unaffected, switching still works), "lets members of a suspended organization sign in and read" (real sign-in lands on the workspace), "refuses Livewire writes in a suspended organization before they run" (real Livewire update requests over HTTP with the context from persistent middleware: `save` and an event that runs it are 403 and never run; export, `nextPage`, `$refresh`, a refresh event and property updates work; reactivation restores; verified the test fails without the persistent middleware), permissions and policy abilities (every `OrganizationPermission` through `hasPermissionTo` and the Gate, read/write policy abilities, an organization passed with no context), cancelled read-only / restricted writable, the exact kept-permission list, staff unaffected.
- `tests/Feature/Console/CustomersTest.php` (26): access by role (5 roles with `customers.view` open all four pages; analyst, technical-admin, content-manager 403), never create/edit/delete, list columns (members, owner, type, tier, status, trial date), search (name, slug, owner name, email, phone), filters, server-side paging (32 records, 25 shown), members with roles per organization, users lookup with organizations and roles; **suspend from the console** by an operations-manager (status, `suspended_at`, `status_before_suspension`, one `customers` audit entry with staff causer, reason and before/after, no duplicate `updated` entry, history shows staff and reason, `goproperty:audit-verify` passes, owner emailed on the `notifications` queue with the note and without the internal reason, agent and other organizations not emailed); **reason required** (form and service); **non-permitted staff** (support-agent, compliance-officer, finance-admin: actions hidden, policy denies, service refuses, nothing audited or sent); reactivation returns to `restricted`, audited and emailed with the workspace link; invalid transitions refused.
- Existing test changed: `StaffTwoFactorEnforcementTest::consolePageUrls()` fills the `record` parameter of the two new resources with an organization / user id (and the list check now expects `/customers/` and `/customer-users/`).

**Follow-ups:**
- P05: `SubscriptionService` sets `organizations.status` to `suspended` at grace end and restores it on payment (BILLING_PAYMENTS.md § 2 now says so). If staff and billing both suspend, decide which reactivation wins (today the console's reactivate simply returns to `status_before_suspension`).
- P03 tenant portal: apply the read-only rule to a suspended organization's records with the payment-proof exception (ARCHITECTURE.md § 4 says how).
- Compliance restrict (`compliance.restrict`, ONBOARDING_COMPLIANCE.md § 5.5) is not built here; it belongs with the compliance queue (P01-03 / compliance tasks).
- Livewire refusals show Livewire's error modal with the 403 message; a friendlier toast can come with the workspace UI kit (P02).
- Not checked in a browser (the console pages and the notice render in tests).

**Next:** P01-04, P01-05, then P01-03 and P01-08.

### P01-07 — Complete (2026-10-26): console staff management, configurable roles, 2FA enforcement, two-person approvals

**Delivered:**
- **Permission catalogue in code** (`App\Platform\Staff\StaffPermission`, 51 permissions on the `staff` guard, grouped by console area) with the four protected permissions from PLATFORM_CONSOLE.md § 1 (`impersonate.write`, `backups.approve_production_restore`, `roles.manage`, `data.purge`). Includes § 6's `impersonate.read_only`, `impersonate.write`, `impersonation.view_log` with their default grants (ready for P01-08) and P01-09's `system.status` (technical-admin, super-admin). The interim `ConsolePermissions` (every active staff member held the settings permissions) is gone: settings pages and the heartbeat now follow roles.
- **Default roles** (`DefaultStaffRole`): the eight roles of § 1 with descriptions and grants; `super-admin` holds the whole catalogue, is protected (never edited or deactivated). `StaffRolesSeeder` in `InstallSeeder::SEEDERS` (idempotent; never undoes console edits: default grants on creation, later only permissions new to the catalogue).
- **Schema** (three migrations): `roles` gains `is_default`, `is_protected`, `description`, `active`; `platform_staff` gains `invited_by_staff_id`, `invitation_token_hash`, `invitation_expires_at`, `invitation_accepted_at`, `deactivated_at`; new `approval_requests` (action, subject morph, payload, summary, reason, requested/approved/rejected by, status, decision note, expires_at, decided_at).
- **Permission checks** (`HasStaffRoles` on `PlatformStaff`): spatie's check is replaced so a staff permission comes only from active roles, only for an active account **with 2FA enrolled**. All grant/removal methods run outside any organization context.
- **Services:** `StaffRoles` (grant/revoke, create/update/deactivate/reactivate roles, protected permissions only by a super-admin and only after a second super-admin approves), `StaffLifecycle` (invite by email, accept invitation, activate after 2FA, deactivate, reactivate, edit details/IP allowlist), `SuperAdminFloor` (active super-admins never drop below min(current, 2), serialised on the role row), `ApprovalService` (request/approve/reject/expire, handler registry for later actions; nobody decides their own request; decider needs the action's permission), `RoleGrantApproval` (role grants approved by a second super-admin), `FirstSuperAdmin` (installer). Every change is written to the hash-chained audit log (log `staff`) with the acting staff member.
- **Two-person role grants:** granting `super-admin`, `finance-admin`, `compliance-officer` or any role carrying a protected permission opens an approval request; the grant takes effect only on approval. Bootstrap exception (documented in § 1): while nobody else holds `super-admin`, the lone super-admin's grant of `super-admin` applies at once (audited `bootstrap: true`).
- **Installer:** `InstallationSteps::createSuperAdmin()` (shared by `goproperty:install` and the web wizard) calls `App\Platform\Staff\FirstSuperAdmin::grant($staff)`, also on resume. It only works for the first staff account.
- **2FA and lifecycle in the console:** every console page (and Livewire update: the 2FA middleware and the new `StaffAccountState` are persistent) sends staff without 2FA to enrol; invitees open the emailed link (`/{ADMIN_PATH}/invitation/{token}`, one use, 7 days, stored hashed), set a password, are signed in, must enrol 2FA, and become active on the next request. Deactivated staff are signed out at their next request (any browser). `last_login_at` is recorded.
- **Filament pages** (`App\Platform\Filament`): Staff (list with roles, status, 2FA, last login; profile with history; Invite, Grant/Remove role, Edit details incl. IP allowlist, Send invitation again, Deactivate/Reactivate), Staff → Roles (`/staff-roles`), Staff → Approvals (`/approvals`, pending badge). Policies: `PlatformStaffPolicy`, `StaffRolePolicy`, `ApprovalRequestPolicy`. A warning shows on every console page while fewer than two active super-admins exist.
- **Scheduler:** `approvals-expire` every 15 minutes (`Schedule::call`).
- **Docs:** PLATFORM_CONSOLE.md § 1 (two-person approvers, "How it is built"), DATABASE_SCHEMA.md (`platform_staff`, staff roles, `approval_requests`, `permissions`), ARCHITECTURE.md § 5, INSTALLER.md step 6.

**Tests and evidence (local, MariaDB 11.4):**
- Orchestrator's review run (branch with latest `main`): `composer ci` Pint passed, Larastan 0 errors, Pest **957 tests: 956 passed, 1 skipped, 6,333 assertions**.
- `composer ci`: Pint passed, Larastan 0 errors, Pest **957 tests (956 passed, 1 skipped: the existing module-boundary check), 6,333 assertions**, 0 failed.
- **AC: a two-person action cannot be approved by its requester:** `StaffApprovalsTest` "cannot be approved by its requester, by the service, the policy or the console" (dataset super-admin / finance-admin / compliance-officer: service refuses approve and reject, policy denies, console hides the buttons, the role is not held; a second super-admin approves through the console and only then the grant applies, audited), plus "needs the right permission to approve", "needs a second super-admin, not just roles.manage", reject, expiry, duplicate refusal, deactivated-meanwhile, generic refund action, bootstrap.
- **AC: staff without 2FA are forced to enrol:** `StaffTwoFactorEnforcementTest` walks every GET route of the console (staff, roles, approvals, settings, previews, heartbeat, ping) for a super-admin without 2FA and for an invited staff member on first sign-in (after setting a password): each redirects to the 2FA set-up; such staff hold no permission; after enrolling the invitee becomes active. A companion test opens every one of those pages for a super-admin with 2FA.
- `StaffRolesTest` (catalogue and default grants incl. § 6, seeder keeps edits, settings pages follow roles, custom role in the console, rename/permissions audited, protected rules, deactivation), `StaffLifecycleTest` (invite from the page, unique email, hashed one-time token, expired/replaced links, password rule, deactivation ends sessions and sign-in, floor of two super-admins and the warning, profile history, last login, details/IP allowlist).
- Installer: `InstallCommandTest` (CLI install: the staff account holds exactly `super-admin`, catalogue and default roles seeded, grant audited; resume keeps it), `WebInstallWizardTest` (the wizard's account holds `super-admin`).
- Existing tests changed: `staffMember()` now gives `super-admin` by default (console access comes from roles; `roles: []` for none) and `staffNamed()` was added; three `OrganizationRolesTest` cases use `roles: []` because they create their own staff roles; `ConsolePanelTest` "refuses staff who are not active" split: invited → 403, deactivated → signed out and sent to sign-in (the new lifecycle rule); `SchedulerHeartbeatTest` checks `system.status` with an analyst (403) and a technical admin (200) instead of redefining the gate; `InstallerCommandsTest` "fails on pending migrations" now deletes exactly the create migration (a second migration name now contains `platform_staff`).

**Follow-ups:**
- P01-08 uses `impersonate.read_only` / `impersonate.write` / `impersonation.view_log` and `ApprovalService::request(ApprovalAction::WriteImpersonation, …)`; write-impersonation requests have no handler (the session checks the approved request).
- Later tasks register handlers for `refund`, `production_restore`, `data_purge` with `ApprovalService::handle()` (StaffServiceProvider or the module's provider).
- Reactivating a deactivated staff member restores their roles without a second approval (spec silent).
- `goproperty:staff-reset-2fa` (INSTALLER.md § 9) is not built here.

**Next:** P01-02 (registration wizard) and P01-08 (impersonation).

### P01-09 — In review (2026-10-26): web installer wizard

**Status:** In review. Everything is built and drilled locally on MariaDB 11.4.12, and every test passes. The AC names MySQL 8 and MariaDB 10.6, which only GitHub Actions can run, and Actions has no runners yet (P00-02). Same situation as P00-06.

**To close it:** once Actions starts jobs, run **Install drill** by hand (or let the nightly run). Confirm all four database jobs pass (MySQL 8.0/8.4, MariaDB 10.6/11.4); each now walks the web wizard too. Then mark P01-09 complete.

**Delivered** ([INSTALLER.md § 4–5](../INSTALLER.md)):
- **First screen and unlock** (`GET /install`, `POST /install/unlock/token|file`). These run before `APP_KEY` exists, with no session, cookie or CSRF.
  - **Setup token:** checked with `SetupToken::verify()`.
  - **File check** (`App\Installer\Web\UnlockChallenge`): a `GP-UNLOCK XXXX-XXXX-XXXX` phrase, valid 30 minutes. Only hashes are stored, and the 20 most recent phrases stay valid, so a reload doesn't break a file already made. The file is read from `storage/app/install-unlock.txt`. A byte-order mark, CRLF and lower case are tolerated; symlinks and files over 512 bytes are refused. The file is deleted on success.
  - **Checks on each unlock post:** same-origin (`Origin`, or `Sec-Fetch-Site: same-origin` plus a matching `Referer`), HTTPS except on local hosts (with AutoSSL / Let's Encrypt advice), and 5 attempts per IP per 15 minutes. Attempts are counted in a `flock`ed file with hashed IPs (`UnlockAttempts`).
  - **On success:** `.env` is created from `.env.example` with a key (`InstallationSteps::ensureApplicationKey`), `APP_DEBUG=false` is written, and the encrypted file session starts in the same request.
- **Session and grant:**
  - **Session:** the wizard has its own encrypted file session (`gp_install_session`, path `/install`, Strict, Secure on https; `InstallerSession`). `InstallerWebStack` builds the cookie, session and CSRF middleware only once a key exists, so a key-less request never fails at terminate.
  - **Grant** (`InstallerGrant`): a token in the session and its hash in `install.grant`, so there is one grant at a time and a new unlock ends the old browser's access.
  - **Rotation:** the first use swaps both the token and the session ID.
  - **Lifetime:** 60 minutes from the last completed step. Each step and each migration batch renews it; viewing pages does not. On expiry the owner unlocks again in the browser, and nothing is lost.
- **Steps** (`App\Http\Controllers\Install\*`, `WizardProgress`): steps open in order. Progress is read from the markers, the `migrations` table and the staff table, so a lost session or a re-unlock resumes. Only "server check seen" and "email/storage skipped" live in the session. If `.env`'s database stops matching the checkpoint, every step shows "Install stopped" (409).
  1. **Server check:** the detailed report, grouped. Passing checks are collapsed; failures show cPanel and Plesk fixes. Blocking failures stop the install.
  2. **Site & database:** "Test connection" writes nothing; "Save" goes through `configureSite` (probe, `.env`, checkpoint). Passwords are never echoed; if left empty later, the saved one is kept.
  3. **Build:** one batch per request (`InstallationSteps::migrateBatch()`, new) with a `<progress>` bar ("5 of 17") that continues by itself. After the last batch, `seed()` runs. A failed migration offers "Empty the database and start this step again" (`FreshDatabaseReset`, typed database name).
  4. **Email & storage:**
     - **Email:** SMTP presets for Postmark, Mailgun and SES, or any SMTP host, then a test email. It must succeed or be skipped.
     - **Storage** (`StorageSettings`, `StorageProbe`): S3-compatible. It writes, reads back and deletes a file, and writes `AWS_*` only after that test passes. Errors are explained with the keys removed. Storage can also be skipped.
  5. **First super-admin:** created with the shared `createSuperAdmin`, which holds the P01-07 hook comment for the `super-admin` role. 2FA is enrolled in the wizard (`TwoFactorEnrolment`):
     - a QR code, manual key and `otpauth` link, confirmed with a 6-digit code;
     - stored the way Filament stores it (secret encrypted, 8 recovery codes hashed);
     - the codes are shown once with Download, Copy and a required "I've saved my recovery codes" tick.
     - Resuming with the same email and password works; another account is refused.
  6. **Finish:**
     - The `Finalizer` runs (cleanup checkpoint first, `ADMIN_PATH`, secrets removed, lock and row, caches).
     - The install is written to the audit log (`InstallationSteps::recordInstallation`, `log_name` `installer`, version, `via`, IP). The CLI now does this too.
     - **Console handover** (`ConsoleHandover`): a database session under `gp_staff_session` with the console's flags, logged as a staff sign-in. Not done when `ADMIN_DOMAIN` is set.
     - **Finish screen**, shown once as the reply to the post:
       - the console URL ("Bookmark this");
       - the detected cron line, with cPanel and Plesk steps;
       - a live cron check polling the new console route `/{ADMIN_PATH}/system/heartbeat` (`SchedulerHeartbeatController`, permission `system.status`, added to the interim `ConsolePermissions`);
       - the installation record as a one-time download (with `APP_KEY`; the web install leaves no copy on the server);
       - next steps (anything skipped, Paystack, Termii, Turnstile, backups, theme, Cloudflare, `APP_DEBUG` confirmed off).
- **Views:** plain PHP (`resources/views/installer/layout.php`, `steps/*.php`) on the login page's split shell, ported to the static `public/install.css`. A brand panel with a vertical step list (`aria-current="step"`) becomes a "Step 3 of 7" bar on phones. Checked in the browser at desktop and 360 px.
- **`ArtisanOptimizer`:** puts the application back as the facade root and container instance after `config:cache`, which boots a second app and leaves the facades pointing at it. Harmless at the end of a command, but the web finish keeps working in the same request.
- **Install drill** (`scripts/release/install-drill.sh`): after the CLI install, a second split layout is installed by walking the wizard with `curl` against `php -S`, under the same shared-host limits:
  - file-check unlock, then the migrations one batch per request;
  - a test email to a new local SMTP sink (`scripts/release/smtp-sink.php`), with storage skipped;
  - TOTP enrolment (a code from the package's own Google2FA) and the 8 recovery codes;
  - finish, checking the console path, cron line and record with `APP_KEY`;
  - the handed-over console session, and the heartbeat turning fresh after one `schedule:run`;
  - `/install` and the wizard routes return 404, `check-installation` passes, and `audit-verify` passes.

  Both schemas are compared with a plain migrate. `install-drill.yml` now also runs on changes to installer controllers, middleware, routes, views and `install.css`.
- **Docs:**
  - INSTALLER.md § 3, § 4 (phrase handling, attempts file, installer session, grant mechanics, `APP_DEBUG` at unlock), § 5 (plain PHP views, step order and resume, batch settings, storage written after its test and skippable, 2FA storage, audit entry, handover, record download), § 7 and § 12.
  - [install-recovery.md](runbooks/install-recovery.md): new § 5 on the web installer, and the record that was never downloaded.
  - PLATFORM_CONSOLE.md § 2 (the heartbeat endpoint) and AGENT.md § 2.

**Tests and evidence (local, MariaDB 11.4.12):**
- Orchestrator's review run after merging P01-01 into the branch: `composer ci` Pint passed, Larastan 0 errors, Pest **917 tests: 916 passed, 1 skipped, 5,377 assertions**. The `BackupRunnerTest` flake noted below came from the shared backup temp folder that P01-01 fixed.
- `composer ci`: Pint passed, Larastan 0 errors, Pest **878 tests: 877 passed, 1 skipped, 5,131 assertions**. 54 tests are new:
  - `tests/Feature/Installer/WebUnlockTest.php` (23)
  - `WebInstallWizardTest.php` (10)
  - `NoHttpRecoveryTest.php` (7)
  - `tests/Feature/Console/SchedulerHeartbeatTest.php` (4)
  - `tests/Unit/StorageSettingsTest.php` (10)

  `tests/Support/InstallerBrowser.php` is a cookie-carrying test browser that drops in-memory sessions between requests.
- **AC: web install drill (file-check unlock, TOTP enrolment, finish):**
  - **In Pest:** `WebInstallWizardTest` › "installs GoProperty over HTTP, the same as the command line" uses a real throwaway database, a file-check unlock, a real test email over SMTP and TOTP enrolment. After finish it checks the same `.env` values, markers, staff, audit entry and schema as a plain migrate. "hands the owner a signed-in console session…" opens the console dashboard and heartbeat with the handed-over cookie.
  - **Drill:** on freshly built packages `goproperty-0.19.0-rc.1` and `rc.2`, `install-drill.sh` passed every step, CLI and web. Batches ran in 0–1 s, the finish took 5 s under `max_execution_time=30`, and both schemas were identical to a plain migrate (186 columns and tables). The release drill (`verify-drill.sh`) also passed on rc.2.
  - **Local caveat, as in P00-06:** this PHP has no `intl`, so the local run used a scratch copy of the script that drops `intl` from the unpacked checker (restored before `verify-package`). The repository script and the zip were unchanged.
  - **Not run here:** MySQL 8 and MariaDB 10.6 (GitHub Actions only, blocked by P00-02).
- **AC: after finish `/install` is 404 and no HTTP route can reset or re-run installation:**
  - `NoHttpRecoveryTest`: every installer route has `EnsureInstallerOpen` first. With valid unlock proofs present, every installer route and method returns 404 with no cookie and changes nothing. Each single marker (lock, row, cleanup checkpoint) closes them all.
  - It also checks that no route outside `/install` injects installer services or uses an installer controller, and that no web-reachable code (`app/Http`, `app/Livewire`, `app/Platform`, views, routes) mentions the installer services or the install, finish, migrate or wipe commands.
  - The walk-through test and the drill also show 404 after a real finish.
- **Flaky, unrelated:** `BackupRunnerTest` › "reports each destination after a manual run" failed once in the first parallel run (exit 1 instead of 0). It passed 3 times on its own and in 3 later full parallel runs, including the final `composer ci`. The dumper's queries are scoped to the current database, so the new scratch-database tests should not affect it.

**Follow-ups:**
- P01-07: assign `super-admin` in `InstallationSteps::createSuperAdmin` (comment in place). Move `system.status` into the permission catalogue (`technical-admin`, `super-admin`).
- `ConsolePanelProvider` and `ConsolePermissions` gained one line each, which may conflict with P01-07 and P01-08 edits to the same files.
- The command-line install still does not ask for S3 storage (INSTALLER.md § 7); the wizard tests it.
- The upgrade drill (previous release → new) remains open (INSTALLER.md § 12).

**Next:** P01-01 to P01-08 continue. P01-09 closes with P00-06 once the Install drill workflow runs.

### P01-01 — Complete (2026-10-26): organization switcher, roles per organization (spatie teams)

**Delivered:**
- **spatie/laravel-permission 8.3.2** (`~8.3.2`) with teams: team = organization, team column `organization_id` (`config/permission.php`).
  - **Tables** (five migrations, one table each, literal names for the installer's `OwnTables`): `permissions`, `roles` (`organization_id` nullable FK), `model_has_permissions` and `model_has_roles` (surrogate `id`, nullable `organization_id` FK, unique `(organization_id, role/permission, model_id, model_type)`), `role_has_permissions`. Spatie's stub puts the team column in the primary key, which cannot be null; the surrogate key lets staff hold global (no-organization) roles in the same tables.
  - **The team follows `OrganizationContext`:** `App\Tenancy\OrganizationTeamResolver` reads the context on every call and refuses `setPermissionsTeamId()`, so a team id cannot outlive a request or a queued job. `OrganizationContext::outside()` runs code with no organization.
  - **Customers** (`App\Tenancy\HasOrganizationRoles` on `User`): forgets roles/permissions loaded for another organization (same model across `run()` calls or restored in a job), refuses grants outside an organization, to non-members, or of a role another organization defined; `joinOrganization()`, `roleNamesIn()`, `hasRoleIn()`, `roleNamesByOrganization()`, `workspaceOrganizationIds()`/`workspaceOrganizations()`.
  - **Staff** (`App\Platform\Console\HasStaffRoles` on `PlatformStaff`): spatie roles on the `staff` guard, always read and granted outside any organization context, so they stay global even during impersonation (P01-08). The staff catalogue, console and the extra `roles` columns stay with P01-07.
- **Customer roles and permission catalogue in code:** `App\Enums\OrganizationPermission` (26 permissions incl. `workspace.access`) and `App\Enums\OrganizationRole` (`org-owner`, `org-admin`, `agent`, `property-owner`, `tenant`, with their grants per ARCHITECTURE.md § 5). `OrganizationRolesSeeder` (idempotent, syncs grants back to the catalogue) is in `InstallSeeder::SEEDERS`; the demo seeder makes the demo user `org-owner` of Demo Realty.
- **Workspace access** (extra requirement from the orchestrator): `/o/{slug}` admits only members holding `workspace.access` there (`org-owner`, `org-admin`, `agent`), through `OrganizationPolicy::view` in `SetOrganizationContext`. Tenant-only and property-owner-only members, members with no role, strangers, unknown slugs and old slugs of such organizations all get the same 404; old slugs still 301 for real workspace members.
- **Organization switcher** in the app shell: lists only workspaces the user may open, with their role in each; choosing one posts to `POST /organizations/{slug}/switch` (`SwitchOrganizationController`, policy `switchTo`, 404 otherwise), which opens it and stores it as `organization_user.is_default` (exactly one per user). **Picker** at `/dashboard` (cards with role and "Last used").
- **Landing after sign-in** (`App\Tenancy\WorkspaceLanding`) for password, 2FA and passkey sign-in (custom Fortify `LoginResponse`, `PasskeyLoginResponse`, updated `TwoFactorLoginResponse`): the intended URL, else the only workspace, else the last chosen, else the picker.
- **`App\Models\Membership`** (`organization_user`, `BelongsToOrganization`): the organization-scoped way to list members. **`App\Models\Role`** (spatie role with `organization()`).
- **Reusable cross-tenant probes:** `Tests\Support\OrganizationScopedModels` (+ dataset `organization-scoped models` in `tests/Datasets/`) lists every organization-owned model with a factory; `CrossTenantAccessTest` runs each through no-context reads/creates, scoped listing, find by foreign id, bulk update/delete, create in and move to another organization, and a URL-bound foreign id (404), and fails while any `BelongsToOrganization` model in `app/` or `Modules/` is missing from the list. Later tasks add their models there.
- `organizations` added to `AdminPath::RESERVED`.
- **Fix outside the task (P00-12):** spatie's `BackupJob` takes its temporary folder from the config repository, not from `BackupRunner`'s per-run config, so every run shared `backup-temp/temp` and emptied the other's zip (the "own folder per run" comment was not true). It showed up as a parallel-test race in `GoogleDriveAndFilesTest`. `BackupRunner` now points the repository at the run's folder during the job; regression test added.
- **Docs:** DATABASE_SCHEMA.md § 1 (`organization_user`, the five spatie tables) and § 8 (staff roles share the tables, null organization); ARCHITECTURE.md § 4 (switcher, landing, team resolver, cross-tenant probes) and § 5 (staff roles global, catalogue location, who opens the workspace).

**Tests and evidence (local, MariaDB 11.4):**
- `composer ci` (agent, twice; then the orchestrator's review run): Pint passed, Larastan 0 errors, Pest **863 tests / 2,997 assertions**, 0 failed, 1 skipped (the existing module-boundary check). The review run first caught a flaky test: under a long parallel run the queue tick's 192 MB memory guard could stop the tick before any job ran. `OrganizationRolesTest` and `QueueTickTest` now raise that limit for the test and assert the tick drained.
- **AC: two orgs, a user in both can switch:** `OrganizationSwitcherTest` "lets a member of two organizations switch between them" (picker → switch → `/o/other-realty` with role Agent → switch back → next sign-in lands on the last choice), "keeps exactly one default organization", one-org user goes straight to it, 2FA and passkey landing, intended URL wins.
- **AC: data never crosses orgs:** `CrossTenantAccessTest` (dataset over every organization-owned model + completeness check, members listing, roles/permissions per organization, another organization's custom role, organization settings); `OrganizationRolesTest` (team from context only, per request, per queued job through the real queue tick, stale relation never answered, refused grants, staff global roles incl. inside an organization context).
- **AC: `/o/{slug}` of an org you don't belong to returns 404:** `OrganizationSwitcherTest` "answers 404 for an organization the user does not belong to" (another org, its previous slug, a third org, unknown slug; switching to them also 404), tenant/property-owner-only member 404 and not listed, member without a role 404; `SetOrganizationContextTest` still green.
- Existing tests changed: `SurfacesTest` now expects the one-org demo user to land on `/o/demo-realty` instead of `/dashboard` (the new landing rule); `AppShellTest`, `ThemePublishingTest`, `SurfacesTest` and `SetOrganizationContextTest` make their user a member with a role (`joinAs()` in `tests/Pest.php`) instead of a bare `attach()`, because a member without a workspace role is now refused.

**Follow-ups:**
- The unique indexes on `model_has_roles`/`model_has_permissions` include the nullable `organization_id`; MySQL and MariaDB treat NULLs as distinct, so the database does not stop a duplicate global (staff) assignment. Spatie checks before inserting; P01-07 should keep staff grants going through `HasStaffRoles`.
- P01-04 (invitations) and P01-06: removing a member must also remove their role assignments in that organization (`model_has_roles` rows with its `organization_id`).
- P01-07: add `is_default`, `is_protected`, `description`, `active` to `roles` and the staff catalogue; create staff roles with `organization_id` null (outside an organization context). `permission:create-role --team-id` and `permission:assign-role --team-id` are refused by the resolver; use code with `OrganizationContext::run()`.
- P01-08: staff permission checks during impersonation go through `HasStaffRoles` (already outside the context).
- The first Livewire component inside `/o/{slug}` (P02) needs `SetOrganizationContext` as Livewire persistent middleware, or its update requests run without a context (they fail closed today).
- Tenants and agency-invited owners land on the picker until P03/P04 route them to `/portal` and `/owner`. `users.last_active_organization_id` (DATABASE_SCHEMA.md § 1) is not used; `organization_user.is_default` holds the last choice.
- Agent narrowing by building (`agent_building`) and record-level rules stay in the policies of the tasks that add those models.

**Next:** P01-02 (registration wizard; the first user joins as `org-owner` with `joinOrganization()`).

### P02 and P03 split into sub-tasks — 2026-10-26

[docs/plans/P02-P03-subtasks.md](plans/P02-P03-subtasks.md) splits P02 into 10 sub-tasks and P03 into 22, each with what it builds, its dependencies and its acceptance. Every original acceptance criterion maps to one sub-task. It also sets the run order: waves of at most two sub-tasks in parallel, each pair chosen so they do not edit the same files. The file ends with 15 decisions and doc gaps the product owner should settle before P02 starts (module list, cycle-maths naming, public lookups without an organization context, webhook URL, billing-transfer statuses and others).

### P00-13 — Blocked (2026-10-26): staging install and deploy on merge, built and drilled locally

**Status:** Blocked. Everything that can be built without a server is done and drilled on one machine. The AC needs a real staging host (PRE-04, with the product owner) and GitHub Actions starting jobs (P00-02).

**To close it:**
1. Create the staging hosting account (PRE-04) with SSH, PHP 8.4 and an empty MySQL/MariaDB database.
2. In GitHub, create the `staging` environment with the variables `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PATH`, `DEPLOY_PHP` (and `DEPLOY_PORT` if not 22) and the secret `DEPLOY_SSH_KEY` ([shared-hosting.md § 3](runbooks/shared-hosting.md)).
3. Build a deploy archive and run `scripts/deploy/first-install.sh` over SSH, which runs `goproperty:install` (runbook § 2 step 4). Add the cron line it prints.
4. Add the two backup destinations there and do P00-12's real backup run.
5. Merge a change to `main`; the Deploy workflow deploys staging. Check `/up`, the login pages and that `current` points at the new release. Then mark P00-13 Complete.

**Delivered:**
- **First install into the deploy layout:** `scripts/deploy/first-install.sh` unpacks a deploy archive into `releases/<name>/`, links `.env` to `shared/.env` (mode 600) and `storage/` to `shared/storage/`, runs `goproperty:install` with any options after `--`, checks that the key was written to the shared file, and only then points `current` at the release. It refuses to run if `current` already exists.
- **Installer fixes for that layout:**
  - `AtomicFile` now follows symlinks before its write-and-rename, so the installer writes `shared/.env` instead of replacing the release's `.env` link with a file the next deploy would lose.
  - `CronLine` prints `<app>/current/artisan schedule:run` when the app runs from `releases/<name>` next to `shared/`, so the cron line survives deploys.
- **Deploy on merge:** `.github/workflows/deploy.yml` now runs on every push to `main` and deploys `staging`; production stays a manual run. Until the `staging` environment has `DEPLOY_HOST`, a push run ends with a warning instead of failing. Runs per environment are serialised.
- **Backup before migrating (optional):** with the environment variable `DEPLOY_BACKUP_FIRST=1` (meant for production), `remote-deploy.sh` runs `goproperty:backup-run db` before migrations. A failed backup stops the deploy and the live release stays.
- **Deploy drill:** `scripts/deploy/deploy-drill.sh` runs under shared-host limits (30 s, 256 MB, `exec`/`proc_open`/`pcntl` off):
  1. first install via `first-install.sh` and `goproperty:install`, then checks for the link, `APP_KEY`, `ADMIN_PATH`, mode 600, the install lock, the cron line, and the site, `/login` and the console login over HTTP;
  2. an upgrade that keeps `shared/.env` byte for byte;
  3. a release with a failing migration, which never goes live;
  4. `BACKUP_FIRST=1` without a destination, which stops the deploy;
  5. pruning to `KEEP_RELEASES=2`.

  The Install drill workflow now also builds a deploy archive and runs this drill, and runs on pull requests that touch `scripts/deploy/`.
- **Docs:** [shared-hosting.md](runbooks/shared-hosting.md) § 2 (first install with the script), § 3 (deploy on merge, `DEPLOY_BACKUP_FIRST`) and § 4 (backups now use the pure-PHP dumper and `goproperty:backup-destination:add`; the old text still said `mysqldump` via `exec`).

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **823 passed, 1 skipped (2,813 assertions)**. New: writing `.env` through a symlink, and the cron line in the releases layout.
- Deploy drill: passed, all five steps.
- Release `0.13.0-rc.1` (65 MB) built; the verify drill and the install drill passed (schema identical, 186 columns and tables).
- `deploy.yml` and `install-drill.yml` parse as valid YAML. Neither has run in GitHub, because Actions has no runners yet (P00-02).

**Follow-ups:**
- HSTS where staging and production terminate HTTPS (from P00-10a).
- Give the `production` environment a required reviewer when it is created.

**Next:** P01-01 (organization switcher, roles per organization). P00-13 and P00-12 close when staging exists.

### P00-12 — In review (2026-10-26): P00-12b files backup, Google Drive destination, our own dumper

**Status:** In review. Everything is built and drilled locally. The AC "land encrypted in both destinations" still needs one real run against a real S3-compatible bucket and a Google Workspace Shared Drive. Their accounts are PRE-03, with the product owner.

**To close it:**
1. Add both destinations with `goproperty:backup-destination:add` (staging, P00-13).
2. Run `goproperty:backup-run db` and `goproperty:backup-run files`.
3. See both zips in each place.
4. Run `goproperty:backup-restore latest --database=…`.

**Delivered (12b):**
- **Files backup:** a separate nightly job at 02:30, encrypted the same way, of `storage/app/brand`, `private` and `public`. It has its own backup name, so retention never mixes it with database backups.
- **Google Drive destination:**
  - **Built:** `App\Backup\GoogleDrive\DriveClient` (a service-account JWT signed RS256 for an access token; list, folders, resumable uploads in 8 MiB chunks, download, delete with a fallback to trash) and `GoogleDriveAdapter`, a Flysystem adapter that maps paths onto folders in a Shared Drive. Registered as the `google_drive` disk driver.
  - **Why our own:** the community adapter and `google/apiclient` require Guzzle 6/7, while this Laravel 13 app uses Guzzle 8. The Google SDK is also very large.
- **Destination commands** (the console screen is P06-10):
  - `goproperty:backup-destination:add` takes S3 options or a Drive service-account JSON file. Secrets come from files or a hidden prompt, never the command line. It writes, reads and deletes a test file, and **switches the destination off if that fails**.
  - Also `:list`, with the last database and files backup per destination, and `:test`, with `--disable`/`--enable`.
- **Our own dumper:**
  - **Why:** `druidfi/mysqldump-php` turned out to be **GPL-3.0**. Bundling it in the release zip would have put copyleft terms on GoProperty, and it also shipped a `.env.mysql` file that the package check rightly refused.
  - **Replacement:** `App\Backup\PdoMySqlDump`, a consistent snapshot (`START TRANSACTION WITH CONSISTENT SNAPSHOT`). Rows stream unbuffered into multi-row INSERTs of about 1 MB; non-UTF-8 bytes go as hex, so a statement never spans lines; generated columns are skipped.
  - **Licence check:** every package is now MIT, Apache-2.0 or BSD, apart from LGPL dompdf (fine as a library), dual-licensed nette, and the paid Flux licence.
- **Concurrent runs:** each run now uses its own temporary folder. Spatie empties a shared one at the start, so a manual run could have wiped the nightly run's zip; the parallel test suite found this.
- **Docs:** DATABASE_SCHEMA.md `backup_destinations`, and PLATFORM_CONSOLE.md § 4 on the Drive adapter.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **822 tests / 2,807 assertions**, and **six consecutive full parallel runs passed**. New: 6 in `tests/Feature/Backup/GoogleDriveAndFilesTest.php` against an in-memory Drive v3 fake (`tests/Support/FakeGoogleDrive.php`), and a round-trip drill.
- **Google Drive:**
  - **Sign-in:** the JWT is verified with the service account's public key, and one token serves many requests.
  - **Files:** folders are created once, a re-upload replaces the old file, and read, size, date, list and delete work.
  - **Large files:** a 700 KB file goes up in three 256 KB resumable chunks.
  - **Full run:** a database backup goes to Drive and is downloaded and decrypted again; a 403 from Drive is recorded as a failed run with Google's message.
- **Files backup:** the scheduled `backup-files` event (`30 2 * * *`) produced one AES-256 zip containing an uploaded brand file, unreadable without the password and readable with it.
- **Commands:** add (S3 and Drive), list, check, switch off; a Drive without access is saved switched off; a bad key file is refused.
- **Round trip:** line breaks, quotes, a `;` at the end of a line, emoji and Yoruba diacritics, binary with NUL bytes, NULL, an empty blob, BIGINT and a generated column all come back identical after a dump and restore.
- **Drills:** release `0.12.0-rc.3` (62 MB) built, the verify drill passed and the install drill passed (schema identical, 186 columns and tables).

**Follow-ups:**
- One real run to S3 and to a Shared Drive (above). Then mark P00-12 Complete.
- **Spec gaps:**
  - The files backup is a full encrypted zip each night, not the spec's incremental sync with a weekly manifest. That is fine at launch size; revisit when media grows, e.g. by mirroring media to a second S3 provider as § 4 suggests.
  - The "config snapshot" in § 4 is covered by the database backup (settings, plans).
- Console screens for destinations, run now, download, verify and restore to staging: P06-10. Alerts on failed backups: P08-03.

**Next:** P00-13 (staging installed with the installer, deploy pipeline). The real backup run belongs there too.

### P00-12a — Complete (2026-10-26): database backup, encryption, restore and verify

P00-12 is split into sub-tasks (IMPLEMENTATION_PLAN.md § 3.1): **12a** the database backup, its encryption, restore and verify; **12b** the files backup, the Google Drive destination and adding destinations from the command line. P00-12 is marked complete when both are done.

**Delivered:**
- **Dependencies:**
  - `spatie/laravel-backup` 10.3 does the packaging, AES-256 zip encryption, copying and retention.
  - **Dumper:** first `druidfi/mysqldump-php`, replaced in 12b by our own `App\\Backup\\PdoMySqlDump` because that library is GPL-3.0.
  - `league/flysystem-aws-s3-v3` 3.35 provides the S3 destination. The AWS SDK is trimmed to S3 by its official Composer cleanup (`extra.aws/aws-sdk-php`), so `vendor/` grew by about 7 MB and 700 files.
- **No `exec`, `proc_open` or signals** (`App\Backup\BackupRunner`):
  - **Dump:** spatie's dumper factory uses `PhpMySqlDumper` (pure PHP) instead of `mysqldump`, as ARCHITECTURE.md § 12 asks. `BACKUP_DUMPER=mysqldump` switches back where a host allows it.
  - **In-process:** the job runs inside the scheduler's PHP process rather than through `backup:run`, which needs `pcntl`, and the zip step gets the per-run config.
  - **Encryption:** every zip is AES-256 encrypted. The password is `BACKUP_ARCHIVE_PASSWORD`, or is derived from `APP_KEY` (HMAC); `goproperty:backup-password` prints it for a hand restore. The zip is re-opened after writing (`verify_backup`).
- **Destinations** (`backup_destinations`, DATABASE_SCHEMA.md § 8):
  - **Drivers:** `s3` (R2, Spaces, Wasabi, B2…), `google_drive` (12b), and `local`. The local driver is for drills only and is refused in production, since a backup on the same server is not a backup.
  - **Security:** credentials are encrypted in the database and never part of the model's output. Each destination becomes a filesystem disk only for the run.
  - **Failures:** `continue_on_failure` is on, so one unreachable destination does not stop the others.
- **Runs** (`backup_runs`): one row per job and destination, with status, file, size, schedule/manual and staff.
  - **Recording a failure:** a failure, or having no destination, is recorded with its reason and logged as critical. Alerts come with P08-03, and the package's own mails are off.
  - **Retention, per destination after each run:** everything from the last day, one a day for 30 days, one a month for 12 months. The `db` and `files` jobs use separate backup names, so retention never mixes them.
- **Schedule:** database at 01:30 and files at 02:30, as separate jobs, plus a monthly verify on the 1st at 04:00.
- **Restore** (`App\Backup\Restorer`, `goproperty:backup-restore <zip|latest> --database=…`):
  - **How:** it decrypts in PHP and replays the dump statement by statement over PDO, so no `mysql` client is needed.
  - **Safety:** it empties the target first and **refuses the site's own database**. Production restores go into a new database and `DB_DATABASE` is switched (runbook).
- **Verify** (`goproperty:backup-verify`): downloads the newest database backup, restores it into `BACKUP_VERIFY_DB_DATABASE`, checks that every live table and the migration history are there, and marks the run verified.
- **Docs:**
  - `docs/runbooks/restore.md`: what a backup is, restoring with the command, restoring by hand with 7-Zip and phpMyAdmin, files restore, the monthly check.
  - `.env.example`: lists `BACKUP_DUMPER`, `BACKUP_VERIFY_DB_DATABASE` and `BACKUP_ARCHIVE_PASSWORD`.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **815 tests / 2,729 assertions**. New: 4 drill tests in `tests/Drill/BackupDrillTest.php`, which commit real rows, and 12 in `tests/Feature/Backup/`.
- **AC (drilled):**
  - **No exec:** `goproperty:backup-run db` ran in a subprocess with `exec`, `proc_open` and `pcntl` disabled, and the scheduled `backup-db` event (`30 1 * * *`) ran too. Both destinations that take database backups received a zip; a files-only destination received none.
  - **Encryption:** every zip's entry is AES-256 and unreadable without the password.
  - **Restore:** the newest backup was downloaded, decrypted and restored into another database, and a marker row came back. The verify restored and marked the run, another `APP_KEY` cannot open it, and the site's own database is refused.
- **Other tests:** an S3 destination (Flysystem level) gets the zip; one failing destination does not stop the other; inactive and other-job destinations are skipped; a 400-day-old backup is deleted by retention; credentials are encrypted at rest; local is refused in production; password derivation and override; the schedule expressions.
- **By hand:** a real database backup under the same disabled functions took under a second (4.5 KB zip, AES-256 confirmed), and restoring it into `gp_restore` brought back all 22 tables.

**Not yet verified:** a real S3-compatible bucket and Google Drive. Their accounts are PRE-03, still with the product owner. The S3 driver is tested at the filesystem level only.

**Next:** P00-12b (files backup, Google Drive destination, adding destinations from the command line).

### P00-11 — Complete (2026-10-26): audit log hash chain, authentication log for both guards

**Delivered:**
- **Append-only audit log with a hash chain** (`App\Audit`, PLATFORM_CONSOLE.md § 7):
  - **New columns:** `activity_log` gains `impersonator_staff_id`, `ip`, `user_agent`, `reason`, `prev_hash` and `hash`. Spatie's model is replaced by `AuditEntry`, which fills IP, user agent, reason (`AuditContext::because()`) and the impersonator (wired by P01-08).
  - **The chain:** each entry stores the previous hash and an HMAC-SHA256 of its own content, keyed from `APP_KEY` and never the raw key. Someone with database access but without the key cannot forge a valid entry.
  - **Writes:** appends take a row lock on the new one-row `audit_chain_head` table, so concurrent writes cannot fork the chain.
  - **JSON columns:** hashed with sorted keys, because MySQL's JSON type reorders them.
  - **Key rotation:** entries made with a key in `APP_PREVIOUS_KEYS` still verify.
  - **Append-only:** the model refuses updates and deletes, `activitylog:clean` is refused, and the buffered (bulk-insert) mode is off for good.
  - **Existing entries:** sealed into the chain by the migration.
  - **Verification:** `goproperty:audit-verify` walks the chain and names the first entry that was changed, removed or reordered, including removed newest entries. It runs nightly and logs a critical line on a break, and the install drill now runs it after installing.
  - **Who:** the causer is the staff member on console requests and the customer elsewhere; an explicit `causedBy()` still wins.
- **Authentication log for customers and staff** (`authentication_logs`, PLATFORM_CONSOLE.md § 8, built in the app rather than with rappasoft's package so one table covers both guards and all the spec's events):
  - **Customers:** sign-in success, passkey sign-in, failure (known or unknown account), sign-out, 2FA passed and failed, recovery code used, Turnstile failed, the start of a lockout, reset link sent, password reset.
  - **Staff:** sign-in success and failure, 2FA passed and failed, sign-out (including idle timeout).
  - **Stored:** IP, user agent, country from Cloudflare's `CF-IPCountry` when present, and whether the browser was new. A typed email or phone is kept only as an HMAC.
  - **Retention:** rows older than a year are deleted nightly.
  - The sign-in device tracking from P00-10b moved into the same subscriber, so each sign-in is recorded once with its new-device flag.
- **Docs:** DATABASE_SCHEMA.md § 8 describes the chain, the head row and the authentication log.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **799 tests / 2,639 assertions** (22 new in `tests/Feature/Audit/`).
- **AC: tampering breaks verification:** changing an entry's description, properties, causer, time or hash; deleting an entry in the middle; deleting the newest entry; and forging an entry with a plain SHA-256 link are each detected at the right entry, and the command exits 1. Reordered JSON keys and a rotated `APP_KEY` still verify, and unchained older entries can be sealed.
- **AC: logins and failures recorded for both guards:** customer failure (known by phone, unknown), success with new device, sign-out; 2FA failed, recovery code, passed; three failures then lockout; Turnstile failure; reset link only for real accounts; password reset; staff failure (unknown, known), 2FA failed, success, 2FA passed, sign-out. Rows store IP, user agent and country, and the typed identifier only as an HMAC.
- **Also tested:** the app refuses to change, delete or clean entries; the staff causer on a console change; the nightly checks; one-year pruning.
- **Drills:** release `0.11.0-rc.1` built, the verify drill passed and the install drill passed: schema identical (164 columns and tables) and the new audit-chain check passed.
- **Local environment:** the scratch MariaDB and the local install-drill copy were rebuilt, since the scratchpad had been cleared between sessions.

**Follow-ups:**
- Audit-log and access-log viewers (console, org-owner Activity page), and the "staff views of the audit log are logged" rule: P06-07.
- Retention for the audit log (7 years money, 2 years other) needs an archive that keeps the chain verifiable (export, then a signed checkpoint row); `activitylog:clean` stays refused until then.
- Console alert on bursts of failed sign-ins, and the sessions list with "log out other sessions": with the console dashboard (P06) and the profile (P01).
- The country comes from a header Cloudflare sets. Off Cloudflare a client could send it, so it is approximate only.

**Next:** P00-12 (backups baseline).

### P00-10 — Complete (2026-10-26): P00-10c staff sign-in with mandatory 2FA, Sign-in security settings

All three sub-tasks are done (10a customer sign-in, 10b 2FA and the other sign-in screens, 10c staff), and every AC passes:
- **Pages match the templates:** `login.html` and `login-2fa.html` were compared element by element at desktop, and `login.html` at mobile (10a, 10b).
- **Tests cover:** the rate limit, the lockout and identical answers for unknown and known accounts, for customers (10a) and staff (here). They also show that staff cannot sign in without 2FA (here).

**Delivered (10c):**
- **Mandatory staff 2FA:**
  - **How:** Filament's authenticator-app 2FA, with recovery codes, is required on the console (`isRequired`).
  - **Storage:** `PlatformStaff` keeps the secret and the hashed recovery codes in the existing `two_factor_*` columns.
  - **First sign-in:** a staff member without 2FA is signed in only to the set-up screen. Every console page, the theme preview and the session ping redirect there until an authenticator is enrolled. The preview and ping routes needed Filament's 2FA-required check added by hand, since Filament adds it only to its own pages.
  - **Later sign-ins:** the password, then the 6-digit code. Filament keeps one generic error, equal timing and a per-IP limit of 5 tries a minute.
  - **Step-up:** now asks for the code too (password checked first, so a typo does not use up a code).
- **Staff sign-in page** (`StaffLogin`): dark neutral background and one centred card, headed "Staff console". There is no sign-up link and no marketing, and "Remember me" is gone, since staff use 2FA on every sign-in (§ 9.6).
- **30-minute idle timeout** (`StaffIdleTimeout`): the session ends after 30 minutes without a request. Two minutes before, a dialog with a countdown offers "Stay signed in", which pings the server. Any console request counts as activity.
- **Optional per-staff IP allowlist** (`StaffIpAllowlist`, `platform_staff.ip_allowlist`, addresses or CIDR ranges): from anywhere else the session ends and the reply is a plain-text 404 with no branding. Editing the list comes with staff management (P01-07).
- **Settings → Sign-in security** (PLATFORM_CONSOLE.md § 9.6). It applies on save and is audited by the settings store.
  - **Fields:** passkeys on/off, Turnstile mode and keys, lockout count and minutes, remember days, trusted-device days, and who must use 2FA.
  - **Turnstile secret:** never sent to the browser (shown as "•••• set"). Changing or removing it needs step-up (password and code).
- **Docs:** DATABASE_SCHEMA.md § 1 now lists `user_devices`.

**Interim and flagged:**
- **`auth.require_2fa_for`:** stored and editable, but enforced only once organization roles and Transaction Tiers exist (P01-01/P01-02). The page says so.
- **Who may open the page:** as with the other settings pages, every active staff member until roles exist (P01-07, approved 2026-10-26). The spec has `super-admin`, with `technical-admin` read-only.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **777 tests / 2,547 assertions** (12 new in `tests/Feature/Console/StaffSignInTest.php`). Console tests now create staff through a `staffMember()` helper with 2FA set up, and the console sign-in and step-up tests supply real TOTP codes.
- **AC: staff cannot sign in without 2FA:** without enrolment, six console URLs redirect to set-up; after enrolment the console opens; with 2FA, a wrong code is refused and the right one signs in.
- **Other tests:** identical errors for an unknown email and a wrong password; the 6th attempt is rate-limited; timeout at 31 minutes (but not 10); the ping; allowlist allow and deny (plain 404, signed out); no "Remember me"; the settings save, validate, keep the secret hidden, ask for step-up to change it, leave it alone otherwise, and can remove it.
- **In the browser (local):**
  - **Staff sign-in:** the page showed the dark card.
  - **Enrolment:** signing in as the seeded staff account went straight to "Set up two-factor authentication". Enrolling with the QR code's secret, a real code and the password showed 8 recovery codes, and then the console opened.
  - **Settings page:** Sign-in security rendered.
- **Drills:** release `0.10.0-rc.1` built, the verify drill passed and the install drill passed. The installed schema is identical to a plain migrate (145 columns and tables), and `/` and `/login` answer 200.

**Next:** P00-11 (audit log and access log).

### P00-10b — Complete (2026-10-26): 2FA challenge and the other sign-in screens

**Delivered:**
- **2FA challenge from `docs/design/templates/login-2fa.html`:**
  - **Code entry:** six boxes with auto-advance, backspace, arrow keys, paste and auto-submit. Without the script it is one plain code field.
  - **Account shown masked:** "t•••@domain", or "+234 ••• 4567" for a phone-only account.
  - **Wrong code:** "Codes change every 30 seconds…".
  - **Other ways to verify:** a recovery code (each works once); a passkey, shown only when the account has one; and "Lost your phone and codes? Get help", shown when a support email is set.
  - **Panel:** the template's text, and no activity cards.
- **"Don't ask again on this device for N days"** (`auth.trust_device_days`, 30):
  - **Devices table:** a new `user_devices` table stores the SHA-256 of a random token kept in an encrypted HttpOnly cookie (`gp_device`).
  - **Skipping:** `RedirectIfTwoFactorNeeded` skips the challenge only for that browser and that account, until the date passes.
  - **Trusting:** it is set only when ticked (`TwoFactorLoginResponse`).
- **New-device email:** every customer sign-in records the browser. A browser new to an account that has signed in before gets "New sign-in to your … account" with time (Lagos), browser, IP and a reset-password button. Email only; no SMS.
- **Remember me:** `auth.remember_days` now applies on every sign-in route (password, 2FA, passkey) through `RememberDuration`.
- **Forgot password:**
  - **Same reply:** one "Email or phone number" field, and the same reply for any input: "If an account matches, we've sent a reset link by email. No email? Ask your agency for a new sign-in link."
  - **Delivery:** the link goes to the account's email, also when found by phone. Phone-only accounts get nothing sent.
  - **Route:** Fortify's email-only route, which told whether an address exists, is replaced, and it is throttled to 6 a minute.
  - **Wording:** UX_DESIGN.md § 3.3 says "by email or WhatsApp". WhatsApp delivery does not exist yet, so the reply says email only until the notification module adds it.
- **Reset password, verify email and confirm password** now use the same split layout and security policy.
- **Password rule:** new customer passwords must be at least 10 characters, as in UX_DESIGN.md § 3.1, and in production not on a breached-password list. This replaces the starter kit's 12 characters with mixed case and symbols. The staff installer keeps its 12-character rule.
- **Tests:** the test case now resets all scoped services before each request, as production does.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **765 tests / 2,437 assertions** (14 new in `tests/Feature/Auth/TwoFactorAndRecoveryTest.php`; the kit's reset tests now post `identifier`).
  - **2FA:** the challenge page content, a wrong code and then the right one, a single-use recovery code.
  - **Trusted browser:** skips the challenge for 10 configured days, while another browser is challenged; it is not trusted unless ticked.
  - **New-device email:** sent only for a new browser on an account that already has one.
  - **Forgot password:** the same reply for five kinds of input; the email goes to the right account; phone-only accounts get nothing.
  - **Other pages:** every page renders with the policy, the 10-character rule holds, and a remembered 2FA sign-in lasts 30 days.
- **In the browser (1440 px):** every element of the 2FA page matches `login-2fa.html` in size, font and colour. It sits 60 px lower because this test account had no passkey or support email, so those two options were hidden. Signing in with a test account and typing a real code one digit at a time auto-submitted and opened the dashboard.

**Follow-ups:**
- "Add a passkey for faster sign-in" one-time prompt after a password sign-in, and "Log out other sessions" in the profile (UX_DESIGN.md § 3.1): with the profile pages (P01).
- WhatsApp reset links for phone-only tenants: notification module.
- HSTS: set where HTTPS is terminated (staging/production, P00-13).

**Next:** P00-10c (staff sign-in with mandatory 2FA, Sign-in security console page).

### P00-10a — Complete (2026-10-26): customer sign-in page and its security

P00-10 is split into sub-tasks (IMPLEMENTATION_PLAN.md § 3.1): **10a** customer sign-in; **10b** 2FA challenge and the other sign-in screens; **10c** staff sign-in with mandatory 2FA and the Sign-in security console page. P00-10 is marked complete when all three are done.

**Delivered:**
- **`/login` rebuilt from `docs/design/templates/login.html`** (`layouts/auth-split`, `resources/css/auth.css`, `resources/js/auth.js`).
  - **Brand panel:** the uploaded sign-in photo under its overlay, or the gradient and skyline. It also carries the dark logo, the activity cards (switchable) and the headline, benefits and trust line from `site.auth_*`.
  - **Form:** passkey button, which the browser also offers as autofill in the field; "Email or phone number"; password with show/hide and a Caps Lock warning; "Keep me signed in"; a Turnstile slot; and a busy state on Sign in.
  - **Shared styles:** the base styles shared with the landing page moved to `resources/css/site-base.css`.
- **Phone numbers as a sign-in name:**
  - **Schema:** `users.phone` (unique, E.164) is added, and `users.email` becomes nullable, both as in DATABASE_SCHEMA.md § 1.
  - **Normalising:** `App\Support\PhoneNumber` turns "0803 123 4567", "+234 803…", "234803…" and "00234…" into one +234 number. No SMS is sent anywhere.
  - **Matching:** Fortify's `username` is now `identifier`, and `FindUser` finds the account by email or phone.
- **Sign-in security** (`App\Auth\SignIn`, first step of Fortify's login pipeline):
  - **One generic answer:** "Those details don't match our records." for an unknown account, a wrong password or anything else. Unknown accounts still cost one password check, so timing gives nothing away.
  - **Lockout:** after `auth.lockout_after` (5) failures from one IP, sign-in pauses for `auth.lockout_minutes` (15). Failures are counted per account, so switching between email and phone does not reset them. While paused even the right password is refused, the form shows "Too many attempts" with a reset-password link, and the button is disabled.
  - **Turnstile:** with keys set, the check appears after 3 failures for the account or the IP in 15 minutes, or always (`auth.turnstile_mode`). It is verified server-side with a 5 s timeout and fails closed on rejection, errors, timeouts or odd answers.
  - **Without Turnstile keys,** the pause starts at 3 failures, as PLATFORM_CONSOLE.md § 9.6 says.
  - **Rate limit:** the route also limits each IP to 30 tries a minute, and each identifier from one IP to 10.
  - **Remember me:** lasts `auth.remember_days` (30), and is set only when asked.
  - **Passkeys switch:** `auth.passkeys_enabled` off hides the button and makes the passkey sign-in routes return 404.
- **New settings** (§ 9.6): `auth.passkeys_enabled`, `auth.turnstile_mode`, `auth.lockout_after`, `auth.lockout_minutes`, `auth.remember_days`, `auth.trust_device_days` and `auth.require_2fa_for`. Their console page comes with 10c.
- **Page security:** sign-in pages send a strict Content-Security-Policy — no inline scripts, no third-party scripts except Cloudflare Turnstile, `frame-ancestors 'none'` — and never print custom code.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **750 tests / 2,348 assertions**. There are 33 new tests in `tests/Feature/Auth/SignInTest.php` and `tests/Unit/Support/PhoneNumberTest.php`, and the kit's login tests now post `identifier`.
- **AC: rate limit, lockout and identical answers:**
  - an unknown email, an unknown phone, nonsense and a wrong password give the same status, redirect and message;
  - the pause works per account and IP, and another IP is unaffected;
  - it clears after 15 minutes;
  - the bot check comes after 3 failures and the pause after 5;
  - the Turnstile request carries the secret, token and IP, and four failure modes are refused;
  - the 31st request a minute from one IP gets 429.
- **AC: matches the template (in the browser):**
  - **Desktop (1440 px):** all 113 visible elements were compared against `login.html`. Colours, fonts, sizes and positions match, apart from colour syntax, the floating cards' animation phase, the template's Turnstile stand-in, and the Help link (shown only when a support email is set).
  - **Mobile (375 px):** every block has the same position and height, and there is no horizontal scroll.
  - **Real states:** the wrong-details and locked states looked as designed when tried in the browser.

**Next:** P00-10b (2FA challenge and the other sign-in screens).

### P00-09 — Complete (2026-10-26): P00-09c console settings pages and brand images

All three sub-tasks are done (09a theme engine and app shell, 09b landing page, 09c console pages), and every AC passes: the landing page matches the template at desktop and mobile (09b), publishing a colour updates the customer app and Filament (09a, and again here through the console UI), and a draft never reaches the public site (09a, 09b, 09c).

**Delivered (09c):**
- **Console pages under Settings** (PLATFORM_CONSOLE.md § 9.1). Each is one column of cards with a live preview column on wide screens.
  - **Brand & appearance:** identity, logos, icon, favicon, colours, greys, font, corners, hero and sign-in photos with overlay colour and strength.
  - **Public website:** hero and sign-in text, announcement, footer, social links, FAQ (repeater with rich text) and the legal pages.
  - **Search & sharing:** title, title pattern, description, share image, indexing (automatic / on / off), canonical origin. Its preview shows the Google result and the link card.
  - **Custom code:** head, body-start, body-end, CSS and the allowed script hosts. It applies on save and needs step-up.
  - **Versions:** every published version, with "Restore as draft".
- **Draft workflow** (`DraftSettingsPage`):
  - Save draft checks each value against the settings registry and shows problems on the matching field.
  - The preview (`/{ADMIN_PATH}/theme-preview/landing`) reloads after each save.
  - Header actions: Publish, Discard draft, Reset to default, Open preview.
  - **Audit:** draft saves are now logged (`theme` / `draft_saved`, before and after per key) as well as publishes and each published key. Rich text the editor only reformats does not count as a change.
- **Contrast warnings** (§ 9.2, § 9.8):
  - white text on the primary colour below 4.5:1 (it names the darker shade buttons use);
  - white text over a hero or sign-in photo under its overlay below 4.5:1, from the photo's average colour.
- **Brand images** (`App\Theme\BrandAssets`, private `brand` disk, `/brand/{role}`):
  - **Checks:** file type by magic bytes, which must match the extension; size and pixel limits per role. SVG is allowed only for logos, icon and favicon, and is refused if it contains script, `on*=` handlers, `javascript:`, `foreignObject`, iframe/embed/object, doctype/entities or `data:` links.
  - **Photos:** re-encoded to WebP q82 at 2400 px and 1200 px (EXIF dropped), served with `srcset`. The share image becomes a 1200 px JPEG, and a raster icon also gets a 180 px touch icon.
  - **Serving:** files are named by content hash; `?v={hash}` responses are `public, max-age=31536000, immutable` with `nosniff`, and SVGs get a sandbox CSP. These routes, robots.txt and the sitemap run without session or cookies.
  - **Clean-up:** files that no draft, published theme or stored version names are deleted, so restore keeps working.
  - **Fallbacks:** logos, icon and favicon fall back to the generated mark in theme colours (SVG, and PNG for the touch icon). The starter kit's Laravel `favicon.ico`, `favicon.svg` and `apple-touch-icon.png` are removed; `/favicon.ico` redirects to `/brand/favicon`.
  - **Where they show:** uploaded logos replace the mark on the landing nav and footer (`logo_only` hides the name), the console logo (light and dark), and the app sidebar (icon). Favicons apply on every surface.
- **Step-up** (`App\Platform\Console\StepUp`): password re-entry, rate-limited to 5 tries a minute, opens a 10-minute window. A staff member with confirmed 2FA must also give a TOTP code, so this becomes password + 2FA as soon as P00-10 enrols staff. Used for custom code and the canonical origin.
- **Permissions:** pages check `settings.theme`, `settings.site`, `settings.seo` and `settings.custom_code` through Laravel gates (`ConsolePermissions`).

**Interim choices (not in the spec as written; approved by the product owner on 2026-10-26 until P01-07 and P00-10):**
- **Roles:** until staff roles exist (P01-07), every active staff member holds these four permissions. The spec limits custom code to `super-admin`. Today the only staff accounts are the installer's first administrators, so this is equivalent; P01-07 must swap the gate for spatie roles.
- **Step-up:** password-only until staff 2FA exists (P00-10). The code check is already wired.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **717 tests / 2,229 assertions** (43 new in `tests/Feature/Theme/ConsoleSettingsPagesTest.php` and `BrandAssetsTest.php`).
  - **Pages and drafts:** every page opens for staff and redirects guests; a draft is saved, audited and invisible to visitors; registry and form errors land on the right fields; publish, discard, reset to default and restore work; the contrast warning fires for yellow.
  - **Images:** the hero upload yields 2400 px and 1200 px WebP files shown only in the preview; unsafe or mislabelled files are refused; replaced draft photos are deleted while published ones stay; uploaded logos, icon and favicon appear on the landing page, the app and the console.
  - **Search & sharing:** the Google preview follows the form, and indexing maps to on/off/automatic.
  - **Step-up:** the canonical origin and custom code need the password (a wrong one is refused); custom CSS with `</style` is refused.
  - **Brand route:** fallbacks, headers, unknown roles and bad versions are covered.
- **In the browser (console at 1440 px):** a draft saved in Brand & appearance showed only in the preview column. After Publish, the console itself turned teal and read "Ile Homes console", and the public landing printed the new colour and name. The Public website, Search & sharing (Google and card preview), Custom code and Versions pages render.
- **Drills:** release `0.9.0-rc.2` built; the verify drill passed (tamper target is now `public/images/skyline.svg`, since the Laravel favicon is gone); the install drill passed.

**Follow-ups:**
- P01-07: replace the interim permission gates with staff roles (custom code `super-admin` only).
- P00-10: staff 2FA enrolment (step-up then asks for the code automatically). The sign-in page should use `theme.auth_image`, overlay and `site.auth_*`, which are already stored and served.
- **Generated share card:** the spec's 1200×630 card when no share image is uploaded needs a TTF font for GD. Until then `og:image` is left out when none is uploaded.
- **Web manifest:** PWA icons (192/512) and `site.webmanifest` from `theme.icon` are not built yet.
- P00-12: include `storage/app/brand` in the files backup.
- The console preview covers the landing page and the app shell. Login, email and invoice previews follow with those features (P00-10, P02/P03).

**Next:** P00-10 (login pages to final quality).

### P00-09b — Complete (2026-10-26): landing page, SEO, custom code

**Delivered:**
- **Landing page at `/`** (`marketing.home`, `layouts/marketing`, partials for brand, hero, plans, announcement and footer), converted from `docs/design/templates/landing.html`. The CSS and script are the template's (`resources/css/marketing.css`, `resources/js/marketing.js`) with its tokens mapped to the theme variables, so a published colour, font or radius restyles the page with no rebuild. The kit's `welcome` view and the static `public/robots.txt` are removed.
  - **From the published theme:** brand name and mark, hero heading/subheading/badge/lede/trust items, FAQ (sanitised HTML), footer text, legal name, social links (only those set), announcement ribbon (dismissed per content, remembered in the browser), support email for "Book a demo" and "Contact".
  - **From the plan catalogue, never typed into settings:** `config/plans.php` and `App\Marketing\PlanCatalogue` hold the launch plans, VAT, trial days and annual months (BILLING_PAYMENTS.md § 1) until the billing module adds the plans table. Plan cards, the facts row, the calculator's first result and JSON-LD use it; the script gets the same numbers from a JSON block.
  - **Footer:** only links that lead somewhere (anchors, Book a demo, Log in, Privacy, Terms). Help centre, news and receipt verification join when those pages exist.
- **`/privacy` and `/terms`** render `site.privacy_policy` and `site.terms` (sanitised on save) in the same layout.
- **Search and sharing (§ 9.4)** via `App\Marketing\PublicSite`: title (`seo.title`, else name — tagline; other pages through `seo.title_pattern`), description, canonical and `og:url` on `seo.canonical_origin` (else `APP_URL`), Open Graph, Twitter card, theme colour and JSON-LD `Organization` + `SoftwareApplication` with the Starter price.
  - **Indexing:** `seo.indexing_enabled`, unset meaning production only. Off → `noindex, nofollow`, `robots.txt` is `Disallow: /`, `sitemap.xml` is 404. On → `robots.txt` disallows `/login`, `/register`, `/o/`, `/portal`, `/owner`, `/pay/`, `/verify/`, `/install`, `/dashboard`, `/settings` and links the sitemap (marketing pages, cached 10 minutes). The console path is never mentioned.
  - **Sign-in and app pages** now print `noindex, nofollow` (shared `partials/head`).
  - `og:image`, logo and favicon switch to the uploaded brand images once P00-09c adds the brand image routes.
- **Custom code (§ 9.5)** is printed only on the marketing layout: CSS in `<style data-custom-css>`, head code at the end of `<head>`, body code after `<body>` and before `</body>`. It is left out for signed-in users, in the console preview and with `?safe=1`.
- **Content-Security-Policy on public pages** (`PublicPageHeaders`): scripts, frames and connections only from the site and `custom_code.allowed_hosts`; inline scripts allowed (analytics snippets are inline); `object-src 'none'`, `frame-ancestors 'self'`; plus `nosniff` and a referrer policy.
- **Console preview** of the landing page with the draft theme at `/{ADMIN_PATH}/theme-preview/landing` (staff only, preview banner, noindex, no custom code).
- **Theme fix:** `--canvas` is now a fixed tint per neutral (`#f7f8fb` for slate, as in the template) instead of a 50/100 mix, which was one shade bluer.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **674 tests / 1,954 assertions** (17 new in `tests/Feature/Marketing/`). The test case now starts each request on the published theme, as production does.
- **AC: matches the approved template at desktop and mobile.**
  - **Structure:** a test compares the tag and class of every element under `<main>` with the template file; they are identical.
  - **Desktop (1440×900):** in the browser, every section starts at the same offset with the same height as the template. The computed colour, background, border, font size, weight, radius, shadow and box size of all 820 elements in the nav, hero and main match, apart from colour-syntax differences (`color-mix` vs `rgba`, same values), 1 px text widths (bundled vs Google-hosted Inter), and animation states. One real difference was found and fixed: the import bar's amber segment used the old `--color-accent` name.
  - **Mobile (375×812):** every section height matches and there is no horizontal scroll.
  - **Only intended difference:** the footer is shorter, since it shows only working links and no social links are set by default.
  - **Interactions:** the menu, calculator (250 units → Scale ₦102,500, ₦410 per unit) and yearly toggle work. No console errors.
- **Drafts:** website text and colours appear only after publishing; the landing preview shows the draft to staff only.
- **SEO and custom code:** tags, title pattern, indexing on/off, production default, robots/sitemap content, safe mode, signed-in users, sign-in pages and the console preview, and the CSP host list are each tested.
- **Drills:** release build `0.9.0-rc.1`, verify drill passed (its tamper target moved from `public/robots.txt`, now a route, to `public/favicon.svg`), install drill passed with a new check that `robots.txt` keeps crawlers off `/login` and never names the console path.

**Follow-ups:**
- The font plugin emits a `.woff` `@font-face` next to each `.woff2`, and browsers fetch both for some weights (about 120 KB extra). Look for a woff2-only option when fonts are next touched.
- Help centre, news and receipt verification links return to the footer with P03 (verify page) and the content pages.

**Next:** P00-09c (console settings pages and image uploads).

### P00-09a — Complete (2026-10-26): theme engine, app shell

P00-09 is split into sub-tasks (IMPLEMENTATION_PLAN.md § 3.1): **09a** theme engine and app shell; **09b** landing page, SEO and custom code; **09c** console settings pages and image uploads. P00-09 is marked complete when all three are done.

**Delivered:**
- **Settings registry for PLATFORM_CONSOLE.md §§ 9.2–9.5** (`config/settings.php`, 53 keys): `theme.*`, `site.*`, `seo.*`, `custom_code.*`, each with defaults from the approved templates and the rules in the spec (plain text rejects `<>`; hex colours; fixed lists for font, neutral and radius; three trust items and three benefits; https-only social links and canonical origin; title pattern must contain `{page}`; 50 KB custom code; no `</style` in custom CSS). The P00-07 placeholder `site.name` became `theme.name`.
  - **New in the settings store:** `item_rules` validate list and object items, and `html` fields go through `App\Support\SafeHtml`. That is a Symfony HtmlSanitizer policy allowing only text formatting, headings, lists, tables and https/mailto links; scripts, handlers, iframes and `javascript:` are dropped.
  - **`SettingsStore::validated()`** checks a value without storing it.
- **Colours** (`App\Theme\Color`, `Palette`):
  - **Conversion:** exact hex↔OKLCH, with gamut mapping by chroma reduction, and WCAG contrast.
  - **Scales:** generated from one hex. The colour lands on its closest step, and the other steps follow Tailwind's blue (primary) or amber (accent) lightness, chroma and hue drift. **The defaults reproduce the approved template's tokens exactly.**
  - **Dark brand surface:** `--hero-deep` is always ≥ 9:1 against white.
  - **Neutrals:** four neutral scales are available.
- **`Theme`, `ThemeManager`, `ThemeContext`:**
  - **Draft:** edits go into one draft, validated when saved.
  - **Publish:** writes only the changed keys through the settings store (each audited) and keeps a numbered snapshot in the new `setting_versions` table. The previous version is archived, a theme log entry records who published, and any version can be restored. Reset-to-defaults and discard are available.
  - **Isolation:** `ThemeContext` (scoped) gives every request the **published** theme. Only the staff-only console route `/{ADMIN_PATH}/theme-preview/app` (no-store, noindex) switches to the draft.
- **CSS variables everywhere:**
  - **`<x-theme-styles />` in `<head>`:** prints the theme as an unlayered `:root` rule, overriding Tailwind 4's `@theme` defaults in `app.css` with no rebuild. It loads only the chosen font and sets `theme-color`.
  - **Variable names:** as in the template, except `--color-brand-accent*` (Flux owns `--color-accent`) and `--gp-radius*` (Tailwind owns `--radius-*`).
  - **Flux:** its accent now follows the brand primary.
  - **Filament:** reads its primary palette, font (local) and name from the theme on every request, and prints the same variables.
- **Fonts:** Inter, Plus Jakarta Sans, Manrope and DM Sans are bundled through Vite (`@fontsource`, 400–800). Each page preloads only the chosen family.
- **App shell:**
  - The starter kit's Laravel logo is replaced by a generated GoProperty mark in theme colours (sidebar and auth layouts), and the product name comes from the theme.
  - The sidebar has a workspace switcher placeholder, workspace and surface navigation, and a mobile bottom navigation (Home, Rentals, Properties, Account).
  - The forced dark mode is removed; the default is light, per § 9.9.
  - Page templates `<x-page>` (heading, description, actions) and `<x-empty-state>` are used on the dashboard and all three surfaces.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **657 tests / 1,836 assertions** (65 new: `tests/Unit/Theme/PaletteTest.php`, `tests/Feature/Theme/*`).
- **AC: publishing a colour updates the customer app and Filament.** After publishing `#0F766E`, `/dashboard`, `/o/{slug}`, `/portal` and `/owner` print `--color-primary-700:#0f766e`, and the console prints Filament's `--primary-700` for the same colour.
- **AC: a draft does not leak.** With a draft colour and name saved, `/login`, the customer pages and the console show only the published values. The preview shows the draft to staff, and redirects guests and customers to the console sign-in.
- **Other tests:**
  - only the chosen font is loaded;
  - the theme name is used in titles and the console brand;
  - drafts merge, publish creates numbered versions, restore and reset work;
  - every validation rule from the spec is enforced, and FAQ and legal HTML is cleaned;
  - palette exactness, monotonic scales and ≥ 9:1 hero contrast hold for 8 colours.

**Next:** P00-09b (landing page).

### P00-08 — Complete (2026-10-26)

**Decision (product owner, 2026-10-26):** the staff session cookie uses `Path=/`, not `Path=/{ADMIN_PATH}` as ONBOARDING_COMPLIANCE.md § 1 said. Livewire, which Filament runs on, has one update endpoint at the site root, so a path-scoped cookie would break every console action. Separation comes from the cookie name and the guard: staff use `gp_staff_session` and the `staff` guard, and customer pages never read it. The doc is updated.

**Delivered:**
- **Console panel:** `ConsolePanelProvider`, a Filament panel `console` at `/{ADMIN_PATH}` (or on `ADMIN_DOMAIN`, read from config). It signs in on the **`staff` guard** (new guard and provider on `platform_staff`), uses Filament's login page and dashboard, has no widgets, and placeholder colours until P00-09. `PlatformStaff` implements `FilamentUser`, and only `active` staff may enter. Resources and pages are discovered in `App\Platform\Filament`, the only namespace the tenancy rule allows to read across organizations. If `ADMIN_PATH` breaks the PLATFORM_CONSOLE.md § 10 rules, the console **is not registered at all** (a critical log line), rather than served at a guessable path.
- **Staff session** (`UseConsoleSession`, global, before any session starts; `App\Platform\Console\ConsoleRequests`):
  - **Which requests use it:** console URLs, and Livewire updates whose component snapshots all come from console pages. Livewire records each page's path in its snapshot.
  - **Cookie:** `gp_staff_session`, `HttpOnly`, `SameSite=Strict`, `Secure` (except a local copy on http), 30-minute idle lifetime.
  - **IP allowlist:** the optional `CONSOLE_IP_ALLOWLIST` gives other addresses a plain 404.
- **Probe logging:** `/admin`, `/administrator`, `/wp-admin`, `/wp-login.php`, `/phpmyadmin` and `/cpanel` return a normal 404 and are logged as "Console path probe" with path and IP (`config/console.php`).
- **Route skeleton** (`routes/web.php`, ONBOARDING_COMPLIANCE.md § 1):
  - `/` and `/login` as before;
  - `/o/{organization}`, using the `organization` middleware from P00-07;
  - `/portal` and `/owner`, which are cross-organization and set no context;
  - `/pay/{reference}` and `/verify/{code}`, named routes with their formats checked, returning 404 until those features exist;
  - the console at `/{ADMIN_PATH}`.

  The three signed-in surfaces have empty shells on the app layout, and the sidebar links to each of the user's organizations plus "My rentals" and "My properties" (the starter kit's GitHub and docs links are gone).
- **Demo data:** `DatabaseSeeder` adds "Demo Realty" (`/o/demo-realty`) with `test@example.com` as a member, and on local copies only, an active staff account `staff@example.com` (password `password`).
- **Filament assets:** Composer's `post-autoload-dump` now runs `filament:assets`, so CI, the deploy tarball and the release zip always carry them; they are git-ignored. `config('app.admin_domain')` is new. The install drill now also checks the installed console (sign-in page 200, console root 302, `/admin` 404).
- Docs: ONBOARDING_COMPLIANCE.md § 1 (cookie), PLATFORM_CONSOLE.md intro, AGENT.md § 4.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci` twice: Pint passed, Larastan 0 errors, Pest **592 tests / 1,522 assertions**, 1 skipped (38 new in `tests/Feature/Console/`).
- **AC 1 (panel auth-protected and empty):** guests are redirected to the console sign-in. Active staff see a dashboard with no widgets; invited and deactivated staff get 403. Signing in through Filament's login form authenticates only the `staff` guard, and a customer's credentials are refused there.
- **AC 2 (a customer session cannot reach it):** a signed-in customer is redirected to the console sign-in. The console sets only `gp_staff_session` and customer pages only the customer cookie. The console path never appears on `/`, `/login`, `/dashboard`, `/portal` or `/owner`. The Livewire cookie selection is unit-tested, including mixed snapshots and `/{path}-evil`. The IP allowlist, probe logging, `ADMIN_DOMAIN` hosting, and an invalid `ADMIN_PATH` are also tested; the last one checks a separate process's route list, with no console routes for `admin`, `short` or `Has-Capitals-1`.
- **AC 3 (seeded demo user sees all three shells):** `SurfacesTest` runs `DatabaseSeeder`, signs in through `POST /login`, and opens `/o/demo-realty`, `/portal` and `/owner`.
- **In a real browser** (built-in browser, `php -S` with the seeded data in a throwaway database):
  - **Console sign-in:** staff signed in through Filament's form, which posts to the site-wide `/livewire-…/update` endpoint, and reached the empty console.
  - **Customer sign-in:** the demo user, in the same browser, signed in and opened all three shells through the sidebar.
  - **Separate sessions:** the console stayed signed in while the customer was signed in. Signing out of the console left the customer signed in, and signing in again with the customer cookie present worked.
  - **Errors:** `/admin` returned 404, and the server printed no PHP warnings.

  The dev server, database and lock file were removed afterwards.
- **Drills on a fresh `goproperty-0.8.0-rc.1.zip`:** the release drill passed, including the secret scan of Filament's published assets. The install drill passed (same local `intl` caveat as P00-06), with the console reachable at the generated path, under shared-host limits and with cached config.

**Follow-ups:**
- P00-10: staff sign-in to final quality, mandatory 2FA (enforced at first sign-in), lockout, Turnstile; `goproperty:staff-reset-2fa`.
- P00-09: theme the console from the platform theme; replace the kit's header layout links (`layouts/app/header.blade.php` still links to the starter kit) and add the org switcher.
- P01-07: staff roles (spatie, `staff` guard) and the first account's `super-admin` role; per-staff `ip_allowlist`.
- P01-01: landing rules after sign-in (ONBOARDING_COMPLIANCE.md § 1: one org → `/o/{slug}/today`, several → picker, tenant → `/portal`); `/portal` and `/owner` access limited to tenants and invited owners once those records exist.
- P04 / P03: the pay page and document verification behind the reserved routes.

**Next task:** P00-09 (design system and platform theme).

### Security update: Symfony advisories — 2026-10-26

Not a plan task; follows up the advisories found during P00-07.

**Delivered:**
- `symfony/http-foundation` 8.0.15 → **8.1.9** (CVE-2026-108082: ReDoS through the `Cache-Control` request header; shipped in the release package) and `symfony/yaml` 8.0.15 → **8.1.9** (CVE-2026-109413; dev only, through Laravel Sail). Only these two packages changed; the other Symfony components stay on their current versions.
- The blocker was `config.platform.php` = 8.4.0 in `composer.json`, because the patched Symfony releases need PHP 8.4.1. It is now **8.4.1**, and `require.php` is `^8.4.1`.
- The minimum is aligned everywhere, so a host that passes our checks also passes Composer's platform check:
  - The old-PHP guard (`bootstrap/php-version.php`) checks 80401 and names the full version ("GoProperty needs PHP 8.4.1 or newer; this server runs 8.4.0."). Previously an 8.4.0 host would have passed our guard and then failed with Composer's own error.
  - `RequirementsChecker::MINIMUM_PHP` is now 8.4.1.
  - The `Old PHP guard` CI job and the release drill match the new message, with the image's patch version allowed to vary. The CI pattern was checked against matching, wrong-version, old-wording and fatal-error outputs.
- Docs: INSTALLER.md § 2 and § 5, ARCHITECTURE.md § 12.1, shared-hosting.md § 1, ci.md, release.md. Effect on hosts: none in practice (8.4.1 shipped in December 2024, and panels keep PHP 8.4 on the latest patch).

**Tests and evidence (local, MariaDB 11.4.12):** `composer audit` reports no advisories. `composer ci` passed twice: Pint passed, Larastan 0 errors, Pest 554 tests / 1,425 assertions (1 skipped, as before). On a fresh `goproperty-0.7.1-rc.1.zip`, the package's platform check requires 80401, `http-foundation` is 8.1.9 and `yaml` is absent. The release drill passed, and the install drill passed (same local `intl` caveat as P00-06). Not run here: the guard on real PHP 7.4/8.1 (Docker, CI only).

### P00-07 — Complete (2026-10-26)

**Delivered:**
- **Modules:**
  - **Packages:** `nwidart/laravel-modules` 13.2.0, which autoloads modules through `wikimedia/composer-merge-plugin` 2.1.0 (`extra.merge-plugin` reads `Modules/*/composer.json`).
  - **`config/modules.php`:** trimmed so generated modules share the app's single Vite build. No per-module `package.json`, `vite.config.js`, asset folders, API routes or Blade layout are generated. Module `composer.json` files use the `goproperty/` vendor name.
  - **No modules yet;** Core lives in the main `App` namespace, as ARCHITECTURE.md § 8 allows.
- **Tenancy core** (ARCHITECTURE.md § 4):
  - **`OrganizationContext`:** scoped singleton, so it is fresh per request and per job. API: `set`, `get`, `id`, `has`, `clear`, and `run()`, which restores the previous context even on failure.
  - **`BelongsToOrganization` + `OrganizationScope`:** queries and creates **without a context throw `MissingOrganizationContext`** (fail closed). `organization_id` is filled from the context. Creating a row in another organization, or moving one, throws `CrossOrganizationWrite`.
  - **`SetOrganizationContext` middleware** (alias `organization`):
    - looks the organization up by slug and checks membership;
    - gives non-members and unknown slugs the same 404;
    - redirects an old slug (`previous_slugs`) with a 301, keeping the path and query;
    - sets the context and `URL::defaults`;
    - runs **before route model binding**, so models bound from the URL are scoped.
  - **Larastan rule** `Tools\PHPStan\Rules\OrganizationScopeBypassRule` (registered in `phpstan.neon`, analysed itself). Outside `App\Platform` and `Modules\Platform` it rejects removing `OrganizationScope` (by class string, instance or array), and `withoutGlobalScopes()` on an organization-owned model or one it cannot identify. It reads models through `Builder` and `Relation` generics and follows nested trait use.
- **Organizations** (pulled into Core; ARCHITECTURE.md § 8 puts organizations there, and the context needs them):
  - `organizations` table with every DATABASE_SCHEMA.md § 1 column except foreign keys to tables that do not exist yet (`logo_media_id` stays a plain id), plus `organization_user` (unique membership, `is_default`).
  - `Organization` model: enum, encrypted and money casts; routed by slug; `cac_number` hidden from serialisation; activity log; table defaults declared on the model. `User::organizations()` and `belongsToOrganization()`.
- **Base enums** (`App\Enums`): `OrganizationType`, `OrganizationStatus`, `TransactionTier` (int), `Currency` (NGN, ₦, 100 kobo), with a `HasValues` trait for validation rules.
- **`App\Support\Money`:** immutable integer kobo with no floats.
  - **Parsing:** exact from whole naira or strings (`"₦1,250,000.50"`, with comma grouping checked and at most 2 decimals).
  - **Arithmetic:** `plus`, `minus` and `times`, with bounds checked *before* each operation, because an overflowing PHP integer silently becomes a float.
  - **Rounding:** `percentage()` in basis points and `dividedBy()`, with exact integer rounding in every PHP 8.4 `RoundingMode` (default half away from zero).
  - **Splitting:** `allocate()` by largest remainder and `split()`; the parts always sum to the total.
  - **Comparisons and output:** comparisons, `sum`, `min` and `max`; `format()` gives `₦1,250,000` with kobo only when non-zero (UX_DESIGN.md § 1) and grouping done on digit strings, so huge values stay exact; `toDecimalString()`; JSON as kobo.
  - **`MoneyCast`:** `*_kobo` columns read back as Money and accept writes only as Money, so a naira number cannot be stored as kobo.
- **Settings store** (`App\Settings`): `settings` table with global rows stored as `global`/0, because MySQL allows repeated NULLs in unique indexes. A registry declares each key with type, default, rules, scope, secret flag, permission and step-up (`config/settings.php` has `site.name` and the two Turnstile keys; modules call `define()`). `SettingsStore` is typed, validated and cached per scope. Secrets are encrypted in the database and the cache, and logged only as `[secret]`. Organization keys use the current context, and every change and reset is audited.
- **Activity log:** `spatie/laravel-activitylog` 5.1.3, with the `activity_log` table as published. `Organization` changes are logged (CAC number excluded) and so are settings changes. `clean_after_days` is raised to 7 years with a "never schedule `activitylog:clean`" note, because the default (365) would delete audit entries that PLATFORM_CONSOLE.md § 7 keeps for 7 years.
- `tools/` (dev only) is excluded from the release package. Docs: ARCHITECTURE.md § 4 and § 8, AGENT.md § 2–3, DATABASE_SCHEMA.md § 8.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci` twice: Pint passed, Larastan 0 errors (the new rule finds no bypass in `app/`), Pest **553 tests / 1,424 assertions**, 1 skipped (the module-boundary check, until modules exist).
- **AC 1 (scoped model queried without a context fails):**
  - **At run time:** `OrganizationScopeTest` shows get, count, find, first, exists, bulk update, bulk delete and create all throw without a context. With a context, each organization sees only its own rows, cannot find another's row by id, and cannot create in or move rows to another organization. `SetOrganizationContextTest` covers membership 404s, old-slug redirects, guests, and a foreign record bound from the URL returning 404.
  - **Statically:** `OrganizationScopeBypassRuleTest` (PHPStan `RuleTestCase` with Larastan) flags all 9 bypass forms in a fixture and stays silent for the Platform namespaces, non-tenant models and other scopes. Fixture tables live in throwaway databases, so the real schema stays GoProperty's own.
- **AC 2 (money helper unit-tested):** `tests/Unit/MoneyTest.php`, 88 cases: parsing and rejection, overflow on every operation, all 8 rounding modes, allocation sums, formatting and round-trips.
- Also: `SettingsStoreTest` (encryption in the database, cache and log, isolation, audit, validation), `OrganizationTest` and `ModuleSkeletonTest` (module generated into a temporary folder).
- **Package and install drills on a fresh `goproperty-0.7.0-rc.1.zip`** (same local `intl` caveat as P00-06): the release drill passed, including the secret scan of the new vendor code. The install drill passed with all 11 migrations under shared-host limits, with the schema still identical to a plain migrate.
- While testing, found and fixed: organization defaults (`allow_support_access`, `platform_covered`) were unknown to a new model, so every later save logged them as changed from null; and `cac_number` serialised in plain text.

**Follow-ups:**
- ~~Security: two Symfony advisories~~ Resolved in the security update above (both packages on 8.1.9, PHP platform 8.4.1).
- P01-01: spatie teams roles on `organization_user`, org switcher, and cross-tenant probes on every Phase-1 model.
- P00-09: theme, website and SEO keys plus `setting_versions` (draft/publish) on the settings registry.
- P00-11: activity log extra columns, hash chain, both guards as causer, per-log retention.
- Queued jobs touching organization data must call `OrganizationContext::run()`; consider a job middleware when the first such job appears.

**Next task:** P00-08 (console panel shell and route skeleton).

### P00-06 — In review (2026-10-26)

**Delivered** ([INSTALLER.md § 7](../INSTALLER.md), § 12):
- **`InstallationSteps`**: the installer's steps for the CLI and the web wizard (P01-09). Each step is safe to repeat:
  - `ensureApplicationKey`: create `.env` from `.env.example`, reuse a valid key or generate one, switch it into the process.
  - `configureSite`: probe, then write the site and database settings plus `HOSTING_PROFILE=shared`, `APP_TIMEZONE=Africa/Lagos`, `APP_DEBUG=false`, and file cache and sessions until the tables exist. It refuses database settings that differ from the checkpoint.
  - `buildDatabase`: batched migrations with progress, then `InstallSeeder`.
  - `configureMail` and `sendTestEmail` (SMTP).
  - `createSuperAdmin`: 12+ characters and a breached-password check; an existing staff account is accepted only with the same email and password.
  - `finalize`, then `writeInstallationRecord` (mode 0600, with `APP_KEY`).
  - Stages move only forward, in the checkpoint and the database row.
- **`php artisan goproperty:install`**: interactive (Laravel Prompts) or `--no-interaction`. It runs the server check with cPanel and Plesk fixes, then validates every answer before writing anything. Passwords come only from files or hidden prompts, with a warning when others can read the file. It refuses to run once installed, and prints the console URL, the cron line and the record path.
- **`php artisan goproperty:setup-token`**: a 40-character token printed once, with only its SHA-256 stored (mode 0600); a new token replaces the old one. `SetupToken::verify()` is for the P01-09 unlock.
- **`php artisan goproperty:check-installation {--json}`** (`InstallationHealth`): server requirements, pending migrations and missing tables, the three markers, production settings (key, debug off, unique console path, https, hosting profile) and scheduler and queue-tick heartbeats under 5 minutes old. Exits 1 on any failure.
- **`php artisan goproperty:finish-install`**: recovery for a finish stopped at `cleanup_pending`, which no other path could complete. Hidden **`goproperty:schema-snapshot`** for the drill.
- **`CronLine`**: panel CLI binaries first (cPanel ea-php84, CloudLinux alt-php84, Plesk 8.4), else PHP_BINARY only if it is a CLI binary (never php-fpm or lsphp); paths are quoted.
- **`platform_staff` table and `PlatformStaff` model, pulled forward from P00-08** so the installer can create the first super-admin. 2FA columns are nullable until first console sign-in; the guard, console and roles stay in P00-08. **`SchemaSnapshot`**: tables, columns, indexes, foreign keys and the migration list from `information_schema` (base tables only, not views).
- **Install drill** `scripts/release/install-drill.sh <zip>`, run by the new workflow `install-drill.yml` on MySQL 8.0 and 8.4 and MariaDB 10.6 and 11.4 (nightly, on pull requests touching migrations, seeders or the installer, by hand) and before every release (`release.yml` now needs it). It unpacks the split layout and runs `goproperty:install --no-interaction` under `max_execution_time=30`, `memory_limit=256M`, with `exec`, `proc_open` and pcntl disabled. Then:
  - a second install is refused;
  - after one cron run, `check-installation` passes;
  - `public_html` serves `/` and `/login` with 200 and `/install` with 404;
  - `verify-package` passes on the installed copy;
  - the schema is diffed against a plain `migrate` of the same zip.
- `config/app.php` timezone now reads `APP_TIMEZONE` (was hard-coded UTC, so the installer's Africa/Lagos had no effect). The workflows now install every PHP extension the requirements check needs (sodium, exif and others were missing from the list). [install-recovery.md](runbooks/install-recovery.md) is new (markers disagreeing, failed migration, lost access, start over).
- Doc corrections: INSTALLER.md § 5 step 3 said the installer writes `BROADCAST_CONNECTION=log`, but P00-03 chose `null` (via `HOSTING_PROFILE=shared`). § 7 now lists the real options. § 12 notes that the wizard-over-HTTP and upgrade drills are still to come.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **417 tests / 1,157 assertions** in parallel (64 new in `InstallCommandTest`, `InstallerCommandsTest` and `CronLineTest`; the gate also passed twice in a row before the last three tests were added). `InstallCommandTest` covers:
  - a full install into a throwaway database;
  - schema equal to a plain migrate, and a deliberate column change detected;
  - a second install refused, and resume after an interruption;
  - changed database settings and a different super-admin refused;
  - missing or invalid answers caught before `.env` is written;
  - breached and short passwords, and a refused database password;
  - a failed test email, a readable password file, and interactive prompts.
- **AC 2 (schema matches a plain migrate):** proven in Pest on every run and by the install drill below.
- **AC 1 (install drill):** on a freshly built `goproperty-0.6.0-rc.2.zip`, every step passed with MariaDB 11.4.12, including `check-installation` healthy (12 checks) and an identical schema snapshot. **Local caveat:** neither PHP build on this machine has `intl`. The real drill correctly stopped at the server check, so the local run used a scratch copy of the script that drops `intl` from the unpacked checker and restores it before `verify-package`. The repository script and the zip were unchanged. PHP 8.4.27 with sodium was used. The release drill (P00-04) also passed on the same zip.
- Not run here: MySQL 8 and MariaDB 10.6 (no servers on this machine) and the workflows themselves (GitHub Actions blocked, P00-02). Those are exactly what the AC names, hence In review.

**To finish:** once Actions starts jobs, run **Install drill** by hand (or let the nightly run) and confirm all four database jobs pass, then mark P00-06 complete.

**Follow-ups:**
- P00-08: `staff` guard and panel on `platform_staff`. Give the first staff account the `super-admin` role when roles exist (it is created without one). Enforce 2FA enrolment at first sign-in.
- P00-10: `goproperty:staff-reset-2fa`, which the recovery runbook names.
- P01-09: wizard steps on `InstallationSteps`; unlock with `SetupToken::verify()`; S3 storage setup and test (the CLI only warns for now); a drill that walks the wizard over HTTP.
- Upgrade drill (previous release → new release), INSTALLER.md § 12.
- If offline hosts matter: the breached-password check fails open but logs an error when the service is unreachable.

**Next task:** P00-07 (module skeleton and Core).

### P00-05 — Complete (2026-10-26)

**Delivered** (`App\Installer\…`, [INSTALLER.md § 3](../INSTALLER.md)):
- **Markers** (`InstallationMarkers`): lock file `storage/app/installed.lock`; `installation_state` table (one row, migration `2026_10_26_000000`, documented in DATABASE_SCHEMA.md § 8); checkpoint `storage/app/private/install.checkpoint` holding the stage (`InstallStage`: configured → migrating → owner_ready → cleanup_pending → finalized), an HMAC fingerprint of the database settings and an HMAC over both, keyed by `APP_KEY`. Installer closed if any marker says finished, or the checkpoint is unreadable or forged. The database marker is read only once `APP_KEY` exists, through a 3-second-timeout copy of the default connection. If the database can't be reached, the installer stays closed when a checkpoint exists and open when none does. Site open only when the lock and the row both say finalized.
- **`AwaitApplicationKey`** (first global middleware): with no usable key (missing, or not a valid 32-byte key), every GET/HEAD redirects to `/install` and writes get a plain 503 with `Retry-After`. No session, cookie, database or log use.
- **`EnsureSiteInstalled`** (second): "GoProperty is being set up" (503, `noindex`, `no-store`) on every public route until finalized; "temporarily unavailable" when the lock exists but the row can't be read. `/install` and `/up` pass.
- **`/install`** (`routes/install.php`, outside the web group, `EnsureInstallerOpen` → 404 once closed): first screen with the version and a pass/fail summary per group (PHP, Folders, Security, Build), with no paths or values. The pages are plain PHP views plus a static `public/install.css`, so they work before `storage/` is writable or the manifest is confirmed. Unlock and wizard steps are P01-09. (Shipping `install.css` closes that P00-04 follow-up early.)
- **`RequirementsChecker`**: every blocking and advisory check in § 5 step 2, each with cPanel and Plesk fixes. Writability is proved with a real probe file; HTTPS is required except on localhost/`*.test`; document-root and HTTPS checks are web-only. Host facts go through `ServerEnvironment`, so tests can simulate a poor host.
- **`EnvironmentFile`**: creates `.env` from `.env.example` (mode 0600). It sets keys in place, removes duplicates and appends new keys. Values are bare or double-quoted with `\`, `"` and `$` escaped, so `${…}` never expands, and each one is parsed back to prove it round-trips. Control characters are refused. Writes are atomic (`AtomicFile`: temp file → fsync → rename), and errors name the key, never the value.
- **`DatabaseProbe`** + `DatabaseSettings` (validated; password hidden from dumps): temporary connection with a 3 s timeout and MySQL 8.0 / MariaDB 10.6 minimums. Tables are listed **in the chosen database only**: Laravel's default lists every database the user can see. It refuses foreign tables and a finished install, and accepts our own tables from an earlier attempt. Driver errors become plain advice (refused password, no access, missing database, unreachable host); anything else is scrubbed of host, user and password. `OwnTables` reads `Schema::create('…')` names from the migration files; a test checks that a migrated database holds nothing else.
- **`BatchedMigrator`**: `progress()` ("34 of 112") and `runBatch(max, seconds)`, one migration per step (default 5 per batch, 20 s budget, at least one per batch). It resumes from Laravel's `migrations` table and reports a failing migration by name with a cleaned message. **`FreshDatabaseReset`** ("empty the database and start again", § 6) drops only GoProperty's tables, only while the installer is open, never when `organizations` has rows, and only after the database name is typed exactly.
- **`InstallSeeder`**: the installer's idempotent seeders (empty until roles, plans, tiers, theme and email templates exist). `DatabaseSeeder` calls it, and on a `local` copy writes both markers so `migrate --seed` gives a working dev site.
- **`Finalizer`**, in § 5 order: checkpoint → `cleanup_pending` first (so a later failure leaves the installer closed); `ADMIN_PATH=gp-control-xxxxxx` (`AdminPath`, rules from PLATFORM_CONSOLE.md § 10, an existing unique path kept on rerun); `SESSION_DRIVER`/`CACHE_STORE=database`; token, challenge, unlock file, grant and attempt files deleted; lock + row finalized; config/route/view/event caches in-process (`ArtisanOptimizer`; a failure is a warning, not a failed finish); `install.log` entry without the console path. `config('app.admin_path')` added for P00-08.
- `config/installer.php`, `install` log channel, `APP_NAME` default `GoProperty`. The release drill now checks the gates on the packaged app.
- Fixed while testing: `$` in PHP regexes also matches before a trailing newline, so `localhost\n` passed host validation. All installer and version patterns now use `D` (end of string only); regression tests added for hosts, `.env` key names and console paths.
- Docs: INSTALLER.md § 2, § 3 (how each marker is read), § 12; DATABASE_SCHEMA.md § 8; PLATFORM_CONSOLE.md § 10 (reserved words); AGENT.md § 2; [release.md](runbooks/release.md) § 3.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci` twice in a row: Pint passed, Larastan 0 errors, Pest **353 tests / 962 assertions** in parallel (186 new or reworked: `tests/Feature/Installer/*`, `tests/Drill/FreshCopyDrillTest.php`, `tests/Unit/AdminPathTest.php`). Every test starts with a temporary installer folder and an installed site, so tests never touch the checkout's own markers.
- **AC 1 (fresh copy → /install, no database, no error log):**
  - In-process: with no key, every GET redirects and every write gets a 503, with zero database queries, no session cookie and no error log call.
  - Real server: `php -S` on this checkout with `APP_KEY` empty, the database pointed at port 1 (nothing listening) and an empty private storage folder. `/`, `/login`, `/register`, `/dashboard` and `/up` returned 302 to `/install`; POST `/login` returned 503; `/install` returned 200 without a cookie. The log folder stayed empty and the server printed no PHP warnings.
  - Packaged app: the release drill on a fresh `goproperty-0.5.0-rc.1.zip` showed the same results in both layouts.
- **AC 2 (any one marker keeps the installer closed):** `/install` returns 404 with only the lock file, only a finalized row, only a `cleanup_pending`/`finalized` checkpoint, a corrupt, forged or re-keyed checkpoint, or a recorded database that can't be reached. It stays 200 for configured/migrating/owner_ready.
- Not run here: MySQL 8 (CI covers MySQL 8.0/8.4 once Actions runs); the GitHub workflows (P00-02 blocker).

**Follow-ups:**
- P00-06: `goproperty:setup-token`, `goproperty:install` and `goproperty:check-installation` drive these services; write `docs/runbooks/install-recovery.md` (markers disagreeing, start over), which INSTALLER.md § 9 names.
- P01-09: unlock (token and file check, same-origin, `flock` rate limit), grant, wizard steps and finish screen, using `/install` routes on the web stack after unlock; the detailed requirements report (`RequirementCheck::$detail`, `cpanel`, `plesk`).
- P00-11: move the finish record from `install.log` to the audit log (who, when, version, IP).
- P00-08: read `config('app.admin_path')` for the panel path.
- Later seeders (roles, plans, tiers, theme, email templates) go into `InstallSeeder::SEEDERS`; `InstallSeederTest` checks they are idempotent.

**Next task:** P00-06 (installer CLI + install drill).

### P00-04 — In review (2026-10-26)

**Delivered:**
- **Release zip** built by `scripts/release/build-package.sh <version> <dir>`: `goproperty-<version>.zip` + `.zip.sha256`. Inside: `README.txt` (which layout to use), `preferred/goproperty/` and `split/goproperty-app/` + `split/public_html/` ([INSTALLER.md § 2](../INSTALLER.md)). Each layout has production `vendor/`, built Vite assets, `.env.example`, empty `storage/` and `bootstrap/cache/` (Laravel's package caches are removed and rebuilt on first boot), `VERSION`, and `SHA256SUMS` (`sha256sum` format; every file except `storage/` and `bootstrap/cache/`; public files listed as `public/…` in both layouts). Same commit → same entries, order and timestamps. 55 MB zip; 17,112 files in the preferred layout, 17,113 in the split one.
- **Split layout:** `public_html/index.php` is generated from `public/index.php` (paths to `../goproperty-app/`, plus `$app->usePublicPath(__DIR__)`; the build fails if `index.php` changes shape). `bootstrap/app.php` now reads an optional `bootstrap/public-path.php`, written only into the split layout, so artisan and cron use `public_html` too (edit it for Plesk's `httpdocs`).
- **Shared build step** `scripts/release/stage.sh`: tracked files → `composer install --no-dev --optimize-autoloader` → `npm ci && npm run build` → dev files removed. `scripts/deploy/build-release.sh` (SSH deploy) now uses it too; its output is unchanged.
- **`php artisan goproperty:verify-package [path] [--public=]`** (`App\Release\PackageVerifier`, `ReleaseZip`, `DirectoryTree`, `ZipTree`): checks a zip in place (unsafe names are refused before anything is unpacked), an unpacked layout, or with no argument the running installation. Refuses: changed, missing or added files against `SHA256SUMS`; symlinks; `..`/absolute/backslash paths; duplicate zip entries; entries outside the layouts; `.env*` (only `*.example` allowed), `auth.json`, key files, SQLite files (by name and by header), `.git`/`node_modules`; dev dependencies (`installed.json` `dev` flag and `composer.lock` `packages-dev`); secret-shaped strings (private keys, AWS, Paystack/Stripe, GitHub, Slack, Google, filled-in `APP_KEY`, DSNs with keys; reported by kind, never printed); a missing Vite manifest or asset; entry points without the old-PHP guard; a split layout without its public path; a zip that does not match its `.sha256` or whose `VERSION` differs from its name. On a live site it ignores the site's own `.env`, `storage/`, `bootstrap/cache/`, symlinked `storage`/`.env` from the SSH deploy, and what hosting panels add (`error_log`, `.user.ini`, `.well-known/`, `cgi-bin/`, cPanel's PHP-handler block in `.htaccess`). Prints at most 50 problems. A folder with neither `VERSION` nor `SHA256SUMS` gets one line ("not an unpacked release package").
- **Old-PHP guard** `bootstrap/php-version.php`, loaded first by `artisan` and `public/index.php`: on PHP < 8.4 prints "GoProperty needs PHP 8.4 or newer; this server runs 8.1." (exit 1 on the CLI, HTTP 500 plain text on the web). No PHP 8 syntax; excluded from Pint so `declare(strict_types=1)` is not added.
- **Release workflow** `.github/workflows/release.yml`: on tags `vX.Y.Z` / `vX.Y.Z-suffix` → `CI` → build → release drill (with old-PHP checks on `php:7.4-cli` and `php:8.1-cli`) → `gh release create` with the zip and `.sha256` (suffix versions as pre-releases). Manual runs build a test package from any branch and keep it as a 7-day artifact (`actions/upload-artifact` v7.0.2, pinned by SHA). `ci.yml` now runs on branch pushes only (tags go through Release) and has a new job **`Old PHP guard`** (artisan and `index.php` on PHP 7.4 and 8.1).
- **Release drill** `scripts/release/verify-drill.sh <zip>` (§ 3 of the runbook): verifies the zip and both layouts with the package's own artisan, refuses a changed file, an added file and a changed zip entry, serves both layouts with `php -S` (`/up`, `/`, a built asset → 200), re-verifies after running, and checks the old-PHP sentence in Docker when `OLD_PHP_IMAGES` is set.
- Docs: [docs/runbooks/release.md](runbooks/release.md) (new: cutting a release, contents, drill, verifying on the server), INSTALLER.md § 2 and § 12, [ci.md](runbooks/ci.md) (fifth required check `Old PHP guard`), [shared-hosting.md](runbooks/shared-hosting.md) § 2–3, AGENT.md § 2. `/dist/` and `/bootstrap/public-path.php` are git-ignored.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **167 tests / 343 assertions** in parallel (89 new: every verifier rule, zip checks, the command, the PHP guard message and its PHP-7-safe syntax).
- **AC 1 (zip passes):** built `goproperty-0.4.0-rc.1.zip` with `build-package.sh`; `verify-drill.sh` passed: the package's own `goproperty:verify-package` passed the zip (34,225 files in about 5 s, 110 MB peak memory) and both unpacked layouts (the split layout found its public files in `public_html` from artisan); both layouts served `/up`, `/` and a built font with HTTP 200 under `php -S`, and still verified afterwards.
- **AC 2 (tampering fails):** a changed `public/robots.txt` (checksum mismatch), an added `public_html/added.php` (not listed) and a changed zip entry (inner checksum and `.sha256` both) were each refused with exit 1.
- Not run here: Docker is not accessible on this machine, so the old-PHP guard was not executed on PHP 7.4/8.1 locally (unit-tested on 8.4: message, passes 8.4, no PHP 8 tokens; loaded before Composer). The Release workflow and the `Old PHP guard` CI job were YAML-checked only (`actionlint` not installed) and have not run: GitHub Actions still fails every job in seconds (latest CI run 12:15 UTC), the P00-02 blocker.
- Local caveat: no `.env` in this checkout, so `composer ci` ran with a throwaway `APP_KEY` in the environment (CI creates `.env` and runs `key:generate`).

**To finish:** once GitHub Actions starts jobs, push a test tag (for example `v0.1.0-rc.1`) or run Release by hand, confirm the drill passes (including PHP 7.4/8.1) and the release is published, then mark P00-04 complete.

**Follow-ups:**
- P01-09: add the installer's static `public/install.css` to the package (INSTALLER.md § 2).
- P00-06: the install drill can reuse `build-package.sh` and the split layout from `verify-drill.sh`.
- The SSH deploy tarball has no `VERSION`/`SHA256SUMS`, so `goproperty:verify-package` on a CI-deployed server reports "not an unpacked release package". Add them to `build-release.sh` if the console's **System → Version** page (P08) needs them.
- The zip holds two copies of `vendor/` (55 MB). If an owner's File Manager upload limit is lower, publish one zip per layout instead.
- The launch checklist (IMPLEMENTATION_PLAN.md § 4) asks for a **signed** release zip; signing (e.g. GitHub artifact attestations) is not part of P00-04.
- The old-PHP Docker images are pinned by tag, not digest.

**Next task:** P00-05 (installer core services).

### P00-03 — In review (2026-10-26)

**Delivered:**
- **One profile switch.** `HOSTING_PROFILE=shared|vps` (`config/hosting.php`) sets the defaults for `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER`, `BROADCAST_CONNECTION` (published `config/broadcasting.php`), `REALTIME_DRIVER`, `PDF_DRIVER` and `QUEUE_TICK_ENABLED`; each variable still overrides it. Shared: database drivers, broadcasting `null`, polling every 15 s, dompdf, tick on. VPS: Redis, Reverb (60 s fallback poll), Chrome, tick off. `.env.example` now sets only `HOSTING_PROFILE`.
- **In-process queue tick** (`App\Hosting\Queue\QueueTick` + `TickWorker`, a subclass of Laravel's worker): scheduled with `Schedule::call()` every minute, last in `routes/console.php`. Drains the `database` queue with four lanes (payments = `billing`, notifications, default, bulk = `pdf` + `imports`), one job per lane in turn, per-lane caps, 45 s budget, memory and lost-connection stops, a 120 s cache lock, and a heartbeat. Retries use `QUEUE_TICK_TRIES` (3) and `QUEUE_TICK_BACKOFF` (30 s); failed jobs go to `failed_jobs` (Laravel's `queue:work` command does that, not its worker, so the tick does it itself). Job timeouts only when the pcntl functions work. A scheduler heartbeat runs every minute too.
- **Scheduler fix for shared hosts:** Laravel's scheduler calls `pcntl_async_signals()` before every event whenever the pcntl extension is loaded. On hosts that load pcntl but disable its functions, every scheduled event failed (found by the drill). `HostingServiceProvider` turns that signal hook off when the functions are missing.
- **PDF driver interface** `App\Pdf\PdfRenderer` (via `PdfManager`): `dompdf` (dompdf/dompdf 3.1.6; remote fetches, PHP and JS off; chroot to `resources/` and `public/`) and `chrome` through a Gotenberg service over HTTP (`PDF_CHROME_URL`, optional basic auth), so the PHP server needs neither Node nor `proc_open`. Browsershot is not used. Both let the template's `@page` rule win.
- **Realtime:** `App\Hosting\Realtime` (`usesWebsockets()`, `pollSeconds()`) for P07's live screens.
- **`php artisan goproperty:hosting`:** shows profile, drivers, whether `exec`/`proc_open` exist, heartbeats; exits 1 when settings cannot work together (unknown profile, tick off on shared hosting, jobs pushed to a queue the tick does not drain, Reverb without broadcasting or the Pusher SDK, Redis without a client, Chrome without a Gotenberg URL).
- **Deploy pipeline:** `.github/workflows/deploy.yml` (manual: environment + maintenance flag) calls `ci.yml` (now also `workflow_call`, `HOSTING_PROFILE=shared`), builds with `scripts/deploy/build-release.sh`, uploads with `scp` using pinned host keys, and runs `scripts/deploy/remote-deploy.sh` over SSH: unpack → link `shared/.env` and `shared/storage` → `goproperty:hosting` → optional `down` → `migrate --force` → config/route/view/event caches in the new release → atomic `current` switch → `queue:restart` → `up` → keep 5 releases. Then it checks `APP_URL/up`. GitHub environment settings are listed in [shared-hosting.md § 3](runbooks/shared-hosting.md).
- **Tests run on the shared profile:** `phpunit.xml` sets `HOSTING_PROFILE=shared` and no longer forces array cache or sessions (only the queue stays `sync`). `tests/TestCase.php` re-points the database cache store at the parallel worker's database (Fortify's rate limiter builds it before Laravel switches databases, and its reconnect was dropping each test's transaction).
- Docs: ARCHITECTURE.md § 2 and § 12.1, INSTALLER.md § 8, BRANDING_DOCUMENTS.md § 5, AGENT.md § 2 and § 4, README, [shared-hosting.md](runbooks/shared-hosting.md) § 2–3, § 5–6, [ci.md](runbooks/ci.md). The runbook's `BROADCAST_CONNECTION=log` became `null`, so broadcast payloads (possible PII) never reach the logs.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **79 tests / 220 assertions** in parallel (45 new: queue tick, schedule rules, profile defaults and overrides, configuration check, PDF drivers, cron drill). The parallel suite passed 3 runs in a row.
- **AC 2, cron drill** (`tests/Drill/CronDrillTest.php`): `php -d disable_functions=exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,pcntl_fork,pcntl_alarm,pcntl_signal,pcntl_async_signals artisan schedule:run` ran a queued job in the child process, which confirmed `exec`, `proc_open` and `pcntl_signal` were absent; jobs table empty, both heartbeats written.
- **AC 1** passes locally (suite on the shared profile). Not yet seen in CI: GitHub Actions still fails every job in seconds without a runner (runs on 2026-10-26 up to 11:32 UTC), the P00-02 blocker.
- **Deploy smoke test:** built a release (18 MB, no dev packages) and ran `remote-deploy.sh` twice against a local folder laid out like the host, with PHP restricted as above. First deploy migrated an empty database and switched `current`; the second ran with `MAINTENANCE=1 KEEP_RELEASES=1` (down → migrate → caches → switch → up, old release pruned). Duplicate and unsafe release names were refused. Served from `current/public`: `/up`, `/`, `/login` 200 and `/dashboard` 302 to `/login`. The cron line from `current/` ran a queued `Artisan::queue('inspire')` job. The SSH/scp steps and the workflow itself were not run (no host yet, PRE-04; Actions blocked), and `actionlint` was not available, so the workflows were only YAML-checked.
- Local caveat: this machine's PHP has no `intl`, so Composer ran with `--ignore-platform-req=ext-intl`; CI installs intl. MySQL 8.x was not run locally this time; CI covers MySQL 8.0/8.4 and MariaDB 10.6/11.4.

**To finish:** once GitHub Actions starts jobs, push and confirm the four `CI (…)` jobs are green (they include the cron drill), then mark P00-03 complete.

**Follow-ups:**
- P00-13: trigger `deploy.yml` on merges to `main` (staging) and set the staging environment variables and secret.
- P00-04 can reuse `scripts/deploy/build-release.sh` for the release zip.
- P03-05: bundle Inter for dompdf (fonts cache in `storage/framework/cache/dompdf`) and run the dompdf vs Chrome visual diff against a Gotenberg container.
- P07-03: use `Realtime::pollSeconds()` for `wire:poll`; installing Reverb adds `pusher/pusher-php-server`, which `goproperty:hosting` checks for.
- P08-03: read `Heartbeats` (`scheduler`, `queue-tick`) for the operations dashboard and the stopped-scheduler alert.

**Next task:** P00-04.

### P00-02 — Blocked (2026-10-26)

**Delivered:**
- `composer ci`: config clear → `pint --parallel --test` → `phpstan analyse` (Larastan level 7) → `php artisan test --parallel`. `composer test` now runs Pest only; `composer lint` fixes style.
- `.github/workflows/ci.yml`: runs `composer ci` on every push (and on demand), one job per database: `CI (MySQL 8.0)`, `CI (MySQL 8.4)`, `CI (MariaDB 10.6)`, `CI (MariaDB 11.4)`. MySQL 8.0 stays in the matrix because [docs/runbooks/shared-hosting.md § 1](runbooks/shared-hosting.md) promises 8.0+, although it reached end of life in April 2026. PHP 8.4, `npm ci` + `npm run build`, Composer cache. Actions pinned to commit SHAs (checkout v7.0.1, setup-php 2.40.0, setup-node v7.1.0, cache v6.1.0); passes `actionlint` 1.7.12.
- `.github/dependabot.yml`: weekly grouped updates for the pinned actions only (Composer/npm stay pinned by hand).
- [docs/runbooks/ci.md](runbooks/ci.md): what runs, how to reproduce locally against any server, and the branch-protection settings for `main`. AGENT.md § 2 and the README now point at `composer ci`.
- Larastan stays at level 7 (the kit default), above the level-6 minimum in the task.

**Tests and evidence (local):** `composer ci` passed against MySQL 8.4.11, MariaDB 10.6.27 and MariaDB 11.4.12 (each: Pint passed, Larastan 0 errors, Pest 34 tests / 83 assertions, 4 parallel workers each with its own `testing_test_N` database; the MariaDB runs used the `mariadb` connection). MySQL 8.0 could not be run locally (removed from nixpkgs at end of life); it is covered by its CI job.

**Why blocked:**
1. **CI jobs never start.** Run [40315055647](https://github.com/thekingchild/Goproperty/actions/runs/40315055647) (commit `64aeb52`) failed in 2–3 seconds on all four jobs, twice (one rerun). No runner was assigned and no step ran, so there are no logs. On a private repository on a personal account this is GitHub refusing to start jobs, usually a failed payment or a spending limit of $0 once included minutes are used up. The repo owner should open the run page (the annotation states the reason) and check **Settings → Billing and plans** (Actions usage, spending limit, payment method). After that, re-run the workflow or push any commit.
2. **Branch protection on `main`** needs a repository admin in the GitHub settings (the tools available here cannot set it). Settings to apply are in [docs/runbooks/ci.md § 3](runbooks/ci.md).

**To finish:** once the four CI jobs are green on the latest commit and branch protection is on, mark P00-02 complete.

**Next task:** P00-03 (does not depend on P00-02).

### P00-01 — Complete (2026-10-26)

**Delivered:** Laravel application skeleton at the repository root, from the official Livewire starter kit (`laravel/livewire-starter-kit` commit `b1e4f70`).

- Fortify auth with every kit feature kept: registration, email verification, 2FA (TOTP + recovery codes), passkeys, password confirmation. Flux UI components.
- Filament 5 installed as a package only. The staff panel, `staff` guard and `ADMIN_PATH` come in P00-08, so no admin panel is reachable yet.
- Pest 4: the kit's PHPUnit tests are rewritten in Pest style; `tests/Pest.php` applies `RefreshDatabase` to feature tests.
- Laravel Sail (`compose.yaml`): PHP 8.4 runtime, `mysql:8.4`, `axllent/mailpit:v1.32.0`. No Redis (shared-hosting profile uses database drivers).
- `.env.example` and `phpunit.xml` default to MySQL (Sail values; tests use the `testing` database Sail creates). Local mail goes to Mailpit.
- Pint enforces `declare(strict_types=1)` ([AGENT.md § 5](../AGENT.md)); Larastan runs at level 7 (kit default, above the level-6 minimum).
- Font: Inter, bundled from `@fontsource/inter` through the Vite font plugin and served locally ([UX_DESIGN.md § 6](../UX_DESIGN.md)); the kit's Bunny Fonts download was removed.
- Kit steps skipped on purpose: the ⚡ emoji filename prefix for Livewire components (emoji filenames are fragile in zips, FTP and cPanel file managers; Livewire works without it), the `--teams` option (organizations are our own design, [DATABASE_SCHEMA.md](../DATABASE_SCHEMA.md)), Laravel Boost, and the kit's GitHub workflow (PHP 8.3 + SQLite; CI is P00-02).

**Resolved versions** (`composer.lock`, `package-lock.json`). `composer.json` pins runtime and test packages to patch releases (`~X.Y.Z`) and sets `config.platform.php` to `8.4.0`, so updates never pull packages that need a newer PHP than the hosts run.

| Package | Version |
| --- | --- |
| PHP (minimum / tested) | ^8.4 / 8.4.27 |
| laravel/framework | 13.38.0 |
| livewire/livewire | 4.4.8 |
| livewire/flux | 2.21.0 |
| livewire/blaze | 1.0.20 |
| laravel/fortify | 1.41.0 |
| filament/filament | 5.10.3 |
| pestphp/pest / pest-plugin-laravel | 4.7.8 / 4.1.0 (PHPUnit 12.5.33) |
| larastan/larastan | 3.13.2 (PHPStan 2.3.2) |
| laravel/pint | 1.32.1 |
| laravel/sail | 1.69.0 |
| MySQL (Sail image / tested) | 8.4 / 8.4.11 |
| tailwindcss, @tailwindcss/vite | 4.3.3 |
| laravel-vite-plugin / vite-plus | 3.2.0 / 1.4.0 |
| @fontsource/inter | 5.3.0 |
| @laravel/passkeys | 0.2.0 |

**Tests and evidence:** fresh `git clone` → `composer install` → `key:generate` → `migrate --seed` on MySQL 8.4.11 → `npm ci` → `npm run build` → `composer test`: Pint passed, Larastan 0 errors, Pest 34 tests / 83 assertions passed. The app booted with `php artisan serve`; `/`, `/login`, `/register`, `/forgot-password` returned 200, `/dashboard` redirected guests to `/login`, and the seeded `test@example.com` user signed in to the dashboard in a browser. The Docker daemon was not available in the build environment, so `sail up` itself was not run; MySQL 8.4 ran natively instead.

**Follow-ups:**
- The kit's "delete account" setting hard-deletes the user row, which conflicts with [AGENT.md § 3](../AGENT.md) rule 7 (no hard deletes). Replace it with soft delete/deactivation when user accounts are reworked in P01; NDPA erasure stays in P09.
- Kit registration, login and welcome pages are stock placeholders: P00-09 (landing), P00-10 (login with email **or phone**), P01 (organization signup).
- Pest 5 has been released (needs PHP 8.4 and Laravel ≥ 13.23). The plan specifies Pest 4, so it stays on 4.7; decide on an upgrade separately.
- The theme font list in [PLATFORM_CONSOLE.md § 9](../PLATFORM_CONSOLE.md) (Plus Jakarta Sans, Manrope, DM Sans) should be bundled the same way as Inter in P00-09.

**Next task:** P00-02.

### Plan split into tasks — 2026-10-26

- The phases in `IMPLEMENTATION_PLAN.md` § 3 were split into 83 numbered tasks (PRE, P00–P09), each with its deliverable and acceptance criteria, following the Membersflow layout. No application code exists yet.
- Phase weeks follow the § 2 overview (P02 weeks 6–8, P03 weeks 9–12, P04 week 13); the old detail headings said weeks 6–7, 8–11 and 12.
- Where the original plan gave a deliverable no acceptance criterion, the task's acceptance was written from the deliverable and the companion docs. Review those before starting each phase.

### PRE-05 — Complete (2026-10-25)

- Design checkpoint: SocietyFlow reference in `docs/design/reference/`; approved designs `docs/design/templates/landing.html`, `login.html`, `login-2fa.html` (commit `8ba112e`).
