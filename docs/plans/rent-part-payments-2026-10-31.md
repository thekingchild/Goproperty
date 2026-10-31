# Part payments of rent bills: the agency sets how many (P03-17)

Asked by the product owner on 2026-10-31, while confirming P06-04's default 35: "Part payment is allowed. The number of installments is determined by the agency/agent." GoProperty's own subscription invoices stay paid in full (docs/plans/P06-subtasks.md, "Decided by the product owner (2026-10-31)"); this is about **tenants' rent bills**.

## Today

Tenants already pay a bill in as many payments as they like. Every confirmed payment is allocated to bills in `PaymentService::confirmLocked()` (`Modules\Payments`): first the bill it was for, then the tenant's other open bills, oldest due first; anything left becomes tenant credit. A bill paid in part is `partially_paid` until it is paid, or `overdue` after its due date (`Modules\Billing\Enums\BillStatus`). Nothing counts the payments made on a bill.

How payments arrive (map of 2026-10-31):

| Path | Who | Amount chosen by | Confirmed |
| --- | --- | --- | --- |
| Proof of payment (portal or member) | Tenant / member | The payer (prefilled with what is owed) | When a member confirms |
| Payment recorded by a member (transfer, cash, POS) | Member (`payments.confirm`) | The member | At once, or from the queue |
| Bank statement match (automatic, bulk, by hand) | Job / owner, admin | The bank line | When matched or confirmed |
| The agency's Paystack (`/pay/{reference}/online`) | Tenant | **Always the full outstanding amount** | Automatically |
| Tenant credit applied to a new bill | Automatic | The credit held | At once (no payment row) |
| Imports (payment history, opening balances), handover credits | Import / member | The file / member | Never allocated to a bill as a payment |

## Decided by the product owner (2026-10-31)

1. **A limit on how many part payments** a tenant may make on one bill. No schedule of amounts or dates.
2. **One due date per bill**, as today: reminders and "overdue" are unchanged.
3. **Set as an organization default, changed on each lease.**
4. **Set by the agency's owners and administrators**, not by agents.

## Defaults (for the product owner to confirm)

1. **Which bills.** Rent bills (type `rent`, from a lease). Utility, opening-balance and other bills are unlimited.
2. **The setting.** Organization setting `leasing.max_rent_payments` (defined by the Leasing module, which owns the lease's value): empty = no limit (today's behaviour, so nothing changes until an agency sets it), otherwise 1 to 12 (1 = rent is paid in one payment). Per lease, `leases.max_rent_payments`: empty = the organization's default, otherwise 1 to 12. Both are edited with `organization.update` (owners and administrators), audited. The lease's value can be changed on any lease that is not ended, not only drafts (it is a payment rule, not a contract term), and is shown on the lease page.
3. **Read when a payment arrives, not frozen on the bill.** A change applies to bills still open. Lowering it below the payments a bill already has undoes nothing: the bill just takes no more part payments.
4. **What counts as a payment on a bill.** Each confirmed payment allocated to it, including the part of a payment that spilled over from another bill. Tenant credit applied automatically does not count (the tenant did not make a payment). Payments waiting for confirmation (a proof sent, a statement line suggested) count towards the limit while they wait, so a tenant cannot send five proofs at once.
5. **The last allowed payment must clear the bill.** With a limit of 3 and two payments made, the third must be at least what is still owed.
6. **Tenants are refused; the agency is warned.** A tenant's proof that would break the limit is refused with the reason ("This bill can be paid in at most 3 payments; this one must be the full ₦400,000 still owed"). Paystack online always asks for the full balance, so it never breaks the limit. Money that already reached the agency is never refused: a payment a member records or confirms, and a bank statement match, go through, and the payment page and the confirmation queue show "Over the agreed number of part payments (4 of 3)" so the member decides.
7. **Shown to tenants.** The portal's bill page and the public pay page say "You can pay this bill in up to 3 payments (1 made)" when a limit applies, and the proof form starts with the full balance when only one payment is left. Members see the same line on the bill and payment pages.
8. **Where it is set in the workspace.** A new Settings → **Rent payments** page (seen with `settings.view`: owners and administrators; agents do not open settings pages) holding the default. On a lease's page, the number with a **Change** form for owners and administrators; agents see it only.

## Acceptance (proposed)

- A tenant cannot send a proof that would exceed the bill's limit or leave the last allowed payment short; a member-recorded or bank-matched payment over the limit is confirmed and flagged.
- The lease's value wins over the organization's default; empty everywhere means no limit; only owners and administrators can change either, audited.

## How it is built (P03-17, 2026-10-31)

- **Leasing:** migration `2026_12_08_100101` adds `leases.max_rent_payments`; `Contracts\RentPaymentLimits` (`Leases\RentPaymentRules`: the lease's value, else the organization's default, else none); `LeaseService::setRentPaymentLimit()` (`LeasePolicy::setPaymentLimit`: `organization.update`, a lease that is not final; audited `lease.rent_payments_changed`), copied to a renewal; Settings → **Rent payments** (`Livewire\RentPaymentSettingsPage`, `/o/{slug}/settings/payments/rent`, saved with `updateProfile`, audited by the settings store); the number and its **Change** form on the lease's page.
- **Payments:** `Contracts\PartPayments` and `PartPaymentAllowance` (`Payments\PartPaymentCounts`); `PaymentService::recordProof()` refuses a tenant's own proof over the limit or short on the last allowed payment, under the bill's lock; `confirmLocked()` adds `over_part_payment_limit` to the `payment.confirmed` audit entry when a confirmation takes a bill over; the confirmation queue badges waiting payments that would, and the payment page explains it.
- **Shown:** the portal's bill page (in the amount card) and proof page (with what the last payment must be, or that none is left), the public pay page, and the members' bill page ("2 of 3 allowed, 1 waiting").
