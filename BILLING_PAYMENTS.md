# GoProperty — SaaS Billing & Payments

How GoProperty charges its customers (agencies / property owners) and how those charges are collected. Customer-side money flows (rent, utilities, receipts) are covered in [ARCHITECTURE.md § 6.4](ARCHITECTURE.md) and [DATABASE_SCHEMA.md § 4](DATABASE_SCHEMA.md).

> **Why custom billing instead of Laravel Spark/Cashier:** Spark and Cashier speak Stripe/Paddle, neither of which is practical for Nigerian Naira collection. GoProperty implements a small, purpose-built billing layer around Paystack + manual bank transfer. It is deliberately boring: invoices, payments, webhooks, a ledger — no recurring-charge magic we don't control.

## 1. Pricing Model (per block/apartment)

The spec: billing is based on the number of **blocks/apartments (units)**, not buildings. Recommended shape:

**Base plan + per-unit overage, monthly or annual:**

| Plan | Base price (illustrative) | Included units | Per extra unit / month |
| --- | --- | --- | --- |
| Starter | ₦15,000 / month | 10 | ₦800 |
| Growth | ₦40,000 / month | 50 | ₦600 |
| Scale | ₦90,000 / month | 200 | ₦450 |
| Enterprise | negotiated | negotiated | negotiated |

- Prices are **illustrative placeholders** — to be finalized by the business before Phase 7.
- Annual plans get ~2 months free (`annual_discount_percent`).
- **Metering rule:** on the monthly billing day, count the org's units with `status != unavailable` and not soft-deleted, subtract `included_units`, floor at zero → `counted_units`. Bill `base_price + counted_units × unit_price`.
- **Mid-cycle unit changes (MVP policy):** no proration. Adding units changes what is counted at the *next* billing day; removing units likewise. This is simple, explainable, and avoids credit-note machinery in v1. Proration is a listed post-MVP refinement.
- VAT: 7.5% Nigerian VAT added as a separate line on every platform invoice.
- Currency: **NGN only** at launch; amounts stored in kobo.

## 2. Subscription Lifecycle

```
trialing ──(trial ends, invoice issued)──▶ active ──(invoice unpaid past due+grace)──▶ past_due
   ▲                                                                                    │
   │                                                                                    ▼ (7-day grace)
cancelled ◀──(owner cancels / non-payment)── suspended ◀──(grace ends)─────────────────┘
   ▲                                          │
   └────────────(reactivate + pay)────────────┘
```

| State | Meaning | System behaviour |
| --- | --- | --- |
| `trialing` | 14-day trial (configurable per plan) | Full features, banner with days left |
| `active` | Paid up | Normal |
| `past_due` | Invoice open past `due_date` | 7-day grace, prominent banner |
| `grace` | Final 7 days before suspension | Read-only warnings, data intact |
| `suspended` | Unpaid beyond grace | Login blocked to write actions; data retained; export tooling available; auto-reminder every 14 days |
| `cancelled` | Terminated | Data retained 90 days, then NDPR-governed purge |

- Payment methods the customer can keep on file: Paystack card authorization (auto-charge attempt on invoice issue) or bank transfer (manual, see § 5).
- **Suspension is reversible and non-destructive.** No data deletion ever happens automatically.
- Platform-covered orgs (`platform_covered = true` or `payer_organization_id` pointing at a platform-owned org) skip dunning — invoices are marked paid internally with an audit trail.

## 3. Invoice Generation & Unit Metering

Monthly job (`saas:meter-units`, 1st of month 03:00):

1. For each `active`/`past_due` subscription, count units (rule in § 1).
2. Append a row to `subscription_unit_events` (ledger — every change of counted units is preserved for dispute resolution and analytics).
3. Create `platform_invoices` row: `subtotal = base + counted_units × unit_price`, `vat = 7.5%`, `total`. Number from the platform sequence (`PR-2026-000123`).
4. Email the invoice to all `org-owner`s of the payer organization (and CC payer-org owners when different).
5. If a card authorization exists → attempt Paystack charge (§ 4). Else → bank transfer instructions (§ 5).

Charge failure → invoice stays `open`, subscription → `past_due` after `due_date` (net 7).

Plan changes: immediate plan switch re-computes the next invoice at the new plan's rates; the current invoice is untouched (no proration in MVP, consistent with § 1).

## 4. Paystack Integration

Used for SaaS subscription collection. Scope: **transaction charges with saved authorizations** — we own invoicing, retries, and state; Paystack is a rail, not a subscription engine.

### 4.1 Flows

| Flow | Mechanism |
| --- | --- |
| First card setup | Paystack Inline/checkout: initialize transaction with `plan`-less amount ₦100 verification charge OR a real invoice charge; `authorization` from `charge.success` is stored on the subscription |
| Auto-charge on invoice | `POST /transaction/charge_authorization` with stored auth + unique `reference = PINV-{number}-a{n}` |
| Optional Paystack plan subscription | Available but **not** the primary path — keep pricing logic server-side in our metering job |
| Dedicated Virtual Accounts (DVA) | Phase-7.5 enhancement: issue a per-customer DVA so bank transfers auto-match on `charge.success` webhooks — removes manual confirmation at scale |

### 4.2 Webhook handling

- Endpoint `/webhooks/paystack`, HMAC SHA-512 signature verification against raw body; unverified payloads logged and dropped.
- Persist every event to `webhook_log` (raw payload + processed flag) **before** processing — replay-safe, idempotent handlers keyed on `reference`.
- `charge.success` → verify by fetching the transaction server-side (`GET /transaction/verify/:reference` — never trust the payload amount alone) → mark `platform_payment` confirmed → mark invoice paid → subscription → `active` → receipt + notifications.
- `charge.failed` → log, increment attempt counter, schedule retry ladder (see § 6).
- Hourly reconciliation job re-verifies all `open` invoices older than 1h against Paystack (catches missed webhooks).

### 4.3 Idempotency & safety rules

1. One payment record per unique `paystack_reference` (DB unique index) — webhook replays are no-ops.
2. Amount validation: confirmed amount must equal invoice total; mismatch → flag for platform-staff review, never auto-apply partials.
3. All state transitions in transactions; invoice marking and subscription reactivation commit atomically.
4. Keys: secret key only in backend config; frontend uses Paystack Inline public key; test keys on staging enforced by env name check.

## 5. Bank Transfer Workflow (manual confirmation)

For customers who prefer transfer, or when card charge fails:

1. Invoice shows GoProperty's settlement account + the invoice number as required narration.
2. Customer (org owner) uploads proof (image/PDF) against the invoice from `/app/billing` → `platform_payments` row `pending` with proof.
3. Platform `billing-admin` reviews in the console: confirms (invoice → paid, subscription → active, confirmation email) or rejects with reason (customer notified, may re-upload).
4. Every confirmation records `confirmed_by_user_id` + timestamp in the audit log. There is **no auto-confirmation** of manual proofs in v1; DVA (§ 4.1) is the scaling path.

## 6. Dunning & Retries

| Trigger | Action |
| --- | --- |
| Card charge fails | Retry ladder: +1d, +3d, +5d (3 attempts, new references). Each failure emails the org owner with a pay-now link |
| Invoice unpaid at due date | Email + in-app banner; subscription → `past_due` |
| 7 days past due | Grace warning daily (email), banner escalates |
| 14 days past due (grace end) | Suspend: write-actions blocked; SMS + email to owner; features gated by middleware |
| Every 14 days suspended | Reminder re-send |
| 90 days suspended | Mark `cancelled`; data purge timer starts (NDPR tooling handles erasure) |

Reactivation: any confirmed payment covering open invoices returns the subscription to `active` immediately (webhook or manual confirmation — same code path).

## 7. Billing Responsibility Transfer (org ↔ org)

The property-owner↔agent handover. State machine on `billing_transfer_requests`:

```
pending ──accept──▶ completed   (payer_organization_id switches, payment method re-enrolment starts)
pending ──decline──▶ declined   (nothing changes)
pending ──cancel──▶ cancelled   (initiator cancels before response)
pending ──14d──▶ expired        (invitation token expires too)
```

Rules:

1. Only an `org-owner` can initiate; only the target org's `org-owner` can accept/decline.
2. **Acceptance is what moves the bill.** The UI must state, on the accept screen: "You are taking over the GoProperty subscription bill for {amount}/month from {date}." The accept action creates the completed transfer in one transaction.
3. From acceptance: the new payer gets card-setup + bank instructions within 1 billing cycle; interim invoices route to the new payer.
4. The initiator is notified of every state change. The platform console shows pending transfers (support visibility).
5. Reversal = a new transfer request in the other direction; no edit of a completed transfer.

## 8. Refunds & Credit Notes

- Paystack refunds via API, only by `billing-admin`, always tied to a `platform_payment`, audit-logged, invoice returns to `open` unless a credit note replaces it.
- Overpayment / goodwill: create a credit note (post-MVP table `credit_notes`; MVP handles by adjusting the next invoice manually with an audit note).

## 9. Reports Feeding Off This Data

- MRR & ARR (from active subscriptions' effective rates), unit growth per customer, churn & suspension events, ARPU by plan, collection rate (confirmed vs issued), average days-to-pay, customer performance leaderboard (units growth + payment reliability) — all queryable from `platform_invoices`, `platform_payments`, `subscription_unit_events`, `subscriptions` without touching tenant tables.

## 10. Open Decisions for the Business

1. Final plan prices, included-unit bands and per-unit rates (§ 1 placeholders).
2. Whether trial is 14 days, and whether trials require a card.
3. VAT registration status of the platform company (affects invoice compliance lines).
4. DVA provider timing: Paystack DVA at launch vs manual confirmation only (recommend: launch manual, add DVA in the first quarter after launch).
