# GoProperty — Platform Console (`goproperty.ng/{ADMIN_PATH}`)

Companion documents: [ARCHITECTURE.md](ARCHITECTURE.md) · [ONBOARDING_COMPLIANCE.md](ONBOARDING_COMPLIANCE.md) · [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md) · [UX_DESIGN.md](UX_DESIGN.md)

The console is a Filament 5 panel served at a **configurable, non-obvious path** (`ADMIN_PATH`, default `gp-control` plus a random suffix from the installer, never `/admin` — see § 10) on the main domain, which suits shared hosting. It can move to its own subdomain later by configuration only ([ONBOARDING_COMPLIANCE.md § 1](ONBOARDING_COMPLIANCE.md)). It has a separate `staff` guard and `platform_staff` table, its own session cookie (scoped to the console path), mandatory 2FA, a short idle timeout (30 min), and an optional IP allowlist. Every action in it is audited.

## 1. Staff & Configurable Roles

Staff access is controlled only by **roles** (spatie permissions on the `staff` guard). There are no separate job titles: the role names describe what people do. A person can hold more than one role.

### Configurable roles

- GoProperty ships with the **default roles** below, but they are not hard-coded. A `super-admin` can, in **Staff → Roles**:
  - create new roles (e.g. "Regional Support Lead") by ticking permissions from the permission catalogue;
  - rename roles and change their permissions;
  - deactivate roles that are no longer used (staff holding them must be moved first).
- **Permission catalogue:** permissions are defined in code per console area and action (e.g. `customers.view`, `customers.suspend`, `tiers.approve`, `refunds.create`, `backups.restore_staging`, `impersonate.read_only`, `templates.edit`). New features add permissions, never roles.
- **Protected permissions** — impersonation write mode, production restore approval, role management, data purge — can only be added to a role by a `super-admin` and are still subject to the two-person rules below.
- The `super-admin` role itself can't be edited or deleted, and at least two active super-admins must exist.
- Every role and permission change is audited and shown in the staff's profile history.

### Default roles

| Role | Can do | Cannot do |
| --- | --- | --- |
| `super-admin` | Everything, including staff management, role grants, production restore approval, system settings | — (but still subject to two-person rules) |
| `operations-manager` | Customers, organization suspend/reactivate, plan changes for customers, grace extensions, view all dashboards | Refunds, backups restore, view compliance documents |
| `compliance-officer` | Review Transaction Tier applications and documents, approve/reject tiers, work the compliance flag queue, restrict orgs for compliance | Billing actions, impersonation in write mode |
| `finance-admin` | Plans & prices, platform invoices, confirm bank-transfer subscription payments, refunds, credit notes | Staff management, compliance documents |
| `support-agent` | Customer lookup, read-only impersonation (with reason), resend emails/invoices, view delivery logs | Money actions, compliance documents, write-mode impersonation |
| `technical-admin` | Operations dashboard, queues/failed jobs, webhook replay, backups (run, download, verify, restore to staging), email provider settings | Customer data editing, money actions |
| `content-manager` | Email templates, marketing page content, announcements | Customer data |
| `analyst` | Analytics dashboard and reports (aggregated, no PII) | Anything that changes data |

### Two-person rules (segregation of duties)

Needs a second staff member with the right role to approve:

- Refunds above a set amount (e.g. ₦100,000).
- Restoring a backup to production.
- Granting `super-admin`, `finance-admin` or `compliance-officer`.
- Write-mode impersonation.
- Deleting/purging an organization's data (NDPA erasure).

### Staff lifecycle

Invite by email → set password + 2FA on first login → active. Deactivation immediately ends all sessions. Staff profile: name, work email, phone, roles, status, last login, 2FA status, IP allowlist (optional).

## 1A. Business Settings Managed in the Console

| Setting | Who edits | Who approves | Spec |
| --- | --- | --- | --- |
| Plans, prices, included units, features, SMS credits | `finance-admin` | — (audited; customers notified 30 days ahead) | [BILLING_PAYMENTS.md § 1](BILLING_PAYMENTS.md) |
| Transaction Tier limits and required documents | `compliance-officer` | `super-admin` | [ONBOARDING_COMPLIANCE.md § 5.2](ONBOARDING_COMPLIANCE.md) |
| Per-organization tier override | `compliance-officer` | — (reason + expiry required) | same |
| Email templates | `content-manager` | — (versioned) | § 5 |
| Backup destinations | `technical-admin` | — | § 4 |
| Staff roles and permissions | `super-admin` | Second super-admin for protected permissions | § 1 |
| Platform theme (name, logos, colours, login page) | `super-admin` or `content-manager` | — (versioned, preview first) | § 9 |
| Console path (`ADMIN_PATH`) | `technical-admin` (via `.env` on the server) | — | § 10 |

## 2. System Operations Dashboard (for `technical-admin`, `super-admin`)

Health at a glance, refreshed every 60 s:

| Area | Widgets |
| --- | --- |
| App | Uptime, error rate (Sentry), p95 response time, deploy version, maintenance mode toggle |
| Queues | Depth and wait time per queue (`default`, `notifications`, `billing`, `pdf`, `imports`), failed jobs with retry/forget; Horizon link on VPS, read from the `jobs`/`failed_jobs` tables on shared hosting |
| Scheduler | Last run + result of every scheduled command; heartbeat alert if a job didn't run |
| Webhooks | Paystack: last event received, failure rate, events awaiting processing — for GoProperty's own account **and** per connected agency account; replay button |
| Messaging | Email/SMS/WhatsApp sent, delivered, bounced, failed (24h/7d); SMS spend; provider status |
| Backups | Last DB backup, last files backup, per destination, size, success/failure, next scheduled run |
| Storage | Object storage usage, database size, largest tables growth |
| Security | Failed logins (24h), locked accounts, active impersonation sessions, new staff sessions |

Built with Filament widgets + embedded Laravel Pulse cards. Alerts (Slack/email) fire on: failed backup, scheduler missed, queue wait > 5 min, webhook failure rate > 5%, error spike.

## 3. System Analytics Dashboard (for `analyst`, `operations-manager`, `super-admin`)

Business numbers, from nightly summary tables (`daily_platform_metrics`) so the dashboard is fast and never queries live tenant tables:

- **Revenue:** MRR, ARR, revenue this month vs last, collection rate of platform invoices, average days to pay, outstanding platform invoices.
- **Customers:** signups per week, trial → paid conversion, active / past due / suspended / cancelled counts, churn rate, customers by state.
- **Usage:** total units, units added this month, active users (daily/weekly), tenants with portal access, tickets opened/resolved.
- **Transaction Tiers:** organizations per tier, tier upgrade applications (pending/approved/rejected), open compliance flags.
- **Collections (recorded, not handled by GoProperty):** total tenant payments recorded per month, split by channel (bank match / Paystack / proof / cash).
- **Leaderboard:** top customers by units, growth, and payment reliability.

Every chart has a date range filter and CSV export.

## 4. Backups & Restore

### What is backed up (separately)

| Backup | Contents | Schedule | Retention |
| --- | --- | --- | --- |
| **Database** | Full MySQL dump (compressed, AES-256 encrypted archive) | Nightly 01:30 + managed-DB point-in-time recovery (binlog) | 30 daily, 12 monthly |
| **Files** | Uploaded media (payment proofs, documents, photos), generated PDFs, compliance documents | Nightly 02:30 (incremental sync), weekly full manifest | 30 days of versions |
| **Config snapshot** | Non-secret settings, email templates, plans | With DB backup | as DB |

DB and files run as **separate jobs** with separate configs (spatie/laravel-backup `--only-db` / `--only-files`), so a failure in one doesn't block the other.

### Destinations

- **Primary: S3-compatible storage** (Cloudflare R2 / DigitalOcean Spaces / Wasabi / Backblaze B2). A different provider or account from the live media bucket, so one compromised account can't delete both.
- **Secondary: Google Drive** via a Flysystem Google Drive adapter. Caveat: Google service accounts have no storage of their own, so use a **Shared Drive** (Google Workspace) or OAuth credentials of a dedicated GoProperty Google account. Drive is best for DB dumps. Large media is better mirrored to a second S3-compatible provider (e.g. with rclone).
- Destinations are configured in the console by `technical-admin`. Credentials are stored encrypted and never shown after saving.

### Console actions

| Action | Who | Notes |
| --- | --- | --- |
| Run backup now (DB / files) | `technical-admin` | Queued; result appears in list |
| List / download | `technical-admin` | Download link is signed and expires in 15 min; every download audited |
| Verify | `technical-admin` | Restores the dump into a temporary database, runs integrity checks, then drops it |
| Restore to staging | `technical-admin` | For drills and investigations |
| Restore to production | `technical-admin` + approval by `super-admin` | Two-person rule, typed confirmation, 2FA re-check, maintenance mode on, safety backup taken first. The runbook in `docs/runbooks/restore.md` is the fallback if the app itself is down |

Monthly automatic restore test to staging; a quarterly manual drill is recorded in the console.

## 5. Email Notification System & Templates

### Template management (`content-manager`)

- Templates are stored in the database (`email_templates` + `email_template_versions`). Each has a key (`bill.issued`, `payment.confirmed`, `trial.ending`, `tier.upgrade_required`, …), subject, body, category and status.
- **Safe placeholders only:** `{{ tenant.first_name }}`, `{{ bill.amount }}`, `{{ org.name }}`. They are resolved from a whitelist per template. **Database templates are never rendered as Blade**, which would let anyone with template access run code. The surrounding layout (header, logo, footer, unsubscribe) is a trusted Blade file in code.
- Body is written in Markdown with a live preview using sample data; "send test to me" button.
- Every edit creates a new version; roll back to any version; who changed what is audited.
- Per-organization branding (logo, colours, sender display name "Adeyemi Realty via GoProperty") is applied automatically to customer-facing emails.

### Categories

| Category | Examples | Opt-out? |
| --- | --- | --- |
| Security | Login from new device, password reset, 2FA changes | No |
| Transactional | Bill issued, receipt, payment confirmed/rejected, ticket updates, platform invoice | No |
| Reminders | Rent due, lease renewal, trial ending | Per-user channel preferences |
| Product / marketing | Newsletters, feature announcements | Opt-in only (NDPA consent) |

### Delivery

- Provider: Postmark / Mailgun / SES, configured in the console.
- Bounce and complaint webhooks add addresses to a suppression list. Suppressed users get an in-app notice to fix their email.
- Every send is logged in `notification_deliveries` (status, provider message ID, opened where available).
- The same template engine later serves SMS and WhatsApp templates.

## 6. Impersonation (support & monitoring)

Lets support staff see exactly what a customer sees.

- **Who:** `support-agent` and `operations-manager` (read-only); write mode needs approval by a second staff member.
- **Customer consent:** each organization has a setting "Allow GoProperty support access", on by default with a clear explanation in Settings. Org-owners can switch it off; staff then need the org-owner to grant a time-limited access window.
- **Start:** staff picks a user, types a reason (e.g. support ticket number). Can't impersonate other staff.
- **Session:** maximum 30 minutes. A visible banner on every page: "GoProperty support (Ada O.) is viewing this account — End session". Hand-off from the staff console to the customer app uses a one-time signed token valid for 60 seconds, which starts a separate, time-limited customer-side session; the staff session cookie is never sent to customer pages.
- **Always blocked while impersonating:** confirming/rejecting payments, voiding receipts, changing bank accounts or Paystack keys, billing transfers, changing passwords/2FA, inviting or removing members, deleting anything.
- **Audit:** start, end, reason and every page viewed and action taken are recorded with both identities (staff + impersonated user). The org-owner gets an email summary after the session.

Implementation: `lab404/laravel-impersonate` (or the Filament impersonate plugin) wrapped in GoProperty's own guard and policy checks.

## 7. Audit Logs

- Records **every change** to business data: who (user or staff, plus impersonator if any), what (model, ID, before → after values), when, from where (IP, user agent), and why (reason, where required).
- Covers customer actions (leases, bills, payments, receipts, settings, members) and staff actions (suspensions, tier decisions, refunds, template edits, backup downloads, role changes).
- **Append-only:** no update/delete permission for anyone through the app. Each entry stores a hash of the previous one, so tampering is detectable.
- **Views:**
  - Console: searchable across all orgs (filter by org, user, staff, action, date). Staff views of the audit log are themselves logged.
  - Customer app: org-owners see their own organization's audit log (Settings → Activity).
- Retention: 7 years for money-related entries, 2 years for others. Personal data inside entries is minimised (IDs, not full bank numbers).

Built on spatie/laravel-activitylog with extra columns (`impersonator_id`, `ip`, `user_agent`, `reason`, `hash`).

## 8. Access Logs

- **Authentication log** for both customers and staff: login success/failure, logout, 2FA challenge result, password reset, lockout, OTP sent/verified. Stores IP, user agent, approximate location and the account (or attempted email).
- **Sessions:** users see their active sessions (device, location, last active) and can log out other sessions. Staff sessions are visible to `super-admin`.
- **Alerts:** email on login from a new device. Console alerts on bursts of failed logins (possible credential stuffing).
- **HTTP access logs** are kept at Cloudflare/nginx level for 90 days for incident investigation; they are not stored in the app database.
- Retention: 1 year in the app.

Built on `rappasoft/laravel-authentication-log` (or equivalent) for both guards.

## 9. Platform Theme (configurable)

GoProperty's own look is a **setting, not code**, so the brand can be decided or changed later without a developer. Managed in **Settings → Platform theme** by `super-admin` or `content-manager`.

### What can be set

| Group | Settings |
| --- | --- |
| Identity | Product name (default "GoProperty"), short name, legal company name and address (for platform invoices), support email / phone / WhatsApp |
| Logos | Logo for light backgrounds, logo for dark backgrounds, square icon (one 512 px upload → favicon and PWA icons generated) |
| Colours | Primary, accent, neutral tone (slate / gray / zinc / stone), success / warning / danger (defaults provided). Full 50–950 shade scales are generated from each chosen colour |
| Typography | Font from a bundled list (Inter, Plus Jakarta Sans, Manrope, DM Sans), served locally |
| Shape | Corner style: sharp / rounded / extra-rounded |
| Appearance | Default light / dark / follow device |
| Login page | Headline, sub-text, three benefit bullets, side panel style (gradient or uploaded image), trust line |
| Emails | Header logo, footer text, social links |

### Where it applies

- GoProperty's own surfaces: marketing pages, login and signup, the staff console, GoProperty emails, platform (subscription) invoices, and the "Generated with …" footer line.
- Agency workspace and tenant portal use the platform theme for the app frame, plus the agency's logo. **Agency documents and agency bill pages always use the agency's branding** ([BRANDING_DOCUMENTS.md](BRANDING_DOCUMENTS.md)).

### How it works

- Stored as versioned global settings (`settings`, keys `theme.*`). Edits are a **draft** with a live preview (login page, dashboard, email, invoice). **Publish** makes it live; any earlier version can be restored; "Reset to default" is always available.
- Colours become CSS variables (`--color-primary-50` … `--color-primary-950`) output in the page `<head>`. Tailwind 4 theme tokens point at those variables, so **no asset rebuild is needed** when the theme changes. This matters on shared hosting, which has no Node on the server.
- Filament reads the same settings for the console colours, so the console always matches.
- Contrast is checked against WCAG AA when saving. If text on the primary colour would be hard to read, a darker shade is used for text automatically and a warning is shown.
- The theme is cached and the cache cleared on publish.

### Default theme (shipped until changed)

Product name "GoProperty", primary blue `#1D4ED8`, accent amber `#F59E0B`, neutral slate, font Inter, rounded corners, light appearance, generated text logo. Treat these as placeholders.

## 10. Console Path

- The console URL is `https://{domain}/{ADMIN_PATH}`. **Default: `gp-control`**, but the installer (`php artisan goproperty:install`) writes a unique value such as `gp-control-k7m2qx` (random suffix) into `.env`, so no two installations share a guessable path.
- Rules: lowercase letters, numbers and hyphens, 8–40 characters. Reserved words are rejected (`admin`, `login`, `o`, `portal`, `owner`, `pay`, `verify`, `api`, `webhooks`, `storage`, `build`).
- In production, if `ADMIN_PATH` is still the bare default `gp-control`, the operations dashboard shows a red warning and a daily email reminds `technical-admin` to change it.
- To change it: edit `ADMIN_PATH` in `.env`, then run `php artisan config:cache`. Staff are told the new address; the old path returns a plain 404.
- The path is the first thing staff bookmark. Never put it in public docs, emails to customers, `robots.txt` or support macros.
