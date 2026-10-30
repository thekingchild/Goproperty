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
- **How SMS credits work (P05-03; ARCHITECTURE.md § 6.7 "How SMS credits are built"):** one credit is one SMS page. The month's allowance arrives on the 1st (Lagos) and what is left of last month's expires; credits bought never expire; sending uses allowance credits first. A credit is taken just before each SMS is handed to GoText; at zero, SMS stop (email and in-app reminders carry on) and nothing is charged. An SMS GoText refuses outright gives its credit back. Owners and administrators are told at 20 % of the month's allowance and at zero, once a month each. Bundles (default 500, 1,000, 2,500, 5,000 credits at ₦6 each, plus VAT, set by `finance-admin`) are bought by an owner from Settings → SMS credits: each is a platform invoice (`PR-…`) paid through GoProperty's own Paystack checkout, and the credits are added once Paystack confirms the payment (§ 4.2–4.3 apply: verified by fetch, once per reference, a mismatch held for staff). Not while the organization is suspended (it is read-only; only the subscription is paid then).
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
- Organizations' **own** Paystack accounts (tenant rent, never GoProperty's money) follow the same rules on their own endpoint, `/webhooks/paystack/{webhook_token}`, signed with that organization's secret, with verify-by-fetch, one payment per `(organization_id, paystack_reference)` and an amount mismatch **held for the organization's review** instead of platform staff's (built in P03-08: [ONBOARDING_COMPLIANCE.md § 4.2](ONBOARDING_COMPLIANCE.md) "How it is built", [ARCHITECTURE.md § 6.4](ARCHITECTURE.md) "How online payments are built"). Their deliveries share `webhook_log` with `organization_id` set.
- Persist every event to `webhook_log` (raw payload + processed flag) **before** processing — replay-safe, idempotent handlers keyed on `reference`.
- `charge.success` → verify by fetching the transaction server-side (`GET /transaction/verify/:reference` — never trust the payload amount alone) → mark `platform_payment` confirmed → mark invoice paid → subscription → `active` → receipt + notifications.
- `charge.failed` → log, increment attempt counter, schedule retry ladder (see § 6).
- Hourly reconciliation job re-verifies all `open` invoices older than 1h against Paystack (catches missed webhooks).
- **Built so far (P05-03):** GoProperty's webhook `/webhooks/paystack` (`Modules\SaasBilling`), the HMAC check with GoProperty's key, `webhook_log` before processing, verify-by-fetch and one `platform_payments` row per reference, for SMS credit bundles. The hourly reconciliation, stored authorizations and subscription invoices come with P06-02/P06-03.

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

## 7. Management Handover (org → org)

**Product owner decision (2026-10-27): this replaces the "billing responsibility transfer".** A landlord who hands GoProperty billing to an agency is handing over the *management* of their buildings. So the buildings move into the agency's organization, the agency's own subscription pays for them (billing follows units, § 1.4), and the landlord keeps a read-only view as `property-owner` through the owner portal (`/owner`, P03-15). No organization ever pays another organization's bill, and paying never grants access by itself: access comes from where the buildings live.

### 7.1 Who and what

- A handover moves **chosen buildings** (with their units) from one organization to another. A landlord with five buildings may hand three to one agency and keep two.
- **Starting management:** an `org-owner` of the landlord's organization (type `property_owner` or `hybrid`) offers buildings to an agency already on GoProperty, found by its web address or an owner's email. Only an `org-owner` of the receiving organization accepts or declines.
- **Returning buildings the agency holds without a handover** (added or imported by the agency): the agency's `org-owner` may offer them to the landlord's own organization the same way. **A return is one-way** (product owner, 2026-10-28): once the landlord's owner accepts, the buildings live in the landlord's organization and the handover ends as `returned`, final, with no notice and no end date. If the agency should keep managing them, the landlord then offers them with a normal handover. A landlord without an organization registers a free `property_owner` one first. **Safeguard (P04-04 default, to confirm):** a return goes only to an organization one of whose `org-owner`s is the account linked to the buildings' owner record in the agency (the owner portal, P03-15), the same account for every building offered; an agency whose owner record is not linked invites the owner to the portal first, so an agency can never push buildings into an unrelated organization. Built in P04-04 (§ 7.8).
- The old "agency asks the landlord to pay the agency's bill" direction is dropped; an agency that wants its landlord on GoProperty invites them to the owner portal (P03-15).
- A building is in at most one open handover (pending, active or ending). Only active buildings are handed over.

### 7.2 State machine (`management_handovers`)

```
pending ──accept──▶ active      (buildings and live records move to the receiving organization, one transaction)
pending ──decline──▶ declined   (nothing moves)
pending ──cancel──▶ cancelled   (the offering organization withdraws before an answer)
pending ──14d──▶ expired        (the link expires too)
active ──notice──▶ ending       (either side gives notice; the agency keeps managing until the end date)
ending ──withdraw notice──▶ active   (only the side that gave it, before the end date)
ending ──end date──▶ ended      (live records move back to the landlord's organization)
pending ──accept──▶ returned    (a one-way return, § 7.1: the buildings move to the landlord's own organization, for good)
```

A return (§ 7.1) goes `pending ──accept──▶ returned` (final; no notice, no end, no withdrawal of notice); it is declined, withdrawn and expires like any offer. Which accepted state an offer reaches is set by its kind (`management` → `active`, `return` → `returned`). Any other transition is refused and changes nothing. States change only through the handover service (AGENT.md § 3 rule 5).

### 7.3 The accept screen

The receiving owner sees, before accepting:

- the buildings and the number of units in each;
- who collects rent (§ 7.5) and the notice period (§ 7.6);
- **the bill change:** "Your GoProperty bill goes from {current}/month to {after}/month (before VAT) from your next billing day, {date}." Units are counted on the billing day (§ 1.4), so at MVP there is **no proration**; when proration ships (a post-MVP refinement, § 1.4), handovers use it. Plans have no hard unit limit (§ 1.1): extra units cost the plan's per-unit price, and the screen suggests a bigger plan when it would be cheaper.

Accepting is the agency's consent to the new bill. It passes the amount shown, and is refused if the amount changed while the screen was open (as P02-04 does).

### 7.4 What moves and what stays

- **Moves (the live records):** the buildings with their photos and files, units, tenants of those units, active and reserved leases (with the deposit recorded on them), utility settings (with where their billing stands: the next period to bill, P03-06b) and meter readings (with their meter photos; each moved setting points to the receiving organization's utility type of the same code or name, created from the old one if it has none: utility types are organization-wide configuration and do not move; DATABASE_SCHEMA.md § 4). Agent assignments on the moved buildings are dropped (they belong to the old organization's members). In the receiving organization the landlord becomes an owner profile (an existing one is matched, else one is created from the landlord organization's details), linked to the landlord's user, who gets the `property-owner` role there (P03-15: `Modules\Properties\OwnerPortal\OwnerLinks::link()` sets `owner_profiles.user_id` and the membership with that role only; the landlord then sees the buildings in `/owner`, read-only; at the end of management the link in the agency's organization is removed with `unlink()` unless the landlord owns other buildings there).
- **Stays (the history):** bills, receipts, payments, credit notes and documents issued before acceptance stay in the organization that issued them, with its numbering and branding; they are legal records and are never rewritten. So do the expenses an organization recorded for a moved building, with their receipt files (P03-12: the organization's own books, which the other side never sees). Unpaid bills issued before acceptance stay with their issuer, which collects them. The agency issues the next bills from acceptance. Audit entries stay where they were written; both organizations get a handover entry.
- **Clashes:** a moved building takes the next free web address (slug) in the receiving organization; import references (`external_ref`) that clash there are cleared on the moved row. Media files move to the receiving organization's folder.
- **Tenant records** (product owner, 2026-10-28): a moving tenant is matched by phone in the receiving organization or copied there; the landlord's record stays with its history and shows "Moved to {agency} on {date}". It cannot be archived while it has unpaid bills or unsettled credit (the tenant still pays the landlord those bills there), and archives itself once both are clear (and no lease there holds a unit for them). On a return the tenant's original record, recorded on the handover, is used first, then the phone. The same holds on a one-way return with the sides swapped (the agency's record shows "Moved to {landlord} on {date}"). Built in P04-04 (§ 7.8).
- **Open maintenance tickets** (product owner, 2026-10-28; built in P04-05, ARCHITECTURE.md § 6.6): every ticket of a moved building that is not closed (submitted, under review, in progress, resolved, reopened, and a tenant's unsent draft) moves with it, in the same transaction, on acceptance, at the end of a management and on a one-way return. As with tenant records, nothing changes organization: the receiving organization gets **its own ticket**, with the next number of its sequence, "Moved from {organization}, was TKT-000012", the moved tenant's record and the lease there, and the public messages and their files copied as its evidence (internal notes, the status history and the notices stay where they were written); a resolved ticket stays resolved (the tenant's reopen window counts from the original resolution), a draft stays a draft, any other arrives `submitted`, nobody is assigned. The ticket left behind is closed for good with the note "Building moved to {organization} on {date}" and keeps its history (a draft is discarded). The tenant is told once that the agency now handles their repair, and the receiving organization's owners and administrators once per ticket. Closed tickets stay behind as history.
- **Portal links** (P04-04): pending portal invitations and one-time sign-in links of moved tenants on the side they left are revoked (audited), unless the tenant still holds a lease there; the receiving organization invites them again, and a tenant with a portal account keeps it (their new record carries it).
- **Members of the old organization** (for example the landlord's caretaker as `agent`) lose access to the moved buildings; the agency can invite them.
- **Tenant credit** (P03 decision 11, product owner): credit is money a tenant paid into the landlord's account, so it never moves by itself. The handover lists each moving tenant's unused credit (and unpaid bills, which stay with the landlord, § above). Per tenant the landlord chooses **pass to the agency** (default: paid outside GoProperty; the agency records it as the tenant's opening credit only after confirming the money arrived) or **refund the tenant** (paid outside GoProperty and recorded as a refund that closes the credit). Until settled the credit stays on the landlord's books as owed to the tenant, visible to both organizations, never lost or counted twice (§ 7.8). **On a one-way return** (P04-04 default, to confirm) the roles are swapped: the credit is on the agency's books, the agency chooses **pass to the landlord** (default) or **refund the tenant**, and the landlord confirms the money arrived to open it on its record.

### 7.5 Who collects rent

The offer says who collects rent for the handed-over buildings:

- **Agency collects** (the usual practice): tenants pay the agency's own account; the agency pays the landlord after its commission, outside GoProperty.
- **Landlord collects:** tenants pay the landlord's own account. This needs a collection account per building (P03-07); until that exists, only "agency collects" is offered.

Either way, GoProperty never touches the money (AGENT.md § 3 rule 8, [ONBOARDING_COMPLIANCE.md § 0](ONBOARDING_COMPLIANCE.md)).

### 7.6 Ending management

- **Either side may end it alone, with notice:** the landlord ends the management, or the agency resigns. The notice period is set on the offer (default 30 days, 0–180) and shown on the accept screen. During notice the agency keeps managing, and its subscription keeps counting the units.
- **At the end date** (hourly job), the live records of § 7.4 move back to the landlord's organization, and records the agency created during management (its bills, receipts, payments, documents) stay with the agency as history; the landlord can export them.
- **The landlord's organization is kept** (never deleted) while a handover from it is active or ending, so there is always somewhere for the buildings to return to. Organizations of type `property_owner` are platform-covered (§ 2), so returned units cost the landlord nothing.
- After the return, the landlord may hand the buildings to another agency with a new handover. An accepted handover is never edited: a change is an end plus a new handover.

### 7.7 Rules

1. Only an `org-owner` offers, accepts, declines, withdraws, or gives and withdraws notice. All of these are blocked while staff impersonate, and a suspended organization does none of them (they are writes).
2. Acceptance, the end of a management and the one-way return each move everything in **one transaction**: each module moves its own records through its contract, and any failure rolls back the whole move.
3. Both sides are told of every state change, by email and in the app. The platform console shows pending and ending handovers (support visibility).
4. Only organizations already on GoProperty can be offered buildings; inviting an organization that is not on GoProperty yet is a later addition.

### 7.8 How it is built (P03-14, P04-04, P04-05)

P02-04 built the offer flow as a "billing transfer" (`Modules\SaasBilling`, `billing_transfer_requests`, `BillingTransferService`) that recorded an answer and moved nothing. P03-14 reworked it into the management handover, in its own module, `Modules\Handovers` (it is about who manages buildings, not GoProperty's subscription; it reads the bill through SaasBilling's `Contracts\SubscriptionQuotes`). The payer switch first planned for P06-06 is dropped: `subscriptions.payer_organization_id` stays only for platform-covered organizations (§ 2). `billing_transfer_requests` is dropped (an accepted transfer had moved nothing; the audit log keeps every offer and answer), and its pages (`/o/{slug}/billing-transfers…`) redirect to the handovers page.

- **Tables:** `management_handovers`, `management_handover_buildings`, `management_handover_tenants` ([DATABASE_SCHEMA.md § 1](DATABASE_SCHEMA.md)); the link is a Core invitation of kind `org_billing_transfer` (name kept) with `management_handover_id`.
- **One writer:** `Handovers\HandoverService` (`offer`, `accept`, `decline`, `cancel`, `expire`, `giveNotice`, `withdrawNotice`, `end`): locks the row in the offering organization's context, refuses any move § 7.2 does not allow with nothing changed (`HandoverRefused`), is audited (log `handovers`, the handover as subject; the move itself as one entry in each organization, that organization as subject, with the records and counts), and tells both sides once (`HandoverOffered` with the link to the receiving owners, `HandoverChanged` to everyone else; mail and in-app; an owner of both organizations hears once). The hourly jobs `handovers-expire` (offers after 14 days) and `handovers-end` (notice ran out) run as the system.
- **Who:** `handovers.manage`, `org-owner` only, blocked while staff impersonate, not kept while suspended (`ManagementHandoverPolicy`). A landlord's organization (`property_owner` or `hybrid`) offers its own active buildings to an organization of type `agency` or `hybrid`, found by web address or an owner's email (`HandoverTargets`, 10 attempts an hour). An agency (or `hybrid`) returns buildings it holds without a handover to a `property_owner` or `hybrid` organization (`offerReturn`, policy ability `offerReturn`; P04-04, below).
- **The offer:** the building picker lists the organization's active buildings in no open handover (`OfferableBuildings`; a pending offer past its 14 days no longer holds its buildings); rent collection is "the agency collects" only (§ 7.5: no per-building collection account exists yet); notice 0–180 days, default 30.
- **The accept screen:** the bill before and after the units the buildings add (every unit not archived, read in the landlord's organization through `UnitDirectory`), "from your next billing day" (the 1st of next month, § 3), a cheaper plan when there is one; accepting passes the amount after and is refused if it changed.
- **The move:** `App\Tenancy\Moves\OrganizationMoves::run()` calls every module's `MovesBuildingRecords` in order inside the acceptance's (or the end's) transaction: Properties 10 (buildings, archived units too, slug and `external_ref` clashes, agent assignments dropped, the landlord's owner profile matched or created and linked with `OwnerLinks::link()`, or on the return the old owner restored and `OwnerLinks::unlink()`), Tenants 20 (the tenants of the moved live leases: the tenant with the same phone there, else a copy with the portal link; the original stays with its history), Leasing 30 (draft and active leases with their deposit entries and `next_billing_date`; a contract number taken there is cleared on the moved lease), Billing 40 (utility settings re-pointed to the receiving organization's type of the same code or name, made when missing; every reading, billed ones keeping `bill_id`), Tickets 50 (P04-05: each ticket that is not closed becomes a new numbered ticket there with its public thread and files copied, the old one closed with "Building moved to …"; ARCHITECTURE.md § 6.6), media 90 (each moved record's files copied to the new organization's folder, the old folder removed after the commit, the copy removed if the move fails). Records change organization only through `BuildingMove::reassign()`; any failure rolls everything back.
- **Tenant credit (P03 decision 11):** `management_handover_tenants` lists each moving tenant's credit and unpaid bills (refreshed at offer and at acceptance); the landlord chooses "pass to the agency" (default) or "refund the tenant" on the handover's page and may change it until settled; a refund is recorded by the landlord (a `refund` entry closes the credit); passed-on credit is confirmed by the agency once the money arrived (one transaction: `handed_over` closes it on the landlord's books, `brought_over` opens it on the agency's tenant, which the next bill takes). Until then it stays on the landlord's books, shown to both. Through Payments' `Contracts\HandoverCredits` (`Handovers\HandoverCredits`); owners only, blocked while staff impersonate, audited, both sides told.
- **Console:** Customers → Handovers lists pending and ending handovers (`customers.view`, read-only), with the offering and receiving organizations ("Offered by" / "Offered to") and a pending return marked as such.

**The one-way return, as built (P04-04).** `management_handovers.kind` is `management` (every earlier row) or `return` (`Enums\HandoverKind`); a return row has the agency as `organization_id`, the landlord's organization as `to_organization_id` and `landlord_organization_id`, `rent_collected_by` null (§ 7.5 does not apply) and `notice_days` 0.
- **Offer** (`HandoverService::offerReturn`, the handovers page's "Return buildings to their landlord"): the agency's active buildings in no open handover (the same picker and lock as an offer: buildings it manages under an active handover, or offered in a pending one, are refused); the target found by `HandoverTargets::findLandlord()` (web address or an owner's email; `property_owner` or `hybrid`, not suspended). The safeguard of § 7.1 (`returnOwner()`): each building has an owner record linked to an account (else "invite them to the owner portal first"), all the same account (else "one owner's buildings at a time"), and that account is an `org-owner` and member of the target (else refused). Checked again on acceptance.
- **Accept** (the same accept screen, worded for a return: buildings, units, the bill change from `SubscriptionQuotes` — a `property_owner` organization is platform-covered, so it shows what the quote gives; no rent collection or notice): one transaction, `MoveDirection::OneWayReturn` through the same movers; the handover ends `returned`, final. Properties gives each building the landlord's owner record in their own organization (linked to their account, else by email, else a new company record from the organization's details; **not linked** with `OwnerLinks::link()`, as the landlord runs that organization) and unlinks the buildings' owner records in the agency with `OwnerLinks::unlink()` unless they still own buildings there. History stays with the agency. Notice, withdrawal of notice and the hourly end are refused on a returned handover (`HandoverStatus::Returned` moves nowhere).
- **Tenant credit:** `HandoverCredits` treats the offering organization as the holder (the landlord at a management, the agency at a return) and the receiving one as the confirmer; `pass_to_agency` means "pass to the receiving organization" (shown as "Pass to the landlord" on a return).
- **Tenant records** (§ 7.4): `App\Tenancy\Moves\MovedTenants` (Core contract, implemented by `Handovers\MovedTenantRecords` from the offering organization's handover tenant lines of `active`, `ending` and `returned` handovers; only the other organization's name is read) tells the Tenants module where a record's tenant moved and when (acceptance). `Modules\Tenants\Profiles\MovedTenantArchive` refuses archiving while unpaid bills (Billing's `BillLedger::openBills`) or credit (Payments' `HandoverCredits::balances`) remain, and archives the record itself (audited `tenant.archived`, reason `moved_and_settled`, no causer) once both are clear and no lease holds a unit: at once on `PaymentConfirmed`, `BillVoided` and Core's `MovedTenantsChanged` (sent after the commit of an acceptance and of each credit settlement; `Listeners\ArchiveSettledMovedTenants`, after commit), and by the daily sweep `tenants-archive-moved` (03:50, `default` queue) as the backstop. The tenant page shows "Moved to {organization} on {date}".
- **Return matching:** `Handovers\TenantOrigins` pairs records from earlier accepted handovers between the two organizations (both ways, newest wins) and passes them to the move (`BuildingMove::knownTenantIn()`); the Tenants mover uses that original record first (restored if it had archived itself), then the phone, then a copy, so a tenant whose phone changed lands on their original record, at the end of a management and on a one-way return.
- **Portal links:** `Tenants\Handover\LeftPortalLinksRevoker` (mover order 35, after Leasing) cancels the pending `tenant` invitations and `sign_in_link`s of the moved tenants' records left behind, unless they still hold a lease there; audited `tenant.portal_invitation_revoked` / `tenant.sign_in_link_revoked` with `reason` `moved` and the handover.

### 7.9 Owner statements (P08-05)

When the agency collects (§ 7.5), or simply manages an owner's buildings, it owes the owner what each month's rent leaves after its commission. GoProperty works that out and records whether the agency paid it; it never moves the money (AGENT.md § 3 rule 8). Product owner 2026-10-28 (`docs/plans/P02-P03-subtasks.md`); the defaults below are to be confirmed.

- **Who has statements:** every owner profile of an organization, for the buildings linked to it; the owner sees them in `/owner` when linked as `property-owner` there (P03-15). A landlord managing their own organization is its `org-owner` and needs none.
- **Terms per owner** (`owner_statement_terms`, set by the agency's owners and administrators): the **commission**, a percentage of rent collected (basis points; until set the statement says "Commission not set" and takes none); **utilities pass to the owner** (default off: utilities collected are shown but not counted). (The "share tenant contact details" term was removed in P03-16, product owner 2026-10-29: owners always see their tenants' details, and the statement shows each tenant's phone and email wherever it names the tenant — as the bill was issued, the renewals as the tenant is now; a month settled before then keeps what it recorded.)
- **Net due for a month** = rent collected − commission + (utilities collected, when they pass to the owner) − expenses charged to the owner. Money in kobo; commission rounded to the kobo per building (halves away from zero); months are Lagos months. Any figure may be negative.
- **What counts, and when:** money collected for the owner's buildings' bills, by when it was recorded, never by a date someone typed: a payment's share of a bill in the month the payment was **confirmed**; tenant credit in the month it was **used** for a bill (credit is the tenant's money held for later: an overpayment, or credit refunded or handed over, is never counted, so refunds are netted); a **voided receipt**, negative, in the month it was voided (the payment itself does not change, P03-10b). Utility bills are utilities; rent, opening-balance and other bills are rent. Bills issued in the month are listed with their status (void ones as VOID; a bill with money on it cannot be voided). Expenses count when the agency marks them **charged to owner** (`expenses.charged_to_owner`, default off), in the month they were paid.
- **Settled / Unsettled:** the agency's owner or administrator marks an ended month **Settled** with the date the owner was paid and a reference, or back to **Unsettled** with a reason; both audited; never while GoProperty staff impersonate; never by the owner. Settling keeps the month's statement as it is then (`owner_statement_settlements.figures`): the settled month never changes, and anything confirmed or voided later counts in the month it happens. An expense is not charged into a settled month, nor a charged one there changed, until the month is unsettled.
- **Statement PDF** (BRANDING_DOCUMENTS.md § 4): a settled month's is kept once, in the branding of the day it was settled; an unsettled month's is rendered on each download.
- **Not here:** payout approvals and payout workflows (post-launch module 3 "Owner payouts", IMPLEMENTATION_PLAN.md § 7).

How it is built: [ARCHITECTURE.md § 5 "Owner statements (P08-05)"](ARCHITECTURE.md), [DATABASE_SCHEMA.md § 4](DATABASE_SCHEMA.md).

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
9. ~~SMS provider~~ — GoText, one GoProperty account; GoProperty pays GoText and agencies pay through SMS credits (§ 1.2); SMS templates are GoProperty's and never promotional ([docs/plans/sms-provider-2026-10-29.md](docs/plans/sms-provider-2026-10-29.md), 2026-10-29).
