# P05 — Reminders, notifications, email templates & WhatsApp: waves and defaults

Rules: [ARCHITECTURE.md § 6.7](../../ARCHITECTURE.md) (reminder engine, ladders, channels, WhatsApp), § 7 (scheduler), § 8 (modules talk through contracts); [DATABASE_SCHEMA.md § 7](../../DATABASE_SCHEMA.md) (`reminders`, `notification_deliveries`, WhatsApp tables, `sms_credit_entries`); [PLATFORM_CONSOLE.md § 5](../../PLATFORM_CONSOLE.md) (email templates); [sms-provider-2026-10-29.md](sms-provider-2026-10-29.md) (GoText, GoProperty-owned SMS wording, never promotional); [DATA_IMPORT.md § 3](../../DATA_IMPORT.md) (imported tenants hear nothing until invited); AGENT.md § 3 rules 3 (dates through the cycle calculator), 6 (idempotency) and 10 (templates are data).

## Waves

- **Wave 1: P05-01 and P05-04 in parallel.**
  - P05-01: a new module `Modules\Reminders` with the `reminders` table, the four ladders (rent, utilities, lease renewal, overdue), the hourly dispatch job and the email and in-app messages. It reads bills, leases and tenants through new contracts of Billing, Leasing and Tenants.
  - P05-04: the email template system in the console. Independent of P05-01; once both are in, P05-01's wording moves into templates (the messages are already built from plain placeholder values).
- **Wave 2: P05-02** (SMS through GoText, `notification_deliveries`, channel preferences). It adds the `sms` channel to P05-01's engine. Built against a `log` driver until the GoText test account (PRE-03) and GoText's answers (sms-provider note, "To confirm with GoText") arrive.
- **Wave 3: P05-03, P05-05 and P05-06 in parallel.** SMS credits and the delivery health dashboard need P05-02's delivery log; WhatsApp needs the delivery log and the per-event channel order.

## Defaults for the open questions (orchestrator, 2026-10-29; for the product owner to confirm)

### P05-01 — the reminder engine

1. **Ladders** (ARCHITECTURE.md § 6.7 defaults), counted in Lagos calendar days from the bill's due date (T−n before, +n after) or the lease's end date:
   - rent due: T−30, T−14, T−7, T−3, T−1, T−0;
   - utility due: T−3, T−0;
   - overdue (every bill type still owing): +1, +7, +14;
   - lease renewal (to staff): T−60, T−30, T−7 before the lease's last day.

   They are the same for every organization in P05-01. A settings page to switch a ladder off or pick its days (with the "days to rent due" preview of UX_DESIGN.md § 4, section 6) is a follow-up, proposed as **P05-07**.
2. **Short cycles:** a "due" rung is used only when it is at most half of the bill's period, so every month gets the same rungs whatever its length. Weekly rent gets T−3, T−1 and T−0. Monthly rent gets T−14 and closer. Quarterly and longer get the whole ladder. A bill whose period is shorter than 7 days (daily rent) gets no reminders at all, overdue included: one a day would be noise, and the agency sees it in the overdue list.
3. **Before the bill exists.** With the default 7-day lead, rent's T−30 and T−14 rungs come before the bill is issued. Those reminders are about the lease's next period: amount, period and due date, with no pay link. Once the bill exists, reminders give its number, the amount still owed, the payment reference and the pay link. Each rung goes once per lease period, whether it was worked out from the lease or from the bill.
4. **No separate "bill issued" message.** The first rung on or after the bill's issue day is the tenant's first message about it. With the defaults the bill is issued on T−7, the day of the T−7 rung.
5. **Exact days, no catch-up.** A rung is sent on its own day only. A day on which the scheduler never ran is not made up later, so tenants never get a burst of stale reminders; the next rung still goes. A bill issued late (a manual bill, a late meter reading) starts at the next rung. Reminders are worked out only from the import's commit date forwards (DATA_IMPORT.md § 3); nothing is sent for dates in the past.
6. **When they go.** The job runs hourly. It plans the day's reminders from 00:00 Lagos and sends them from **08:00 Lagos** (no messages at night), so a run that fails is retried by the next hour's run. A reminder not sent by the end of its day is recorded as skipped (`missed`).
7. **Checked again just before sending.** A reminder is skipped, with the reason recorded, when:
   - the bill was paid in full or voided;
   - the lease ended, was terminated, or (renewal alerts) has a drafted renewal;
   - the tenant was archived or can no longer be reached.
8. **Tenant channels in P05-01:** email (the portal account's address, else the tenant record's) and in-app for a tenant with a portal account. Each channel is its own reminder row, so a retry never sends a channel twice. Phone-only tenants get nothing until P05-02 (SMS) and P05-06 (WhatsApp).
9. **Imported tenants** get no reminders until the agency has invited them to the portal (any channel) or they have a portal account (DATA_IMPORT.md § 3: "lets the tenant know nothing until Invite tenants now").
10. **Renewal alerts go to staff:** the agents assigned to the lease's building who hold `leases.manage`; when it has none, the organization's owners and administrators. In the app and by email, linked to the lease page. They are skipped when a renewal is already drafted, and for a rung that falls before the lease starts (a short lease). An auto-renewing lease is still alerted ("it rolls on by itself unless you change it"). The tenant and the building's owner get no renewal alerts in P05-01.
11. **Which organizations:** every organization except cancelled ones. Tenants of a suspended organization still get bill reminders: their bills still stand, and the portal still takes payment proofs. Renewal alerts are skipped while the organization is suspended, since its members cannot renew anything.
12. **Tenants who moved with their building** (P03-14, P04-04): their bills stay with the organization that issued them, and that organization keeps reminding them about those bills.
13. **Wording and branding:** plain text from plain values (never Blade), in the agency's name like ticket emails (sender "Adeyemi Realty via GoProperty", replies to the agency's email, BRANDING_DOCUMENTS.md § 1). The link is the portal's bill page for a tenant with an account, else the public pay page `/pay/{reference}` once the bill exists.

### Later P05 tasks (to confirm before each one starts)

14. **SMS rungs (P05-02):** to save credits, SMS goes only on rent T−7 and T−1, utility T−0 and overdue +1 and +7. Email and in-app go on every rung.
15. **SMS delivery status (P05-02):** a queued job polls `GET /sms/{uid}` until a final status. *Confirmed (product owner, 2026-10-30):* GoText gives delivery reports through that endpoint, not a webhook. *As built (2026-10-30):* checks 5 minutes, 30 minutes, 2 and 12 hours after sending; still unknown after that, the message stays `sent`.
16. **Email templates (P05-04):** seeded from P05-01's wording (one template per reminder type and rung kind). The console's `content-manager` role edits them; agencies do not edit wording, only their branding. *As built (2026-10-30):* six reminder templates (`reminder.rent_due`, `rent_upcoming`, `bill_due`, `bill_overdue`, `lease_ending`, `lease_ending_auto_renew`) with P05-01's wording unchanged; rent and other bills share the overdue template, as P05-01's wording did. Bounce and complaint webhooks for Postmark and Mailgun; SES (through SNS) is a follow-up.

### Defaults taken in P05-02 (orchestrator, 2026-10-30; for the product owner to confirm)

17. **"Verified number" is the portal account's.** Phone verification lives on the account (`users.phone_verified_at`, P03-01b), so only a tenant with a portal account whose number is verified is texted, at that number. A tenant record without an account is never texted, even when the agency typed a number.
18. **At most once.** A send whose outcome is unknown (the job died while calling GoText) is marked failed, never retried: a duplicate costs a credit and confuses the tenant. Other failures retry after 1, 5 and 30 minutes, then the delivery is `failed`.
19. **Real SMS only in production** (`SMS_DRIVER=gotext` and a token). Staging and local use the log driver until GoText's test mode is confirmed.
20. **Plain GSM text.** SMS wording uses "NGN" instead of ₦ and drops accents, so 160 characters fit one page (one non-GSM character would cut it to 70).
21. **Preferences.** An account may turn reminder emails and SMS off (Settings → Notifications); in-app reminders, security and transactional messages stay on. Staff may turn off renewal alert emails.
22. **SMS link.** The SMS links to the public pay page (`/pay/{reference}`) for every tenant, shorter than the portal's bill page.
