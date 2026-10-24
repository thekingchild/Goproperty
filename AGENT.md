# AGENT.md — Working Rules for AI Coding Agents

This file is the operating contract for AI coding agents (and any contributor) working in the GoProperty repository. Read it before your first task; re-read it if a task touches money, tenancy, or notifications.

Baseline docs, in order: [README.md](README.md) → [ARCHITECTURE.md](ARCHITECTURE.md) → [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md) → [BILLING_PAYMENTS.md](BILLING_PAYMENTS.md) → [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md). Where a task contradicts these documents, **stop and flag the contradiction** — do not silently improvise a third design.

## 1. Project Snapshot

- **What:** GoProperty — multi-tenant property-management SaaS (Nigeria-first): buildings → units → tenants, leases, rent/utility billing with payment-proof confirmation, maintenance tickets, Building Group discussions, and a platform-owner console that bills customers per unit via Paystack or bank transfer.
- **Stack:** Laravel 13, PHP 8.4+, MySQL 8, Redis, Livewire 3 + Volt (customer/tenant surfaces), Filament 4 (platform console), Laravel Reverb (realtime), nwidart/laravel-modules (modular monolith).
- **Money unit:** integer **kobo**, always (`*_kobo` columns). Naira only.

## 2. Environment & Commands

Assume Laravel Sail locally unless told otherwise.

```bash
# Environment
composer install && npm install
sail up -d                      # or: php artisan serve with local MySQL/Redis
sail artisan migrate --seed     # seeded demo org/building/lease data

# Quality gate — run ALL of these before declaring any task done
sail pint --dirty               # or: vendor/bin/pint
sail artisan test --parallel    # Pest
vendor/bin/phpstan              # Larastan, level 6 minimum

# Long-running processes (dev)
sail artisan queue:listen --queue=default,notifications,billing,pdf
sail artisan schedule:work
sail artisan reverb:start

# Modules (nwidart)
php artisan module:make <Name>
php artisan module:migrate <Name>
```

CI runs `pint --test`, `phpstan`, and `pest --parallel` on every PR. Never merge red.

## 3. Non-Negotiable Invariants

Breaking any of these is a critical defect regardless of task size:

1. **Tenancy.** Every customer-domain model uses `BelongsToTenant`. Never query tenant data without `TenantContext` set; never remove/disable a global scope without a platform-guarded, documented reason. Platform-side cross-org reads live only in `Modules\Platform` (or `App\Platform` pre-extraction).
2. **Money.** Kobo integers only. No floats, no floatval, no `->amount / 100` arithmetic outside the money helper. All money math goes through `App\Support\Money` (or the module equivalent). Currency is NGN.
3. **Cycle math.** All lease period arithmetic goes through `Modules\Leasing\CycleCalculator`. Never hand-roll date intervals.
4. **Authorization.** Every controller action, Livewire component, and Filament resource checks a Policy. Hiding UI is never the security control.
5. **State machines.** Lease, ticket, payment, subscription, and billing-transfer states change only through their service classes (`LeaseService`, `TicketService`, `PaymentService`, `SubscriptionService`, `BillingTransferService`) — never by mass-assignment or direct attribute writes from controllers/jobs.
6. **Idempotency.** Webhook handlers, reminder dispatch, bill generation, and metering jobs must be safe to re-run (unique keys / locks / first-or-create by natural key). New queued job ⇒ think replay.
7. **No data destruction.** No hard deletes of org, tenant, bill, payment, or subscription data. Soft deletes + status transitions only. Purge paths exist solely in the NDPR erasure tooling.
8. **Migrations.** Append-only. Never edit a merged migration; write a new one. Every migration reversible (`down()`), and every new table with tenant rows carries `organization_id` + composite indexes per DATABASE_SCHEMA.md.

## 4. Architecture Rules

- **Modules:** new bounded features are new modules (`php artisan module:make`). A module's models/services/policies are private — cross-module access goes through the module's `Contracts/` interface or domain events (`PaymentConfirmed`, `LeaseActivated`, `TicketReplied`, …). No cross-module Eloquent queries or table joins.
- **Core stays thin:** tenancy, auth, users, orgs, settings, money, documents, shared UI. If you're adding domain logic to Core, it probably belongs in a module.
- **Surfaces:** `/app` and `/portal` are Livewire 3 (+ Volt) — do not introduce Vue/React there. `/platform` is Filament 4. Marketing pages are plain Blade + Livewire forms.
- **Events over hooks:** subsystems react to domain events; don't have Billing reach into Tickets internals, etc.
- **Queues:** everything slow (PDF, mail, SMS, webhooks processing, metering) is queued with the right queue name (`default`, `notifications`, `billing`, `pdf`) and a retry/backoff config.
- **Documents:** generated via Browsershot + Blade templates; numbering via `document_counters` inside a transaction. Never `max(id)+1`.

## 5. Code Conventions

- PHP 8.4: `declare(strict_types=1)`, constructor property promotion, readonly where sensible, enums for every status set, named arguments where they aid clarity.
- Naming: `Action`/`Service` classes for business operations; form objects/requests for validation; Livewire components in `App\Livewire\<Area>\...`; Filament resources under the platform module only.
- Validation: Form Requests for controllers, `rules()` methods for Livewire; validate every upload (mime sniff, size caps); never trust client-visible-only constraints.
- Tests: Pest. Feature tests for anything touching money, tenancy, or state machines — including the negative paths (unauthorized role, cross-tenant ID, replayed webhook, over-settled bill).
- No PII in logs. Bank account numbers and IDs are encrypted casts. Log channels get org/user context via Sentry tags, not message contents.
- Comments explain constraints (why), not mechanics (what). If a doc in this repo constrains the code, reference the doc section, e.g. `// See BILLING_PAYMENTS.md § 6: acceptance moves the payer`.

## 6. Task Workflow

1. **Locate the phase** the task belongs to in [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md); if the task isn't covered by a phase or module in the docs, say so before coding.
2. **Plan the shape** (models/migrations/module/events/policies) in one short paragraph in the PR description before writing code for anything non-trivial.
3. **Implement** following § 3–5. Small commits, imperative subjects (`Add payment confirmation policy`).
4. **Verify:** full quality gate (§ 2), plus manual smoke of affected journeys if UI changed.
5. **Update docs:** any change to schema, billing semantics, or architecture gets a same-PR edit to the relevant `.md` file. Docs drift is treated as a bug.
6. **Definition of done:** lint clean, static analysis clean, tests green including new coverage, docs updated, no TODO left in money/tenancy code (TODOs elsewhere require a tracking comment).

## 7. Absolute Prohibitions

- Never commit `.env`, keys, or production credentials; never put secrets in code or fixtures.
- Never disable CSRF, policy checks, rate limits, or upload validation to "make a test pass".
- Never send real SMS/email in non-production (provider calls guarded by environment check with a hardcoded env-name list).
- Never run destructive artisan commands against staging/production (`migrate:fresh`, `db:wipe`) without explicit human instruction for that environment.
- Never widen a Filament panel, route group, or CORS policy "temporarily".
- Never log or expose payment references + amounts together in client-facing responses beyond what the feature requires.

## 8. When Unsure

- If a rule here conflicts with a doc, the more specific doc wins; if two docs conflict, flag it.
- If a change would alter money semantics, tenancy enforcement, or a state machine and the docs don't explicitly sanction it — present the proposal and wait. These three areas have no "ask forgiveness" mode.
