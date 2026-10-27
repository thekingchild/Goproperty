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
  - a **separate session cookie** (`gp_staff_session`, `Secure`, `HttpOnly`, `SameSite=Strict`, 30-minute idle timeout), chosen by global middleware (`UseConsoleSession`) before any session starts, so customer and staff sessions never mix even on one domain. Console pages, and Livewire updates sent from them, read only this cookie; customer pages never read it. It uses `Path=/` rather than the console path, because Livewire has one update endpoint at the site root (`/livewire-…/update`) that console pages must reach (decided 2026-10-26, P00-08);
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
    B --> C[Verify email by link]
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

1. **Account:** name, email, phone, password (checked against breached-password lists). Email verified by link. The phone number is checked for a valid Nigerian mobile format and stored as +234…, but **not verified by SMS code** — GoProperty sends no SMS during signup or sign-in (each SMS costs money). Identity for higher limits is checked through Transaction Tier documents (§ 5), not the phone.
2. **Organization:** type (`agency`, `property_owner`, `hybrid`), display name, slug (suggested from name), state/LGA, logo (optional). CAC number optional at Tier 1.
3. **Transaction Tier:** the customer states expected yearly collections and picks a tier (§ 4). Tier 1 is instant. Tier 2/3 require documents; until approved the org works at Tier 1 limits, so signup is never blocked.
4. **Plan & trial:** choose a subscription plan (priced per unit, [BILLING_PAYMENTS.md § 1](BILLING_PAYMENTS.md)). 14-day trial, no card required. Subscription status `trialing`.
5. **Collections setup** (§ 4): add one or more bank accounts (name checked against the bank via Paystack account-name lookup) and/or connect the agency's own Paystack account.
6. **Data:** Excel/CSV import with a dry run first — owners, properties, units, tenants, ongoing leases ("paid until"), opening balances, utilities ([DATA_IMPORT.md](DATA_IMPORT.md)) — or manual entry. Target: first bill sent within 30 minutes of signup.
   - **Branding:** logo, colours and letterhead, with a live preview of the invoice and receipt ([BRANDING_DOCUMENTS.md](BRANDING_DOCUMENTS.md)).
7. A progress checklist on the dashboard tracks steps 3–6 (including branding) until complete.

**How the wizard behaves (built in P01-02):**

- **Pages.** Account at `/register` and "check your email" at `/register/check-email` (the sign-in pages' split layout); organization at `/signup/organization`; then Tier, plan, collections and data inside the new workspace at `/o/{slug}/setup` (Livewire). The brand panel lists the seven steps; phones show "Step 4 of 7".
- **No account enumeration.** Every valid account form ends on the same "check your email" page and nobody is signed in. A new address gets the verification link; an address that already has an account gets an email saying so (sign in / reset password), and nothing on that account changes. A phone number that already belongs to another account is not stored, and the organization step asks for a number again. Field errors are about the form only.
- **Verification link.** Valid 60 minutes. Opened in the browser that signed up, it confirms the address and signs the new account in, so signup continues straight to the organization step. Opened anywhere else, the person signs in first and returns to the link. Never signs in an account with two-factor authentication.
- **Bot check and limits.** Turnstile follows Sign-in security ([PLATFORM_CONSOLE.md § 9.6](PLATFORM_CONSOLE.md)): `always` on every signup; `after_failures` from the 4th signup from one IP address in 15 minutes. The account form is limited to 5 a minute and 30 an hour per IP, and 6 an hour per email address. Breached passwords are refused in production.
- **Resume.** The step is saved on the server (`organization_onboardings.step`), not in the session. Signing in again goes to the confirm-email page, the organization step (an account with no organization at all), or `/o/{slug}`, which sends whoever can carry on to the saved step. "Back" returns to any earlier step, including the organization details (the slug is kept).
- **Organization.** The signed-up user becomes its first `org-owner`. One organization in signup at a time per user. Owners who manage their own buildings (type `property_owner`) get `platform_covered` set; changing the type during signup updates it.
- **Tier.** The customer states expected yearly collections; the wizard suggests the lowest tier that covers them (launch values from `config/tiers.php` until the `transaction_tiers` table exists). Tier 1 is active at once. A Tier 2/3 choice is recorded as requested (`organization_onboardings.requested_tier`), its checklist item stays open and links to the application page (§ 5.3) until compliance approves, and `organizations.transaction_tier` stays `1`.
- **Plan and trial.** Plans from the plan catalogue. The choice is stored in `organizations.signup_plan_key` and `trial_ends_at` is set 14 days ahead the first time a plan is chosen, until SaaS billing creates the subscription (P06-01).
- **Collections and data.** "Set up later" only, until bank accounts (P01-05 / P03-07), agency Paystack (P03-08) and import or properties (P02) exist; their checklist items stay pending. Finishing the last step makes the organization `active` and opens the dashboard.
- **Checklist.** One row per item in `onboarding_checklist_items`. Features mark their item done through `App\Onboarding\OnboardingChecklist::complete()`; an item links to its page once that page's route exists (`ChecklistItem::routeName()`). Only members who may edit the organization see it, and it disappears when every item is done.

**Team members** (owners, administrators, agents) join by invitation from Settings → Members (built in P01-04, [ARCHITECTURE.md § 5–6.1](ARCHITECTURE.md)): an email link, single use, valid 14 days, that can be sent again (a new link; the old one stops working) or revoked. The link is bound to the invited address. Someone new sets their name, an optional mobile number and a password (the signup rules; the address counts as confirmed), and lands in the workspace; someone with an account signs in first and then joins. Inviting never reveals whether an address already has an account. A suspended organization takes no new members, and nothing about members can be changed while GoProperty staff impersonate a user.

**Tenants** never self-register. The agency invites them, and the tenant opens the invitation link, sets a password (and can add a passkey), then lands in `/portal`. No SMS codes are involved. The agency picks how the link reaches the tenant:

| Channel | Cost to GoProperty | Phone verified? |
| --- | --- | --- |
| **Email** | None | No (email is verified) |
| **Agency's WhatsApp Business** (if connected, [ARCHITECTURE.md § 6.7](ARCHITECTURE.md)) | None — Meta bills the agency | **Yes**, when the tenant opens the link sent to that number |
| **Share it yourself** — "Copy link" or "Show QR code" (scanned in person at signing or handover) | None | No, unless the agency ticks "Phone number confirmed in person" (records who confirmed it) |

- Invitation links are single-use, expire after 14 days, and can be re-sent or revoked from the tenant record.
- A tenant without email signs in with **phone number + password**. To reset a forgotten password, the link goes by the agency's WhatsApp if connected; otherwise the tenant asks the agency, which creates a one-time sign-in link from the tenant record (copy or QR, audited, expires in 24 hours). After first sign-in, phone-only tenants are invited (not forced) to add an email for self-service resets.
- SMS bill reminders still go out as before (they count against the agency's plan SMS credits), but only to numbers that are verified or agency-confirmed (see [ARCHITECTURE.md § 6.7](ARCHITECTURE.md)).

**How tenant invitations behave (built in P03-01b, [ARCHITECTURE.md § 6.1](ARCHITECTURE.md)):**

- **Where.** The tenant's record in the workspace, section "Portal access" (owners, administrators and agents; `tenants.portal_access`). Invite by email (needs the tenant's address), by the agency's WhatsApp (offered only once WhatsApp can send: until P05-06 a stand-in that sends nothing and is never offered in production), or "Show link and QR code" (shown once; copy or print it). One invitation per tenant at a time; "Send again" (any channel) makes a new link and kills the old one; "Revoke" kills it.
- **The link** (`/portal/invitations/{token}`) shows the agency, who invited and the address or masked number it is for. Someone new chooses a password (name prefilled) and is signed in, then may add a passkey (optional) before the portal. Used, revoked and expired links all show the same "no longer works" page.
- **No account takeover.** An account that already uses the invited email address or phone number must sign in first and then accept; the link never signs anyone in to an existing account. Someone signed in with another account is refused, unless that account has no email (emailed link) or no phone number (WhatsApp or shared link) yet and nobody else uses it: it then gains it.
- **What the account gets.** Emailed link: the confirmed address, plus the tenant's number if no other account uses it. WhatsApp link: the number, verified. Shared link / QR: the number, not verified until the agency ticks "Phone number confirmed in person" (which records who). Phone-only tenants have no email on their account; adding one comes with the portal's profile page (P03-11a).
- **Tenants are not members.** Accepting links the tenant's record to the account; it never adds the person to the organization's workspace or gives them a role there. They sign in at `/login` (email or phone number + password, or a passkey) and land on `/portal`.
- **One-time sign-in link** (from the same section, `/portal/sign-in/{token}`): for a tenant whose account is phone-only, without two-factor authentication, not working in any organization's workspace and not a tenant of another organization; otherwise the record says why not (they reset their password by email, use a recovery code, or ask the account's owner). It works once within 24 hours, may set a new password, and a new link replaces the last. Shown as link / QR or sent by the agency's WhatsApp (which then verifies the number). Creating, using and revoking it are audited.
- **Limits.** Invitations: 5 sends per tenant a day, 30 per member and 50 per organization an hour; sign-in links: 5 per tenant a day, 20 per member and 30 per organization an hour; the link pages: 30 openings and 10 uses a minute per IP. None of this while GoProperty staff impersonate, or while the organization is suspended.
**Property owners** managed by an agency are invited into the owner portal. Owners who manage their own buildings sign up as an organization of type `property_owner`. If such an owner later hands buildings to an agency (management handover, [BILLING_PAYMENTS.md § 7](BILLING_PAYMENTS.md)), the buildings move into the agency's organization and the owner follows them in the owner portal; their own organization stays, so the buildings can come back.

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
- Every bill gets a short unique **payment reference** (e.g. `GP-7KQ2M9X`: `GP-` and 7 characters without I, L, O or U; P03-04b), shown on the invoice, in the SMS/WhatsApp reminder, and on the tenant's pay page: "Put this in your transfer narration."
- **Bank statement import:** the agency uploads its statement (CSV/XLSX at launch, PDF later). Column mapping is saved per bank. GoProperty matches each credit:
  - reference found in the narration → **high-confidence** match;
  - same amount + payer name similar to the tenant → **suggested** match;
  - otherwise unmatched, for manual allocation.
  The agent confirms matches in one tap (bulk confirm for high-confidence ones). One credit can pay several bills, and any excess goes to the tenant's credit balance. Duplicate statement lines are ignored using a hash of date + amount + narration + balance.
- **How the bank accounts are built (P01-05)** (`/o/{slug}/settings/collections`, `App\Banking\BankAccounts`):
  - **Who:** org-owners only, for seeing (`payment_settings.view`) and changing (`organization.payment_settings`). The bank account decides where tenants' money goes, the same stakes as the Paystack keys below, so administrators and agents cannot change it; impersonating staff can neither see nor change it. Read-only while the organization is suspended.
  - **Whose key does the lookup:** GoProperty's own Paystack secret (`PAYSTACK_SECRET_KEY`, `config/services.php`), through `App\Banking\BankAccountNameResolver` (Paystack driver: `GET /bank/resolve`). An account-name lookup moves no money, so this keeps § 0; the organization's own Paystack keys (§ 4.2) are optional and come later, while every organization needs its account checked.
  - **Outcomes:** the bank's name matches the name given (case, word order, punctuation and "Ltd"/"Limited" do not matter; a long name cut short by the bank still matches) → saved with the bank's spelling, *Name checked*; another name → **refused, nothing saved**, the page shows the bank's name and offers "Use this name"; unknown number → refused; no answer (no key configured, Paystack down) → saved as *Name not checked*, usable, with a "Check name" button for later. A later check that finds another name marks the account *Name does not match* and stops showing it to tenants until it is corrected.
  - **Storage and display:** account numbers are encrypted casts, shown masked (`GTBank ···6789`), never sent back to the browser, never in the audit log. Up to 10 accounts; the first usable one is the primary (printed on invoices), one can be made primary, deactivated, reactivated or removed (soft delete). Lookups are limited to 10 an hour per organization (each reveals an account holder's name). Every change is audited (log `payment_settings`). The first usable account ticks the setup checklist's collections item. P03-07 builds statement import and matching on these accounts.
- **Statement import as built (P03-07a)** (`/o/{slug}/statements`, `Modules\Collections`; [ARCHITECTURE.md § 5](ARCHITECTURE.md) "Bank statements", § 6.4 "How bank statement import is built"; matching is P03-07b):
  - **Who:** org-owners and org-admins (`statements.view`, `statements.import`); agents see no statement (the whole account's history, with no building to narrow by). Read-only while suspended; staff impersonating may look and export, never upload or download the file.
  - **Upload:** one of the organization's bank accounts (deactivated ones too, for late payments into them) and its statement as the bank exports it, CSV or Excel (`.xlsx`; `.xls` must be saved as `.xlsx`; PDF later), up to 10 MB and 20,000 transactions. The file is checked (a renamed script or a zip that is not an Excel workbook is refused) and kept as evidence. When the heading above the table names an account number, it must be the chosen account's. The page shows which column holds what — the account's saved mapping, or one worked out from the headers (Trans. Date, Remarks/Narration/Description, Debits/Credits or one Amount column, signed or with Dr/Cr, Balance, Reference) — and the first rows; the mapping and the date order (day first by default) are saved per bank account.
  - **Lines:** amounts in exact kobo (commas, ₦/NGN, brackets and CR/DR marks read; more than two decimals refused, never rounded); credits wait for matching; **debits are kept but never offered for matching**; titles, balances and totals are skipped; unreadable rows are listed with the reason. Duplicates are ignored with `line_hash` = SHA-256 of the bank account, date, direction, amount, normalised narration, running balance and bank reference (when the statement has them) and the line's occurrence among identical lines of the file — so re-uploading a statement, or an overlapping one, adds only new lines, and two identical credits of one day stay two. The report: transactions read, new lines (credits, debits), duplicates skipped, unreadable rows.
- **The pay page as built (P03-05c)** (`/pay/{reference}`, Billing's `PayPageController`; [BRANDING_DOCUMENTS.md § 5](BRANDING_DOCUMENTS.md) "How it is built (P03-05c)"): no sign-in; the reference (as typed: case, spaces, dashes, O/I/L ignored, redirected to its one address) finds the bill in its own organization; it shows the agency's branding as on the invoice, the bill, the amount still owed and the organization's **primary usable account now** (bank, account name, full number) with "Put {reference} in your transfer narration", plus any online payment option (P03-08). Never the tenant's name or contacts. A paid bill says it is paid and a void bill says not to pay it, both without bank details; with no usable account the page asks the payer to contact the agency. Unknown references are a plain 404; 30 pages a minute per IP.
- **Proof upload** stays as the tenant-side fallback: structured fields (amount, date, sender name, bank) plus image. Re-used screenshots are flagged by image hash. As built (P03-09; [ARCHITECTURE.md § 6.4](ARCHITECTURE.md) "How proofs of payment and cash receipts are built"): the tenant submits a proof for one of their own bills from the portal (only the tenant the bill was issued to, through their portal account; P03-11b builds the page on `Modules\Payments\Contracts\PaymentProofs`), or a member uploads one the tenant sent them (Payments → Upload a proof). Amount, date paid, sender's name, bank, optional narration and note, and the file (JPEG, PNG, WebP or PDF, up to 10 MB, type checked from the bytes). It records a bank transfer waiting for confirmation, reviewed in the confirmation queue (confirm → receipt; reject with a reason). The same file uploaded again in the organization (same SHA-256) is flagged "Same file as another proof" for the reviewer, with the other uploads listed — never refused automatically. At most 5 proofs a bill an hour and 20 a tenant a day. Proofs are evidence: "remove" archives with a reason and keeps the file. The portal keeps accepting proofs while the organization is suspended (§ 3).
- Works for **any amount**, including multi-million annual rent, because the money moves bank-to-bank with no gateway in between.

### 4.2 Agency's own Paystack account (optional)

- The agency creates and verifies **its own** Paystack business account. Paystack runs KYC/AML on that agency directly, and money settles to the agency's bank account.
- The agency pastes its Paystack keys into GoProperty and sets GoProperty's per-organization webhook URL (`/webhooks/paystack/{org-ulid}`) in its Paystack dashboard. Keys are stored encrypted, shown masked, and can be rotated. Only org-owners can change them; impersonating staff never can.
- Tenant pays by card / Pay with Transfer / USSD on the bill page → webhook → GoProperty verifies the transaction with Paystack server-side → bill marked paid → receipt issued automatically.
- **Online-payment cap:** the org sets a maximum bill amount for online payment (default from Paystack's limits for that account). Bills above the cap show bank-transfer details only. This keeps large rent off the gateway.
- Caveat: a Paystack account has one webhook URL. If the agency already uses it for something else, they need a separate Paystack account for GoProperty, or GoProperty falls back to periodic verification of pending references.

### 4.3 Cash / POS

Recorded by an agent with a mandatory note. The receipt is sent straight to the tenant by SMS/WhatsApp so the tenant can confirm it. Voiding a receipt needs a second approver. As built (P03-09): the record form confirms a cash or POS payment as it is recorded (the member took the money), so the receipt is issued at once and the tenant is told once, by email with the receipt PDF attached and in the portal, or by the agency's WhatsApp when no email is known (no SMS until P05). As built (P03-10b): the receipt is issued the moment the payment is confirmed, and the tenant is told in the portal and by email, or by the agency's WhatsApp when no email is known (a stand-in sender until P05-06; no SMS in P03, decision 3); a void is asked for with a reason and approved by a different owner or administrator (an organization with a single owner and no administrator voids alone, recorded as such) — [ARCHITECTURE.md § 6.4](ARCHITECTURE.md) "How receipts are built". Cash at or above the legal thresholds is flagged (§ 5.4).

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

**How it is built (P01-03):**

- **The owner's page** (`/o/{slug}/compliance/tier`, Livewire `App\Livewire\Compliance\TierApplicationPage`; the setup checklist's Tier item and the workspace menu link to it). Seeing it needs `compliance.view`, changing it `organization.compliance`: both `org-owner` only (administrators and agents get 403; people outside the workspace 404). The owner starts an application for a tier above the approved one (the tier asked for at signup is suggested, and the stated yearly collections are copied), uploads each document with its number, holder name, issue and expiry dates where they apply, names the compliance contact (Tier 3), and submits. One open application per organization. While it is open the organization keeps its approved tier.
- **Required documents** (`App\Compliance\RequiredDocuments`, from `config/tiers.php` until the Tier settings screen, P06-08): Tier 2 needs the owner's ID, CAC certificate, CAC status report, **SCUML certificate**, proof of address and directors' IDs; Tier 3 adds beneficial owners, the AML policy statement, the state regulator registration and a named compliance contact. Professional membership is optional (and the regulator registration for Tier 2). A document counts while it waits for review or is accepted and has not expired; the proof of address must be dated within 3 months. **Submitting is refused, in the page and in the service, while anything required is missing.** The business bank account in the CAC name is checked by compliance by hand until bank accounts exist (P01-05 / P03-07).
- **Uploads:** PDF, JPEG or PNG, sniffed from the content (never the name or the browser's type), up to 8 MB (`compliance.max_upload_kb`). Stored encrypted with the application key on the private compliance disk under a random name (`App\Compliance\ComplianceFiles`); numbers, names and file names are encrypted in the database. A single-document type replaces the earlier upload (kept, soft deleted); director IDs and beneficial owners add up. The owner can remove what compliance has not accepted, only while the application is with them. The owner never downloads the files again: only compliance staff open them.
- **Review** (Console → Compliance → Tier applications, [PLATFORM_CONSOLE.md § 1C](PLATFORM_CONSOLE.md)): start review, accept or reject single documents (a rejected one shows its note to the owner and no longer counts), **request more information** (the application goes back to the owner, who sees the request, uploads, answers and submits again), **approve** or **reject** with a reason (audit log only) and an optional note to the owner. Approval accepts the pending documents, moves `organizations.transaction_tier` to the tier asked for through `App\Compliance\OrganizationTier` (one audit entry with the staff member, reason and tiers), and ticks the checklist's Tier item; it is refused while a required document is rejected or missing. Rejection leaves the tier as it is; the owner may apply again. The org-owners are emailed (queued) on every decision and request.
- **Expiry:** a daily scheduled check (`App\Platform\Compliance\DocumentExpiryCheck`, 03:15) flags documents of open or approved applications within 30 days of their expiry date (`expiring_notified_at`) and marks those past it `expired`; each organization's owners get one email per run, each document is flagged once per stage, and both stages are audited and listed in Console → Compliance → Document expiry. **The tier is not changed on expiry:** this section only asks for reminders and flags; renewing documents after approval comes with re-verification and the flag queue (P06-09).
- **Not yet:** `auth.require_2fa_for` (2FA for Tier 2/3 owners, [PLATFORM_CONSOLE.md § 9.6](PLATFORM_CONSOLE.md)) is not enforced yet; an identity-verification provider is not connected.

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

Platform staff can also manually restrict or suspend an organization for compliance reasons (audited, reason required, org-owner notified). Suspend/reactivate is in Console → Customers ([PLATFORM_CONSOLE.md § 1B](PLATFORM_CONSOLE.md)); while suspended the organization is read-only for its members ([ARCHITECTURE.md § 4](ARCHITECTURE.md)).

### 5.6 What GoProperty is *not*

GoProperty does not file suspicious-transaction or cash-transaction reports for agencies. The agency is the reporting entity. GoProperty provides the flags, records and exports that help the agency meet its own duty, and can refuse or end service to an organization that is a compliance risk. This is stated in the Terms of Service.
