# GoProperty — SaaS Billing & Payments

How GoProperty charges its customers (agencies / property owners) and how those charges are collected. Customer-side money flows (rent, utilities, receipts) are covered in [ONBOARDING_COMPLIANCE.md § 4](ONBOARDING_COMPLIANCE.md), [ARCHITECTURE.md § 6.4](ARCHITECTURE.md) and [DATABASE_SCHEMA.md § 4](DATABASE_SCHEMA.md).

> **Revenue model: subscription only.** GoProperty earns from its own subscription invoices, paid by Paystack (card) or manual bank transfer. It takes **no fee on tenant payments** and never receives, holds or splits tenant money — tenants pay agencies directly ([ONBOARDING_COMPLIANCE.md § 0](ONBOARDING_COMPLIANCE.md)). The GoProperty Paystack account is used only for GoProperty's own subscription income.

> **Why custom billing instead of Laravel Spark/Cashier:** Spark and Cashier speak Stripe/Paddle, neither of which is practical for Nigerian Naira collection. GoProperty implements a small, purpose-built billing layer around Paystack + manual bank transfer. It is deliberately boring: invoices, payments, webhooks, a ledger — no recurring-charge magic we don't control.

## 1. Pricing Model (per block/apartment)

Billing is based on the number of **blocks/apartments (units)**, not buildings: a base price that includes some units, plus a fee per extra unit. Monthly or annual. VAT is added on top.

### 1.1 Launch prices (editable in the console)

| Plan | Base / month | Units included | Each extra unit / month | Annual (pay 10 months, get 12) |
| --- | --- | --- | --- | --- |
| **Starter** | ₦10,000 | 10 | ₦700 | ₦100,000 / year base |
| **Growth** | ₦35,000 | 50 | ₦500 | ₦350,000 / year base |
| **Scale** | ₦85,000 | 200 | ₦350 | ₦850,000 / year base |
| **Enterprise** | from ₦250,000 | 1,000 | custom | custom contract |

Examples (before 7.5% VAT):

| Agency | Plan | Monthly | Per unit |
| --- | --- | --- | --- |
| 8 units | Starter | ₦10,000 | ₦1,250 |
| 30 units | Starter | ₦24,000 | ₦800 |
| 60 units | Growth | ₦40,000 | ₦667 |
| 250 units | Scale | ₦102,500 | ₦410 |

**Why these prices**

- Per unit, the cost is ₦400–₦1,250 a month, under 1% of a typical ₦1.5m–₦3m annual rent. It's a small fraction of what agencies earn per unit (typically 5–10% of rent in management fees, plus first-year agency fees), so the value case is easy to make.
- Each plan becomes more expensive than the next one up just before its limit (Starter at 50 units = ₦38,000 vs Growth ₦35,000; Growth at 200 units = ₦110,000 vs Scale ₦85,000), so customers are nudged to upgrade without a hard cutoff.
- Annual billing (2 months free) suits how Nigerian businesses budget, matches the yearly rent cycle, and protects against price changes from inflation.
- **No free plan at launch:** a 14-day full trial without a card instead. Free plans bring support load without revenue. Revisit 6 months after launch.

### 1.2 What each plan includes

| Feature | Starter | Growth | Scale | Enterprise |
| --- | :-: | :-: | :-: | :-: |
| Properties, units, tenants, leases, bills | ✓ | ✓ | ✓ | ✓ |
| Agency-branded invoices, receipts, statements ([BRANDING_DOCUMENTS.md](BRANDING_DOCUMENTS.md)) | ✓ | ✓ | ✓ | ✓ |
| Tenant portal + owner portal & statements | ✓ | ✓ | ✓ | ✓ |
| Bank transfer with statement matching + proof upload | ✓ | ✓ | ✓ | ✓ |
| Excel import (dry run) & export ([DATA_IMPORT.md](DATA_IMPORT.md)) | ✓ | ✓ | ✓ | ✓ |
| Maintenance tickets, email reminders | ✓ | ✓ | ✓ | ✓ |
| Staff users | 3 | 10 | Unlimited | Unlimited |
| SMS credits included / month | 100 | 500 | 2,000 | Custom |
| Agency's own Paystack (online payments) | — | ✓ | ✓ | ✓ |
| WhatsApp notifications (agency's own WhatsApp Business API) | — | ✓ | ✓ | ✓ |
| Building Group | — | ✓ | ✓ | ✓ |
| Agent ↔ building assignment, approval workflows | — | — | ✓ | ✓ |
| Remove "Generated with GoProperty" from documents | — | — | ✓ | ✓ |
| Assisted data import by GoProperty staff | — | — | ✓ | ✓ |
| Support | Email | Email + chat | Priority + onboarding call | Dedicated manager |

- **SMS:** included credits reset monthly. Extra SMS bundles are bought in-app (priced in the console, e.g. ₦6 per SMS page). WhatsApp messages are billed by Meta directly to the agency's own WhatsApp Business account, so GoProperty doesn't resell them.
- **Transaction Tier is separate from plan.** Any plan can be on any tier.

### 1.3 Changing prices

- Plans, prices, included units, features and SMS credits are edited by `finance-admin` in the console (`plans`, `plan_feature` tables).
- Existing customers keep their price until their next renewal, and get 30 days' notice by email and in-app banner. Customers can be "grandfathered" on an old price.
- Every change is versioned and audited.

### 1.4 Billing rules

- Annual plans: 2 months free (`annual_discount_percent` ≈ 16.67%).
- **Metering rule:** on the billing day, count **all of the org's units that are not soft-deleted and whose property is not archived**, regardless of occupancy or the `unavailable` status (so marking units unavailable can't be used to reduce the bill). Subtract `included_units`, floor at zero → `counted_units`. Bill `base_price + counted_units × unit_price`.
- **Mid-cycle unit changes (MVP policy):** no proration. Adding units changes what is counted at the *next* billing day; removing units likewise. This is simple, explainable, and avoids credit-note machinery in v1. Proration is a listed post-MVP refinement.
- VAT: 7.5% Nigerian VAT added as a separate line on every platform invoice.
- Currency: **NGN only** at launch; amounts stored in kobo.
- **Plans vs Transaction Tiers:** the plan (priced per unit) and the Transaction Tier (how much money the agency may manage, based on verification — [ONBOARDING_COMPLIANCE.md § 5](ONBOARDING_COMPLIANCE.md)) are independent.

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
| `cancelled` | Terminated | Data retained 90 days, then NDPA-governed purge |

- Payment methods the customer can keep on file: Paystack card authorization (auto-charge attempt on invoice issue) or bank transfer (manual, see § 5).
- **Suspension is reversible and non-destructive.** No data deletion ever happens automatically.
- **How "write actions blocked" is enforced:** by `organizations.status` = `suspended` through the one central rule in [ARCHITECTURE.md § 4](ARCHITECTURE.md) (`App\Tenancy\OrganizationWriteGuard`). `SubscriptionService` must therefore set the organization's status to `suspended` when the subscription is suspended and restore it on payment, the same way staff do from the console (`App\Platform\Customers\OrganizationSuspension`, which records the status to return to). Paying the subscription (`organization.billing`) stays allowed while suspended.
- Platform-covered orgs (`platform_covered = true` or `payer_organization_id` pointing at a platform-owned org) skip dunning — invoices are marked paid internally with an audit trail. Signup sets `platform_covered` for organizations of type `property_owner` ([ONBOARDING_COMPLIANCE.md § 2](ONBOARDING_COMPLIANCE.md)).
- Until the subscription records exist (P06-01), the signup wizard keeps the chosen plan in `organizations.signup_plan_key` and the trial end in `organizations.trial_ends_at` (14 days from the first plan choice). P06-01 creates each `trialing` subscription from them.

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
3. Platform `finance-admin` reviews in the console: confirms (invoice → paid, subscription → active, confirmation email) or rejects with reason (customer notified, may re-upload).
4. Every confirmation records `confirmed_by_user_id` + timestamp in the audit log. There is **no auto-confirmation** of manual proofs in v1; DVA (§ 4.1) is the scaling path.

## 6. Dunning & Retries

| Trigger | Action |
| --- | --- |
| Card charge fails | Retry ladder: +1d, +3d, +5d (3 attempts, new references). Each failure emails the org owner with a pay-now link |
| Invoice unpaid at due date | Email + in-app banner; subscription → `past_due` |
| 7 days past due | Grace warning daily (email), banner escalates |
| 14 days past due (grace end) | Suspend: write-actions blocked; SMS + email to owner; features gated by middleware |
| Every 14 days suspended | Reminder re-send |
| 90 days suspended | Mark `cancelled`; data purge timer starts (NDPA tooling handles erasure) |

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

**As built (P02-04, ahead of P06; [ARCHITECTURE.md § 6.1](ARCHITECTURE.md)).** `Modules\SaasBilling\Transfers\BillingTransferService` records offer, accept, decline, withdraw and expiry with this state machine; any other transition is refused and changes nothing. Only organizations already on GoProperty can be offered a bill (by web address or an owner's email); inviting an organization that is not yet on GoProperty is a later addition. The offering organization is the current payer and owns the row. The amount on the accept screen is the offering organization's plan price for its billable units today (before VAT; the plan chosen at signup until P06-01's subscription exists), and acceptance records it. Until P06-06, acceptance records the handover only: `payer_organization_id` does not move yet, no payment method is re-enrolled, `completed_at` stays empty, and the accepting organization gets no access to the other's buildings. P06-06 applies the switch, including to transfers completed before it ships.

## 8. Refunds & Credit Notes

- Paystack refunds via API, only by `finance-admin` (second approval above the two-person threshold — [PLATFORM_CONSOLE.md § 1](PLATFORM_CONSOLE.md)), always tied to a `platform_payment`, audit-logged, invoice returns to `open` unless a credit note replaces it.
- Overpayment / goodwill: create a credit note (post-MVP table `credit_notes`; MVP handles by adjusting the next invoice manually with an audit note).

## 9. Reports Feeding Off This Data

- MRR & ARR (from active subscriptions' effective rates), unit growth per customer, churn & suspension events, ARPU by plan, collection rate (confirmed vs issued), average days-to-pay, customer performance leaderboard (units growth + payment reliability) — all queryable from `platform_invoices`, `platform_payments`, `subscription_unit_events`, `subscriptions` without touching tenant tables.

## 10. Open Decisions for the Business

1. ~~Final plan prices~~ — set in § 1.1 (editable).
2. Whether trial is 14 days, and whether trials require a card.
3. VAT registration status of the platform company (affects invoice compliance lines).
4. DVA provider timing: Paystack DVA at launch vs manual confirmation only (recommend: launch manual, add DVA in the first quarter after launch).
5. ~~Transaction Tier thresholds~~ — set in [ONBOARDING_COMPLIANCE.md § 5.2](ONBOARDING_COMPLIANCE.md) (editable).
6. ~~Which plans get automation~~ — statement matching on all plans; own Paystack and WhatsApp from Growth (§ 1.2).
7. ~~Free tier~~ — no free plan at launch; 14-day trial (§ 1.1). Revisit after 6 months.
8. Corporate customers deducting withholding tax: accept payment net of WHT on upload of the WHT credit note.
