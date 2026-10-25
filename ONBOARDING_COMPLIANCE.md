# GoProperty — Onboarding, Domains, Payment Collection & Transaction Tiers

Companion documents: [ARCHITECTURE.md](ARCHITECTURE.md) · [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md) · [BILLING_PAYMENTS.md](BILLING_PAYMENTS.md) · [PLATFORM_CONSOLE.md](PLATFORM_CONSOLE.md) · [UX_DESIGN.md](UX_DESIGN.md)

This document covers how a customer gets onto GoProperty: domains and login paths, registration, the subscription phase, how agencies collect rent, and the **Transaction Tiers** that decide how much money an agency may manage through the platform.

## 0. Core Principle — GoProperty Never Touches Tenant Money

GoProperty is **software, not a payment intermediary**. Rent, service charges and utility payments go **directly from the tenant to the agency/owner** — into the agency's own bank account or the agency's own Paystack account. GoProperty records, matches, receipts and reports those payments.

Why:

- Real estate agents and developers are designated non-financial businesses under the Money Laundering (Prevention and Prohibition) Act 2022, supervised by SCUML (EFCC). That AML duty belongs to the agency. GoProperty should help agencies comply, not take on the duty itself.
- Collecting other businesses' money and splitting it could make GoProperty look like a payment aggregator to the CBN, with licensing and AML obligations.
- Payment gateways apply anti-money-laundering checks per merchant and limit high-value real-estate payments. Each agency must be its own merchant.

The only money GoProperty itself receives is its **own subscription fee** (see [BILLING_PAYMENTS.md](BILLING_PAYMENTS.md)).

> Before launch: obtain a short written opinion from a Nigerian fintech/regulatory lawyer confirming that this "no funds flow" model needs no CBN licence, and confirm the agency-owned-Paystack setup with Paystack.

## 1. Domains & Login Paths (decision)

**Decision: one domain, path-based everything. No subdomain per agency.** This works on shared hosting (one domain, one SSL certificate, one document root) and can move to subdomains later by changing configuration only.

### Launch layout (single domain)

| Path | Purpose | Who |
| --- | --- | --- |
| `goproperty.ng/` | Marketing site, pricing, signup, legal pages | Public |
| `goproperty.ng/login` | One login for everyone except GoProperty staff | Agency staff, property owners, tenants |
| `goproperty.ng/o/{org-slug}/…` | Agency/owner workspace (dashboard, properties, leases, bills, payments) | Org members |
| `goproperty.ng/portal/…` | Tenant portal, across every agency the tenant rents from | Tenants |
| `goproperty.ng/owner/…` | Owner portal (read-only statements, properties), across agencies | Property owners invited by agencies |
| `goproperty.ng/pay/{reference}` · `/verify/{code}` | Branded pay page (no login) · document verification | Tenants, anyone holding a document |
| `goproperty.ng/{ADMIN_PATH}` | Platform console (Filament) | GoProperty staff only |

### The staff console path

- The path comes from configuration (`ADMIN_PATH` in `.env`), default `gp-control` with a random suffix added by the installer, e.g. `/gp-control-k7m2qx` ([PLATFORM_CONSOLE.md § 10](PLATFORM_CONSOLE.md)). It is **never** `/admin`, `/administrator` or `/dashboard`, and is not linked from the public site or `robots.txt`.
- A hidden path only cuts automated bot traffic; it is **not** the security control. The real controls are:
  - a separate `staff` guard and `platform_staff` table;
  - a **separate session cookie** (`gp_staff_session`, `Path=/{ADMIN_PATH}`, `Secure`, `HttpOnly`, `SameSite=Strict`), set by panel middleware before the session starts, so customer and staff sessions never mix even on one domain;
  - mandatory 2FA, strict login rate limits, lockout and Turnstile;
  - optional IP allowlist (office/VPN IPs). Non-allowlisted requests get a plain 404;
  - 30-minute idle timeout and full audit.
- `/admin` and other common guesses return a normal 404 and are logged as probes.

### Moving to subdomains later (no code changes)

| Setting | Launch (shared hosting) | Later (VPS) |
| --- | --- | --- |
| `APP_URL` | `https://goproperty.ng` | `https://app.goproperty.ng` |
| `ADMIN_PATH` / `ADMIN_DOMAIN` | `gp-control-k7m2qx` / empty | empty / `ops.goproperty.ng` (also a non-obvious name) |

Filament panels support both `->path()` and `->domain()`. Old URLs redirect permanently to the new location, so links in already-sent emails keep working.

### Why not a subdomain (or separate login page) per agency?

1. **People belong to several organizations.** A tenant may rent from two agencies; a staff member may work for two. One login with an org switcher is simpler than separate logins per subdomain.
2. **Isolation comes from the database scope, not the URL.** The `organization_id` scope and policies protect data either way. A subdomain adds no security.
3. **Phishing risk.** Self-chosen subdomains such as `gtbank-rent.goproperty.ng` look official. Paths are less convincing to fake.
4. **Shared hosting:** wildcard subdomains and wildcard SSL are often unavailable or paid extras on shared plans.

### Why the org slug is in the path

- Links in emails and SMS (`/o/adeyemi-realty/bills/BL-2026-0042`) always open the right organization, whatever org the user last used.
- Multiple browser tabs can show different organizations at once.
- Middleware resolves `{org-slug}`, checks the user is a member, then sets `OrganizationContext`. A non-member gets a 404, not a 403, so organizations can't be discovered by guessing.
- Slugs can be changed once; old slugs redirect.

### After login

| User is… | Lands on |
| --- | --- |
| Member of one organization | `/o/{slug}/today` (agent "Today" inbox) |
| Member of several | Organization picker (remembers last choice) |
| Tenant only | `/portal` |
| Owner only | `/owner` |
| Mixed (e.g. agent who also rents) | Last used, with a switcher listing orgs + "My rentals" + "My properties" |

**Later (Scale plan add-on, VPS only):** a branded tenant-portal entry at `{slug}.goproperty.ng` or the agency's own domain (`tenants.adeyemirealty.com`), for white-labelling only. It redirects into the same single app. Deferred until customers ask for it.

### Staff accounts are separate

GoProperty staff use a **separate guard and table** (`platform_staff`), not the customer `users` table. A staff account can never also be a tenant or agency member. Staff reach customer accounts only through audited impersonation (see [PLATFORM_CONSOLE.md § 6](PLATFORM_CONSOLE.md)).

## 2. Registration Flow (agency / property owner)

```mermaid
flowchart TD
    A[goproperty.ng → Start free trial] --> B[Create account: name, email, phone, password]
    B --> C[Verify email link + phone OTP]
    C --> D[Create organization: type, name, slug, state/LGA]
    D --> E[Choose Transaction Tier 1 / 2 / 3]
    E -->|Tier 1| F[Tier 1 active immediately]
    E -->|Tier 2 or 3| G[Upload documents: CAC, SCUML, director ID, …]
    G --> H[Operates at Tier 1 limits while compliance reviews]
    H --> I{Compliance officer review}
    I -->|Approved| J[Tier 2/3 active]
    I -->|Rejected / more info| G
    F --> K[Choose subscription plan → 14-day trial starts]
    J --> K
    H --> K
    K --> L[Set up collections: bank account(s) and/or own Paystack]
    L --> M[Import data from Excel or add first property]
    M --> N[First bill sent 🎉]
```

Step details:

1. **Account:** name, email, phone, password (checked against breached-password lists). Email verified by link; phone verified by OTP.
2. **Organization:** type (`agency`, `property_owner`, `hybrid`), display name, slug (suggested from name), state/LGA, logo (optional). CAC number optional at Tier 1.
3. **Transaction Tier:** the customer states expected yearly collections and picks a tier (§ 4). Tier 1 is instant. Tier 2/3 require documents; until approved the org works at Tier 1 limits, so signup is never blocked.
4. **Plan & trial:** choose a subscription plan (priced per unit, [BILLING_PAYMENTS.md § 1](BILLING_PAYMENTS.md)). 14-day trial, no card required. Subscription status `trialing`.
5. **Collections setup** (§ 4): add one or more bank accounts (name checked against the bank via Paystack account-name lookup) and/or connect the agency's own Paystack account.
6. **Data:** Excel/CSV import with a dry run first — owners, properties, units, tenants, ongoing leases ("paid until"), opening balances, utilities ([DATA_IMPORT.md](DATA_IMPORT.md)) — or manual entry. Target: first bill sent within 30 minutes of signup.
   - **Branding:** logo, colours and letterhead, with a live preview of the invoice and receipt ([BRANDING_DOCUMENTS.md](BRANDING_DOCUMENTS.md)).
7. A progress checklist on the dashboard tracks steps 3–6 (including branding) until complete.

**Tenants** never self-register. The agency invites them by SMS/WhatsApp/email link; the tenant verifies their phone by OTP and lands in `/portal`.
**Property owners** managed by an agency are invited into the owner portal. Owners who manage their own buildings sign up as an organization of type `property_owner`.

## 3. Subscription Phase (GoProperty's own billing)

| Stage | What happens |
| --- | --- |
| Trial (14 days) | Full features of the chosen plan. Banner with days left; emails at T-7, T-3, T-1 |
| Trial ends | First platform invoice issued (units counted that day). Pay by **Paystack** (card) or **manual bank transfer** with proof upload, confirmed by GoProperty finance staff |
| Active | Monthly or annual invoice by unit count. Card on file auto-charged; otherwise bank-transfer instructions |
| Unpaid | `past_due` → `grace` → `suspended` ladder ([BILLING_PAYMENTS.md § 2, § 6](BILLING_PAYMENTS.md)). Suspension blocks the agency's write actions. The tenant portal stays read-only and still accepts payment proofs, so tenants are not punished |
| Pay | Any confirmed payment reactivates immediately |

The **subscription plan** (what the agency pays GoProperty, based on units) and the **Transaction Tier** (how much money the agency may manage, based on verification) are **independent**. A small agency with a few luxury units may need Tier 3 on a Starter plan.

## 4. Payment Collection (bank transfer + agency-owned Paystack)

Each organization enables one or both channels under **Settings → Payment collection**.

### 4.1 Bank transfer (always available)

- The agency adds one or more bank accounts. The account name is checked via Paystack's account-name lookup before saving.
- Every bill gets a short unique **payment reference** (e.g. `GP-7KQ2M`), shown on the invoice, in the SMS/WhatsApp reminder, and on the tenant's pay page: "Put this in your transfer narration."
- **Bank statement import:** the agency uploads its statement (CSV/XLSX at launch, PDF later). Column mapping is saved per bank. GoProperty matches each credit:
  - reference found in the narration → **high-confidence** match;
  - same amount + payer name similar to the tenant → **suggested** match;
  - otherwise unmatched, for manual allocation.
  The agent confirms matches in one tap (bulk confirm for high-confidence ones). One credit can pay several bills, and any excess goes to the tenant's credit balance. Duplicate statement lines are ignored using a hash of date + amount + narration + balance.
- **Proof upload** stays as the tenant-side fallback: structured fields (amount, date, sender name, bank) plus image. Re-used screenshots are flagged by image hash.
- Works for **any amount**, including multi-million annual rent, because the money moves bank-to-bank with no gateway in between.

### 4.2 Agency's own Paystack account (optional)

- The agency creates and verifies **its own** Paystack business account. Paystack runs KYC/AML on that agency directly, and money settles to the agency's bank account.
- The agency pastes its Paystack keys into GoProperty and sets GoProperty's per-organization webhook URL (`/webhooks/paystack/{org-ulid}`) in its Paystack dashboard. Keys are stored encrypted, shown masked, and can be rotated. Only org-owners can change them; impersonating staff never can.
- Tenant pays by card / Pay with Transfer / USSD on the bill page → webhook → GoProperty verifies the transaction with Paystack server-side → bill marked paid → receipt issued automatically.
- **Online-payment cap:** the org sets a maximum bill amount for online payment (default from Paystack's limits for that account). Bills above the cap show bank-transfer details only. This keeps large rent off the gateway.
- Caveat: a Paystack account has one webhook URL. If the agency already uses it for something else, they need a separate Paystack account for GoProperty, or GoProperty falls back to periodic verification of pending references.

### 4.3 Cash / POS

Recorded by an agent with a mandatory note. The receipt is sent straight to the tenant by SMS/WhatsApp so the tenant can confirm it. Voiding a receipt needs a second approver. Cash at or above the legal thresholds is flagged (§ 5.4).

## 5. Transaction Tiers

### 5.1 What a tier measures

- **Annual collection volume:** total confirmed tenant payments across all channels (bank match, Paystack, proof, cash) over a rolling 12 months.
- **Single-payment size:** the largest single confirmed payment.
- **Forecast:** expected collections over the next 12 months from active leases and utility settings. GoProperty knows the rent schedule, so it can warn **before** a limit is crossed.

### 5.2 Tier table (launch values — editable)

| | Tier 1 — Standard | Tier 2 — Business | Tier 3 — Enterprise |
| --- | --- | --- | --- |
| Annual collection volume (rolling 12 months) | up to **₦50 million** | up to **₦500 million** | above ₦500 million — no cap, enhanced monitoring |
| Largest single payment | up to **₦5 million** | up to **₦50 million** | no cap; any single payment above ₦100 million is reviewed |
| Who it suits | Individual agents, small landlords | Registered agencies | Large agencies, developers, estates |
| Activation | Instant | Compliance review (target 2 business days) | Compliance review + call (target 5 business days) |
| Re-verification | — | Yearly | Yearly + on major changes |

**Why these numbers**

- **₦5m single payment (Tier 1)** matches the MLPPA 2022 cash threshold for individuals and covers a typical annual rent for a mid-market 2–3 bedroom flat in Lagos or Abuja.
- **₦50m a year (Tier 1)** is about 25–35 mid-market units at ₦1.5–2m a year: a realistic ceiling for an individual agent or small landlord without a registered business.
- **₦500m a year (Tier 2)** is about 200–300 mid-market units, or around 100 premium units. That covers most registered agencies.
- **₦50m single payment (Tier 2)** covers premium residential and most commercial lettings (offices, shops) paid annually.
- **Tier 3** is for large portfolios, estates, developers and luxury/commercial lettings, where single annual rents of ₦20m–₦100m+ are normal. There is no hard cap, but compliance looks at it more closely.

**Editing the limits**

- Limits live in the `transaction_tiers` table and are edited in the console (**Compliance → Tier settings**). A `compliance-officer` proposes a change, a `super-admin` approves it (two-person rule). Each change has an effective date and is versioned and audited.
- **Raising** a limit applies from its effective date.
- **Lowering** a limit never restricts anyone straight away: affected organizations are notified and get 60 days to apply for an upgrade.
- **Per-organization override:** compliance can set custom limits for one organization (e.g. a Tier 2 agency with one large commercial client), with a reason and an expiry date.
- Required documents per tier are also editable from the same screen.

### 5.3 Requirements per tier

| Requirement | T1 | T2 | T3 |
| --- | :-: | :-: | :-: |
| Verified email + phone of org-owner | ✓ | ✓ | ✓ |
| Org-owner government ID (NIN slip, passport, driver's licence or voter's card) | ✓ | ✓ | ✓ |
| Bank account name matches the person or business | ✓ | ✓ | ✓ |
| CAC registration (certificate + status report; business name or company) | — | ✓ | ✓ |
| **SCUML registration certificate** | — | ✓ | ✓ |
| Business bank account in the CAC-registered name | — | ✓ | ✓ |
| Proof of business address (utility bill ≤ 3 months) | — | ✓ | ✓ |
| Directors' IDs | — | ✓ | ✓ |
| Beneficial owners (people with significant control) | — | — | ✓ |
| Named compliance contact + short AML policy statement | — | — | ✓ |
| State real-estate regulator registration where the state requires it (e.g. Lagos LASRERA) | — | if applicable | ✓ |
| Professional body membership (ESVARBON / NIESV), optional but displayed as a trust badge | — | optional | optional |

Notes:

- SCUML registration needs a CAC-registered business, so an individual agent without CAC stays at Tier 1.
- Documents go to private storage, are encrypted, are visible only to the `compliance-officer` and `super-admin` staff roles, and every view is logged.
- Identity/CAC checks are manual at launch. An identity-verification provider (e.g. Youverify, Dojah, Prembly, Smile ID) can be added later to automate them.
- Document expiry dates are tracked (e.g. proof of address, ID). Reminders go out 30 days before expiry.

### 5.4 Monitoring & flags

A daily job, `compliance:evaluate-tiers`, plus checks on each payment confirmation, produce **compliance flags**:

| Flag | Trigger | Who is told |
| --- | --- | --- |
| `volume_80` | Rolling volume ≥ 80% of tier limit | Org-owner (in-app + email): "You're approaching your tier limit — upgrade" |
| `forecast_over` | Forecast for next 12 months exceeds tier limit | Org-owner: early upgrade prompt |
| `volume_100` | Rolling volume ≥ 100% of tier limit | Org-owner + compliance queue in console; upgrade required |
| `single_over` | One payment above the tier's single-payment limit | Org-owner + compliance queue |
| `cash_threshold` | Cash payment ≥ ₦5m (individual payer) / ₦10m (corporate payer), the MLPPA 2022 thresholds | Org-owner (it's *their* reporting duty) + compliance queue |
| `possible_structuring` | Several cash/transfer payments from the same tenant just below a threshold within a short window | Compliance queue (not shown to the tenant) |
| `spike` | Monthly volume > 3× trailing 6-month average | Compliance queue |
| `document_expiring` / `document_expired` | Tier document near/after expiry | Org-owner |

### 5.5 What happens when an agency goes over its tier

GoProperty can't stop money moving between a tenant and an agency's bank, so enforcement limits **features**, never tenants' receipts:

1. **≥ 80%:** banner + email with an "Upgrade tier" button that opens the document upload form.
2. **≥ 100%:** "Upgrade required" banner. 30-day grace period. The org is listed in the console compliance queue.
3. **Grace ends without an upgrade application:** the org becomes **restricted**. No new leases, properties or units; the online-payment channel is paused. Existing bills, payment recording, confirmations and receipts keep working, and the tenant portal is unaffected.
4. Compliance staff can extend the grace period with a reason (audited).
5. While an upgrade application is under review, the org is never restricted.

Platform staff can also manually restrict or suspend an organization for compliance reasons (audited, reason required, org-owner notified).

### 5.6 What GoProperty is *not*

GoProperty does not file suspicious-transaction or cash-transaction reports for agencies. The agency is the reporting entity. GoProperty provides the flags, records and exports that help the agency meet its own duty, and can refuse or end service to an organization that is a compliance risk. This is stated in the Terms of Service.
