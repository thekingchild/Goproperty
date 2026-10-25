# GoProperty — Property Management SaaS

GoProperty is a multi-tenant SaaS platform for property management in Nigeria. Agencies and property owners manage buildings, blocks/apartments, tenants, leases, utility billing, maintenance tickets and tenant communities — while the platform owner manages subscriptions, customer accounts, feature enablement, monitoring and reporting from a separate console.

## Core Facts

| Aspect | Decision |
| --- | --- |
| Stack | Laravel 13, PHP 8.4+, MySQL 8 / MariaDB 10.6+, Livewire 4, Filament 5 (platform console), Pest 4 |
| Hosting | Runs on **cPanel or Plesk shared hosting** (database queues via cron, polling, dompdf — see [docs/runbooks/shared-hosting.md](docs/runbooks/shared-hosting.md)); Redis/Horizon/Reverb/headless Chrome switch on by config when moved to a VPS ([ARCHITECTURE.md § 12.1](ARCHITECTURE.md)) |
| Tenancy | Single database, row-level scoping on `organization_id` (see [ARCHITECTURE.md](ARCHITECTURE.md)) |
| SaaS billing | Per-unit plans from ₦10,000/month (Starter, 10 units) — editable; Paystack card payments + bank transfer with receipt confirmation |
| Payments (customers) | Tenants pay the agency **directly**: bank transfer (matched from the agency's bank statement, or proof upload) or the agency's **own** Paystack account. GoProperty never receives or routes tenant money (see [ONBOARDING_COMPLIANCE.md § 0](ONBOARDING_COMPLIANCE.md)) |
| Transaction Tiers | Tier 1 (≤ ₦50m/yr) / Tier 2 (≤ ₦500m/yr) / Tier 3 (above) — editable; Tier 2 & 3 need CAC + SCUML documents; system flags agencies exceeding their tier and prompts an upgrade |
| Theme | GoProperty's own name, logos, colours, font and login page are configurable in the console (no code change) |
| Domains | One domain: `goproperty.ng/login` (one login for agencies, owners, tenants) · `/o/{org-slug}` (agency workspace) · `/portal` · `/owner` · staff console on a configurable secret path. No subdomain per agency; can move to subdomains by config |
| Branding | Agencies use their own name, logo, colours and letterhead on invoices, receipts, emails and the tenant portal |
| Data import | Excel/CSV with dry run: owners, properties, units, tenants, ongoing leases, opening balances, utilities, payment history |
| Communications | Email (transactional provider) + SMS (Termii) + optional WhatsApp via the agency's own WhatsApp Business API (templates fetched and mapped) |
| Realtime | Polling on shared hosting; Laravel Reverb on VPS (Building Group) |
| Extensibility | Modular monolith — features are self-contained modules (see [ARCHITECTURE.md § 8](ARCHITECTURE.md)) |

## Reading Order

1. **[IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md)** — phased build plan, milestones, team assumptions, risks. Start here.
2. **[ARCHITECTURE.md](ARCHITECTURE.md)** — system architecture, tenancy model, role matrix, key subsystems (tickets, Building Group, reminders, documents), module system.
3. **[DATABASE_SCHEMA.md](DATABASE_SCHEMA.md)** — full data model: tables, key columns, enums, relationships, integrity rules.
4. **[BILLING_PAYMENTS.md](BILLING_PAYMENTS.md)** — SaaS pricing model, unit metering, Paystack integration, bank-transfer workflow, dunning and the billing-responsibility transfer flow.
5. **[ONBOARDING_COMPLIANCE.md](ONBOARDING_COMPLIANCE.md)** — domains & login paths, registration and subscription phase, payment collection (bank transfer + agency-owned Paystack), Transaction Tiers and compliance monitoring.
6. **[PLATFORM_CONSOLE.md](PLATFORM_CONSOLE.md)** — configurable staff roles, platform theme, operations and analytics dashboards, backups & restore, email templates, impersonation, audit and access logs.
7. **[UX_DESIGN.md](UX_DESIGN.md)** — design principles, login pages, marketing hero, design system.
8. **[BRANDING_DOCUMENTS.md](BRANDING_DOCUMENTS.md)** — agency branding settings and the designed invoice, receipt and other documents (reference templates in `docs/design/templates/`).
9. **[DATA_IMPORT.md](DATA_IMPORT.md)** — Excel/CSV import with dry run, ongoing rents and opening balances.
10. **[AGENT.md](AGENT.md)** — working rules for AI coding agents (and human contributors): conventions, commands, guardrails, definition of done.

## The Cast

- **Platform owner** — runs the SaaS. Manages customers, plans, billing and renewals, Transaction Tier reviews, feature flags, backups, monitoring, analytics and reports. Has staff with configurable, role-based admin access.
- **Agent / agency** — the typical paying customer. Manages buildings, blocks/apartments, tenants, leases, utility bills, tickets and Building Groups on behalf of property owners.
- **Property owner** — owns the buildings. May sign up directly (platform bill can be subsidised/covered, or transferred to an invited agent once accepted).
- **Tenant** — occupies one or more allocated apartments, pays rent and utilities, uploads payment proofs, raises maintenance tickets, participates in the Building Group.

## Signature Flows

- **Billing responsibility transfer** — a property owner invites an agent/agency and offers to hand over the platform subscription bill; the agent must accept before the obligation moves.
- **Lease lifecycle** — allocation → contract (daily → biennial cycles) → rent invoice → payment → proof upload → confirmation → receipt; renewal reminders go out by email and SMS.
- **Maintenance ticket** — draft → submitted → under review → in progress → resolved → closed, with threaded replies between tenant and agent/owner.
- **Building Group** — a per-building discussion board with realtime posts, replies, emojis, light rich text and images.

## Status

Design phase. No code yet. The documents in this repository are the implementation baseline; changes to the data model or billing semantics should be reflected here first.
