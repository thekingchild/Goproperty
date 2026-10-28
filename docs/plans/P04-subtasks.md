# P04 — Maintenance tickets: waves and defaults

Rules: [ARCHITECTURE.md § 6.6](../../ARCHITECTURE.md) (state machine, thread, notifications), § 5 (roles, ticket visibility), § 9 (ticket attachments are evidence); [DATABASE_SCHEMA.md § 5](../../DATABASE_SCHEMA.md); AGENT.md § 3 rules 5 (only `TicketService` changes states) and 7 (no hard deletes of evidence).

## Waves

- **Wave 1: P04-01 alone.** It lays the tables, `TicketService` and the basic pages the other two build on:
  - tables `tickets` and `ticket_status_history`;
  - `TicketService` with every transition;
  - categories, priority and assignment;
  - permissions and policy;
  - a staff ticket page (create on behalf, show, status actions, assign);
  - a tenant portal ticket page (raise, show, confirm resolution or reopen).
- **Wave 2: P04-02 and P04-03 in parallel.**
  - P04-02: `ticket_messages`, attachments, internal notes, and the property owner's reply when no agent is assigned.
  - P04-03: staff and tenant inboxes by status, notifications on every transition, a ticket started from a lease, the owner's read-only list in `/owner`, and open tickets in the agent's "Today" inbox if it exists.
- P04-04 (handover follow-ups) is already complete.

## Defaults for the open questions (orchestrator, 2026-10-28; for the product owner to confirm)

1. **Reopen window:** a tenant may reopen a resolved or closed ticket within 14 days of it being resolved (organization setting `tickets.reopen_days`, 1–90). Staff may reopen at any time.
2. **Closing:** the tenant's "confirm resolution" closes the ticket. A resolved ticket the tenant does not answer closes itself after 7 days (setting `tickets.auto_close_days`, 1–30) through a daily job.
3. **Who raises a ticket:**
   - A tenant raises one from the portal for a unit they hold a live (active or reserved) lease on.
   - Staff raise one on a tenant's behalf, or for a unit with no tenant (e.g. common areas), with the tenant optional.
   - A draft is the tenant's unsent ticket. Staff tickets start at `submitted`.
4. **Ticket number:** each organization numbers its tickets in sequence (`TKT-000001`), like receipts.
5. **Assignment:** to an organization member who works on the ticket's building (`BuildingAccess`: owner, admin, or an agent assigned to it). Unassigned tickets are visible to owners and admins.
6. **Unit status:** a ticket never changes `units.status`; people set `under_maintenance` themselves.
7. **Attachments (P04-02):**
   - images (JPEG, PNG, WebP, HEIC) and PDFs;
   - up to 10 MB each and 5 per message;
   - evidence, never hard-deleted.
8. **Property owner (landlord):**
   - In `/owner`, they see the tickets of their buildings read-only: number, title, category, priority, status and dates, with no internal notes.
   - They may reply in the thread only while no agent is assigned (ARCH § 5 role matrix).
   - Tenant contact details follow P08-05's sharing switch.
9. **Notifications (P04-03):**
   - by email and in-app;
   - phone-only tenants get none until P05 (WhatsApp/SMS);
   - each transition notifies each party once: the tenant, the assigned member, or the owners and admins when the ticket is unassigned.
10. **Expenses:** `expenses.ticket_id` stays unwritten in P04. "Record an expense for this ticket" is a follow-up.
11. **Plan limit `tickets.limit`:** not enforced until P06 (SaaS billing).
