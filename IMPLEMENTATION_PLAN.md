# GoProperty — Implementation Plan

Companion documents: [ARCHITECTURE.md](ARCHITECTURE.md) · [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md) · [BILLING_PAYMENTS.md](BILLING_PAYMENTS.md) · [ONBOARDING_COMPLIANCE.md](ONBOARDING_COMPLIANCE.md) · [PLATFORM_CONSOLE.md](PLATFORM_CONSOLE.md) · [UX_DESIGN.md](UX_DESIGN.md) · [BRANDING_DOCUMENTS.md](BRANDING_DOCUMENTS.md) · [DATA_IMPORT.md](DATA_IMPORT.md) · [AGENT.md](AGENT.md)

## 1. Team & Timeline Assumptions

| Item | Assumption |
| --- | --- |
| Team | 2 full-stack developers + 1 UI/UX designer (part-time after Phase 1) + product owner (you) |
| Working cadence | 2-week sprints, demo at sprint end |
| Total to launch | ~23 weeks (~12 sprints) for the full feature set, built and launched in one release (no separate pilot), plus ~20% contingency. Was 18 weeks; +3 weeks for Transaction Tiers & compliance, statement matching, agency-owned Paystack and the expanded console; +2 weeks for Excel import, agency branding/documents, WhatsApp and shared-hosting compatibility |
| Solo-dev scenario | Double every phase duration ≈ 11–12 months |
| Sequencing principle | Money paths (tenancy → leasing → bills → receipts → SaaS billing) are built early and hardened continuously; collaboration features (Building Group) come last so they can't destabilize billing |

Each phase below lists deliverables and acceptance criteria (AC). A phase is done when all ACs pass on staging, not when code is pushed.

## 2. Phase Overview

| Phase | Name | Weeks | Milestone |
| --- | --- | --- | --- |
| 0 | Foundation, tooling, installer core, login pages & backups baseline | 1–2 | M0 |
| 1 | Tenancy, auth, registration, Transaction Tiers, staff console core & web installer | 3–5 | M1 |
| 2 | Properties, units, owners & import (part 1) | 6–8 | M2 |
| 3 | Leasing, branded documents & the customer money loop + import (part 2) | 9–12 | M3 |
| 4 | Maintenance tickets | 13 | M4 |
| 5 | Reminders, notifications, email templates & WhatsApp | 14–15 | M5 |
| 6 | SaaS billing, compliance monitoring & full platform console | 16–18 | M6 |
| 7 | Building Group | 19 | M7 |
| 8 | Reports, operations & analytics dashboards | 20 | M8 |
| 9 | Hardening, NDPA & launch | 21–23 | M9 (Launch) |

## 3. Phase Details

### Phase 0 — Foundation, tooling, login pages & backups baseline (Weeks 1–2)

**Deliverables**
- Laravel 13 project (Livewire 4 starter kit), PHP 8.4, MySQL 8, Filament 5, Pest 4; Sail for local dev. Exact versions pinned.
- **Hosting profiles** ([ARCHITECTURE.md § 12.1](ARCHITECTURE.md), runbook [docs/runbooks/shared-hosting.md](docs/runbooks/shared-hosting.md)): config switches for queue/cache/session drivers, realtime (polling vs Reverb) and PDF driver (`dompdf` vs Chrome). Shared-hosting deploy pipeline (CI builds assets, deploys over SSH, runs migrations); scheduler and queue run **in-process** from the one cron line (no `exec`/`proc_open`, [INSTALLER.md § 8](INSTALLER.md)). CI also runs the test suite with the shared-hosting profile, on both MySQL 8 and MariaDB 10.6+. Staging runs on the same kind of cPanel/Plesk account as production.
- CI: GitHub Actions running Pint, Larastan (level 6), Pest; branch protection on `main`.
- **Installer core** ([INSTALLER.md](INSTALLER.md)) — built now so staging and every later phase are installed the same way production will be:
  - Release package built by CI on each tag: zip with `vendor/` + built assets, `preferred` and `split` (public_html) layouts, `SHA256SUMS`, `VERSION`, PHP-version guard, package verifier (`goproperty:verify-package`).
  - Installation markers (lock file + `installation_state` row + checkpoint, fail-closed), `AwaitApplicationKey` middleware, "being set up" 503 for public routes until finalized, `/install` 404 afterwards.
  - Shared services used by both installers: requirements checker, atomic `.env` writer, DB connection probe, batched migration runner, idempotent seeders, finalizer (generates the `ADMIN_PATH` suffix).
  - CLI: `goproperty:setup-token`, `goproperty:install` (interactive + `--no-interaction`), `goproperty:check-installation`.
  - **Install drill in CI:** build → verify → unpack split layout → install under shared-host limits (`max_execution_time=30`, `exec`/`proc_open` disabled) on MySQL 8 and MariaDB 10.6; schema must match a plain `migrate`.
- Module skeleton (nwidart/laravel-modules) with a `Core` area: tenancy trait (`BelongsToOrganization`) + `OrganizationContext` + middleware, base enums, money helper (kobo), settings store, activity log wiring.
- Filament platform panel shell at the configurable `ADMIN_PATH` with separate `staff` guard, `platform_staff` table and path-scoped session cookie (auth-protected, empty).
- Route skeleton on one domain: `/`, `/login`, `/o/{org-slug}`, `/portal`, `/owner`, `/pay/{ref}`, `/verify/{code}`, `/{ADMIN_PATH}` ([ONBOARDING_COMPLIANCE.md § 1](ONBOARDING_COMPLIANCE.md)). Hosts and admin path read from config so they can move to subdomains later.
- Design system kickoff ([UX_DESIGN.md](UX_DESIGN.md)): Tailwind theme, app shell (sidebar nav, org switcher placeholder), blank page templates for all surfaces.
- **Design checkpoint (done):** the approved designs are `docs/design/templates/landing.html`, `login.html` and `login-2fa.html` ([UX_DESIGN.md § 5](UX_DESIGN.md)).
- **Configurable platform theme** ([PLATFORM_CONSOLE.md § 9](PLATFORM_CONSOLE.md)): theme settings → CSS variables for Tailwind 4 and Filament, default theme, draft/preview/publish; replaceable hero and login images; public website text, SEO and custom-code settings (§ 9.2–9.5).
- **Login pages built to final quality** ([UX_DESIGN.md § 3](UX_DESIGN.md)): customer split-screen login (passkey, or email/phone number + password — no SMS at sign-in), 2FA challenge screen, staff login with mandatory 2FA, forgot/reset password; rate limits, lockout, Turnstile, no account enumeration. Visual spec: `docs/design/templates/login.html`, `login-2fa.html`.
- Audit log (append-only, hash chain) and authentication/access log wired for both guards.
- Backups baseline: nightly encrypted DB and files backups as separate jobs to S3-compatible storage + Google Drive (Shared Drive).
- Staging environment provisioned **with the installer** (CLI path); deploy pipeline to staging on merge.

**Acceptance criteria**
- `composer ci` runs lint + static analysis + tests locally and in CI.
- A seeded demo user can log in and see empty shells of all three surfaces.
- Tenancy trait enforced by a Larastan rule or test that fails when a scoped model is queried without context in test setup.
- CI produces a verified release zip; the install drill passes on both databases; a fresh copy with no `APP_KEY` redirects to `/install` without touching the database or writing error logs.

### Phase 1 — Tenancy, auth, registration, Transaction Tiers & staff console core (Weeks 3–5)

**Deliverables**
- Registration wizard ([ONBOARDING_COMPLIANCE.md § 2](ONBOARDING_COMPLIANCE.md)): account → email verification by link (no SMS) → organization (type, slug) → **Transaction Tier selection** → plan + trial → collections setup → import/first property, with a progress checklist. Property-owner signup with `platform_covered` handling; first user as `org-owner`.
- Transaction Tier applications: document upload (CAC, SCUML, IDs, proof of address, beneficial owners for Tier 3), compliance review queue in the console, approve/reject/more-info, document expiry tracking.
- Organization switcher for multi-org users; per-org role assignment via spatie teams.
- Invitations (email + token): user invites with roles; invite acceptance sets password and joins org.
- Org settings: profile, bank accounts (encrypted, name-checked), **branding** ([BRANDING_DOCUMENTS.md § 1](BRANDING_DOCUMENTS.md)): logo, colours with contrast check, letterhead, signature/stamp, prefixes, notes, layout choice, live invoice/receipt preview.
- Platform console: customers list (orgs, users, statuses, tier), org suspend/reactivate actions, staff management with configurable roles (default set + custom roles from a permission catalogue) + 2FA enforcement, two-person approvals ([PLATFORM_CONSOLE.md § 1](PLATFORM_CONSOLE.md)).
- Impersonation with reason, consent setting, 30-minute limit, banner, blocked money actions, and full audit ([PLATFORM_CONSOLE.md § 6](PLATFORM_CONSOLE.md)).
- **Web installer wizard** ([INSTALLER.md § 4–5](INSTALLER.md)) on the auth-page shell: unlock by setup token **or file check** (no SSH), session-bound grant renewed per step, server check, site & database (auto-created `.env`), batched migrations with progress bar and resume, email + S3 storage tests, first super-admin with **2FA enrolled in the wizard**, finish screen (console path, detected cron line, live cron heartbeat check, installation record, next-steps checklist). The install drill walks the wizard over HTTP.

**AC**
- Two orgs created; user in both can switch; data never crosses orgs ( Pest tests probe cross-tenant access on every Phase-1 model).
- Invited user with role `agent` sees only what § 5 of ARCHITECTURE.md grants.
- A Tier 2 application with missing SCUML cannot be submitted; an approved one changes the org's tier and is audited.
- Impersonating staff cannot confirm a payment or view bank/Paystack keys (tested); the org-owner receives the session summary email.
- Visiting `/o/{slug}` of an org you don't belong to returns 404.
- Suspending an org in the console blocks its users' write actions within one request.
- Web install drill (file-check unlock, TOTP enrolment, finish) passes on MySQL 8 and MariaDB 10.6; after finish `/install` is 404 and no HTTP route can reset or re-run installation (tested).

### Phase 2 — Properties, units & owners (Weeks 6–7)

**Deliverables**
- Owner profiles (person/company), buildings CRUD with amenities/photos, units CRUD (types, rent/service defaults, status board), bulk unit creation (e.g. "10 identical flats").
- Agent↔building assignment (optional narrowing) and unit filters/lists with server-side pagination.
- Org-to-org invitation flow UI: property owner invites agency; agency sees invite inbox; **billing transfer accept screen** with explicit amount (state machine in BILLING_PAYMENTS § 7) — the accept path can be fully wired later, but the invite + accept/decline record lands now.
- Media library integration (photos, thumbnails) on S3-compatible storage with signed URLs.
- **Data import, part 1** ([DATA_IMPORT.md](DATA_IMPORT.md)): import engine (streaming reader, column mapping with saved mappings, downloadable template, **dry run** with error/warning report, queued commit, batch rollback within 7 days, `external_ref` upserts) for owners, properties, units and tenants. Excel/CSV export of every list.

**AC**
- Creating 10 units in one bulk action; unit statuses drive occupancy counts on the building page.
- Cross-org access tests pass (unit of org B not addressable by org A user via ID guessing).
- Branding settings actually render on a sample document page.
- Dry run of a 2,000-row workbook saves nothing and returns exact errors per row; commit then creates exactly the previewed records; re-importing the same file creates no duplicates; rollback removes the batch.

### Phase 3 — Leasing & the customer money loop (Weeks 8–11) — *the heart*

**Deliverables**
- Tenant profiles (with user accounts for portal access). Invitations by email, agency WhatsApp, or copy link / QR; phone verified by opening a WhatsApp-delivered link or confirmed by the agency — no SMS codes. Agency-created one-time sign-in link for phone-only tenants who forget their password.
- Lease engine: `CycleCalculator` (daily → biennial), lease CRUD with draft/reserve → active → renew/terminate, renewal referencing, deposit handling, contract PDF generation (via the PDF driver interface, branded template with signature blocks).
- Rent billing: scheduler job generating rent bills from leases using `next_billing_date`; bill numbering.
- **Branded documents** ([BRANDING_DOCUMENTS.md](BRANDING_DOCUMENTS.md)): invoice and receipt built from `docs/design/templates/`, three layouts, web + PDF views, QR verification codes, amount in words, branding snapshot per document, public `/verify/{code}` page and branded `/pay/{reference}` page.
- Utility billing: utility types, per-unit settings, flat + metered (readings) utilities, utility bill PDF.
- Payment collection ([ONBOARDING_COMPLIANCE.md § 4](ONBOARDING_COMPLIANCE.md)): org bank accounts with Paystack account-name check; unique `payment_reference` per bill; **bank statement import (CSV/XLSX) with matching** and one-tap/bulk confirm; **agency-owned Paystack connection** (encrypted keys, per-org webhook, verify-by-fetch, auto-confirm, online-payment cap); proof upload fallback with duplicate-image detection; cash/POS with direct-to-tenant receipt.
- Payments loop: payment allocations across bills, tenant credit for excess, confirm/reject with reason, receipt PDF with sequential numbering and QR verification code, receipt void with second approver, notifications on every transition.
- Tenant portal: my units, my bills, pay-instructions, upload proof, receipts, documents.
- Expenses recording (category, property/unit linkage, receipt upload) — minimal now, reporting in Phase 8.
- **Data import, part 2:** ongoing leases with **paid until** (no back-billing), opening balances (as `opening_balance` bills), tenant credits, deposits held, utility settings, optional payment history; tenant invitations only on request after review.

**AC**
- End-to-end integration test: create building → 3 units → tenant → lease (annual) → scheduler generates invoice → payment + proof upload → confirmation → receipt number sequence increments → tenant portal shows receipt. Also weekly and semi-annual cycles.
- `CycleCalculator` property-based tests: period end always = start + cycle − 1 day across leap years and month-length edges.
- Overpayment beyond bill balance goes to tenant credit and is applied to the next bill; voided bills accept nothing.
- Invoice and receipt render identically (visual diff) with `dompdf` and Chrome drivers, on one A4 page for up to 8 line items.
- Importing a lease that started in 2023 with "paid until" 31/12/2026 creates no past bills, and the first generated bill covers the period starting 01/01/2027; no reminders are sent for past dates.
- Concurrent confirmations of the same bill cannot over-settle (locked transaction test).
- Statement import: re-uploading the same statement creates no duplicate lines; a credit with the bill reference is matched with high confidence; one credit split across two bills settles both.
- Agency Paystack webhook signed with the wrong org's key is rejected; replayed webhook creates one payment.

### Phase 4 — Maintenance tickets (Week 12)

**Deliverables**
- Ticket lifecycle (draft → submitted → under_review → in_progress → resolved → closed, reopen), categories, priority, assignment, threaded replies with attachments, tenant "confirm resolution / reopen", status history.
- Agent + tenant views (inbox by status), notification on transitions, ticket from lease context.

**AC**
- Tenant can reopen a resolved ticket; staff see internal notes the tenant never sees (enforced server-side).
- Status transitions invalid per state machine are rejected (tested).

### Phase 5 — Reminders, notifications, email templates & WhatsApp (Weeks 14–15)

**Deliverables**
- Reminder engine (`reminders:dispatch`) with dedupe keys; rent/utilities/lease-renewal ladders; overdue ladder.
- SMS via Termii (bill reminders only, to verified/agency-confirmed numbers — no OTP); per-user channel preferences; `notification_deliveries` log with provider refs and cost.
- Email template system ([PLATFORM_CONSOLE.md § 5](PLATFORM_CONSOLE.md)): database templates with whitelisted placeholders (never Blade), versions + rollback, live preview, test send, categories, org branding, bounce/complaint suppression.
- Platform console: delivery health dashboard (failure rates, SMS spend).
- SMS credits per plan (monthly allowance, in-app top-up, ledger).
- **WhatsApp (optional, agency's own Business API)** ([ARCHITECTURE.md § 6.7](ARCHITECTURE.md)): connection settings, template sync from Meta, event → template mapping with variable mapping and preview, sending via Cloud API, status webhooks, tenant opt-in, fallback to SMS/email, per-event channel order.

**AC**
- Time-travel tests (Carbon::setTestNow) prove exact send days and zero duplicates across reruns.
- SMS template stays under 160 chars; failed provider sends retry with backoff then surface in the health dashboard.
- A WhatsApp template paused by Meta disables its mapping on next sync and that event falls back to SMS; a status webhook with a bad signature is rejected.

### Phase 6 — SaaS billing, compliance monitoring & full platform console (Weeks 16–18)

**Deliverables**
- Plans with launch prices and feature matrix ([BILLING_PAYMENTS.md § 1](BILLING_PAYMENTS.md)), editable in the console with versioning and 30-day customer notice; plan features + limits, trial, subscription lifecycle states and gating middleware.
- Monthly metering + platform invoice generation + VAT line; Paystack integration (checkout, stored authorization, auto-charge, webhooks with verify-by-fetch, webhook log + replay); bank transfer proof upload + platform-staff confirmation flow; dunning ladder + suspension gating.
- Billing responsibility transfer completion (switch payer on acceptance; new payer payment-method enrolment).
- Platform console full build: customers, subscriptions, invoices, payments (Paystack + manual confirmations), refunds, feature overrides, staff audit, audit-log and access-log viewers.
- Tier settings screen: editable tier limits and documents (two-person approval, effective dates, versions), per-organization overrides.
- Transaction Tier monitoring ([ONBOARDING_COMPLIANCE.md § 5.4–5.5](ONBOARDING_COMPLIANCE.md)): daily `compliance:evaluate-tiers`, volume snapshots, flags (80%/100%/single-over/cash threshold/structuring/spike/document expiry), upgrade prompts, 30-day grace, restriction, compliance flag queue.
- Backups console ([PLATFORM_CONSOLE.md § 4](PLATFORM_CONSOLE.md)): destinations, run now, list/download, verify, restore to staging, production restore with two-person approval.
- Marketing/pricing page + plan checkout for new orgs.

**AC**
- Paystack sandbox end-to-end: invoice → charge → webhook → paid → active; failed charge → retry ladder → past_due → grace → suspended (write-blocked) → pay → reactivated.
- Webhook replay (same event twice) produces one payment record; forged-signature webhook is dropped and logged.
- Accepting a billing transfer in UI moves `payer_organization_id` and next invoice routes to the new payer.
- Suspension blocks every write surface (spot-check list of 10 actions in tests).
- Time-travel test: an org crossing 80% and 100% of its tier gets exactly one flag each; after 30 days without an application it becomes restricted, yet tenants can still view bills, upload proofs and receive receipts.
- Backup verify restores the latest dump into a temporary database successfully; production restore cannot start without a second approver.

### Phase 7 — Building Group (Week 19)

**Deliverables**
- Per-building group (auto-created), membership sync from leases, posts/comments/reactions, rich text (limited toolbar, sanitized), images (validated + thumbnails), polling updates (shared hosting) with Reverb realtime on VPS, moderation (pin/hide/mute), report-abuse.

**AC**
- Two browser sessions see a post/comment/reaction within 30 s by polling (shared-hosting profile) and within 1 s via Reverb (VPS profile).
- XSS suite: script/iframe/onload payloads in rich text are neutralized; uploads of disallowed types rejected.
- Hidden/removed posts vanish for members but remain for moderators with an audit trail.

### Phase 8 — Reports, operations & analytics dashboards (Week 20)

**Deliverables**
- Customer app dashboards: occupancy, collections vs billed, arrears aging, upcoming renewals, expenses by category, per-building performance; date-range filters; CSV + PDF export.
- System Analytics dashboard ([PLATFORM_CONSOLE.md § 3](PLATFORM_CONSOLE.md)): MRR/ARR, signups and trial conversion, churn, unit growth, tier distribution, recorded collections by channel, customer leaderboard — from nightly `daily_platform_metrics`.
- System Operations dashboard ([PLATFORM_CONSOLE.md § 2](PLATFORM_CONSOLE.md)): queues, scheduler heartbeat, webhooks (own + per agency), messaging, backups, storage, security; alerts to Slack/email.
- Cached aggregates for dashboards; report queries documented for the read-replica config.

**AC**
- Arrears aging buckets (0–30/31–60/61–90/90+) reconcile against a hand-computed fixture org.
- Every report has a feature test with seeded edge data (voided bills excluded, refunds netted).

### Phase 9 — Hardening, NDPA & launch (Weeks 21–23)

**Deliverables**
- Security review: policy coverage sweep, rate-limit audit, upload validation audit, dependency audit, session/2FA checks; external pen-test if budget allows.
- NDPA 2023 + GAID 2025: data protection officer named, breach response plan (72-hour notification), consent records, data-export tooling (per tenant/org), erasure request workflow with 90-day retention enforcement, privacy policy + terms pages.
- Load test (k6) on hot paths: dashboard, bills list, group feed; fix N+1/cache gaps.
- Backup + restore drill on staging from production-like data; runbook written (`docs/runbooks/`).
- Seed production demo org; monitoring/alerting wired (Sentry → Slack, queue-depth alerts); launch checklist executed.
- **Installer & upgrades, final:** console **System → Version** page (current/latest version, pending migrations, last backup; read-only — no in-app code download), authenticated batched "run pending migrations" step for no-SSH upgrades, upgrade drill in CI (previous release → new), runbooks `docs/runbooks/install.md`, `install-recovery.md`, `upgrade.md`, and the owner's install guide with SSH and no-SSH variants for cPanel and Plesk.

**AC**
- Restore drill achieves stated RPO/RTO (§ 11 ARCHITECTURE.md) and the runbook is followed verbatim by someone other than the author.
- Launch checklist (below) fully ticked.
- Real installs on a cPanel and a Plesk account (one with SSH, one without) follow the owner's guide word for word; one zip upgrade + rollback rehearsed on staging.

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
| R11 | Staff misuse of impersonation or backups | Privacy breach | Consent setting, reason required, read-only default, banner, owner email summary, two-person rules, signed expiring backup downloads |
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

1. Confirm the open decisions in [BILLING_PAYMENTS.md § 10](BILLING_PAYMENTS.md) and the follow-up questions raised in the handoff notes (pricing bands, SMS provider choice, hosting budget).
2. Brand is a setting, not a blocker: start with the default theme and change it in the console later ([PLATFORM_CONSOLE.md § 9](PLATFORM_CONSOLE.md)). The SocietyFlow-style design reference is requested from the product owner at the Phase 0 design checkpoint.
3. Get a written legal opinion on the no-funds-flow model and confirm the agency-owned-Paystack setup with Paystack. (Tier limits are set and editable — [ONBOARDING_COMPLIANCE.md § 5.2](ONBOARDING_COMPLIANCE.md).)
4. Provision accounts: Paystack (test), Termii (test), object storage (live media + separate backup provider), Google Workspace Shared Drive for backups, Cloudflare (Turnstile), Sentry; register `goproperty.ng` and choose hosting: a shared host meeting the requirements in [ARCHITECTURE.md § 12.1](ARCHITECTURE.md) (PHP 8.4, MySQL 8 or MariaDB 10.6+, cron every minute; SSH helpful but not required — [INSTALLER.md](INSTALLER.md)), or a small VPS with Forge/Ploi. Hosting decision: **cPanel or Plesk shared hosting** — check the host against [docs/runbooks/shared-hosting.md § 1](docs/runbooks/shared-hosting.md) before buying. (`ADMIN_PATH` is generated by the installer — [INSTALLER.md § 5](INSTALLER.md).)
5. Kick off Phase 0; use [AGENT.md](AGENT.md) as the working contract for anyone (human or AI) writing code.
