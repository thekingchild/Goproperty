# GoProperty — Implementation Plan

Companion documents: [ARCHITECTURE.md](ARCHITECTURE.md) · [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md) · [BILLING_PAYMENTS.md](BILLING_PAYMENTS.md) · [ONBOARDING_COMPLIANCE.md](ONBOARDING_COMPLIANCE.md) · [PLATFORM_CONSOLE.md](PLATFORM_CONSOLE.md) · [UX_DESIGN.md](UX_DESIGN.md) · [BRANDING_DOCUMENTS.md](BRANDING_DOCUMENTS.md) · [DATA_IMPORT.md](DATA_IMPORT.md) · [AGENT.md](AGENT.md) · [docs/PROGRESS.md](docs/PROGRESS.md)

## 1. Team & Timeline Assumptions

| Item | Assumption |
| --- | --- |
| Team | 2 full-stack developers + 1 UI/UX designer (part-time after Phase 1) + product owner (you) |
| Working cadence | 2-week sprints, demo at sprint end |
| Total to launch | ~23 weeks (~12 sprints) for the full feature set, built and launched in one release (no separate pilot), plus ~20% contingency. Was 18 weeks; +3 weeks for Transaction Tiers & compliance, statement matching, agency-owned Paystack and the expanded console; +2 weeks for Excel import, agency branding/documents, WhatsApp and shared-hosting compatibility |
| Solo-dev scenario | Double every phase duration ≈ 11–12 months |
| Sequencing principle | Money paths (tenancy → leasing → bills → receipts → SaaS billing) are built early and hardened continuously; collaboration features (Building Group) come last so they can't destabilize billing |

Each phase below is split into numbered tasks (`P00-01`, `P00-02`, …). Each task has its deliverable and its acceptance criteria (AC). A task is done when its ACs pass, not when code is pushed; a phase is done when every task is done and the phase exit criteria pass on staging.

## 2. Phase Overview

| Phase | Name | Weeks | Milestone | Status |
| --- | --- | --- | --- | --- |
| PRE | Decisions and accounts before coding | 0 | — | In progress |
| P00 | Foundation, tooling, installer core, login pages & backups baseline | 1–2 | M0 | In progress |
| P01 | Tenancy, auth, registration, Transaction Tiers, staff console core & web installer | 3–5 | M1 | In progress |
| P02 | Properties, units, owners & import (part 1) | 6–8 | M2 | Not started |
| P03 | Leasing, branded documents & the customer money loop + import (part 2) | 9–12 | M3 | Not started |
| P04 | Maintenance tickets | 13 | M4 | Not started |
| P05 | Reminders, notifications, email templates & WhatsApp | 14–15 | M5 | Not started |
| P06 | SaaS billing, compliance monitoring & full platform console | 16–18 | M6 | Not started |
| P07 | Building Group | 19 | M7 | Not started |
| P08 | Reports, operations & analytics dashboards | 20 | M8 | Not started |
| P09 | Hardening, NDPA & launch | 21–23 | M9 (Launch) | Not started |

## 3. Work Packages

### 3.1 How tasks are tracked

- **Status lives in two places, kept in step:** the task's ID cell in the tables below, and the ledger in [docs/PROGRESS.md](docs/PROGRESS.md) (what was done, tests run, evidence, what is next).
- **Status values:** `Not started` · `In progress` · `Blocked` (with reason) · `In review` · `Complete (YYYY-MM-DD)`. Write the status in bold after the ID, e.g. `P00-01 — **Complete (2026-11-03)**`.
- **Marking a task complete:** only when every AC in its row passes and the quality gate in [AGENT.md § 2](AGENT.md) is green. In the same commit: (1) set the ID cell to `**Complete (date)**`, (2) add the ledger entry in `docs/PROGRESS.md`, (3) when it is the phase's last task and the exit criteria pass, mark the phase heading and the § 2 Status column `Complete (date)`.
- **Order:** work the earliest task whose dependencies are complete. Finish or hand off the `In progress` task before starting another.
- **Changing scope:** split a large task into sub-IDs (`P03-10a`, `P03-10b`) in `docs/PROGRESS.md` rather than renumbering. New work gets the next free ID in its phase; never reuse an ID.

### PRE — Decisions and accounts before coding — In progress

From § 8. These are product-owner tasks, not code.

| ID | Deliverable | Acceptance |
| --- | --- | --- |
| PRE-01 — **Not started** | Confirm the open decisions in [BILLING_PAYMENTS.md § 10](BILLING_PAYMENTS.md) and the handoff follow-ups (pricing bands, SMS provider, hosting budget) | Each decision written into the relevant doc |
| PRE-02 — **Not started** | Written legal opinion on the no-funds-flow model; agency-owned Paystack setup confirmed with Paystack | Opinion and Paystack confirmation on file |
| PRE-03 — **Not started** | Provision test/dev accounts: Paystack (test), Termii (test), object storage (live media + separate backup provider), Google Workspace Shared Drive, Cloudflare Turnstile, Sentry | Credentials stored in the team's secret store, never in the repo |
| PRE-04 — **Not started** | Register `goproperty.ng`; buy cPanel or Plesk shared hosting checked against [docs/runbooks/shared-hosting.md § 1](docs/runbooks/shared-hosting.md) | Host passes every § 1 check; staging and production accounts exist |
| PRE-05 — **Complete (2026-10-25)** | Design checkpoint: SocietyFlow reference captured, GoProperty designs approved ([UX_DESIGN.md § 5](UX_DESIGN.md)) | `docs/design/templates/landing.html`, `login.html`, `login-2fa.html` committed |

### P00 — Foundation, tooling, login pages & backups baseline (Weeks 1–2) — In progress

Depends on: PRE-03 (test accounts) for P00-12 and P00-13; PRE-04 for P00-13.

| ID | Deliverable | Acceptance |
| --- | --- | --- |
| P00-01 — **Complete (2026-10-26)** | Laravel 13 project (Livewire 4 starter kit), PHP 8.4, MySQL 8, Filament 5, Pest 4; Sail for local dev. Exact versions pinned | Fresh clone installs and boots; resolved versions recorded in `docs/PROGRESS.md` |
| P00-02 — **Blocked** (GitHub Actions jobs are not starting on the account; branch protection needs a repo admin, see `docs/PROGRESS.md`) | CI: GitHub Actions running Pint, Larastan (level 6), Pest, on MySQL 8 and MariaDB 10.6+; branch protection on `main` | `composer ci` runs lint + static analysis + tests locally and in CI; both database jobs green |
| P00-03 — **In review** (every AC passes locally; the CI run waits on GitHub Actions starting jobs, as P00-02, see `docs/PROGRESS.md`) | **Hosting profiles** ([ARCHITECTURE.md § 12.1](ARCHITECTURE.md), [docs/runbooks/shared-hosting.md](docs/runbooks/shared-hosting.md)): config switches for queue/cache/session drivers, realtime (polling vs Reverb) and PDF driver (`dompdf` vs Chrome). Scheduler and queue run **in-process** from one cron line (no `exec`/`proc_open`, [INSTALLER.md § 8](INSTALLER.md)). Shared-hosting deploy pipeline (CI builds assets, deploys over SSH, runs migrations) | CI runs the full suite with the shared-hosting profile; cron-started worker processes a job with `exec`/`proc_open` disabled |
| P00-04 — **In review** (every AC passes locally; the Release workflow waits on GitHub Actions starting jobs, as P00-02, see `docs/PROGRESS.md`) | **Release package** ([INSTALLER.md](INSTALLER.md)): zip built by CI on each tag with `vendor/` + built assets, `preferred` and `split` (public_html) layouts, `SHA256SUMS`, `VERSION`, PHP-version guard, `goproperty:verify-package` | CI produces a release zip that `goproperty:verify-package` passes; a tampered file fails verification |
| P00-05 — **Complete (2026-10-26)** | **Installer core services:** installation markers (lock file + `installation_state` row + checkpoint, fail-closed), `AwaitApplicationKey` middleware, "being set up" 503 until finalized, `/install` 404 afterwards; requirements checker, atomic `.env` writer, DB connection probe, batched migration runner, idempotent seeders, finalizer (generates the `ADMIN_PATH` suffix) | A fresh copy with no `APP_KEY` redirects to `/install` without touching the database or writing error logs; any one marker present keeps the installer closed |
| P00-06 — **In review** (drill passes locally on MariaDB 11.4; MySQL 8 and MariaDB 10.6 run only in CI, which waits on GitHub Actions as P00-02, see `docs/PROGRESS.md`) | **Installer CLI + install drill:** `goproperty:setup-token`, `goproperty:install` (interactive + `--no-interaction`), `goproperty:check-installation`; CI drill: build → verify → unpack split layout → install under shared-host limits (`max_execution_time=30`, `exec`/`proc_open` disabled) | Install drill passes on MySQL 8 and MariaDB 10.6; resulting schema matches a plain `migrate` |
| P00-07 — **Complete (2026-10-26)** | Module skeleton (nwidart/laravel-modules) with a `Core` area: `BelongsToOrganization` trait + `OrganizationContext` + middleware, base enums, money helper (kobo), settings store, activity log wiring | A Larastan rule or test fails when a scoped model is queried without context; money helper unit-tested |
| P00-08 — **Complete (2026-10-26)** | Filament platform panel shell at the configurable `ADMIN_PATH` (separate `staff` guard, `platform_staff` table, path-scoped session cookie). Route skeleton on one domain: `/`, `/login`, `/o/{org-slug}`, `/portal`, `/owner`, `/pay/{ref}`, `/verify/{code}`, `/{ADMIN_PATH}` ([ONBOARDING_COMPLIANCE.md § 1](ONBOARDING_COMPLIANCE.md)); hosts and admin path from config | Panel is auth-protected and empty; customer session cannot reach it; a seeded demo user can log in and see empty shells of all three surfaces |
| P00-09 — **Complete (2026-10-26)** (done as 09a theme engine, 09b landing page, 09c console settings pages; see `docs/PROGRESS.md`) | Design system ([UX_DESIGN.md](UX_DESIGN.md)): Tailwind theme, app shell (sidebar nav, org switcher placeholder), blank page templates for all surfaces. **Configurable platform theme** ([PLATFORM_CONSOLE.md § 9](PLATFORM_CONSOLE.md)): theme settings → CSS variables for Tailwind 4 and Filament, default theme, draft/preview/publish; replaceable hero and login images; public website text, SEO and custom-code settings (§ 9.2–9.5). Landing page built from `docs/design/templates/landing.html` | Landing page matches the approved template at mobile and desktop; changing and publishing a theme colour updates both the customer app and Filament; draft does not leak to the public site |
| P00-10 — **Complete (2026-10-26)** (done as 10a customer sign-in, 10b 2FA and other auth screens, 10c staff sign-in; see `docs/PROGRESS.md`) | **Login pages to final quality** ([UX_DESIGN.md § 3](UX_DESIGN.md)): customer split-screen login (passkey, or email/phone number + password — no SMS at sign-in), 2FA challenge, staff login with mandatory 2FA, forgot/reset password; rate limits, lockout, Turnstile, no account enumeration. Visual spec: `login.html`, `login-2fa.html` | Pages match the templates; tests cover rate limit, lockout, identical responses for unknown vs known accounts, staff cannot sign in without 2FA |
| P00-11 — **Complete (2026-10-26)** | Audit log (append-only, hash chain) and authentication/access log wired for both guards | Tampering with a row breaks chain verification (tested); logins and failures recorded for both guards |
| P00-12 — **In review** (built and drilled locally as 12a/12b; needs one real run to S3 and a Google Shared Drive with the PRE-03 accounts; see `docs/PROGRESS.md`) | Backups baseline: nightly encrypted DB and files backups as separate jobs to S3-compatible storage + Google Drive (Shared Drive) | Both jobs run on schedule and land encrypted in both destinations; a backup decrypts and restores locally |
| P00-13 — **Blocked** (needs PRE-04 staging host and P00-02 runners; built and drilled locally; see `docs/PROGRESS.md`) | Staging provisioned **with the installer** (CLI path); deploy pipeline to staging on merge | Staging installed via `goproperty:install`; a merge to `main` deploys to staging automatically |

**Phase exit:** all P00 tasks complete; the install drill and CI are green on both databases.

### P01 — Tenancy, auth, registration, Transaction Tiers, staff console core & web installer (Weeks 3–5) — In progress

Depends on: P00.

| ID | Deliverable | Acceptance |
| --- | --- | --- |
| P01-01 — **Complete (2026-10-26)** | Organization switcher for multi-org users; per-org role assignment via spatie teams | Two orgs created; a user in both can switch; data never crosses orgs (Pest tests probe cross-tenant access on every Phase-1 model); visiting `/o/{slug}` of an org you don't belong to returns 404 |
| P01-02 — **Complete (2026-10-27)** | Registration wizard ([ONBOARDING_COMPLIANCE.md § 2](ONBOARDING_COMPLIANCE.md)): account → email verification by link (no SMS) → organization (type, slug) → **Transaction Tier selection** → plan + trial → collections setup → import/first property, with a progress checklist. Property-owner signup with `platform_covered` handling; first user as `org-owner` | A new signup reaches the dashboard as `org-owner`; leaving mid-wizard resumes at the same step; property-owner signup sets `platform_covered` |
| P01-03 — **Complete (2026-10-27)** | Transaction Tier applications: document upload (CAC, SCUML, IDs, proof of address, beneficial owners for Tier 3), compliance review queue in the console, approve/reject/more-info, document expiry tracking | A Tier 2 application with missing SCUML cannot be submitted; an approved one changes the org's tier and is audited |
| P01-04 — **In progress** | Invitations (email + token): invite with roles; acceptance sets password and joins org | An invited user with role `agent` sees only what [ARCHITECTURE.md § 5](ARCHITECTURE.md) grants; expired/used tokens rejected |
| P01-05 — **In progress** | Org settings: profile, bank accounts (encrypted, name-checked), **branding** ([BRANDING_DOCUMENTS.md § 1](BRANDING_DOCUMENTS.md)): logo, colours with contrast check, letterhead, signature/stamp, prefixes, notes, layout choice, live invoice/receipt preview | Bank numbers encrypted at rest; low-contrast colours rejected; preview reflects unsaved changes |
| P01-06 — **Complete (2026-10-27)** | Platform console: customers list (orgs, users, statuses, tier), org suspend/reactivate | Suspending an org blocks its users' write actions within one request; reactivating restores them |
| P01-07 — **Complete (2026-10-26)** | Console staff management: configurable roles (default set + custom roles from a permission catalogue), 2FA enforcement, two-person approvals ([PLATFORM_CONSOLE.md § 1](PLATFORM_CONSOLE.md)) | A two-person action cannot be approved by its requester; staff without 2FA are forced to enrol |
| P01-08 — **Complete (2026-10-27)** | Impersonation with reason, permission-gated by staff role (`impersonate.read_only`; `impersonate.write` with two-person approval), 30-minute limit, banner, blocked money actions and a full GoProperty-side audit trail with a console impersonation log ([PLATFORM_CONSOLE.md § 6](PLATFORM_CONSOLE.md)). No organization consent setting and no notification to the organization (changed 2026-10-26 by the product owner; the `allow_support_access` column is dropped) | Impersonating staff cannot confirm a payment or view bank/Paystack keys (tested); staff without the impersonate permission cannot start a session (tested); start, end, reason, pages viewed and actions are recorded with both identities in the hash-chained audit log and shown in the console impersonation log (tested); no email or notification reaches the organization or user (tested) |
| P01-09 — **In review** (every AC passes locally on MariaDB 11.4; the Install drill on MySQL 8 and MariaDB 10.6 waits on GitHub Actions, as P00-06, see `docs/PROGRESS.md`) | **Web installer wizard** ([INSTALLER.md § 4–5](INSTALLER.md)) on the auth-page shell: unlock by setup token **or file check**, session-bound grant renewed per step, server check, site & database (auto-created `.env`), batched migrations with progress bar and resume, email + S3 storage tests, first super-admin with **2FA enrolled in the wizard**, finish screen (console path, detected cron line, live cron heartbeat, installation record, next steps). The install drill walks the wizard over HTTP | Web install drill (file-check unlock, TOTP enrolment, finish) passes on MySQL 8 and MariaDB 10.6; after finish `/install` is 404 and no HTTP route can reset or re-run installation (tested) |

**Phase exit:** all P01 tasks complete; cross-tenant tests cover every Phase-1 model.

### P02 — Properties, units, owners & import part 1 (Weeks 6–8) — Not started

Depends on: P01. Sub-tasks, run order and open decisions: [docs/plans/P02-P03-subtasks.md](docs/plans/P02-P03-subtasks.md).

| ID | Deliverable | Acceptance |
| --- | --- | --- |
| P02-01 — **Not started** | Owner profiles (person/company); buildings CRUD with amenities and photos | Cross-org access tests pass for owners and buildings |
| P02-02 — **Not started** | Units CRUD (types, rent/service defaults, status board); bulk unit creation (e.g. "10 identical flats") | Creating 10 units in one bulk action works; unit statuses drive occupancy counts on the building page |
| P02-03 — **Not started** | Agent↔building assignment (optional narrowing); unit filters and lists with server-side pagination | A unit of org B is not addressable by an org A user via ID guessing; a narrowed agent sees only assigned buildings |
| P02-04 — **Not started** | Org-to-org invitation UI: property owner invites agency; agency invite inbox; **billing transfer accept screen** with explicit amount (state machine in [BILLING_PAYMENTS.md § 7](BILLING_PAYMENTS.md)) — invite + accept/decline record lands now, payer switch in P06-06 | Invite, accept and decline are recorded through `BillingTransferService`; invalid transitions rejected |
| P02-05 — **Not started** | Media library (photos, thumbnails) on S3-compatible storage with signed URLs | Uploads validated (mime sniff, size); signed URLs expire; another org's media is not reachable |
| P02-06 — **Not started** | **Import engine** ([DATA_IMPORT.md](DATA_IMPORT.md)): streaming reader, column mapping with saved mappings, downloadable template, **dry run** with error/warning report, queued commit, batch rollback within 7 days, `external_ref` upserts — for owners, properties, units and tenants | Dry run of a 2,000-row workbook saves nothing and returns exact errors per row; commit creates exactly the previewed records; re-importing the same file creates no duplicates; rollback removes the batch |
| P02-07 — **Not started** | Excel/CSV export of every list | Every list page exports; exports respect the current filters and org scope |

**Phase exit:** all P02 tasks complete; branding settings (P01-05) render on a sample document page.

### P03 — Leasing, branded documents & the customer money loop + import part 2 (Weeks 9–12) — *the heart* — Not started

Depends on: P02. Sub-tasks, run order and open decisions: [docs/plans/P02-P03-subtasks.md](docs/plans/P02-P03-subtasks.md).

| ID | Deliverable | Acceptance |
| --- | --- | --- |
| P03-01 — **Not started** | Tenant profiles with user accounts for portal access. Invitations by email, agency WhatsApp, or copy link / QR; phone verified by opening a WhatsApp-delivered link or confirmed by the agency — no SMS codes. Agency-created one-time sign-in link for phone-only tenants | Each invite channel produces a working one-time link; used/expired links rejected |
| P03-02 — **Not started** | `CycleCalculator` (daily → biennial) | Property-based tests: period end always = start + cycle − 1 day across leap years and month-length edges |
| P03-03 — **Not started** | Lease engine via `LeaseService`: lease CRUD with draft/reserve → active → renew/terminate, renewal referencing, deposit handling, contract PDF (PDF driver interface, branded template with signature blocks) | Invalid lease transitions rejected; renewal links to the previous lease; contract PDF renders with both drivers |
| P03-04 — **Not started** | Rent billing: scheduler job generating rent bills from leases using `next_billing_date`; bill numbering via `document_counters` | Rerunning the job creates no duplicate bills; numbers are gap-free under concurrency |
| P03-05 — **Not started** | **Branded documents** ([BRANDING_DOCUMENTS.md](BRANDING_DOCUMENTS.md)): invoice and receipt from `docs/design/templates/`, three layouts, web + PDF views, QR verification codes, amount in words, branding snapshot per document, public `/verify/{code}` and branded `/pay/{reference}` pages | Invoice and receipt render identically (visual diff) with `dompdf` and Chrome, on one A4 page for up to 8 line items; changing branding later does not change issued documents |
| P03-06 — **Not started** | Utility billing: utility types, per-unit settings, flat + metered (readings) utilities, utility bill PDF | Metered bill = (current − previous reading) × rate in kobo; flat utilities bill each cycle |
| P03-07 — **Not started** | Collection setup ([ONBOARDING_COMPLIANCE.md § 4](ONBOARDING_COMPLIANCE.md)): org bank accounts with Paystack account-name check; unique `payment_reference` per bill; **bank statement import (CSV/XLSX) with matching** and one-tap/bulk confirm | Re-uploading the same statement creates no duplicate lines; a credit with the bill reference is matched with high confidence; one credit split across two bills settles both |
| P03-08 — **Not started** | **Agency-owned Paystack connection**: encrypted keys, per-org webhook, verify-by-fetch, auto-confirm, online-payment cap | A webhook signed with the wrong org's key is rejected; a replayed webhook creates one payment; keys hidden during impersonation |
| P03-09 — **Not started** | Proof upload fallback with duplicate-image detection; cash/POS with direct-to-tenant receipt | Same image uploaded twice is flagged; cash payment sends the receipt to the tenant |
| P03-10 — **Not started** | Payments loop via `PaymentService`: allocations across bills, tenant credit for excess, confirm/reject with reason, receipt PDF with sequential numbering and QR code, receipt void with second approver, notifications on every transition | Overpayment goes to tenant credit and is applied to the next bill; voided bills accept nothing; concurrent confirmations of the same bill cannot over-settle (locked transaction test); void needs a second approver |
| P03-11 — **Not started** | Tenant portal: my units, my bills, pay instructions, upload proof, receipts, documents | Tenant sees only their own leases/bills; portal usable at 360px width |
| P03-12 — **Not started** | Expenses recording (category, property/unit linkage, receipt upload) — reporting comes in P08 | Expenses scoped to org; receipt upload validated |
| P03-13 — **Not started** | **Data import, part 2:** ongoing leases with **paid until** (no back-billing), opening balances (as `opening_balance` bills), tenant credits, deposits held, utility settings, optional payment history; tenant invitations only on request after review | Importing a lease that started in 2023 with "paid until" 31/12/2026 creates no past bills; the first generated bill covers the period from 01/01/2027; no reminders for past dates; no tenant contacted automatically |

**Phase exit:** all P03 tasks complete; end-to-end integration test passes: building → 3 units → tenant → lease (annual) → scheduler generates invoice → payment + proof upload → confirmation → receipt number increments → tenant portal shows receipt. Same for weekly and semi-annual cycles.

### P04 — Maintenance tickets (Week 13) — Not started

Depends on: P03-01 (tenants) and P03-03 (leases).

| ID | Deliverable | Acceptance |
| --- | --- | --- |
| P04-01 — **Not started** | Ticket lifecycle via `TicketService`: draft → submitted → under_review → in_progress → resolved → closed, reopen; categories, priority, assignment, status history | Transitions invalid per the state machine are rejected (tested); tenant can reopen a resolved ticket |
| P04-02 — **Not started** | Threaded replies with attachments; staff internal notes; tenant "confirm resolution / reopen" | Staff see internal notes the tenant never sees (enforced server-side) |
| P04-03 — **Not started** | Agent and tenant views (inbox by status), notification on transitions, ticket from lease context | Each transition notifies the right party once |

**Phase exit:** all P04 tasks complete.

### P05 — Reminders, notifications, email templates & WhatsApp (Weeks 14–15) — Not started

Depends on: P03.

| ID | Deliverable | Acceptance |
| --- | --- | --- |
| P05-01 — **Not started** | Reminder engine (`reminders:dispatch`) with dedupe keys; rent/utilities/lease-renewal ladders; overdue ladder | Time-travel tests (`Carbon::setTestNow`) prove exact send days and zero duplicates across reruns |
| P05-02 — **Not started** | SMS via Termii (bill reminders only, to verified/agency-confirmed numbers — no OTP); per-user channel preferences; `notification_deliveries` log with provider refs and cost | SMS template stays under 160 chars; failed sends retry with backoff then surface in the health dashboard; unverified numbers never texted |
| P05-03 — **Not started** | SMS credits per plan (monthly allowance, in-app top-up, ledger) | Sending stops at zero credit; ledger balances to allowance + top-ups − sends |
| P05-04 — **Not started** | Email template system ([PLATFORM_CONSOLE.md § 5](PLATFORM_CONSOLE.md)): database templates with whitelisted placeholders (never Blade), versions + rollback, live preview, test send, categories, org branding, bounce/complaint suppression | Blade/PHP in a template renders as plain text; rollback restores the previous version; suppressed addresses are skipped |
| P05-05 — **Not started** | Platform console: delivery health dashboard (failure rates, SMS spend) | Dashboard figures match the `notification_deliveries` log for a fixture |
| P05-06 — **Not started** | **WhatsApp (optional, agency's own Business API)** ([ARCHITECTURE.md § 6.7](ARCHITECTURE.md)): connection settings, template sync from Meta, event → template mapping with variable mapping and preview, Cloud API sending, status webhooks, tenant opt-in, fallback to SMS/email, per-event channel order | A template paused by Meta disables its mapping on next sync and that event falls back to SMS; a status webhook with a bad signature is rejected |

**Phase exit:** all P05 tasks complete.

### P06 — SaaS billing, compliance monitoring & full platform console (Weeks 16–18) — Not started

Depends on: P05 (dunning notifications) and P01 (console core).

| ID | Deliverable | Acceptance |
| --- | --- | --- |
| P06-01 — **Not started** | Plans with launch prices and feature matrix ([BILLING_PAYMENTS.md § 1](BILLING_PAYMENTS.md)), editable in the console with versioning and 30-day customer notice; features + limits, trial, subscription lifecycle states via `SubscriptionService`, gating middleware | Price change cannot take effect before 30 days' notice; over-limit actions blocked by middleware |
| P06-02 — **Not started** | Monthly metering + platform invoice generation + VAT line | Rerunning metering creates one invoice per org per period; VAT matches [BILLING_PAYMENTS.md § 10](BILLING_PAYMENTS.md) |
| P06-03 — **Not started** | Platform Paystack: checkout, stored authorization, auto-charge, webhooks with verify-by-fetch, webhook log + replay | Sandbox end-to-end: invoice → charge → webhook → paid → active. Same event twice produces one payment; forged-signature webhook is dropped and logged |
| P06-04 — **Not started** | Bank transfer proof upload + platform-staff confirmation flow | Proof alone never marks an invoice paid; confirmation is audited |
| P06-05 — **Not started** | Dunning ladder + suspension gating | Failed charge → retry ladder → past_due → grace → suspended (write-blocked) → pay → reactivated; suspension blocks every write surface (spot-check list of 10 actions in tests) |
| P06-06 — **Not started** | Billing responsibility transfer completion: switch payer on acceptance; new payer payment-method enrolment | Accepting a billing transfer in the UI moves `payer_organization_id` and the next invoice routes to the new payer |
| P06-07 — **Not started** | Platform console full build: customers, subscriptions, invoices, payments (Paystack + manual), refunds, feature overrides, staff audit, audit-log and access-log viewers | Every page policy-checked per staff role; refunds need the configured approval |
| P06-08 — **Not started** | Tier settings screen: editable tier limits and documents (two-person approval, effective dates, versions), per-organization overrides | A limit change needs a second approver and applies from its effective date |
| P06-09 — **Not started** | Transaction Tier monitoring ([ONBOARDING_COMPLIANCE.md § 5.4–5.5](ONBOARDING_COMPLIANCE.md)): daily `compliance:evaluate-tiers`, volume snapshots, flags (80%/100%/single-over/cash threshold/structuring/spike/document expiry), upgrade prompts, 30-day grace, restriction, compliance flag queue | Time-travel: an org crossing 80% and 100% gets exactly one flag each; after 30 days without an application it becomes restricted, yet tenants can still view bills, upload proofs and receive receipts |
| P06-10 — **Not started** | Backups console ([PLATFORM_CONSOLE.md § 4](PLATFORM_CONSOLE.md)): destinations, run now, list/download, verify, restore to staging, production restore with two-person approval | Backup verify restores the latest dump into a temporary database; production restore cannot start without a second approver |
| P06-11 — **Not started** | Marketing/pricing page + plan checkout for new orgs | Pricing page reads live plan data; checkout starts a subscription in sandbox |

**Phase exit:** all P06 tasks complete.

### P07 — Building Group (Week 19) — Not started

Depends on: P03 (leases drive membership).

| ID | Deliverable | Acceptance |
| --- | --- | --- |
| P07-01 — **Not started** | Per-building group (auto-created), membership sync from leases | Ending a lease removes the tenant from the group; a new lease adds them |
| P07-02 — **Not started** | Posts, comments, reactions; rich text (limited toolbar, sanitized); images (validated + thumbnails) | XSS suite: script/iframe/onload payloads neutralized; disallowed upload types rejected |
| P07-03 — **Not started** | Polling updates (shared hosting) with Reverb realtime on VPS | Two browser sessions see a post/comment/reaction within 30 s by polling (shared-hosting profile) and within 1 s via Reverb (VPS profile) |
| P07-04 — **Not started** | Moderation (pin/hide/mute), report-abuse | Hidden/removed posts vanish for members but remain for moderators with an audit trail |

**Phase exit:** all P07 tasks complete.

### P08 — Reports, operations & analytics dashboards (Week 20) — Not started

Depends on: P03, P06.

| ID | Deliverable | Acceptance |
| --- | --- | --- |
| P08-01 — **Not started** | Customer dashboards: occupancy, collections vs billed, arrears aging, upcoming renewals, expenses by category, per-building performance; date-range filters; CSV + PDF export | Arrears aging buckets (0–30/31–60/61–90/90+) reconcile against a hand-computed fixture org; every report has a feature test with seeded edge data (voided bills excluded, refunds netted) |
| P08-02 — **Not started** | System Analytics dashboard ([PLATFORM_CONSOLE.md § 3](PLATFORM_CONSOLE.md)): MRR/ARR, signups and trial conversion, churn, unit growth, tier distribution, recorded collections by channel, customer leaderboard — from nightly `daily_platform_metrics` | Metrics match a fixture; nightly job is idempotent |
| P08-03 — **Not started** | System Operations dashboard ([PLATFORM_CONSOLE.md § 2](PLATFORM_CONSOLE.md)): queues, scheduler heartbeat, webhooks (own + per agency), messaging, backups, storage, security; alerts to Slack/email | A stopped scheduler raises an alert within the configured window |
| P08-04 — **Not started** | Cached aggregates for dashboards; report queries documented for the read-replica config | Dashboards load from cache; cache invalidates on relevant writes |

**Phase exit:** all P08 tasks complete.

### P09 — Hardening, NDPA & launch (Weeks 21–23) — Not started

Depends on: P00–P08.

| ID | Deliverable | Acceptance |
| --- | --- | --- |
| P09-01 — **Not started** | Security review: policy coverage sweep, rate-limit audit, upload validation audit, dependency audit, session/2FA checks; external pen-test if budget allows | No open critical/high findings |
| P09-02 — **Not started** | NDPA 2023 + GAID 2025: data protection officer named, breach response plan (72-hour notification), consent records, data export (per tenant/org), erasure workflow with 90-day retention enforcement, privacy policy + terms pages | Export contains all of a tenant's data and nothing of others; erasure completes within retention rules |
| P09-03 — **Not started** | Load test (k6) on hot paths: dashboard, bills list, group feed; fix N+1/cache gaps | Hot paths meet the agreed latency budget on the shared-hosting profile |
| P09-04 — **Not started** | Backup + restore drill on staging from production-like data; runbook in `docs/runbooks/` | Drill achieves the RPO/RTO in [ARCHITECTURE.md § 11](ARCHITECTURE.md); the runbook is followed verbatim by someone other than the author |
| P09-05 — **Not started** | Seed production demo org; monitoring/alerting (Sentry → Slack, queue-depth alerts) | A test error reaches Slack; queue-depth alert fires in a drill |
| P09-06 — **Not started** | **Upgrades:** console **System → Version** page (current/latest version, pending migrations, last backup; read-only), authenticated batched "run pending migrations" for no-SSH upgrades, upgrade drill in CI (previous release → new) | Upgrade drill passes on both databases; one zip upgrade + rollback rehearsed on staging |
| P09-07 — **Not started** | Runbooks `docs/runbooks/install.md`, `install-recovery.md`, `upgrade.md`; owner's install guide with SSH and no-SSH variants for cPanel and Plesk | Real installs on a cPanel and a Plesk account (one with SSH, one without) follow the guide word for word |
| P09-08 — **Not started** | Launch checklist (§ 4) executed | Every box in § 4 ticked |

**Phase exit:** all P09 tasks complete — launch.

## 4. Launch Checklist

- [ ] Production installed from the signed release zip through the installer; checksum verified; `/install` returns 404; installation record (with `APP_KEY`) stored offline in two places
- [ ] Production env vars audited (no test keys, mail/SMS senders verified)
- [ ] Paystack live keys + webhook live-verified; test charge + refund executed live with a small amount
- [ ] Termii live sender ID approved; DND-template path verified
- [ ] Scheduled jobs and queue worker running in production (cron on shared hosting / Horizon on VPS) with scheduler heartbeat alert
- [ ] Sentry environment tags correct; alerts routed
- [ ] Backups running 3 consecutive nights + one restore verified
- [ ] Legal pages live; NDPA consent at signup; Terms name the agency as AML reporting entity
- [ ] Written legal opinion on the no-funds-flow model received
- [ ] Transaction Tier thresholds and document lists configured; compliance officer trained on the review queue
- [ ] Backups landing in both S3-compatible storage and Google Drive; one console-driven restore to staging verified
- [ ] All staff accounts have 2FA; roles and permissions reviewed; at least two super-admins
- [ ] VAT line matches company's registration status (BILLING_PAYMENTS § 10)
- [ ] Support inbox + escalation runbook live
- [ ] Smoke-test script green on production

## 5. Testing Strategy

- **Pest feature tests are the backbone.** Money paths (leasing, bills, payments, receipts, SaaS billing) require end-to-end coverage before merge — no exceptions.
- Unit tests for pure logic (`CycleCalculator`, pricing math, dunning ladder); time-travel via Carbon for all scheduler logic.
- Installer: install drill and upgrade drill in CI under shared-host limits on MySQL and MariaDB ([INSTALLER.md § 12](INSTALLER.md)).
- Browser tests (Laravel Dusk or Pest v4 browser plugin) for the five golden journeys: signup→org, lease→receipt, ticket lifecycle, group interaction, platform invoice payment.
- Cross-tenant security tests generated per model (trait-enforced) — the suite fails if a scoped model is added without scope tests.
- Staging rehearsals for: Paystack webhooks (CLI replay), SMS delivery, backup restore.

## 6. Risk Register

| # | Risk | Impact | Mitigation |
| --- | --- | --- | --- |
| R1 | Manual bank-transfer confirmation becomes an ops bottleneck | Scale ceiling, angry customers | Dedicated virtual accounts (DVA) queued as first post-launch item; confirmations tracked with SLA in console |
| R2 | SMS costs/DND blocks break reminder trust | Tenants miss due dates | Email-first design, verified-phones-only SMS, delivery dashboard, cost caps per org |
| R3 | Paystack webhook outages/misses | Wrong subscription states | Hourly reconciliation job, verify-by-fetch, replay tooling |
| R4 | Tenancy leaks (cross-org data exposure) | Catastrophic privacy failure | Trait + global scopes, per-model security tests, Larastan rules, pen-test before launch |
| R5 | Laravel 13/package ecosystem drift (Livewire 4 / Filament 5 / Pest 4 versions) | Upgrade churn mid-build | Pin versions in Phase 0; upgrade only between phases; CI on latest stable |
| R6 | Scope creep (expenses accounting, owner payouts demanded pre-launch) | Timeline slip | Backlog discipline: § 8 post-launch modules; platform owner sign-off per sprint scope |
| R7 | Single dev + complex money logic | Key-person risk | Money paths pair-tested, docs-as-code in this repo, money invariants in tests |
| R9 | GoProperty treated as a payment intermediary or exposed to agencies' AML failures | Licensing demands, legal liability, Paystack account closure | No-funds-flow rule (AGENT.md § 3), agency-owned Paystack only, Transaction Tiers with SCUML for Tier 2/3, compliance flags, ToS naming the agency as reporting entity, written legal opinion before launch |
| R10 | Leak of agencies' Paystack secret keys | Attackers could move agencies' money | Encrypted at rest, masked, org-owner-only edits, blocked during impersonation, access audited, key-rotation prompt |
| R11 | Staff misuse of impersonation or backups | Privacy breach | Role permissions for impersonation, reason required, read-only default, banner, 30-minute limit, full audit trail with a console impersonation log, two-person rules, signed expiring backup downloads |
| R12 | Shared hosting limits (CPU, memory, execution time, disabled `exec`, mail limits) | Slow queues, failed backups/imports, outages | Requirements checklist before choosing a host; chunked queued jobs; API mail provider; dompdf; monitoring of queue wait; clear triggers to move to VPS (ARCHITECTURE.md § 12.1) |
| R13 | Imported data is wrong (bad balances, wrong "paid until") | Wrong bills sent to tenants, trust lost | Mandatory dry run, review summary, no auto-contact of tenants, 7-day rollback, opening balances shown separately on statements |
| R14 | Installer taken over on a fresh upload, or an install/upgrade fails half-way on a shared host | Site hijack, or a broken production with no developer to hand | Host-control unlock (token or file check), HTTPS + same-origin + rate limit, fail-closed markers, batched resumable migrations, "empty and retry" for fresh installs, backups before every upgrade, CI install + upgrade drills under shared-host limits ([INSTALLER.md](INSTALLER.md)) |
| R8 | Low-end devices/2G users abandon portal | Adoption | Livewire server-rendered UI, image caps, polling fallback, performance budget in CI (Lighthouse check on portal pages) |

## 7. Post-Launch Module Backlog (expansion via the module system)

Ordered by expected demand in the Nigerian market:

1. **DVA auto-matching** for platform billing (Paystack dedicated virtual accounts).
2. **Late fees & penalties** module — configurable grace + percentage, auto bill line, legal-notice templates (7-day quit notice generation for agencies).
3. **Owner payouts** — agency commission tracking and payout approvals on top of the owner statements already in the MVP.
4. **WhatsApp Embedded Signup** — GoProperty as a Meta Tech Provider so agencies connect WhatsApp in a few clicks (the MVP already supports the agency's own WhatsApp Business API with template mapping).
5. **E-signatures** on lease contracts (tenant signs in portal).
6. **More collection channels** — open-banking transaction feeds and bank virtual-account APIs on the agency's own bank account; PDF bank statement parsing.
7. **Vacancy marketplace / public listings** — public unit listing pages with enquiry forms.
8. **Mobile app (API module)** — Sanctum API + tenant-facing app.
9. **White-label tenant portal** on agency subdomain/custom domain (VPS hosting).
10. **Insurance/agency fees module**, multi-currency, tenant-scoring style add-ons as the market demands.

(Tenant online payments via the agency's own Paystack and WhatsApp notifications moved into the MVP.)

## 8. Immediate Next Steps (this week)

Items 1, 3 and 4 are tracked as tasks PRE-01 to PRE-04 in § 3.

1. Confirm the open decisions in [BILLING_PAYMENTS.md § 10](BILLING_PAYMENTS.md) and the follow-up questions raised in the handoff notes (pricing bands, SMS provider choice, hosting budget).
2. Brand is a setting, not a blocker: start with the default theme and change it in the console later ([PLATFORM_CONSOLE.md § 9](PLATFORM_CONSOLE.md)). The SocietyFlow-style design reference is requested from the product owner at the Phase 0 design checkpoint.
3. Get a written legal opinion on the no-funds-flow model and confirm the agency-owned-Paystack setup with Paystack. (Tier limits are set and editable — [ONBOARDING_COMPLIANCE.md § 5.2](ONBOARDING_COMPLIANCE.md).)
4. Provision accounts: Paystack (test), Termii (test), object storage (live media + separate backup provider), Google Workspace Shared Drive for backups, Cloudflare (Turnstile), Sentry; register `goproperty.ng` and choose hosting: a shared host meeting the requirements in [ARCHITECTURE.md § 12.1](ARCHITECTURE.md) (PHP 8.4, MySQL 8 or MariaDB 10.6+, cron every minute; SSH helpful but not required — [INSTALLER.md](INSTALLER.md)), or a small VPS with Forge/Ploi. Hosting decision: **cPanel or Plesk shared hosting** — check the host against [docs/runbooks/shared-hosting.md § 1](docs/runbooks/shared-hosting.md) before buying. (`ADMIN_PATH` is generated by the installer — [INSTALLER.md § 5](INSTALLER.md).)
5. Kick off Phase 0; use [AGENT.md](AGENT.md) as the working contract for anyone (human or AI) writing code.
