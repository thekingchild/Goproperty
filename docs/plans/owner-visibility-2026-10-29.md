# Owner visibility changes (P03-16, P04-06) — before P05

The product owner consulted property owners and agents and asked for two changes on 2026-10-29, before P05 starts. They replace two earlier decisions:

- "Owners see tenant names only by default; the agency may share tenant contact details per owner" ([P02-P03-subtasks.md](P02-P03-subtasks.md), "Settled by the product owner (2026-10-28, after P03-14)"; built in P08-05).
- "In `/owner`, owners see the tickets of their buildings read-only" ([P04-subtasks.md](P04-subtasks.md), default 8; built in P04-02/P04-03).

Both changes apply only to owners whose buildings an organization manages for them (`property-owner` in `/owner`). A landlord who manages their own buildings is `org-owner` there and already sees everything.

## Settled by the product owner (2026-10-29)

1. **Owners see their tenants in full, read-only.** For a tenant who holds or held a lease in one of the owner's buildings, the owner sees: name, phone, email, address, occupation, emergency contact, KYC status and ID type. **The ID number stays masked** (last four characters only).
2. **The agency's per-owner "share tenant contact details" switch is removed.** Owners always see these details; an agency cannot hide them.
3. **Contracts, changes and billing in `/owner`, read-only:**
   - the signed (issued) lease contracts of their units, to view and download;
   - lease history per unit: past and current leases, renewals, rent changes, terminations, move-ins and move-outs, as a timeline;
   - invoices, payment history and receipts. **Bank account details and payment references stay hidden** from owners;
   - deposits: each lease's deposit held, deductions and refunds.
4. **The agency chooses which tickets the owner sees.** A ticket is hidden from the building's owner until a member of the agency shares it. The owner is notified about shared tickets only.
5. **Every ticket starts hidden, existing ones included.** Tickets that exist when this ships are not shared; the agency shares the ones that matter.

## Defaults for the details (orchestrator, 2026-10-29; for the product owner to confirm)

### P03-16 — the owner's view of tenants, contracts and billing

1. **Which tenants:** those with a non-draft lease in one of the owner's buildings in that organization, current or past. Records stay per organization: after a handover each agency's card shows the leases, bills and payments it holds (history never moves, BILLING_PAYMENTS.md § 7).
2. **Invoices and receipts are shown as on-screen pages, not the issued PDFs.** The issued invoice prints the organization's bank account and the payment reference, and an issued PDF is never re-rendered (AGENT.md § 4 "Documents"), so the owner gets a page built from the same data without those fields: number, dates, lines, totals, amount paid and outstanding; for a receipt: number, date, amount, method (bank transfer, cash, POS, card), the bill it settles and its status (void shown as void). Proofs of payment uploaded by tenants are not shown.
3. **Lease contracts:** the issued contract PDF as stored (it carries the tenant's details, which owners now see, and no bank details). Drafts are never shown.
4. **Owner reads of a tenant's details are not logged per view.** The owner's portal access itself is already audited (P03-15).
5. **The owner statement and its PDF** show tenant phone and email whenever they show the tenant (the switch is gone). Settled months already snapshotted keep what they recorded.
6. **NDPA:** the tenant-facing privacy notice must say that the landlord of the building sees these details. Recorded as a follow-up for P09-01 (NDPA).

### P04-06 — tickets shared with the owner

1. **Who shares:** a member who may move the ticket (`tickets.manage`) on its building (`BuildingAccess`): `org-owner`, `org-admin`, or an agent working on that building. They can unshare too. Both are audited and shown on the staff ticket page ("Shared with the owner by {name} on {date}").
2. **Hidden means hidden:** an unshared ticket is not on the owner's list, its page and files answer 404, and the owner cannot reply to it. The owner's reply while no agent is assigned (ARCHITECTURE.md § 5) applies only to shared tickets.
3. **Owner notifications (email and in-app, like P04-03):** once when the ticket is shared, then on each status change and each public reply (by staff or the tenant) while it stays shared. Never an internal note, never an assignment. Unsharing tells nobody and stops further notices. Each notice is sent once (keyed like P04-03's).
4. **Who is told on the owner's side:** the users linked to the building's owner profile in that organization (`OwnerPortalAccess`). A building whose owner has no portal account can still have tickets shared; nobody is told until they join, and they then see the shared tickets.
5. **A ticket that moves on a handover arrives unshared** (P04-05): the receiving organization decides.
6. **The tenant is not told** and sees no difference.
7. **Staff list:** a "Shared with owner" badge, a filter, and a column in the list export.
8. **Permissions:** no new permission; sharing follows `tickets.manage` (dropped while suspended, not blocked while impersonating, as P01 classed it).
