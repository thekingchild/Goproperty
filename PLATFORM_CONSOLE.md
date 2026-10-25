# GoProperty — Platform Console (admin.goproperty.ng)

Companion documents: [ARCHITECTURE.md](ARCHITECTURE.md) · [ONBOARDING_COMPLIANCE.md](ONBOARDING_COMPLIANCE.md) · [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md) · [UX_DESIGN.md](UX_DESIGN.md)

The console is a Filament panel on its own subdomain. It has a separate `staff` guard and `platform_staff` table, its own session cookie, mandatory 2FA, a short idle timeout (30 min), and an optional IP allowlist. Every action in it is audited.

## 1. Staff, Roles & Designations

Two separate ideas:

- **Designation** = job title shown on the staff profile and in audit logs (e.g. "Head of Operations", "Finance Officer"). Picked from a managed list (`staff_designations`), grouped by **department**. Designations grant nothing.
- **Role** = what the person can do (spatie permissions on the `staff` guard). A person can hold more than one role.

### Roles

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

Invite by email → set password + 2FA on first login → active. Deactivation immediately ends all sessions. Staff profile: name, work email, phone, designation, department, roles, status, last login, 2FA status.

## 2. System Operations Dashboard (for `technical-admin`, `super-admin`)

Health at a glance, refreshed every 60 s:

| Area | Widgets |
| --- | --- |
| App | Uptime, error rate (Sentry), p95 response time, deploy version, maintenance mode toggle |
| Queues | Depth and wait time per queue (`default`, `notifications`, `billing`, `pdf`), failed jobs with retry/forget, Horizon link |
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
- **Session:** maximum 30 minutes. A visible banner on every page: "GoProperty support (Ada O.) is viewing this account — End session". Hand-off from `admin.` to `app.` uses a one-time signed token valid for 60 seconds.
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
