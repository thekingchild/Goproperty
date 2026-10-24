# GoProperty — Property Management SaaS

GoProperty is a multi-tenant SaaS platform for property management in Nigeria. Agencies and property owners manage buildings, blocks/apartments, tenants, leases, utility billing, maintenance tickets and tenant communities — while the platform owner manages subscriptions, customer accounts, feature enablement, monitoring and reporting from a separate console.

## Core Facts

| Aspect | Decision |
| --- | --- |
| Stack | Laravel 13, PHP 8.4+, MySQL 8, Redis, Livewire 3 (+ Filament for the platform console) |
| Tenancy | Single database, row-level scoping on `organization_id` (see [ARCHITECTURE.md](ARCHITECTURE.md)) |
| SaaS billing | Per-block/apartment unit pricing; Paystack card payments + bank transfer with receipt confirmation |
| Payments (customers) | Bank transfer to the agency/owner's account; tenant uploads proof; agent confirms; receipt is generated |
| Communications | Email (transactional provider) + SMS (Termii or similar Nigerian route) |
| Realtime | Laravel Reverb for the Building Group chat |
| Extensibility | Modular monolith — features are self-contained modules (see [ARCHITECTURE.md § 8](ARCHITECTURE.md)) |

## Reading Order

1. **[IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md)** — phased build plan, milestones, team assumptions, risks. Start here.
2. **[ARCHITECTURE.md](ARCHITECTURE.md)** — system architecture, tenancy model, role matrix, key subsystems (tickets, Building Group, reminders, documents), module system.
3. **[DATABASE_SCHEMA.md](DATABASE_SCHEMA.md)** — full data model: tables, key columns, enums, relationships, integrity rules.
4. **[BILLING_PAYMENTS.md](BILLING_PAYMENTS.md)** — SaaS pricing model, unit metering, Paystack integration, bank-transfer workflow, dunning and the billing-responsibility transfer flow.
5. **[AGENT.md](AGENT.md)** — working rules for AI coding agents (and human contributors): conventions, commands, guardrails, definition of done.

## The Cast

- **Platform owner** — runs the SaaS. Manages customers, plans, billing and renewals, feature flags, backups, monitoring, analytics and reports. Has staff with role-based admin access.
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
