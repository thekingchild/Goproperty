# AGENT.md — Working Rules for AI Coding Agents

This file is the operating contract for AI coding agents (and any contributor) working in the GoProperty repository. Read it before your first task; re-read it if a task touches money, tenancy, or notifications.

Baseline docs, in order: [README.md](README.md) → [ARCHITECTURE.md](ARCHITECTURE.md) → [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md) → [BILLING_PAYMENTS.md](BILLING_PAYMENTS.md) → [ONBOARDING_COMPLIANCE.md](ONBOARDING_COMPLIANCE.md) → [PLATFORM_CONSOLE.md](PLATFORM_CONSOLE.md) → [UX_DESIGN.md](UX_DESIGN.md) → [BRANDING_DOCUMENTS.md](BRANDING_DOCUMENTS.md) → [DATA_IMPORT.md](DATA_IMPORT.md) → [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md). Where a task contradicts these documents, **stop and flag the contradiction** — do not silently improvise a third design.

## 1. Project Snapshot

- **What:** GoProperty — multi-tenant property-management SaaS (Nigeria-first): buildings → units → tenants, leases, rent/utility billing with payment-proof confirmation, maintenance tickets, Building Group discussions, and a platform-owner console that bills customers per unit via Paystack or bank transfer.
- **Stack:** Laravel 13, PHP 8.4+, MySQL 8 **or MariaDB 10.6+** (shared hosts often use MariaDB — avoid database features only one of them supports), Livewire 4 (customer/tenant surfaces; built-in single-file components, no Volt), Filament 5 (platform console), Pest 4, nwidart/laravel-modules (modular monolith). Redis, Horizon, Reverb and headless Chrome are **optional VPS add-ons** — the app must run on shared hosting (ARCHITECTURE.md § 12.1).
- **Naming:** "tenant" in code always means a renter (`TenantProfile`). Multi-tenancy uses "organization": `OrganizationContext`, `BelongsToOrganization`, `SetOrganizationContext`.
- **Money unit:** integer **kobo**, always (`*_kobo` columns). Naira only.

## 2. Environment & Commands

Assume Laravel Sail locally unless told otherwise.

```bash
# Environment
composer install && npm install
sail up -d                      # or: php artisan serve with local MySQL
sail artisan migrate --seed     # seeded demo data; on a local copy also marks the install finished (else every page says "being set up")

# Quality gate — must pass before declaring any task done
sail composer ci                # Pint --test + Larastan (level 7; 6 minimum) + Pest --parallel
sail composer lint              # fix code style (Pint)
sail composer test              # Pest only

# Long-running processes (dev)
sail artisan schedule:work        # scheduler + the in-process queue tick (shared profile), as cron does on the host
sail artisan queue:listen --queue=billing,notifications,default,pdf,media,imports   # optional: runs jobs at once instead of each minute
sail artisan reverb:start         # optional: only when testing VPS-profile realtime
sail artisan goproperty:hosting   # hosting profile, drivers, heartbeats; fails on inconsistent settings

# Release package (docs/runbooks/release.md): tag vX.Y.Z to build and publish in CI
scripts/release/build-package.sh 1.4.0-dev.1 dist     # build the zip locally
scripts/release/verify-drill.sh dist/goproperty-1.4.0-dev.1.zip
php artisan goproperty:verify-package <zip|folder>     # no argument: this installation

# Installer (INSTALLER.md § 7, docs/runbooks/install-recovery.md)
php artisan goproperty:install                         # command-line install (interactive or --no-interaction)
# web install: open /install, unlock with goproperty:setup-token or the file check (storage/app/install-unlock.txt)
php artisan goproperty:check-installation              # health of an installed site; exits 1 on failure
scripts/release/install-drill.sh dist/goproperty-1.4.0-dev.1.zip   # needs DB_* for an account that can create databases

# Modules (nwidart)
php artisan module:make <Name>   # then composer dump-autoload && composer lint (ARCHITECTURE.md § 8: what to keep)
php artisan module:migrate <Name>
```

CI runs `composer ci` on every push, once each on MySQL 8.0/8.4 and MariaDB 10.6/11.4 ([docs/runbooks/ci.md](docs/runbooks/ci.md)). Never merge red.

## 3. Non-Negotiable Invariants

Breaking any of these is a critical defect regardless of task size:

1. **Tenancy.** Every customer-domain model uses `BelongsToOrganization` (queries without an `OrganizationContext` throw; Larastan rejects removing `OrganizationScope` outside the Platform namespace). Never query organization data without `OrganizationContext` set; never remove/disable a global scope without a platform-guarded, documented reason. Platform-side cross-org reads live only in `Modules\Platform` (or `App\Platform` pre-extraction).
2. **Money.** Kobo integers only. No floats, no floatval, no `->amount / 100` arithmetic outside the money helper. All money math goes through `App\Support\Money` (or the module equivalent). Currency is NGN.
3. **Cycle math.** All lease period arithmetic goes through `Modules\Leasing\CycleCalculator`. Never hand-roll date intervals.
4. **Authorization.** Every controller action, Livewire component, and Filament resource checks a Policy. Hiding UI is never the security control.
5. **State machines.** Lease, ticket, payment, subscription, and management-handover states change only through their service classes (`LeaseService`, `TicketService`, `PaymentService`, `SubscriptionService`, the handover service that replaces `BillingTransferService` in P03-14) — never by mass-assignment or direct attribute writes from controllers/jobs.
6. **Idempotency.** Webhook handlers, reminder dispatch, bill generation, and metering jobs must be safe to re-run (unique keys / locks / first-or-create by natural key). New queued job ⇒ think replay.
7. **No data destruction.** No hard deletes of org, tenant, bill, payment, or subscription data, nor of **evidence files** (payment proofs, receipts, signed leases and papers, condition and inspection photos, ticket attachments, issued PDFs). Soft deletes + status transitions only; removing an evidence file archives it. Purge paths exist solely in the NDPA erasure tooling. Marketing attachments (building, unit and Building Group photos) may be deleted for good, confirmed and audited ([ARCHITECTURE.md § 9](ARCHITECTURE.md) "Attachments vs evidence").
8. **No funds flow.** GoProperty never receives, holds, splits or routes tenant money. Tenant payments go to the organization's own bank account or the organization's own Paystack account. The only Paystack account GoProperty owns is for its own subscription income. See ONBOARDING_COMPLIANCE.md § 0.
9. **Impersonation limits.** While a staff member impersonates a user, money actions, bank/Paystack settings, billing transfers and management handovers, credential changes, membership changes and deletes are blocked by policy, and every action is audited with both identities. See PLATFORM_CONSOLE.md § 6.
10. **Templates are data, not code.** Database-stored email/SMS templates are rendered with whitelisted placeholders only — never through Blade or `eval`.
11. **Migrations.** Append-only. Never edit a merged migration; write a new one. Every migration reversible (`down()`), safe to run on an empty database, and doing one schema change where possible (the installer resumes from the `migrations` table and the CI install drill must pass — [INSTALLER.md § 6](INSTALLER.md)). Every new table with tenant rows carries `organization_id` + composite indexes per DATABASE_SCHEMA.md.

## 4. Architecture Rules

- **Modules:** new bounded features are new modules (`php artisan module:make`). A module's models/services/policies are private — cross-module access goes through the module's `Contracts/` interface or domain events (`PaymentConfirmed`, `LeaseActivated`, `TicketReplied`, …). No cross-module Eloquent queries or table joins. Core never imports a module's internals either (a test enforces both); a module plugs into Core from its service provider, e.g. its sidebar pages go into `App\Navigation\WorkspaceNavigation`.
- **Core stays thin:** tenancy, auth, users, orgs, settings, money, documents, shared UI. If you're adding domain logic to Core, it probably belongs in a module.
- **Surfaces:** `/o/{org-slug}`, `/portal` and `/owner` are Livewire 4 — do not introduce Vue/React there. The console at `/{ADMIN_PATH}` is Filament 5 (separate `staff` guard, `platform_staff` table and session cookie); its resources and pages live in `App\Platform\Filament`, the only namespace allowed to read across organizations. Never hard-code the admin path or any host name; read them from config. Marketing pages are plain Blade + Livewire forms.
- **Suspended organizations are read-only:** never add your own suspension check and never bypass the central one (`App\Tenancy\OrganizationWriteGuard`, [ARCHITECTURE.md § 4](ARCHITECTURE.md)); a new customer permission must be classed in `OrganizationPermission::allowedWhileSuspended()`, and only an export (or a write the docs allow during suspension) may carry `#[AllowedWhileSuspended]`.
- **Impersonation limits:** never add your own impersonation check and never bypass the central one (`App\Tenancy\ImpersonationGuard`, [ARCHITECTURE.md § 4](ARCHITECTURE.md)); a new customer permission must also be classed in `OrganizationPermission::blockedWhileImpersonating()`, and a money action, bank/Paystack setting (viewing keys too), billing transfer, credential change, member or role change, delete or import commit/rollback not covered by a blocked permission or a `delete…`/`void…` ability carries `#[BlockedWhileImpersonating]` on its controller, Livewire or policy method.
- **Events over hooks:** subsystems react to domain events; don't have Billing reach into Tickets internals, etc.
- **Queues:** everything slow (PDF, mail, SMS, WhatsApp, webhooks processing, metering, imports) is queued with the right queue name (`default`, `notifications`, `billing`, `pdf`, `media`, `imports`) and a retry/backoff config. Jobs must finish in under ~50 seconds or be split into chunks (shared-hosting workers are cron-started and time-limited). A new queue name must also be added to a lane in `config/hosting.php`, or the shared-hosting tick never runs it.
- **Scheduler:** register scheduled work with `Schedule::call()` or `Schedule::job()` only. `Schedule::command()` and `runInBackground()` need `proc_open`, which shared hosts may disable ([INSTALLER.md § 8](INSTALLER.md)); a test enforces this.
- **Theme:** never hard-code GoProperty colours, logos or the product name in views. Use the platform theme tokens and settings ([PLATFORM_CONSOLE.md § 9](PLATFORM_CONSOLE.md)); agency documents use agency branding.
- **Shared-hosting compatibility:** no feature may require Redis, Horizon, Reverb, Node at runtime, or headless Chrome. Use Laravel's driver/config switches so those are enhancements on VPS only. Ask capabilities, not the profile: `App\Hosting\Realtime` for live updates (`pollSeconds()`, `usesWebsockets()`), `App\Pdf\PdfRenderer` for PDFs.
- **Documents:** Blade templates under `resources/views/documents` (a module's own under `Modules/<Name>/resources/views/documents`, e.g. Leasing's lease contract), rendered only through `App\Documents\DocumentRenderer` (web page, or PDF through the driver interface: `dompdf` or Chrome); table layouts and simple CSS only, no flexbox, grid or CSS variables, font weights 400/600/700/800 (the bundled fonts), so both drivers render identically — `DocumentViewsLintTest` checks every document view, modules' too ([BRANDING_DOCUMENTS.md § 5](BRANDING_DOCUMENTS.md)). Agency branding is snapshotted on each issued document (`BrandingSnapshot`, images embedded); an issued PDF is stored once (`DocumentRenderer::stored()`, evidence) and never re-rendered; verification codes come from `VerificationCodes::issue()` and modules register a `VerificationResolver` into `DocumentVerifiers`. Invoices and receipts are Core's `InvoiceDocument` / `ReceiptDocument`, built by the issuing module and printed in the organization's layout (one A4 page for up to 8 lines). Numbering via `DocumentNumberer::next()` (`document_counters`) inside the issuing transaction, run as `DB::transaction($callback, 3)`. Never `max(id)+1`.
- **Imports:** always dry run before commit; never back-bill imported leases; never auto-contact imported tenants ([DATA_IMPORT.md](DATA_IMPORT.md)). A module makes its records importable by implementing `Modules\Import\Contracts\ImportTarget` in its own code and registering it into `Modules\Import\Contracts\ImportTargets` from its provider; the Import module never reads another module's tables ([ARCHITECTURE.md § 6.12](ARCHITECTURE.md)).

## 5. Code Conventions

- PHP 8.4: `declare(strict_types=1)`, constructor property promotion, readonly where sensible, enums for every status set, named arguments where they aid clarity.
- Naming: `Action`/`Service` classes for business operations; form objects/requests for validation; Livewire components of Core in `App\Livewire\<Area>\...`, and a module's own components in the module (`Modules\<Name>\Livewire\...`, views in `Modules/<Name>/resources/views/livewire`, registered as the Livewire namespace `<name>::` by the module's provider; [ARCHITECTURE.md § 8](ARCHITECTURE.md) rule 2); Filament resources under the platform module only.
- Validation: Form Requests for controllers, `rules()` methods for Livewire; validate every upload (mime sniff, size caps) — files go into the media library only through `App\Media\MediaUploads`, checked by `App\Media\UploadValidator` (`rule()` for validation rules), and are linked only with `App\Media\MediaUrls` ([ARCHITECTURE.md § 9](ARCHITECTURE.md)); never trust client-visible-only constraints.
- Lists: every new workspace list uses the list export (P02-07, [ARCHITECTURE.md § 6.13](ARCHITECTURE.md)): its query lives in a `App\Exports\ListExport` (usually a `QueryListExport`) that the page also builds its rows from, registered into `App\Exports\ListExports` from the module's provider; the Livewire component uses `App\Exports\Livewire\WithListExports` (its `listExports()` passes the current filters) and its view puts `<x-list-export list="…" />` in the actions. Columns: human headers, money via `Money`, times through `ExportCell` (Lagos), enums by label, never an encrypted field or secret (masked at most). The list's policy gets an `export…` ability (a read); mark it `#[BlockedWhileImpersonating]` when the list holds people's contact details. `tests/Feature/Exports/ListExportCoverageTest.php` fails for a sidebar page or paginated component without an export.
- Tests: Pest. Feature tests for anything touching money, tenancy, or state machines — including the negative paths (unauthorized role, cross-tenant ID, replayed webhook, over-settled bill).
- No PII in logs. Bank account numbers and IDs are encrypted casts. Log channels get org/user context via Sentry tags, not message contents.
- Comments explain constraints (why), not mechanics (what). If a doc in this repo constrains the code, reference the doc section, e.g. `// See BILLING_PAYMENTS.md § 7: acceptance moves the payer`.

## 6. Task Workflow

0. **Design checkpoint (done Oct 2026).** The product owner's SocietyFlow reference is captured in `docs/design/reference/`, and the approved GoProperty designs are `docs/design/templates/landing.html`, `login.html` and `login-2fa.html` ([UX_DESIGN.md § 5](UX_DESIGN.md)). Build the landing page and login pages to match those files; ask the product owner before departing from them.
1. **Locate the task ID** (`P03-07` etc.) in [IMPLEMENTATION_PLAN.md § 3](IMPLEMENTATION_PLAN.md) and read [docs/PROGRESS.md](docs/PROGRESS.md) for current state. Resume the `In progress` task before starting another; otherwise take the earliest task whose dependencies are complete. Set it to `In progress` in both files before coding. If the work isn't covered by a task, say so before coding.
2. **Plan the shape** (models/migrations/module/events/policies) in one short paragraph in the PR description before writing code for anything non-trivial.
3. **Implement** following § 3–5. Small commits, imperative subjects (`Add payment confirmation policy`).
4. **Verify:** full quality gate (§ 2), plus manual smoke of affected journeys if UI changed.
5. **Update docs:** any change to schema, billing semantics, or architecture gets a same-PR edit to the relevant `.md` file. Docs drift is treated as a bug.
6. **Definition of done:** lint clean, static analysis clean, tests green including new coverage, docs updated, no TODO left in money/tenancy code (TODOs elsewhere require a tracking comment).
7. **Mark it complete** ([IMPLEMENTATION_PLAN.md § 3.1](IMPLEMENTATION_PLAN.md)): when every acceptance criterion in the task's row passes, in the same commit set its ID cell to `**Complete (YYYY-MM-DD)**`, add a ledger entry to [docs/PROGRESS.md](docs/PROGRESS.md) (what was delivered, tests and results, follow-ups, next task), and update the board there. When it is the phase's last task and the exit criteria pass, mark the phase heading and the § 2 Status column complete too. Never mark a task complete with a failing or skipped acceptance criterion — use `Blocked` or `In review` and say why.

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
