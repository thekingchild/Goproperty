# GoProperty — Platform Console (`goproperty.ng/{ADMIN_PATH}`)

Companion documents: [ARCHITECTURE.md](ARCHITECTURE.md) · [ONBOARDING_COMPLIANCE.md](ONBOARDING_COMPLIANCE.md) · [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md) · [UX_DESIGN.md](UX_DESIGN.md)

The console is a Filament 5 panel served at a **configurable, non-obvious path** (`ADMIN_PATH`, default `gp-control` plus a random suffix from the installer, never `/admin` — see § 10) on the main domain, which suits shared hosting. It can move to its own subdomain later by configuration only ([ONBOARDING_COMPLIANCE.md § 1](ONBOARDING_COMPLIANCE.md)). It has a separate `staff` guard and `platform_staff` table, its own session cookie (`gp_staff_session`, never read by customer pages), mandatory 2FA, a short idle timeout (30 min), and an optional IP allowlist. Every action in it is audited.

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

Needs a second staff member with the right role to approve (`App\Platform\Approvals\ApprovalService`; the approver permission per action: refunds `refunds.approve`, production restore `backups.approve_production_restore`, role grants `roles.manage` held by a super-admin, write-mode impersonation `impersonate.write`, data purge `data.purge`):

- Refunds above a set amount (e.g. ₦100,000).
- Restoring a backup to production.
- Granting `super-admin`, `finance-admin` or `compliance-officer`.
- Write-mode impersonation.
- Deleting/purging an organization's data (NDPA erasure).

### Staff lifecycle

Invite by email → set password + 2FA on first login → active. Deactivation immediately ends all sessions. Staff profile: name, work email, phone, roles, status, last login, 2FA status, IP allowlist (optional).

### How it is built (P01-07)

- **Console pages:** Staff (list, profile with history, Invite staff, Grant/Remove role, Edit details, Send invitation again, Deactivate/Reactivate), Staff → Roles (`/staff-roles`: create, edit permissions by area, deactivate/reactivate), Staff → Approvals (`/approvals`: pending requests with Approve/Reject). Each page and action is checked by a policy (`PlatformStaffPolicy`, `StaffRolePolicy`, `ApprovalRequestPolicy`); the services check again.
- **Catalogue in code:** `App\Platform\Staff\StaffPermission` (protected: `impersonate.write`, `backups.approve_production_restore`, `roles.manage`, `data.purge`); default grants in `DefaultStaffRole`, including § 6's impersonation grants and `system.status` (technical-admin). Staff management uses `staff.view`, `staff.invite`, `staff.manage`, `roles.manage` and `approvals.view`.
- **Default roles keep their names** (the two-person rules and later features refer to them); their permissions and descriptions can change. Custom roles can be renamed. No role is ever deleted; a role is deactivated once nobody holds it, and a deactivated role grants nothing.
- **Two-person rules for roles:** granting `super-admin`, `finance-admin`, `compliance-officer`, or any role carrying a protected permission, opens an approval request; adding a protected permission to a role does too (the other changes apply at once). Role-grant requests are approved by a **second super-admin**. Nobody can approve or reject their own request (service and policy). Requests expire (7 days for role grants).
- **Bootstrap:** right after install there is one super-admin and nobody else could approve. While nobody else (active or invited) holds `super-admin`, the lone super-admin's grant of `super-admin` applies at once, recorded with `bootstrap: true`; from then on the rules above apply.
- **At least two active super-admins:** the count of active super-admins never drops below min(current, 2): once two exist, no role removal or deactivation may leave fewer than two; while only one exists every console page shows a warning and nothing may leave none.
- **2FA:** every console page sends staff without 2FA to set it up (also for Livewire updates), and without 2FA a staff member holds no permission. Invitees set a password from the emailed link (one use, 7 days, stored hashed), are signed in, enrol 2FA, and their account becomes active on the next request.
- **Audit:** every invitation, acceptance, activation, grant, removal, role change, deactivation and approval decision is written to the audit log (log `staff`) with the acting staff member; entries about a staff member make up their profile history.

## 1A. Business Settings Managed in the Console

| Setting | Who edits | Who approves | Spec |
| --- | --- | --- | --- |
| Plans, prices, included units, features, SMS credits | `finance-admin` | — (audited; customers notified 30 days ahead) | [BILLING_PAYMENTS.md § 1](BILLING_PAYMENTS.md) |
| Transaction Tier limits and required documents | `compliance-officer` | `super-admin` | [ONBOARDING_COMPLIANCE.md § 5.2](ONBOARDING_COMPLIANCE.md) |
| Per-organization tier override | `compliance-officer` | — (reason + expiry required) | same |
| Email templates | `content-manager` | — (versioned) | § 5 |
| Backup destinations | `technical-admin` | — | § 4 |
| Staff roles and permissions | `super-admin` | Second super-admin for protected permissions | § 1 |
| Platform theme, hero/login images, public website text, SEO | `super-admin` or `content-manager` | — (versioned, preview first) | § 9.2–9.4 |
| Custom code (analytics, chat widgets) | `super-admin` (step-up) | — (audited) | § 9.5 |
| Sign-in security (passkeys, Turnstile, 2FA rules) | `super-admin` | — (audited) | § 9.6 |
| Console path (`ADMIN_PATH`) | `technical-admin` (via `.env` on the server) | — | § 10 |

## 2. System Operations Dashboard (for `technical-admin`, `super-admin`)

Health at a glance, refreshed every 60 s:

| Area | Widgets |
| --- | --- |
| App | Uptime, error rate (Sentry), p95 response time, deploy version, maintenance mode toggle. **System → Version**: current and latest release, pending migrations, last backup, and (no-SSH upgrades) a `super-admin`-only batched "run pending migrations" step — no in-app code download ([INSTALLER.md § 10](INSTALLER.md)) |
| Queues | Depth and wait time per queue (`default`, `notifications`, `billing`, `pdf`, `imports`), failed jobs with retry/forget; Horizon link on VPS, read from the `jobs`/`failed_jobs` tables on shared hosting |
| Scheduler | Last run + result of every scheduled command; in-process queue tick heartbeat; alert if a job didn't run. Shows the cron line built from the detected PHP CLI path (owner/technical-admin only). The heartbeats are also served as JSON at `/{ADMIN_PATH}/system/heartbeat` (permission `system.status`), which the installer's finish screen polls for its live cron check |
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
- **Secondary: Google Drive** via the app's own Flysystem adapter over the Drive v3 API (`App\Backup\GoogleDrive`; the community adapter needs a Guzzle version this app does not use). Caveat: Google service accounts have no storage of their own, so use a **Shared Drive** (Google Workspace) or OAuth credentials of a dedicated GoProperty Google account. Drive is best for DB dumps. Large media is better mirrored to a second S3-compatible provider (e.g. with rclone).
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

- **Who:** controlled only by staff permissions from the catalogue (§ 1), checked by policy on every start:
  - `impersonate.read_only` — default roles `support-agent`, `operations-manager` and `super-admin`.
  - `impersonate.write` — a protected permission (only a `super-admin` can add it to a role); each write-mode session also needs approval by a second staff member (two-person rule, § 1).
  - `impersonation.view_log` — read the impersonation log below; default roles `super-admin`, `operations-manager` and `compliance-officer`.
  Staff without the permission do not see the action, and a direct request is refused and recorded. Granting or removing these permissions is a role change and is audited like any other (§ 1).
- **No customer consent and no customer notification:** organizations have no setting to allow or refuse support access, and GoProperty does not email or notify the organization or the impersonated user about a session. Control sits on GoProperty's side: the permissions above, a mandatory reason, the 30-minute limit, the blocked actions and the audit trail. The customer terms of service and privacy notice state that GoProperty support may view accounts to give support and to monitor the service.
- **Start:** staff picks a user, types a reason (e.g. support ticket number). Can't impersonate other staff.
- **Session:** maximum 30 minutes. A visible banner on every page: "GoProperty support (Ada O.) is viewing this account — End session". Hand-off from the staff console to the customer app uses a one-time signed token valid for 60 seconds, which starts a separate, time-limited customer-side session; the staff session cookie is never sent to customer pages.
- **Always blocked while impersonating:** confirming/rejecting payments, voiding receipts, changing bank accounts or Paystack keys, billing transfers, changing passwords/2FA, inviting or removing members, deleting anything.
- **Audit trail (GoProperty side):**
  - Every session is a row in `impersonation_sessions` (staff, user, organization, reason, mode, approval, start, end and how it ended).
  - Session start, end (manual or timeout), each page viewed, each action taken and each blocked or refused attempt are written to the hash-chained audit log (§ 7) with both identities: the impersonated user as causer and the staff member in `impersonator_staff_id`, plus the session ID, organization, IP and user agent.
  - **Console → Customers → Impersonation log** (permission `impersonation.view_log`): every session, filterable by staff member, organization, user, mode and date; a session page shows the reason, approval and the full timeline of pages and actions. Viewing the log is itself audited.
  - Active sessions show on the Operations dashboard (§ 2, Security row) and a `super-admin` can end any of them.
  - The trail is kept for the audit-log retention period (§ 7) and is not shown in the customer app.

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

- **Authentication log** for both customers and staff: login success/failure (password or passkey), logout, 2FA challenge result, recovery code used, Turnstile failure, invitation accepted, reset link sent, agency-created sign-in link, password reset, lockout. Stores IP, user agent, approximate location and the account (or attempted email).
- **Sessions:** users see their active sessions (device, location, last active) and can log out other sessions. Staff sessions are visible to `super-admin`.
- **Alerts:** email on login from a new device. Console alerts on bursts of failed logins (possible credential stuffing).
- **HTTP access logs** are kept at Cloudflare/nginx level for 90 days for incident investigation; they are not stored in the app database.
- Retention: 1 year in the app.

Built on `rappasoft/laravel-authentication-log` (or equivalent) for both guards.

## 9. Platform Theme, Website & Sign-in Settings (configurable)

GoProperty's own look, public website, search appearance and sign-in security are **settings, not code**, so they can change without a developer or an asset rebuild. The model follows the product owner's SocietyFlow build (branding JSON + typed settings registry), with one addition: GoProperty edits are drafted and previewed before they go live.

Visual references: [`docs/design/templates/landing.html`](docs/design/templates/landing.html), [`login.html`](docs/design/templates/login.html), [`login-2fa.html`](docs/design/templates/login-2fa.html). Comments marked `theme.…` in those files show where each setting is used.

### 9.1 Console pages

| Page (Settings → …) | What's on it | Who edits |
| --- | --- | --- |
| **Brand & appearance** | Identity, logos, favicon/icons, colours, font, corner style, landing hero image, login side image | `super-admin`, `content-manager` |
| **Public website** | Hero and login text, announcement ribbon, footer text, social links, FAQ, legal pages | `super-admin`, `content-manager` |
| **Search & sharing** | SEO title/description, title pattern, share image, indexing, canonical origin | `super-admin`, `content-manager` (canonical origin: `super-admin` + step-up) |
| **Custom code** | Head / body-start / body-end scripts and custom CSS | `super-admin` only, with step-up (password + 2FA within 10 minutes) |
| **Sign-in security** | Passkeys, Turnstile keys and mode, 2FA rules, "remember me" | `super-admin` (+ `technical-admin` read-only) |

Each page is one column of cards with a live preview column on the right (desktop). Every save is audited with before/after values (`setting.updated`); secrets are shown as "•••• set" and never sent back to the browser.

### 9.2 Brand & appearance

| Setting (key) | Default | Rules |
| --- | --- | --- |
| `theme.name` / `theme.short_name` | "GoProperty" / "GoProperty" | Plain text, ≤ 120 / ≤ 30 |
| `theme.tagline` | "Property management for Nigeria" | ≤ 160 |
| `theme.legal_name`, `theme.address`, `theme.support_email`, `theme.support_phone`, `theme.support_whatsapp` | — | Used on platform invoices, footer and JSON-LD |
| `theme.logo_light` | Generated text logo | PNG/JPG/WebP/SVG, ≤ 2 MB, recommend ≥ 480×120. For light backgrounds (footer, console, emails) |
| `theme.logo_dark` | Generated (white mark) | Same rules. For dark backgrounds (hero nav, login panel) |
| `theme.logo_mode` | `logo_with_text` | `logo_only` or `logo_with_text` (mark + product name) |
| `theme.icon` | Generated monogram | Square PNG/WebP/SVG, ≤ 1 MB, ≥ 512×512. Generates PWA icons and apple-touch icon |
| `theme.favicon` | From icon, else monogram | PNG/SVG/ICO, ≤ 1 MB, ≥ 48×48 |
| `theme.color_primary` / `theme.color_accent` | `#1D4ED8` / `#F59E0B` | Hex; 50–950 scales generated. `--hero-deep` is the primary pushed darker until white text reaches 9:1 |
| `theme.neutral` | `slate` | slate / gray / zinc / stone |
| `theme.font` | Inter | From a bundled, self-hosted list (Inter, Plus Jakarta Sans, Manrope, DM Sans) |
| `theme.radius` | `rounded` | sharp / rounded / extra-rounded |
| **`theme.hero_image`** | none → brand gradient + skyline art | JPG/PNG/WebP, ≤ 5 MB, recommend 2400×1350 landscape, subject on the right (the headline sits bottom-left) |
| `theme.hero_overlay_color` / `theme.hero_overlay_opacity` | `--hero-deep` / 60 % | Hex or "follow brand"; 0–100 |
| **`theme.auth_image`** | none → brand gradient + skyline art | JPG/PNG/WebP, ≤ 5 MB, recommend 1200×1600 portrait (left panel is 55 % wide, full height) |
| `theme.auth_overlay_color` / `theme.auth_overlay_opacity` | `--hero-deep` / 65 % | Hex or "follow brand"; 0–100 |
| `theme.auth_show_activity` | on | The floating "Payment confirmed / Receipt sent" glass cards on the login panel |

**Uploads (all images):**

- Check the extension **and** the file's magic bytes (PNG, JPEG, WebP, ICO). SVG only for logos/icons, and rejected if it contains `<script`, `on*=` handlers, `javascript:`, `foreignObject`, `iframe`/`embed`/`object`, `<!ENTITY` or `data:` links.
- Photos (hero, login, share image) are **re-encoded** on upload: EXIF removed, converted to WebP (quality 82), and resized to 2400 px and 1200 px wide variants for `srcset`. Shared hosting has GD/Imagick, so this needs no extra service. (SocietyFlow stores originals as-is; GoProperty converts because tenants use slow networks.)
- Served from `/brand/{role}` with `?v={hash}` cache-busting, `Cache-Control: public, max-age=31536000, immutable`, `X-Content-Type-Options: nosniff`, and a sandbox CSP on SVGs.
- Replacing an image deletes the old file. "Remove" falls back to the default (gradient + skyline art, or the generated logo).
- **Photo mode** on the hero/login panel: photo → overlay at the chosen colour/opacity → a fixed bottom fade so the white headline always stays readable. The overlay preview in the console shows the real contrast of white text over the image; below 4.5:1 it warns.

### 9.3 Public website

| Setting | Default | Rules |
| --- | --- | --- |
| `site.hero_heading` / `site.hero_subheading` | "Rent in." / "Receipts out." | ≤ 40 each (two-line headline; the second line is accent-coloured) |
| `site.hero_badge` | "Built for Nigerian property managers" | ≤ 60 |
| `site.hero_lede` | (see landing.html) | ≤ 240, plain text |
| `site.hero_trust` | "14-day free trial · No card required · Cancel anytime" | 3 items, ≤ 30 each |
| `site.auth_headline` / `site.auth_benefits` / `site.auth_trustline` | (see login.html) | Headline ≤ 80; 3 benefits ≤ 50 each; trust line ≤ 80 |
| `site.announcement` / `site.announcement_link` | off | ≤ 140; dismissible ribbon above the nav, remembered per content |
| `site.footer_text` | "Property management and rent collection for Nigerian agencies, landlords and tenants." | ≤ 200 |
| `site.social_links` | — | X, LinkedIn, Instagram, Facebook, YouTube, WhatsApp (URLs, https only) |
| `site.faq` | 6 starter items | ≤ 30 items, question ≤ 140, answer sanitised HTML |
| `site.privacy_policy`, `site.terms` | Templates | Sanitised HTML |

Plain-text fields strip tags and reject `<` `>`. Prices on the landing page are read from the live plans table ([BILLING_PAYMENTS.md § 1](BILLING_PAYMENTS.md)), never typed in here.

### 9.4 Search & sharing (SEO)

| Setting | Default | Rules |
| --- | --- | --- |
| `seo.title` | `theme.name` + tagline | ≤ 70 |
| `seo.title_pattern` | `{page} · {site}` | Must contain `{page}` |
| `seo.description` | — | ≤ 160 recommended, ≤ 320 max, no `<>` |
| `seo.share_image` | Generated 1200×630 brand card (logo, name, tagline, colours) | JPG/PNG/WebP ≤ 3 MB, 200–4096 px a side; re-encoded, EXIF stripped |
| `seo.indexing_enabled` | on in production, **off on staging** | Off → `robots.txt` = `Disallow: /`, `<meta name="robots" content="noindex">`, sitemap 404 |
| `seo.canonical_origin` | `APP_URL` | `https://host` only; `super-admin` + step-up |

Output on public pages: `<title>`, description, canonical, Open Graph (`og:site_name`, `og:type`, `og:title`, `og:description`, `og:url`, `og:image` + size, `og:locale=en_NG`), `twitter:card=summary_large_image`, `theme-color`, favicon/manifest links, and JSON-LD (`Organization` + `SoftwareApplication` with the Starter price as an `Offer`). Help-centre and news articles can override title and description per page.

`/robots.txt` disallows `/login`, `/o/`, `/portal`, `/owner`, `/pay/`, `/verify/` and never mentions the console path. `/sitemap.xml` lists marketing pages and published articles (cached 10 minutes). Login, 2FA, password and app pages are always `noindex, nofollow`.

### 9.5 Custom code

| Setting | Where it prints | Rules |
| --- | --- | --- |
| `custom_code.head` | End of `<head>` | ≤ 50 KB, no control characters |
| `custom_code.body_start` | Right after `<body>` | same |
| `custom_code.body_end` | Before `</body>` | same |
| `custom_code.css` | `<style data-custom-css>` in `<head>` | ≤ 50 KB, must not contain `</style` |

- For analytics (GA4, Meta Pixel), Search Console verification tags, chat widgets and similar. Saved raw (it's code); every change is audited with the full before/after.
- **Public marketing pages only.** Never printed on: login, signup, 2FA, password pages, `/o/…`, `/portal`, `/owner`, `/pay/…`, `/verify/…`, the console, or any page shown to a signed-in user. This keeps third-party scripts away from passwords, tenant data and money pages (UX_DESIGN.md § 3.1). SocietyFlow lets the owner opt in to auth/member scopes; GoProperty deliberately does not.
- **Safe mode:** `?safe=1` on any public page renders it without custom code, so a broken script can be diagnosed and removed.
- Cached; the cache is cleared after each save.
- The CSP on public pages allows the script hosts listed in the `custom_code.allowed_hosts` setting (default: Google Tag Manager/Analytics), so a pasted script from an unknown host is blocked until a `super-admin` adds it.

### 9.6 Sign-in security

| Setting | Default | Notes |
| --- | --- | --- |
| `auth.passkeys_enabled` | on | WebAuthn via Fortify passkeys (`laravel/passkeys` + `@laravel/passkeys`). Relying party = host of `APP_URL` |
| `auth.turnstile_mode` | `after_failures` | `after_failures` (shows after 3 failed attempts for the account or IP in 15 min) or `always` (every login, signup, password-reset and contact form) |
| `auth.turnstile_site_key` / `auth.turnstile_secret_key` | — | Secret encrypted. Without keys, the failure threshold becomes a 15-minute lockout instead. Server check posts `cf-turnstile-response` to Cloudflare siteverify with a 5 s timeout and **fails closed** |
| `auth.lockout_after` / `auth.lockout_minutes` | 5 / 15 | Per identifier + IP; the generic "Those details don't match our records" message never changes |
| `auth.remember_days` | 30 | "Keep me signed in"; not offered to accounts that must use 2FA on every sign-in |
| `auth.trust_device_days` | 30 | "Don't ask again on this device" on the 2FA screen |
| `auth.require_2fa_for` | Tier 2/3 org owners | Also selectable: all org owners, all agency staff |

No SMS codes are used anywhere in signup, sign-in, 2FA, invitations or password reset (SMS is paid per message). A passkey sign-in counts as two factors, so it skips the 2FA screen.

### 9.7 Where it applies

- GoProperty's own surfaces: marketing pages, login and signup, the staff console, GoProperty emails, platform (subscription) invoices, and the "Generated with …" footer line.
- Agency workspace and tenant portal use the platform theme for the app frame, plus the agency's logo. **Agency documents and agency bill pages always use the agency's branding** ([BRANDING_DOCUMENTS.md](BRANDING_DOCUMENTS.md)).

### 9.8 How it works

- Stored as versioned global settings (`settings`, keys `theme.*`, `site.*`, `seo.*`, `custom_code.*`, `auth.*`; uploaded files on the private `brand` disk, `storage/app/brand`, named by content hash and served only through `/brand/{role}`). Each key is declared once in a settings registry with its type, default, validation, permission and whether it is a secret or needs step-up — the console forms, validation and audit all read from that registry.
- Brand, website and SEO edits are a **draft** with a live preview (landing hero, login page, dashboard, email, invoice, Google result, share card). **Publish** makes them live; any earlier version can be restored; "Reset to default" is always available. Custom code and sign-in security apply on save (no draft) because they are operational.
- Colours become CSS variables (`--color-primary-50` … `--color-primary-950`, `--color-brand-accent*`, `--hero-deep`, `--hero-deep-2`, overlay colour/opacity, `--gp-radius*`) printed in the page `<head>`. The accent is `--color-brand-accent` because Flux uses `--color-accent` for its own accent (mapped to the primary), and radii are `--gp-radius*` because Tailwind owns `--radius-*`. Tailwind 4 theme tokens point at those variables, so **no asset rebuild is needed** when the theme changes. This matters on shared hosting, which has no Node on the server.
- Filament reads the same settings for the console colours, so the console always matches.
- Contrast is checked against WCAG AA when saving. If text on the primary colour would be hard to read, a darker shade is used for text automatically and a warning is shown.
- Settings are cached; the cache is cleared on publish/save.

### 9.9 Default theme (shipped until changed)

Product name "GoProperty", primary blue `#1D4ED8`, accent amber `#F59E0B`, neutral slate, font Inter, rounded corners, light appearance, generated text logo, gradient + skyline art on the hero and login panel. Treat these as placeholders.

## 10. Console Path

- The console URL is `https://{domain}/{ADMIN_PATH}`. **Default: `gp-control`**, but the installer (web wizard or `php artisan goproperty:install`, [INSTALLER.md](INSTALLER.md)) writes a unique value such as `gp-control-k7m2qx` (random suffix) into `.env`, so no two installations share a guessable path.
- Rules: lowercase letters, numbers and hyphens, 8–40 characters. Reserved words are rejected (`admin`, `login`, `o`, `portal`, `owner`, `pay`, `verify`, `api`, `webhooks`, `storage`, `build`, `install`, `up`, `livewire`, `brand`, `dashboard`, `settings`; `App\Installer\Finishing\AdminPath`).
- In production, if `ADMIN_PATH` is still the bare default `gp-control`, the operations dashboard shows a red warning and a daily email reminds `technical-admin` to change it.
- To change it: edit `ADMIN_PATH` in `.env`, then run `php artisan config:cache`. Staff are told the new address; the old path returns a plain 404.
- The path is the first thing staff bookmark. Never put it in public docs, emails to customers, `robots.txt` or support macros.
