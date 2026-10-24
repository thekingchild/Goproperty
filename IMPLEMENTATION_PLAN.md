# GoProperty — Implementation Plan

Companion documents: [ARCHITECTURE.md](ARCHITECTURE.md) · [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md) · [BILLING_PAYMENTS.md](BILLING_PAYMENTS.md) · [AGENT.md](AGENT.md)

## 1. Team & Timeline Assumptions

| Item | Assumption |
| --- | --- |
| Team | 2 full-stack developers + 1 UI/UX designer (part-time after Phase 1) + product owner (you) |
| Working cadence | 2-week sprints, demo at sprint end |
| Total to launch | ~18 weeks (9 sprints) for the MVP described here |
| Solo-dev scenario | Double every phase duration ≈ 9 months |
| Sequencing principle | Money paths (tenancy → leasing → bills → receipts → SaaS billing) are built early and hardened continuously; collaboration features (Building Group) come last so they can't destabilize billing |

Each phase below lists deliverables and acceptance criteria (AC). A phase is done when all ACs pass on staging, not when code is pushed.

## 2. Phase Overview

| Phase | Name | Weeks | Milestone |
| --- | --- | --- | --- |
| 0 | Foundation & tooling | 1–2 | M0 |
| 1 | Tenancy, auth, orgs & invitations | 3–4 | M1 |
| 2 | Properties, units & owners | 5–6 | M2 |
| 3 | Leasing & the customer money loop | 7–9 | M3 |
| 4 | Maintenance tickets | 10 | M4 |
| 5 | Reminders & notifications | 11 | M5 |
| 6 | SaaS billing & platform console | 12–14 | M6 |
| 7 | Building Group | 15 | M7 |
| 8 | Reports & dashboards | 16 | M8 |
| 9 | Hardening, NDPR & launch | 17–18 | M9 (Launch) |

## 3. Phase Details

### Phase 0 — Foundation & tooling (Weeks 1–2)

**Deliverables**
- Laravel 13 project (Livewire starter kit), PHP 8.4, MySQL 8, Redis; Sail for local dev.
- CI: GitHub Actions running Pint, Larastan (level 6), Pest; branch protection on `main`.
- Module skeleton (nwidart/laravel-modules) with a `Core` area: tenancy trait + `TenantContext` + middleware, base enums, money helper (kobo), settings store, activity log wiring.
- Filament 4 platform panel shell (auth-protected, empty).
- Design system kickoff: Tailwind theme, app shell (sidebar nav, org switcher placeholder), blank page templates for `/app`, `/portal`, `/platform`.
- Staging environment provisioned; deploy pipeline to staging on merge.

**Acceptance criteria**
- `composer ci` runs lint + static analysis + tests locally and in CI.
- A seeded demo user can log in and see empty shells of all three surfaces.
- Tenancy trait enforced by a Larastan rule or test that fails when a scoped model is queried without context in test setup.

### Phase 1 — Tenancy, auth, orgs & invitations (Weeks 3–4)

**Deliverables**
- Signup flows: agency signup, property-owner signup (with `platform_covered` handling), org profile (CAC optional), first user as `org-owner`.
- Organization switcher for multi-org users; per-org role assignment via spatie teams.
- Invitations (email + token): user invites with roles; invite acceptance sets password and joins org.
- Org settings: profile, bank accounts (encrypted), document branding (logo, colors, invoice prefix/footer).
- Platform console: customers list (orgs, users, statuses), org suspend/reactivate actions, staff management with the four platform roles + 2FA enforcement.

**AC**
- Two orgs created; user in both can switch; data never crosses orgs ( Pest tests probe cross-tenant access on every Phase-1 model).
- Invited user with role `agent` sees only what § 5 of ARCHITECTURE.md grants.
- Suspending an org in the console blocks its users' write actions within one request.

### Phase 2 — Properties, units & owners (Weeks 5–6)

**Deliverables**
- Owner profiles (person/company), buildings CRUD with amenities/photos, units CRUD (types, rent/service defaults, status board), bulk unit creation (e.g. "10 identical flats").
- Agent↔building assignment (optional narrowing) and unit filters/lists with server-side pagination.
- Org-to-org invitation flow UI: property owner invites agency; agency sees invite inbox; **billing transfer accept screen** with explicit amount (state machine in BILLING_PAYMENTS § 7) — the accept path can be fully wired later, but the invite + accept/decline record lands now.
- Media library integration (photos, thumbnails) on S3-compatible storage with signed URLs.

**AC**
- Creating 10 units in one bulk action; unit statuses drive occupancy counts on the building page.
- Cross-org access tests pass (unit of org B not addressable by org A user via ID guessing).
- Branding settings actually render on a sample document page.

### Phase 3 — Leasing & the customer money loop (Weeks 7–9) — *the heart*

**Deliverables**
- Tenant profiles (with user accounts for portal access, phone OTP verification).
- Lease engine: `CycleCalculator` (daily → biennial), lease CRUD with draft/reserve → active → renew/terminate, renewal referencing, deposit handling, contract PDF generation (Browsershot, branded template with signature blocks).
- Rent billing: scheduler job generating rent bills from leases; bill numbering; invoice PDF (bank details on it).
- Utility billing: utility types, per-unit settings, flat + metered (readings) utilities, utility bill PDF.
- Payments loop: record payment (agent or tenant), proof upload from portal, confirm/reject with reason, receipt PDF with sequential numbering, notifications on every transition.
- Tenant portal: my units, my bills, pay-instructions, upload proof, receipts, documents.
- Expenses recording (category, property/unit linkage, receipt upload) — minimal now, reporting in Phase 8.

**AC**
- End-to-end integration test: create building → 3 units → tenant → lease (annual) → scheduler generates invoice → payment + proof upload → confirmation → receipt number sequence increments → tenant portal shows receipt. Also weekly and semi-annual cycles.
- `CycleCalculator` property-based tests: period end always = start + cycle − 1 day across leap years and month-length edges.
- Overpayment beyond bill balance is rejected with a clear message; voided bills accept nothing.
- Concurrent confirmations of the same bill cannot over-settle (locked transaction test).

### Phase 4 — Maintenance tickets (Week 10)

**Deliverables**
- Ticket lifecycle (draft → submitted → under_review → in_progress → resolved → closed, reopen), categories, priority, assignment, threaded replies with attachments, tenant "confirm resolution / reopen", status history.
- Agent + tenant views (inbox by status), notification on transitions, ticket from lease context.

**AC**
- Tenant can reopen a resolved ticket; staff see internal notes the tenant never sees (enforced server-side).
- Status transitions invalid per state machine are rejected (tested).

### Phase 5 — Reminders & notifications (Week 11)

**Deliverables**
- Reminder engine (`reminders:dispatch`) with dedupe keys; rent/utilities/lease-renewal ladders; overdue ladder.
- SMS via Termii (tenant OTP + bill reminders), email templates branded per org; per-user channel preferences; `notification_deliveries` log with provider refs and cost.
- Platform console: delivery health dashboard (failure rates, SMS spend).

**AC**
- Time-travel tests (Carbon::setTestNow) prove exact send days and zero duplicates across reruns.
- SMS template stays under 160 chars; failed provider sends retry with backoff then surface in the health dashboard.

### Phase 6 — SaaS billing & platform console (Weeks 12–14)

**Deliverables**
- Plans, plan features + limits, trial, subscription lifecycle states and gating middleware.
- Monthly metering + platform invoice generation + VAT line; Paystack integration (checkout, stored authorization, auto-charge, webhooks with verify-by-fetch, webhook log + replay); bank transfer proof upload + platform-staff confirmation flow; dunning ladder + suspension gating.
- Billing responsibility transfer completion (switch payer on acceptance; new payer payment-method enrolment).
- Platform console full build: customers, subscriptions, invoices, payments (Paystack + manual confirmations), refunds, feature overrides, staff audit.
- Marketing/pricing page + plan checkout for new orgs.

**AC**
- Paystack sandbox end-to-end: invoice → charge → webhook → paid → active; failed charge → retry ladder → past_due → grace → suspended (write-blocked) → pay → reactivated.
- Webhook replay (same event twice) produces one payment record; forged-signature webhook is dropped and logged.
- Accepting a billing transfer in UI moves `payer_organization_id` and next invoice routes to the new payer.
- Suspension blocks every write surface (spot-check list of 10 actions in tests).

### Phase 7 — Building Group (Week 15)

**Deliverables**
- Per-building group (auto-created), membership sync from leases, posts/comments/reactions, rich text (limited toolbar, sanitized), images (validated + thumbnails), Reverb realtime with polling fallback, moderation (pin/hide/mute), report-abuse.

**AC**
- Two browser sessions see a post/comment/react within 1s via Reverb; polling path works with WebSockets disabled.
- XSS suite: script/iframe/onload payloads in rich text are neutralized; uploads of disallowed types rejected.
- Hidden/removed posts vanish for members but remain for moderators with an audit trail.

### Phase 8 — Reports & dashboards (Week 16)

**Deliverables**
- Customer app dashboards: occupancy, collections vs billed, arrears aging, upcoming renewals, expenses by category, per-building performance; date-range filters; CSV + PDF export.
- Platform console analytics: MRR/ARR, unit growth, churn, collection rate, customer performance leaderboard, aggregate sales/expenses across customers.
- Cached aggregates for dashboards; report queries documented for the read-replica config.

**AC**
- Arrears aging buckets (0–30/31–60/61–90/90+) reconcile against a hand-computed fixture org.
- Every report has a feature test with seeded edge data (voided bills excluded, refunds netted).

### Phase 9 — Hardening, NDPR & launch (Weeks 17–18)

**Deliverables**
- Security review: policy coverage sweep, rate-limit audit, upload validation audit, dependency audit, session/2FA checks; external pen-test if budget allows.
- NDPR: consent records, data-export tooling (per tenant/org), erasure request workflow with 90-day retention enforcement, privacy policy + terms pages.
- Load test (k6) on hot paths: dashboard, bills list, group feed; fix N+1/cache gaps.
- Backup + restore drill on staging from production-like data; runbook written (`docs/runbooks/`).
- Seed production demo org; monitoring/alerting wired (Sentry → Slack, queue-depth alerts); launch checklist executed.

**AC**
- Restore drill achieves stated RPO/RTO (§ 11 ARCHITECTURE.md) and the runbook is followed verbatim by someone other than the author.
- Launch checklist (below) fully ticked.

## 4. Launch Checklist

- [ ] Production env vars audited (no test keys, mail/SMS senders verified)
- [ ] Paystack live keys + webhook live-verified; test charge + refund executed live with a small amount
- [ ] Termii live sender ID approved; DND-template path verified
- [ ] Scheduled jobs visible in production (Horizon + scheduler heartbeat alert)
- [ ] Sentry environment tags correct; alerts routed
- [ ] Backups running 3 consecutive nights + one restore verified
- [ ] Legal pages live; NDPR consent at signup
- [ ] VAT line matches company's registration status (BILLING_PAYMENTS § 10)
- [ ] Support inbox + escalation runbook live
- [ ] Smoke-test script green on production

## 5. Testing Strategy

- **Pest feature tests are the backbone.** Money paths (leasing, bills, payments, receipts, SaaS billing) require end-to-end coverage before merge — no exceptions.
- Unit tests for pure logic (`CycleCalculator`, pricing math, dunning ladder); time-travel via Carbon for all scheduler logic.
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
| R5 | Laravel 13/package ecosystem drift (Filament/Reverb versions) | Upgrade churn mid-build | Pin versions in Phase 0; upgrade only between phases; CI on latest stable |
| R6 | Scope creep (expenses accounting, owner payouts demanded pre-launch) | Timeline slip | Backlog discipline: § 8 post-launch modules; platform owner sign-off per sprint scope |
| R7 | Single dev + complex money logic | Key-person risk | Money paths pair-tested, docs-as-code in this repo, money invariants in tests |
| R8 | Low-end devices/2G users abandon portal | Adoption | Livewire server-rendered UI, image caps, polling fallback, performance budget in CI (Lighthouse check on portal pages) |

## 7. Post-Launch Module Backlog (expansion via the module system)

Ordered by expected demand in the Nigerian market:

1. **DVA auto-matching** for platform billing (Paystack dedicated virtual accounts).
2. **Late fees & penalties** module — configurable grace + percentage, auto bill line, legal-notice templates (7-day quit notice generation for agencies).
3. **Property owner payouts & statements** — owner statements, agency commission tracking, payout approvals.
4. **Tenant online payments** — Paystack checkout for rent/utilities from the tenant portal (currently transfer-first by design; this becomes an org-level paid add-on).
5. **E-signatures** on lease contracts (tenant signs in portal).
6. **WhatsApp notifications bot** (Termii/360dialog) — reminder + ticket updates where tenants live.
7. **Vacancy marketplace / public listings** — public unit listing pages with enquiry forms.
8. **Mobile app (API module)** — Sanctum API + tenant-facing app.
9. **Insurance/agency fees module**, multi-currency, FRSC/tenant-scoring style add-ons as the market demands.

## 8. Immediate Next Steps (this week)

1. Confirm the open decisions in [BILLING_PAYMENTS.md § 10](BILLING_PAYMENTS.md) and the follow-up questions raised in the handoff notes (pricing bands, SMS provider choice, hosting budget).
2. Finalize brand (name check "GoProperty", logo, color system) — Phase 0 design work depends on it.
3. Provision accounts: Paystack (test), Termii (test), object storage, Sentry, Forge/Ploi.
4. Kick off Phase 0; use [AGENT.md](AGENT.md) as the working contract for anyone (human or AI) writing code.
