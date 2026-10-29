# SMS provider and SMS templates — product owner's decisions (2026-10-29)

Settles the "SMS provider" item of PRE-01 and two rules for P05-02/P05-03. Other PRE-01 items (pricing bands, hosting budget, BILLING_PAYMENTS.md § 10) are still open.

## Decisions

1. **The SMS provider is GoText** (`app.gotext.ng`), not Termii. GoProperty holds **one** GoText account for the whole platform; agencies never connect their own SMS account (unlike WhatsApp, where each agency uses its own Meta account).
2. **GoProperty pays GoText;** agencies pay GoProperty through SMS credits (the plan's monthly allowance and in-app top-ups, [BILLING_PAYMENTS.md § 1.2](../../BILLING_PAYMENTS.md)). Tenants pay nothing. Sending stops at zero credit (P05-03).
3. **GoProperty owns the SMS wording.** SMS templates are edited only by the console's `content-manager` role, in the same template system as email (P05-04, [PLATFORM_CONSOLE.md § 5](../../PLATFORM_CONSOLE.md)). Agencies cannot edit the text; only the placeholders change per message (agency name, tenant first name, amount, due date, payment reference, pay link).
4. **SMS is never promotional.** Only *transactional* and *reminder* templates may have an SMS version (bill issued, rent/utility due, overdue, receipt for cash, renewal notice). Marketing is email-only and opt-in. This keeps the messages eligible for the DND (transactional) route, so reminders reach numbers on the Do-Not-Disturb list, and protects the shared sender ID for every agency.

## GoText SMS API (from GoText's docs, as shared by the product owner)

- **Send:** `POST https://app.gotext.ng/api/http/sms/send`, headers `Accept: application/json` and `Content-Type: application/json`.
  - `api_token` (required) — from GoText's Developers page; kept in `.env`/console secrets, never in the repo.
  - `recipient` (required) — the number with country code and **no `+`** (GoProperty stores `+234…`, so the driver strips the `+`). Comma-separated for several numbers; GoProperty sends one number per request so each send has its own delivery row and cost.
  - `sender_id` (required) — an alphanumeric name of up to 11 characters, or a phone number.
  - `type` (required) — `plain` for text.
  - `message` (required) — the body (kept under 160 characters, one page).
  - `schedule_time` (optional) — not used; the reminder engine decides when to send.
  - `dlt_template_id` (optional) — the ID of a registered content template; to be confirmed with GoText whether Nigerian DND delivery needs it.
- **Response:** `{"status": "success", "data": …}` or `{"status": "error", "message": "…"}`. The message's unique `uid` is stored as `notification_deliveries.provider_message_id`.
- **Check one message:** `GET https://app.gotext.ng/api/http/sms/{uid}` with `api_token` in the JSON body. **List messages:** `GET https://app.gotext.ng/api/http/sms/`.
- **Never use GoText's GET send form** (`…/sms/send?recipient=…&api_token=…`): it puts the token and the tenant's number in the URL, where server and proxy logs keep them.
- GoText also offers Contacts, Voice, MMS, WhatsApp, Viber, OTP, Profile, Credit Transfer and Campaign APIs. GoProperty uses none of them for now (no OTP by design; WhatsApp stays on the agency's own Meta account; no campaigns, decision 4).

## To confirm with GoText (before P05-02 is built)

- The exact fields of `data` on send and on `GET /sms/{uid}`: the `uid`, delivery status values, pages used and cost.
- Whether GoText can call a **delivery-report webhook**; if not, P05-02 polls `GET /sms/{uid}` on a queued job until a final status.
- The **DND/transactional route**: whether it needs a registered sender ID, approved templates (`dlt_template_id`) or both, and how long approval takes.
- **Per-agency sender IDs** (BRANDING_DOCUMENTS.md § 1): how GoText registers them and what is needed from the agency.
- A test mode or test sender for staging, rate limits, the account balance endpoint (Profile API), and how messages over 160 characters or with non-GSM characters are counted.
