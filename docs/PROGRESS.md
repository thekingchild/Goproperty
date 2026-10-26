# GoProperty — Progress Ledger

The status of every task in [IMPLEMENTATION_PLAN.md § 3](../IMPLEMENTATION_PLAN.md). Rules for updating it are in [IMPLEMENTATION_PLAN.md § 3.1](../IMPLEMENTATION_PLAN.md) and [AGENT.md § 6](../AGENT.md).

Status values: `Not started` · `In progress` · `Blocked` · `In review` · `Complete (YYYY-MM-DD)`.

## Current state

- **In progress:** P01-07 (console staff roles) and P01-02 (registration wizard). Each task is built in its own worktree and reviewed before it merges
- **In review:** P01-09 (web installer wizard): done and verified locally on MariaDB 11.4; the Install drill on MySQL 8 and MariaDB 10.6 waits on GitHub Actions. P00-03 (hosting profiles), P00-04 (release package) and P00-06 (installer CLI + install drill): done and verified locally; each needs one green run in GitHub Actions (CI for P00-03, a tagged Release for P00-04, the Install drill on MySQL 8 and MariaDB 10.6 for P00-06) once Actions starts jobs. P00-12 (backups): built and drilled locally; needs one real run to an S3-compatible bucket and a Google Shared Drive (PRE-03 accounts)
- **Next task:** P01-04, P01-05 and P01-06 once P01-02 and P01-07 land; PRE-01 to PRE-04 continue with the product owner
- **Blocked:** P00-02: GitHub Actions jobs are not being given a runner (account-level), and branch protection on `main` needs a repo admin. This also holds P00-03's CI check, P00-04's release run and P00-06's install drill
- **Blocked:** P00-13 (staging): the deploy scripts, the deploy on merge and the deploy drill are built and drilled locally; it needs a staging host (PRE-04) and working Actions runners (P00-02)

## Board

| Phase | Tasks | Complete | Status |
| --- | --- | --- | --- |
| PRE | 5 | 1 | In progress |
| P00 | 13 | 7 | In progress |
| P01 | 9 | 1 | In progress |
| P02 | 7 | 0 | Not started |
| P03 | 13 | 0 | Not started |
| P04 | 3 | 0 | Not started |
| P05 | 6 | 0 | Not started |
| P06 | 11 | 0 | Not started |
| P07 | 4 | 0 | Not started |
| P08 | 4 | 0 | Not started |
| P09 | 8 | 0 | Not started |
| **Total** | **83** | **9** | |

## Ledger (newest first)

Each entry: task ID and status, date, what was delivered, tests run and results, follow-ups, next task.

### P01-09 — In review (2026-10-26): web installer wizard

**Status:** In review. Everything is built and drilled locally on MariaDB 11.4.12, and every test passes. The AC names MySQL 8 and MariaDB 10.6, which only GitHub Actions can run, and Actions has no runners yet (P00-02). Same situation as P00-06.

**To close it:** once Actions starts jobs, run **Install drill** by hand (or let the nightly run). Confirm all four database jobs pass (MySQL 8.0/8.4, MariaDB 10.6/11.4); each now walks the web wizard too. Then mark P01-09 complete.

**Delivered** ([INSTALLER.md § 4–5](../INSTALLER.md)):
- **First screen and unlock** (`GET /install`, `POST /install/unlock/token|file`). These run before `APP_KEY` exists, with no session, cookie or CSRF.
  - **Setup token:** checked with `SetupToken::verify()`.
  - **File check** (`App\Installer\Web\UnlockChallenge`): a `GP-UNLOCK XXXX-XXXX-XXXX` phrase, valid 30 minutes. Only hashes are stored, and the 20 most recent phrases stay valid, so a reload doesn't break a file already made. The file is read from `storage/app/install-unlock.txt`. A byte-order mark, CRLF and lower case are tolerated; symlinks and files over 512 bytes are refused. The file is deleted on success.
  - **Checks on each unlock post:** same-origin (`Origin`, or `Sec-Fetch-Site: same-origin` plus a matching `Referer`), HTTPS except on local hosts (with AutoSSL / Let's Encrypt advice), and 5 attempts per IP per 15 minutes. Attempts are counted in a `flock`ed file with hashed IPs (`UnlockAttempts`).
  - **On success:** `.env` is created from `.env.example` with a key (`InstallationSteps::ensureApplicationKey`), `APP_DEBUG=false` is written, and the encrypted file session starts in the same request.
- **Session and grant:**
  - **Session:** the wizard has its own encrypted file session (`gp_install_session`, path `/install`, Strict, Secure on https; `InstallerSession`). `InstallerWebStack` builds the cookie, session and CSRF middleware only once a key exists, so a key-less request never fails at terminate.
  - **Grant** (`InstallerGrant`): a token in the session and its hash in `install.grant`, so there is one grant at a time and a new unlock ends the old browser's access.
  - **Rotation:** the first use swaps both the token and the session ID.
  - **Lifetime:** 60 minutes from the last completed step. Each step and each migration batch renews it; viewing pages does not. On expiry the owner unlocks again in the browser, and nothing is lost.
- **Steps** (`App\Http\Controllers\Install\*`, `WizardProgress`): steps open in order. Progress is read from the markers, the `migrations` table and the staff table, so a lost session or a re-unlock resumes. Only "server check seen" and "email/storage skipped" live in the session. If `.env`'s database stops matching the checkpoint, every step shows "Install stopped" (409).
  1. **Server check:** the detailed report, grouped. Passing checks are collapsed; failures show cPanel and Plesk fixes. Blocking failures stop the install.
  2. **Site & database:** "Test connection" writes nothing; "Save" goes through `configureSite` (probe, `.env`, checkpoint). Passwords are never echoed; if left empty later, the saved one is kept.
  3. **Build:** one batch per request (`InstallationSteps::migrateBatch()`, new) with a `<progress>` bar ("5 of 17") that continues by itself. After the last batch, `seed()` runs. A failed migration offers "Empty the database and start this step again" (`FreshDatabaseReset`, typed database name).
  4. **Email & storage:**
     - **Email:** SMTP presets for Postmark, Mailgun and SES, or any SMTP host, then a test email. It must succeed or be skipped.
     - **Storage** (`StorageSettings`, `StorageProbe`): S3-compatible. It writes, reads back and deletes a file, and writes `AWS_*` only after that test passes. Errors are explained with the keys removed. Storage can also be skipped.
  5. **First super-admin:** created with the shared `createSuperAdmin`, which holds the P01-07 hook comment for the `super-admin` role. 2FA is enrolled in the wizard (`TwoFactorEnrolment`):
     - a QR code, manual key and `otpauth` link, confirmed with a 6-digit code;
     - stored the way Filament stores it (secret encrypted, 8 recovery codes hashed);
     - the codes are shown once with Download, Copy and a required "I've saved my recovery codes" tick.
     - Resuming with the same email and password works; another account is refused.
  6. **Finish:**
     - The `Finalizer` runs (cleanup checkpoint first, `ADMIN_PATH`, secrets removed, lock and row, caches).
     - The install is written to the audit log (`InstallationSteps::recordInstallation`, `log_name` `installer`, version, `via`, IP). The CLI now does this too.
     - **Console handover** (`ConsoleHandover`): a database session under `gp_staff_session` with the console's flags, logged as a staff sign-in. Not done when `ADMIN_DOMAIN` is set.
     - **Finish screen**, shown once as the reply to the post:
       - the console URL ("Bookmark this");
       - the detected cron line, with cPanel and Plesk steps;
       - a live cron check polling the new console route `/{ADMIN_PATH}/system/heartbeat` (`SchedulerHeartbeatController`, permission `system.status`, added to the interim `ConsolePermissions`);
       - the installation record as a one-time download (with `APP_KEY`; the web install leaves no copy on the server);
       - next steps (anything skipped, Paystack, Termii, Turnstile, backups, theme, Cloudflare, `APP_DEBUG` confirmed off).
- **Views:** plain PHP (`resources/views/installer/layout.php`, `steps/*.php`) on the login page's split shell, ported to the static `public/install.css`. A brand panel with a vertical step list (`aria-current="step"`) becomes a "Step 3 of 7" bar on phones. Checked in the browser at desktop and 360 px.
- **`ArtisanOptimizer`:** puts the application back as the facade root and container instance after `config:cache`, which boots a second app and leaves the facades pointing at it. Harmless at the end of a command, but the web finish keeps working in the same request.
- **Install drill** (`scripts/release/install-drill.sh`): after the CLI install, a second split layout is installed by walking the wizard with `curl` against `php -S`, under the same shared-host limits:
  - file-check unlock, then the migrations one batch per request;
  - a test email to a new local SMTP sink (`scripts/release/smtp-sink.php`), with storage skipped;
  - TOTP enrolment (a code from the package's own Google2FA) and the 8 recovery codes;
  - finish, checking the console path, cron line and record with `APP_KEY`;
  - the handed-over console session, and the heartbeat turning fresh after one `schedule:run`;
  - `/install` and the wizard routes return 404, `check-installation` passes, and `audit-verify` passes.

  Both schemas are compared with a plain migrate. `install-drill.yml` now also runs on changes to installer controllers, middleware, routes, views and `install.css`.
- **Docs:**
  - INSTALLER.md § 3, § 4 (phrase handling, attempts file, installer session, grant mechanics, `APP_DEBUG` at unlock), § 5 (plain PHP views, step order and resume, batch settings, storage written after its test and skippable, 2FA storage, audit entry, handover, record download), § 7 and § 12.
  - [install-recovery.md](runbooks/install-recovery.md): new § 5 on the web installer, and the record that was never downloaded.
  - PLATFORM_CONSOLE.md § 2 (the heartbeat endpoint) and AGENT.md § 2.

**Tests and evidence (local, MariaDB 11.4.12):**
- Orchestrator's review run after merging P01-01 into the branch: `composer ci` Pint passed, Larastan 0 errors, Pest **917 tests: 916 passed, 1 skipped, 5,377 assertions**. The `BackupRunnerTest` flake noted below came from the shared backup temp folder that P01-01 fixed.
- `composer ci`: Pint passed, Larastan 0 errors, Pest **878 tests: 877 passed, 1 skipped, 5,131 assertions**. 54 tests are new:
  - `tests/Feature/Installer/WebUnlockTest.php` (23)
  - `WebInstallWizardTest.php` (10)
  - `NoHttpRecoveryTest.php` (7)
  - `tests/Feature/Console/SchedulerHeartbeatTest.php` (4)
  - `tests/Unit/StorageSettingsTest.php` (10)

  `tests/Support/InstallerBrowser.php` is a cookie-carrying test browser that drops in-memory sessions between requests.
- **AC: web install drill (file-check unlock, TOTP enrolment, finish):**
  - **In Pest:** `WebInstallWizardTest` › "installs GoProperty over HTTP, the same as the command line" uses a real throwaway database, a file-check unlock, a real test email over SMTP and TOTP enrolment. After finish it checks the same `.env` values, markers, staff, audit entry and schema as a plain migrate. "hands the owner a signed-in console session…" opens the console dashboard and heartbeat with the handed-over cookie.
  - **Drill:** on freshly built packages `goproperty-0.19.0-rc.1` and `rc.2`, `install-drill.sh` passed every step, CLI and web. Batches ran in 0–1 s, the finish took 5 s under `max_execution_time=30`, and both schemas were identical to a plain migrate (186 columns and tables). The release drill (`verify-drill.sh`) also passed on rc.2.
  - **Local caveat, as in P00-06:** this PHP has no `intl`, so the local run used a scratch copy of the script that drops `intl` from the unpacked checker (restored before `verify-package`). The repository script and the zip were unchanged.
  - **Not run here:** MySQL 8 and MariaDB 10.6 (GitHub Actions only, blocked by P00-02).
- **AC: after finish `/install` is 404 and no HTTP route can reset or re-run installation:**
  - `NoHttpRecoveryTest`: every installer route has `EnsureInstallerOpen` first. With valid unlock proofs present, every installer route and method returns 404 with no cookie and changes nothing. Each single marker (lock, row, cleanup checkpoint) closes them all.
  - It also checks that no route outside `/install` injects installer services or uses an installer controller, and that no web-reachable code (`app/Http`, `app/Livewire`, `app/Platform`, views, routes) mentions the installer services or the install, finish, migrate or wipe commands.
  - The walk-through test and the drill also show 404 after a real finish.
- **Flaky, unrelated:** `BackupRunnerTest` › "reports each destination after a manual run" failed once in the first parallel run (exit 1 instead of 0). It passed 3 times on its own and in 3 later full parallel runs, including the final `composer ci`. The dumper's queries are scoped to the current database, so the new scratch-database tests should not affect it.

**Follow-ups:**
- P01-07: assign `super-admin` in `InstallationSteps::createSuperAdmin` (comment in place). Move `system.status` into the permission catalogue (`technical-admin`, `super-admin`).
- `ConsolePanelProvider` and `ConsolePermissions` gained one line each, which may conflict with P01-07 and P01-08 edits to the same files.
- The command-line install still does not ask for S3 storage (INSTALLER.md § 7); the wizard tests it.
- The upgrade drill (previous release → new) remains open (INSTALLER.md § 12).

**Next:** P01-01 to P01-08 continue. P01-09 closes with P00-06 once the Install drill workflow runs.

### P01-01 — Complete (2026-10-26): organization switcher, roles per organization (spatie teams)

**Delivered:**
- **spatie/laravel-permission 8.3.2** (`~8.3.2`) with teams: team = organization, team column `organization_id` (`config/permission.php`).
  - **Tables** (five migrations, one table each, literal names for the installer's `OwnTables`): `permissions`, `roles` (`organization_id` nullable FK), `model_has_permissions` and `model_has_roles` (surrogate `id`, nullable `organization_id` FK, unique `(organization_id, role/permission, model_id, model_type)`), `role_has_permissions`. Spatie's stub puts the team column in the primary key, which cannot be null; the surrogate key lets staff hold global (no-organization) roles in the same tables.
  - **The team follows `OrganizationContext`:** `App\Tenancy\OrganizationTeamResolver` reads the context on every call and refuses `setPermissionsTeamId()`, so a team id cannot outlive a request or a queued job. `OrganizationContext::outside()` runs code with no organization.
  - **Customers** (`App\Tenancy\HasOrganizationRoles` on `User`): forgets roles/permissions loaded for another organization (same model across `run()` calls or restored in a job), refuses grants outside an organization, to non-members, or of a role another organization defined; `joinOrganization()`, `roleNamesIn()`, `hasRoleIn()`, `roleNamesByOrganization()`, `workspaceOrganizationIds()`/`workspaceOrganizations()`.
  - **Staff** (`App\Platform\Console\HasStaffRoles` on `PlatformStaff`): spatie roles on the `staff` guard, always read and granted outside any organization context, so they stay global even during impersonation (P01-08). The staff catalogue, console and the extra `roles` columns stay with P01-07.
- **Customer roles and permission catalogue in code:** `App\Enums\OrganizationPermission` (26 permissions incl. `workspace.access`) and `App\Enums\OrganizationRole` (`org-owner`, `org-admin`, `agent`, `property-owner`, `tenant`, with their grants per ARCHITECTURE.md § 5). `OrganizationRolesSeeder` (idempotent, syncs grants back to the catalogue) is in `InstallSeeder::SEEDERS`; the demo seeder makes the demo user `org-owner` of Demo Realty.
- **Workspace access** (extra requirement from the orchestrator): `/o/{slug}` admits only members holding `workspace.access` there (`org-owner`, `org-admin`, `agent`), through `OrganizationPolicy::view` in `SetOrganizationContext`. Tenant-only and property-owner-only members, members with no role, strangers, unknown slugs and old slugs of such organizations all get the same 404; old slugs still 301 for real workspace members.
- **Organization switcher** in the app shell: lists only workspaces the user may open, with their role in each; choosing one posts to `POST /organizations/{slug}/switch` (`SwitchOrganizationController`, policy `switchTo`, 404 otherwise), which opens it and stores it as `organization_user.is_default` (exactly one per user). **Picker** at `/dashboard` (cards with role and "Last used").
- **Landing after sign-in** (`App\Tenancy\WorkspaceLanding`) for password, 2FA and passkey sign-in (custom Fortify `LoginResponse`, `PasskeyLoginResponse`, updated `TwoFactorLoginResponse`): the intended URL, else the only workspace, else the last chosen, else the picker.
- **`App\Models\Membership`** (`organization_user`, `BelongsToOrganization`): the organization-scoped way to list members. **`App\Models\Role`** (spatie role with `organization()`).
- **Reusable cross-tenant probes:** `Tests\Support\OrganizationScopedModels` (+ dataset `organization-scoped models` in `tests/Datasets/`) lists every organization-owned model with a factory; `CrossTenantAccessTest` runs each through no-context reads/creates, scoped listing, find by foreign id, bulk update/delete, create in and move to another organization, and a URL-bound foreign id (404), and fails while any `BelongsToOrganization` model in `app/` or `Modules/` is missing from the list. Later tasks add their models there.
- `organizations` added to `AdminPath::RESERVED`.
- **Fix outside the task (P00-12):** spatie's `BackupJob` takes its temporary folder from the config repository, not from `BackupRunner`'s per-run config, so every run shared `backup-temp/temp` and emptied the other's zip (the "own folder per run" comment was not true). It showed up as a parallel-test race in `GoogleDriveAndFilesTest`. `BackupRunner` now points the repository at the run's folder during the job; regression test added.
- **Docs:** DATABASE_SCHEMA.md § 1 (`organization_user`, the five spatie tables) and § 8 (staff roles share the tables, null organization); ARCHITECTURE.md § 4 (switcher, landing, team resolver, cross-tenant probes) and § 5 (staff roles global, catalogue location, who opens the workspace).

**Tests and evidence (local, MariaDB 11.4):**
- `composer ci` (agent, twice; then the orchestrator's review run): Pint passed, Larastan 0 errors, Pest **863 tests / 2,997 assertions**, 0 failed, 1 skipped (the existing module-boundary check). The review run first caught a flaky test: under a long parallel run the queue tick's 192 MB memory guard could stop the tick before any job ran. `OrganizationRolesTest` and `QueueTickTest` now raise that limit for the test and assert the tick drained.
- **AC: two orgs, a user in both can switch:** `OrganizationSwitcherTest` "lets a member of two organizations switch between them" (picker → switch → `/o/other-realty` with role Agent → switch back → next sign-in lands on the last choice), "keeps exactly one default organization", one-org user goes straight to it, 2FA and passkey landing, intended URL wins.
- **AC: data never crosses orgs:** `CrossTenantAccessTest` (dataset over every organization-owned model + completeness check, members listing, roles/permissions per organization, another organization's custom role, organization settings); `OrganizationRolesTest` (team from context only, per request, per queued job through the real queue tick, stale relation never answered, refused grants, staff global roles incl. inside an organization context).
- **AC: `/o/{slug}` of an org you don't belong to returns 404:** `OrganizationSwitcherTest` "answers 404 for an organization the user does not belong to" (another org, its previous slug, a third org, unknown slug; switching to them also 404), tenant/property-owner-only member 404 and not listed, member without a role 404; `SetOrganizationContextTest` still green.
- Existing tests changed: `SurfacesTest` now expects the one-org demo user to land on `/o/demo-realty` instead of `/dashboard` (the new landing rule); `AppShellTest`, `ThemePublishingTest`, `SurfacesTest` and `SetOrganizationContextTest` make their user a member with a role (`joinAs()` in `tests/Pest.php`) instead of a bare `attach()`, because a member without a workspace role is now refused.

**Follow-ups:**
- The unique indexes on `model_has_roles`/`model_has_permissions` include the nullable `organization_id`; MySQL and MariaDB treat NULLs as distinct, so the database does not stop a duplicate global (staff) assignment. Spatie checks before inserting; P01-07 should keep staff grants going through `HasStaffRoles`.
- P01-04 (invitations) and P01-06: removing a member must also remove their role assignments in that organization (`model_has_roles` rows with its `organization_id`).
- P01-07: add `is_default`, `is_protected`, `description`, `active` to `roles` and the staff catalogue; create staff roles with `organization_id` null (outside an organization context). `permission:create-role --team-id` and `permission:assign-role --team-id` are refused by the resolver; use code with `OrganizationContext::run()`.
- P01-08: staff permission checks during impersonation go through `HasStaffRoles` (already outside the context).
- The first Livewire component inside `/o/{slug}` (P02) needs `SetOrganizationContext` as Livewire persistent middleware, or its update requests run without a context (they fail closed today).
- Tenants and agency-invited owners land on the picker until P03/P04 route them to `/portal` and `/owner`. `users.last_active_organization_id` (DATABASE_SCHEMA.md § 1) is not used; `organization_user.is_default` holds the last choice.
- Agent narrowing by building (`agent_building`) and record-level rules stay in the policies of the tasks that add those models.

**Next:** P01-02 (registration wizard; the first user joins as `org-owner` with `joinOrganization()`).

### P02 and P03 split into sub-tasks — 2026-10-26

[docs/plans/P02-P03-subtasks.md](plans/P02-P03-subtasks.md) splits P02 into 10 sub-tasks and P03 into 22, each with what it builds, its dependencies and its acceptance. Every original acceptance criterion maps to one sub-task. It also sets the run order: waves of at most two sub-tasks in parallel, each pair chosen so they do not edit the same files. The file ends with 15 decisions and doc gaps the product owner should settle before P02 starts (module list, cycle-maths naming, public lookups without an organization context, webhook URL, billing-transfer statuses and others).

### P00-13 — Blocked (2026-10-26): staging install and deploy on merge, built and drilled locally

**Status:** Blocked. Everything that can be built without a server is done and drilled on one machine. The AC needs a real staging host (PRE-04, with the product owner) and GitHub Actions starting jobs (P00-02).

**To close it:**
1. Create the staging hosting account (PRE-04) with SSH, PHP 8.4 and an empty MySQL/MariaDB database.
2. In GitHub, create the `staging` environment with the variables `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PATH`, `DEPLOY_PHP` (and `DEPLOY_PORT` if not 22) and the secret `DEPLOY_SSH_KEY` ([shared-hosting.md § 3](runbooks/shared-hosting.md)).
3. Build a deploy archive and run `scripts/deploy/first-install.sh` over SSH, which runs `goproperty:install` (runbook § 2 step 4). Add the cron line it prints.
4. Add the two backup destinations there and do P00-12's real backup run.
5. Merge a change to `main`; the Deploy workflow deploys staging. Check `/up`, the login pages and that `current` points at the new release. Then mark P00-13 Complete.

**Delivered:**
- **First install into the deploy layout:** `scripts/deploy/first-install.sh` unpacks a deploy archive into `releases/<name>/`, links `.env` to `shared/.env` (mode 600) and `storage/` to `shared/storage/`, runs `goproperty:install` with any options after `--`, checks that the key was written to the shared file, and only then points `current` at the release. It refuses to run if `current` already exists.
- **Installer fixes for that layout:**
  - `AtomicFile` now follows symlinks before its write-and-rename, so the installer writes `shared/.env` instead of replacing the release's `.env` link with a file the next deploy would lose.
  - `CronLine` prints `<app>/current/artisan schedule:run` when the app runs from `releases/<name>` next to `shared/`, so the cron line survives deploys.
- **Deploy on merge:** `.github/workflows/deploy.yml` now runs on every push to `main` and deploys `staging`; production stays a manual run. Until the `staging` environment has `DEPLOY_HOST`, a push run ends with a warning instead of failing. Runs per environment are serialised.
- **Backup before migrating (optional):** with the environment variable `DEPLOY_BACKUP_FIRST=1` (meant for production), `remote-deploy.sh` runs `goproperty:backup-run db` before migrations. A failed backup stops the deploy and the live release stays.
- **Deploy drill:** `scripts/deploy/deploy-drill.sh` runs under shared-host limits (30 s, 256 MB, `exec`/`proc_open`/`pcntl` off):
  1. first install via `first-install.sh` and `goproperty:install`, then checks for the link, `APP_KEY`, `ADMIN_PATH`, mode 600, the install lock, the cron line, and the site, `/login` and the console login over HTTP;
  2. an upgrade that keeps `shared/.env` byte for byte;
  3. a release with a failing migration, which never goes live;
  4. `BACKUP_FIRST=1` without a destination, which stops the deploy;
  5. pruning to `KEEP_RELEASES=2`.

  The Install drill workflow now also builds a deploy archive and runs this drill, and runs on pull requests that touch `scripts/deploy/`.
- **Docs:** [shared-hosting.md](runbooks/shared-hosting.md) § 2 (first install with the script), § 3 (deploy on merge, `DEPLOY_BACKUP_FIRST`) and § 4 (backups now use the pure-PHP dumper and `goproperty:backup-destination:add`; the old text still said `mysqldump` via `exec`).

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **823 passed, 1 skipped (2,813 assertions)**. New: writing `.env` through a symlink, and the cron line in the releases layout.
- Deploy drill: passed, all five steps.
- Release `0.13.0-rc.1` (65 MB) built; the verify drill and the install drill passed (schema identical, 186 columns and tables).
- `deploy.yml` and `install-drill.yml` parse as valid YAML. Neither has run in GitHub, because Actions has no runners yet (P00-02).

**Follow-ups:**
- HSTS where staging and production terminate HTTPS (from P00-10a).
- Give the `production` environment a required reviewer when it is created.

**Next:** P01-01 (organization switcher, roles per organization). P00-13 and P00-12 close when staging exists.

### P00-12 — In review (2026-10-26): P00-12b files backup, Google Drive destination, our own dumper

**Status:** In review. Everything is built and drilled locally. The AC "land encrypted in both destinations" still needs one real run against a real S3-compatible bucket and a Google Workspace Shared Drive. Their accounts are PRE-03, with the product owner.

**To close it:**
1. Add both destinations with `goproperty:backup-destination:add` (staging, P00-13).
2. Run `goproperty:backup-run db` and `goproperty:backup-run files`.
3. See both zips in each place.
4. Run `goproperty:backup-restore latest --database=…`.

**Delivered (12b):**
- **Files backup:** a separate nightly job at 02:30, encrypted the same way, of `storage/app/brand`, `private` and `public`. It has its own backup name, so retention never mixes it with database backups.
- **Google Drive destination:**
  - **Built:** `App\Backup\GoogleDrive\DriveClient` (a service-account JWT signed RS256 for an access token; list, folders, resumable uploads in 8 MiB chunks, download, delete with a fallback to trash) and `GoogleDriveAdapter`, a Flysystem adapter that maps paths onto folders in a Shared Drive. Registered as the `google_drive` disk driver.
  - **Why our own:** the community adapter and `google/apiclient` require Guzzle 6/7, while this Laravel 13 app uses Guzzle 8. The Google SDK is also very large.
- **Destination commands** (the console screen is P06-10):
  - `goproperty:backup-destination:add` takes S3 options or a Drive service-account JSON file. Secrets come from files or a hidden prompt, never the command line. It writes, reads and deletes a test file, and **switches the destination off if that fails**.
  - Also `:list`, with the last database and files backup per destination, and `:test`, with `--disable`/`--enable`.
- **Our own dumper:**
  - **Why:** `druidfi/mysqldump-php` turned out to be **GPL-3.0**. Bundling it in the release zip would have put copyleft terms on GoProperty, and it also shipped a `.env.mysql` file that the package check rightly refused.
  - **Replacement:** `App\Backup\PdoMySqlDump`, a consistent snapshot (`START TRANSACTION WITH CONSISTENT SNAPSHOT`). Rows stream unbuffered into multi-row INSERTs of about 1 MB; non-UTF-8 bytes go as hex, so a statement never spans lines; generated columns are skipped.
  - **Licence check:** every package is now MIT, Apache-2.0 or BSD, apart from LGPL dompdf (fine as a library), dual-licensed nette, and the paid Flux licence.
- **Concurrent runs:** each run now uses its own temporary folder. Spatie empties a shared one at the start, so a manual run could have wiped the nightly run's zip; the parallel test suite found this.
- **Docs:** DATABASE_SCHEMA.md `backup_destinations`, and PLATFORM_CONSOLE.md § 4 on the Drive adapter.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **822 tests / 2,807 assertions**, and **six consecutive full parallel runs passed**. New: 6 in `tests/Feature/Backup/GoogleDriveAndFilesTest.php` against an in-memory Drive v3 fake (`tests/Support/FakeGoogleDrive.php`), and a round-trip drill.
- **Google Drive:**
  - **Sign-in:** the JWT is verified with the service account's public key, and one token serves many requests.
  - **Files:** folders are created once, a re-upload replaces the old file, and read, size, date, list and delete work.
  - **Large files:** a 700 KB file goes up in three 256 KB resumable chunks.
  - **Full run:** a database backup goes to Drive and is downloaded and decrypted again; a 403 from Drive is recorded as a failed run with Google's message.
- **Files backup:** the scheduled `backup-files` event (`30 2 * * *`) produced one AES-256 zip containing an uploaded brand file, unreadable without the password and readable with it.
- **Commands:** add (S3 and Drive), list, check, switch off; a Drive without access is saved switched off; a bad key file is refused.
- **Round trip:** line breaks, quotes, a `;` at the end of a line, emoji and Yoruba diacritics, binary with NUL bytes, NULL, an empty blob, BIGINT and a generated column all come back identical after a dump and restore.
- **Drills:** release `0.12.0-rc.3` (62 MB) built, the verify drill passed and the install drill passed (schema identical, 186 columns and tables).

**Follow-ups:**
- One real run to S3 and to a Shared Drive (above). Then mark P00-12 Complete.
- **Spec gaps:**
  - The files backup is a full encrypted zip each night, not the spec's incremental sync with a weekly manifest. That is fine at launch size; revisit when media grows, e.g. by mirroring media to a second S3 provider as § 4 suggests.
  - The "config snapshot" in § 4 is covered by the database backup (settings, plans).
- Console screens for destinations, run now, download, verify and restore to staging: P06-10. Alerts on failed backups: P08-03.

**Next:** P00-13 (staging installed with the installer, deploy pipeline). The real backup run belongs there too.

### P00-12a — Complete (2026-10-26): database backup, encryption, restore and verify

P00-12 is split into sub-tasks (IMPLEMENTATION_PLAN.md § 3.1): **12a** the database backup, its encryption, restore and verify; **12b** the files backup, the Google Drive destination and adding destinations from the command line. P00-12 is marked complete when both are done.

**Delivered:**
- **Dependencies:**
  - `spatie/laravel-backup` 10.3 does the packaging, AES-256 zip encryption, copying and retention.
  - **Dumper:** first `druidfi/mysqldump-php`, replaced in 12b by our own `App\\Backup\\PdoMySqlDump` because that library is GPL-3.0.
  - `league/flysystem-aws-s3-v3` 3.35 provides the S3 destination. The AWS SDK is trimmed to S3 by its official Composer cleanup (`extra.aws/aws-sdk-php`), so `vendor/` grew by about 7 MB and 700 files.
- **No `exec`, `proc_open` or signals** (`App\Backup\BackupRunner`):
  - **Dump:** spatie's dumper factory uses `PhpMySqlDumper` (pure PHP) instead of `mysqldump`, as ARCHITECTURE.md § 12 asks. `BACKUP_DUMPER=mysqldump` switches back where a host allows it.
  - **In-process:** the job runs inside the scheduler's PHP process rather than through `backup:run`, which needs `pcntl`, and the zip step gets the per-run config.
  - **Encryption:** every zip is AES-256 encrypted. The password is `BACKUP_ARCHIVE_PASSWORD`, or is derived from `APP_KEY` (HMAC); `goproperty:backup-password` prints it for a hand restore. The zip is re-opened after writing (`verify_backup`).
- **Destinations** (`backup_destinations`, DATABASE_SCHEMA.md § 8):
  - **Drivers:** `s3` (R2, Spaces, Wasabi, B2…), `google_drive` (12b), and `local`. The local driver is for drills only and is refused in production, since a backup on the same server is not a backup.
  - **Security:** credentials are encrypted in the database and never part of the model's output. Each destination becomes a filesystem disk only for the run.
  - **Failures:** `continue_on_failure` is on, so one unreachable destination does not stop the others.
- **Runs** (`backup_runs`): one row per job and destination, with status, file, size, schedule/manual and staff.
  - **Recording a failure:** a failure, or having no destination, is recorded with its reason and logged as critical. Alerts come with P08-03, and the package's own mails are off.
  - **Retention, per destination after each run:** everything from the last day, one a day for 30 days, one a month for 12 months. The `db` and `files` jobs use separate backup names, so retention never mixes them.
- **Schedule:** database at 01:30 and files at 02:30, as separate jobs, plus a monthly verify on the 1st at 04:00.
- **Restore** (`App\Backup\Restorer`, `goproperty:backup-restore <zip|latest> --database=…`):
  - **How:** it decrypts in PHP and replays the dump statement by statement over PDO, so no `mysql` client is needed.
  - **Safety:** it empties the target first and **refuses the site's own database**. Production restores go into a new database and `DB_DATABASE` is switched (runbook).
- **Verify** (`goproperty:backup-verify`): downloads the newest database backup, restores it into `BACKUP_VERIFY_DB_DATABASE`, checks that every live table and the migration history are there, and marks the run verified.
- **Docs:**
  - `docs/runbooks/restore.md`: what a backup is, restoring with the command, restoring by hand with 7-Zip and phpMyAdmin, files restore, the monthly check.
  - `.env.example`: lists `BACKUP_DUMPER`, `BACKUP_VERIFY_DB_DATABASE` and `BACKUP_ARCHIVE_PASSWORD`.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **815 tests / 2,729 assertions**. New: 4 drill tests in `tests/Drill/BackupDrillTest.php`, which commit real rows, and 12 in `tests/Feature/Backup/`.
- **AC (drilled):**
  - **No exec:** `goproperty:backup-run db` ran in a subprocess with `exec`, `proc_open` and `pcntl` disabled, and the scheduled `backup-db` event (`30 1 * * *`) ran too. Both destinations that take database backups received a zip; a files-only destination received none.
  - **Encryption:** every zip's entry is AES-256 and unreadable without the password.
  - **Restore:** the newest backup was downloaded, decrypted and restored into another database, and a marker row came back. The verify restored and marked the run, another `APP_KEY` cannot open it, and the site's own database is refused.
- **Other tests:** an S3 destination (Flysystem level) gets the zip; one failing destination does not stop the other; inactive and other-job destinations are skipped; a 400-day-old backup is deleted by retention; credentials are encrypted at rest; local is refused in production; password derivation and override; the schedule expressions.
- **By hand:** a real database backup under the same disabled functions took under a second (4.5 KB zip, AES-256 confirmed), and restoring it into `gp_restore` brought back all 22 tables.

**Not yet verified:** a real S3-compatible bucket and Google Drive. Their accounts are PRE-03, still with the product owner. The S3 driver is tested at the filesystem level only.

**Next:** P00-12b (files backup, Google Drive destination, adding destinations from the command line).

### P00-11 — Complete (2026-10-26): audit log hash chain, authentication log for both guards

**Delivered:**
- **Append-only audit log with a hash chain** (`App\Audit`, PLATFORM_CONSOLE.md § 7):
  - **New columns:** `activity_log` gains `impersonator_staff_id`, `ip`, `user_agent`, `reason`, `prev_hash` and `hash`. Spatie's model is replaced by `AuditEntry`, which fills IP, user agent, reason (`AuditContext::because()`) and the impersonator (wired by P01-08).
  - **The chain:** each entry stores the previous hash and an HMAC-SHA256 of its own content, keyed from `APP_KEY` and never the raw key. Someone with database access but without the key cannot forge a valid entry.
  - **Writes:** appends take a row lock on the new one-row `audit_chain_head` table, so concurrent writes cannot fork the chain.
  - **JSON columns:** hashed with sorted keys, because MySQL's JSON type reorders them.
  - **Key rotation:** entries made with a key in `APP_PREVIOUS_KEYS` still verify.
  - **Append-only:** the model refuses updates and deletes, `activitylog:clean` is refused, and the buffered (bulk-insert) mode is off for good.
  - **Existing entries:** sealed into the chain by the migration.
  - **Verification:** `goproperty:audit-verify` walks the chain and names the first entry that was changed, removed or reordered, including removed newest entries. It runs nightly and logs a critical line on a break, and the install drill now runs it after installing.
  - **Who:** the causer is the staff member on console requests and the customer elsewhere; an explicit `causedBy()` still wins.
- **Authentication log for customers and staff** (`authentication_logs`, PLATFORM_CONSOLE.md § 8, built in the app rather than with rappasoft's package so one table covers both guards and all the spec's events):
  - **Customers:** sign-in success, passkey sign-in, failure (known or unknown account), sign-out, 2FA passed and failed, recovery code used, Turnstile failed, the start of a lockout, reset link sent, password reset.
  - **Staff:** sign-in success and failure, 2FA passed and failed, sign-out (including idle timeout).
  - **Stored:** IP, user agent, country from Cloudflare's `CF-IPCountry` when present, and whether the browser was new. A typed email or phone is kept only as an HMAC.
  - **Retention:** rows older than a year are deleted nightly.
  - The sign-in device tracking from P00-10b moved into the same subscriber, so each sign-in is recorded once with its new-device flag.
- **Docs:** DATABASE_SCHEMA.md § 8 describes the chain, the head row and the authentication log.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **799 tests / 2,639 assertions** (22 new in `tests/Feature/Audit/`).
- **AC: tampering breaks verification:** changing an entry's description, properties, causer, time or hash; deleting an entry in the middle; deleting the newest entry; and forging an entry with a plain SHA-256 link are each detected at the right entry, and the command exits 1. Reordered JSON keys and a rotated `APP_KEY` still verify, and unchained older entries can be sealed.
- **AC: logins and failures recorded for both guards:** customer failure (known by phone, unknown), success with new device, sign-out; 2FA failed, recovery code, passed; three failures then lockout; Turnstile failure; reset link only for real accounts; password reset; staff failure (unknown, known), 2FA failed, success, 2FA passed, sign-out. Rows store IP, user agent and country, and the typed identifier only as an HMAC.
- **Also tested:** the app refuses to change, delete or clean entries; the staff causer on a console change; the nightly checks; one-year pruning.
- **Drills:** release `0.11.0-rc.1` built, the verify drill passed and the install drill passed: schema identical (164 columns and tables) and the new audit-chain check passed.
- **Local environment:** the scratch MariaDB and the local install-drill copy were rebuilt, since the scratchpad had been cleared between sessions.

**Follow-ups:**
- Audit-log and access-log viewers (console, org-owner Activity page), and the "staff views of the audit log are logged" rule: P06-07.
- Retention for the audit log (7 years money, 2 years other) needs an archive that keeps the chain verifiable (export, then a signed checkpoint row); `activitylog:clean` stays refused until then.
- Console alert on bursts of failed sign-ins, and the sessions list with "log out other sessions": with the console dashboard (P06) and the profile (P01).
- The country comes from a header Cloudflare sets. Off Cloudflare a client could send it, so it is approximate only.

**Next:** P00-12 (backups baseline).

### P00-10 — Complete (2026-10-26): P00-10c staff sign-in with mandatory 2FA, Sign-in security settings

All three sub-tasks are done (10a customer sign-in, 10b 2FA and the other sign-in screens, 10c staff), and every AC passes:
- **Pages match the templates:** `login.html` and `login-2fa.html` were compared element by element at desktop, and `login.html` at mobile (10a, 10b).
- **Tests cover:** the rate limit, the lockout and identical answers for unknown and known accounts, for customers (10a) and staff (here). They also show that staff cannot sign in without 2FA (here).

**Delivered (10c):**
- **Mandatory staff 2FA:**
  - **How:** Filament's authenticator-app 2FA, with recovery codes, is required on the console (`isRequired`).
  - **Storage:** `PlatformStaff` keeps the secret and the hashed recovery codes in the existing `two_factor_*` columns.
  - **First sign-in:** a staff member without 2FA is signed in only to the set-up screen. Every console page, the theme preview and the session ping redirect there until an authenticator is enrolled. The preview and ping routes needed Filament's 2FA-required check added by hand, since Filament adds it only to its own pages.
  - **Later sign-ins:** the password, then the 6-digit code. Filament keeps one generic error, equal timing and a per-IP limit of 5 tries a minute.
  - **Step-up:** now asks for the code too (password checked first, so a typo does not use up a code).
- **Staff sign-in page** (`StaffLogin`): dark neutral background and one centred card, headed "Staff console". There is no sign-up link and no marketing, and "Remember me" is gone, since staff use 2FA on every sign-in (§ 9.6).
- **30-minute idle timeout** (`StaffIdleTimeout`): the session ends after 30 minutes without a request. Two minutes before, a dialog with a countdown offers "Stay signed in", which pings the server. Any console request counts as activity.
- **Optional per-staff IP allowlist** (`StaffIpAllowlist`, `platform_staff.ip_allowlist`, addresses or CIDR ranges): from anywhere else the session ends and the reply is a plain-text 404 with no branding. Editing the list comes with staff management (P01-07).
- **Settings → Sign-in security** (PLATFORM_CONSOLE.md § 9.6). It applies on save and is audited by the settings store.
  - **Fields:** passkeys on/off, Turnstile mode and keys, lockout count and minutes, remember days, trusted-device days, and who must use 2FA.
  - **Turnstile secret:** never sent to the browser (shown as "•••• set"). Changing or removing it needs step-up (password and code).
- **Docs:** DATABASE_SCHEMA.md § 1 now lists `user_devices`.

**Interim and flagged:**
- **`auth.require_2fa_for`:** stored and editable, but enforced only once organization roles and Transaction Tiers exist (P01-01/P01-02). The page says so.
- **Who may open the page:** as with the other settings pages, every active staff member until roles exist (P01-07, approved 2026-10-26). The spec has `super-admin`, with `technical-admin` read-only.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **777 tests / 2,547 assertions** (12 new in `tests/Feature/Console/StaffSignInTest.php`). Console tests now create staff through a `staffMember()` helper with 2FA set up, and the console sign-in and step-up tests supply real TOTP codes.
- **AC: staff cannot sign in without 2FA:** without enrolment, six console URLs redirect to set-up; after enrolment the console opens; with 2FA, a wrong code is refused and the right one signs in.
- **Other tests:** identical errors for an unknown email and a wrong password; the 6th attempt is rate-limited; timeout at 31 minutes (but not 10); the ping; allowlist allow and deny (plain 404, signed out); no "Remember me"; the settings save, validate, keep the secret hidden, ask for step-up to change it, leave it alone otherwise, and can remove it.
- **In the browser (local):**
  - **Staff sign-in:** the page showed the dark card.
  - **Enrolment:** signing in as the seeded staff account went straight to "Set up two-factor authentication". Enrolling with the QR code's secret, a real code and the password showed 8 recovery codes, and then the console opened.
  - **Settings page:** Sign-in security rendered.
- **Drills:** release `0.10.0-rc.1` built, the verify drill passed and the install drill passed. The installed schema is identical to a plain migrate (145 columns and tables), and `/` and `/login` answer 200.

**Next:** P00-11 (audit log and access log).

### P00-10b — Complete (2026-10-26): 2FA challenge and the other sign-in screens

**Delivered:**
- **2FA challenge from `docs/design/templates/login-2fa.html`:**
  - **Code entry:** six boxes with auto-advance, backspace, arrow keys, paste and auto-submit. Without the script it is one plain code field.
  - **Account shown masked:** "t•••@domain", or "+234 ••• 4567" for a phone-only account.
  - **Wrong code:** "Codes change every 30 seconds…".
  - **Other ways to verify:** a recovery code (each works once); a passkey, shown only when the account has one; and "Lost your phone and codes? Get help", shown when a support email is set.
  - **Panel:** the template's text, and no activity cards.
- **"Don't ask again on this device for N days"** (`auth.trust_device_days`, 30):
  - **Devices table:** a new `user_devices` table stores the SHA-256 of a random token kept in an encrypted HttpOnly cookie (`gp_device`).
  - **Skipping:** `RedirectIfTwoFactorNeeded` skips the challenge only for that browser and that account, until the date passes.
  - **Trusting:** it is set only when ticked (`TwoFactorLoginResponse`).
- **New-device email:** every customer sign-in records the browser. A browser new to an account that has signed in before gets "New sign-in to your … account" with time (Lagos), browser, IP and a reset-password button. Email only; no SMS.
- **Remember me:** `auth.remember_days` now applies on every sign-in route (password, 2FA, passkey) through `RememberDuration`.
- **Forgot password:**
  - **Same reply:** one "Email or phone number" field, and the same reply for any input: "If an account matches, we've sent a reset link by email. No email? Ask your agency for a new sign-in link."
  - **Delivery:** the link goes to the account's email, also when found by phone. Phone-only accounts get nothing sent.
  - **Route:** Fortify's email-only route, which told whether an address exists, is replaced, and it is throttled to 6 a minute.
  - **Wording:** UX_DESIGN.md § 3.3 says "by email or WhatsApp". WhatsApp delivery does not exist yet, so the reply says email only until the notification module adds it.
- **Reset password, verify email and confirm password** now use the same split layout and security policy.
- **Password rule:** new customer passwords must be at least 10 characters, as in UX_DESIGN.md § 3.1, and in production not on a breached-password list. This replaces the starter kit's 12 characters with mixed case and symbols. The staff installer keeps its 12-character rule.
- **Tests:** the test case now resets all scoped services before each request, as production does.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **765 tests / 2,437 assertions** (14 new in `tests/Feature/Auth/TwoFactorAndRecoveryTest.php`; the kit's reset tests now post `identifier`).
  - **2FA:** the challenge page content, a wrong code and then the right one, a single-use recovery code.
  - **Trusted browser:** skips the challenge for 10 configured days, while another browser is challenged; it is not trusted unless ticked.
  - **New-device email:** sent only for a new browser on an account that already has one.
  - **Forgot password:** the same reply for five kinds of input; the email goes to the right account; phone-only accounts get nothing.
  - **Other pages:** every page renders with the policy, the 10-character rule holds, and a remembered 2FA sign-in lasts 30 days.
- **In the browser (1440 px):** every element of the 2FA page matches `login-2fa.html` in size, font and colour. It sits 60 px lower because this test account had no passkey or support email, so those two options were hidden. Signing in with a test account and typing a real code one digit at a time auto-submitted and opened the dashboard.

**Follow-ups:**
- "Add a passkey for faster sign-in" one-time prompt after a password sign-in, and "Log out other sessions" in the profile (UX_DESIGN.md § 3.1): with the profile pages (P01).
- WhatsApp reset links for phone-only tenants: notification module.
- HSTS: set where HTTPS is terminated (staging/production, P00-13).

**Next:** P00-10c (staff sign-in with mandatory 2FA, Sign-in security console page).

### P00-10a — Complete (2026-10-26): customer sign-in page and its security

P00-10 is split into sub-tasks (IMPLEMENTATION_PLAN.md § 3.1): **10a** customer sign-in; **10b** 2FA challenge and the other sign-in screens; **10c** staff sign-in with mandatory 2FA and the Sign-in security console page. P00-10 is marked complete when all three are done.

**Delivered:**
- **`/login` rebuilt from `docs/design/templates/login.html`** (`layouts/auth-split`, `resources/css/auth.css`, `resources/js/auth.js`).
  - **Brand panel:** the uploaded sign-in photo under its overlay, or the gradient and skyline. It also carries the dark logo, the activity cards (switchable) and the headline, benefits and trust line from `site.auth_*`.
  - **Form:** passkey button, which the browser also offers as autofill in the field; "Email or phone number"; password with show/hide and a Caps Lock warning; "Keep me signed in"; a Turnstile slot; and a busy state on Sign in.
  - **Shared styles:** the base styles shared with the landing page moved to `resources/css/site-base.css`.
- **Phone numbers as a sign-in name:**
  - **Schema:** `users.phone` (unique, E.164) is added, and `users.email` becomes nullable, both as in DATABASE_SCHEMA.md § 1.
  - **Normalising:** `App\Support\PhoneNumber` turns "0803 123 4567", "+234 803…", "234803…" and "00234…" into one +234 number. No SMS is sent anywhere.
  - **Matching:** Fortify's `username` is now `identifier`, and `FindUser` finds the account by email or phone.
- **Sign-in security** (`App\Auth\SignIn`, first step of Fortify's login pipeline):
  - **One generic answer:** "Those details don't match our records." for an unknown account, a wrong password or anything else. Unknown accounts still cost one password check, so timing gives nothing away.
  - **Lockout:** after `auth.lockout_after` (5) failures from one IP, sign-in pauses for `auth.lockout_minutes` (15). Failures are counted per account, so switching between email and phone does not reset them. While paused even the right password is refused, the form shows "Too many attempts" with a reset-password link, and the button is disabled.
  - **Turnstile:** with keys set, the check appears after 3 failures for the account or the IP in 15 minutes, or always (`auth.turnstile_mode`). It is verified server-side with a 5 s timeout and fails closed on rejection, errors, timeouts or odd answers.
  - **Without Turnstile keys,** the pause starts at 3 failures, as PLATFORM_CONSOLE.md § 9.6 says.
  - **Rate limit:** the route also limits each IP to 30 tries a minute, and each identifier from one IP to 10.
  - **Remember me:** lasts `auth.remember_days` (30), and is set only when asked.
  - **Passkeys switch:** `auth.passkeys_enabled` off hides the button and makes the passkey sign-in routes return 404.
- **New settings** (§ 9.6): `auth.passkeys_enabled`, `auth.turnstile_mode`, `auth.lockout_after`, `auth.lockout_minutes`, `auth.remember_days`, `auth.trust_device_days` and `auth.require_2fa_for`. Their console page comes with 10c.
- **Page security:** sign-in pages send a strict Content-Security-Policy — no inline scripts, no third-party scripts except Cloudflare Turnstile, `frame-ancestors 'none'` — and never print custom code.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **750 tests / 2,348 assertions**. There are 33 new tests in `tests/Feature/Auth/SignInTest.php` and `tests/Unit/Support/PhoneNumberTest.php`, and the kit's login tests now post `identifier`.
- **AC: rate limit, lockout and identical answers:**
  - an unknown email, an unknown phone, nonsense and a wrong password give the same status, redirect and message;
  - the pause works per account and IP, and another IP is unaffected;
  - it clears after 15 minutes;
  - the bot check comes after 3 failures and the pause after 5;
  - the Turnstile request carries the secret, token and IP, and four failure modes are refused;
  - the 31st request a minute from one IP gets 429.
- **AC: matches the template (in the browser):**
  - **Desktop (1440 px):** all 113 visible elements were compared against `login.html`. Colours, fonts, sizes and positions match, apart from colour syntax, the floating cards' animation phase, the template's Turnstile stand-in, and the Help link (shown only when a support email is set).
  - **Mobile (375 px):** every block has the same position and height, and there is no horizontal scroll.
  - **Real states:** the wrong-details and locked states looked as designed when tried in the browser.

**Next:** P00-10b (2FA challenge and the other sign-in screens).

### P00-09 — Complete (2026-10-26): P00-09c console settings pages and brand images

All three sub-tasks are done (09a theme engine and app shell, 09b landing page, 09c console pages), and every AC passes: the landing page matches the template at desktop and mobile (09b), publishing a colour updates the customer app and Filament (09a, and again here through the console UI), and a draft never reaches the public site (09a, 09b, 09c).

**Delivered (09c):**
- **Console pages under Settings** (PLATFORM_CONSOLE.md § 9.1). Each is one column of cards with a live preview column on wide screens.
  - **Brand & appearance:** identity, logos, icon, favicon, colours, greys, font, corners, hero and sign-in photos with overlay colour and strength.
  - **Public website:** hero and sign-in text, announcement, footer, social links, FAQ (repeater with rich text) and the legal pages.
  - **Search & sharing:** title, title pattern, description, share image, indexing (automatic / on / off), canonical origin. Its preview shows the Google result and the link card.
  - **Custom code:** head, body-start, body-end, CSS and the allowed script hosts. It applies on save and needs step-up.
  - **Versions:** every published version, with "Restore as draft".
- **Draft workflow** (`DraftSettingsPage`):
  - Save draft checks each value against the settings registry and shows problems on the matching field.
  - The preview (`/{ADMIN_PATH}/theme-preview/landing`) reloads after each save.
  - Header actions: Publish, Discard draft, Reset to default, Open preview.
  - **Audit:** draft saves are now logged (`theme` / `draft_saved`, before and after per key) as well as publishes and each published key. Rich text the editor only reformats does not count as a change.
- **Contrast warnings** (§ 9.2, § 9.8):
  - white text on the primary colour below 4.5:1 (it names the darker shade buttons use);
  - white text over a hero or sign-in photo under its overlay below 4.5:1, from the photo's average colour.
- **Brand images** (`App\Theme\BrandAssets`, private `brand` disk, `/brand/{role}`):
  - **Checks:** file type by magic bytes, which must match the extension; size and pixel limits per role. SVG is allowed only for logos, icon and favicon, and is refused if it contains script, `on*=` handlers, `javascript:`, `foreignObject`, iframe/embed/object, doctype/entities or `data:` links.
  - **Photos:** re-encoded to WebP q82 at 2400 px and 1200 px (EXIF dropped), served with `srcset`. The share image becomes a 1200 px JPEG, and a raster icon also gets a 180 px touch icon.
  - **Serving:** files are named by content hash; `?v={hash}` responses are `public, max-age=31536000, immutable` with `nosniff`, and SVGs get a sandbox CSP. These routes, robots.txt and the sitemap run without session or cookies.
  - **Clean-up:** files that no draft, published theme or stored version names are deleted, so restore keeps working.
  - **Fallbacks:** logos, icon and favicon fall back to the generated mark in theme colours (SVG, and PNG for the touch icon). The starter kit's Laravel `favicon.ico`, `favicon.svg` and `apple-touch-icon.png` are removed; `/favicon.ico` redirects to `/brand/favicon`.
  - **Where they show:** uploaded logos replace the mark on the landing nav and footer (`logo_only` hides the name), the console logo (light and dark), and the app sidebar (icon). Favicons apply on every surface.
- **Step-up** (`App\Platform\Console\StepUp`): password re-entry, rate-limited to 5 tries a minute, opens a 10-minute window. A staff member with confirmed 2FA must also give a TOTP code, so this becomes password + 2FA as soon as P00-10 enrols staff. Used for custom code and the canonical origin.
- **Permissions:** pages check `settings.theme`, `settings.site`, `settings.seo` and `settings.custom_code` through Laravel gates (`ConsolePermissions`).

**Interim choices (not in the spec as written; approved by the product owner on 2026-10-26 until P01-07 and P00-10):**
- **Roles:** until staff roles exist (P01-07), every active staff member holds these four permissions. The spec limits custom code to `super-admin`. Today the only staff accounts are the installer's first administrators, so this is equivalent; P01-07 must swap the gate for spatie roles.
- **Step-up:** password-only until staff 2FA exists (P00-10). The code check is already wired.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **717 tests / 2,229 assertions** (43 new in `tests/Feature/Theme/ConsoleSettingsPagesTest.php` and `BrandAssetsTest.php`).
  - **Pages and drafts:** every page opens for staff and redirects guests; a draft is saved, audited and invisible to visitors; registry and form errors land on the right fields; publish, discard, reset to default and restore work; the contrast warning fires for yellow.
  - **Images:** the hero upload yields 2400 px and 1200 px WebP files shown only in the preview; unsafe or mislabelled files are refused; replaced draft photos are deleted while published ones stay; uploaded logos, icon and favicon appear on the landing page, the app and the console.
  - **Search & sharing:** the Google preview follows the form, and indexing maps to on/off/automatic.
  - **Step-up:** the canonical origin and custom code need the password (a wrong one is refused); custom CSS with `</style` is refused.
  - **Brand route:** fallbacks, headers, unknown roles and bad versions are covered.
- **In the browser (console at 1440 px):** a draft saved in Brand & appearance showed only in the preview column. After Publish, the console itself turned teal and read "Ile Homes console", and the public landing printed the new colour and name. The Public website, Search & sharing (Google and card preview), Custom code and Versions pages render.
- **Drills:** release `0.9.0-rc.2` built; the verify drill passed (tamper target is now `public/images/skyline.svg`, since the Laravel favicon is gone); the install drill passed.

**Follow-ups:**
- P01-07: replace the interim permission gates with staff roles (custom code `super-admin` only).
- P00-10: staff 2FA enrolment (step-up then asks for the code automatically). The sign-in page should use `theme.auth_image`, overlay and `site.auth_*`, which are already stored and served.
- **Generated share card:** the spec's 1200×630 card when no share image is uploaded needs a TTF font for GD. Until then `og:image` is left out when none is uploaded.
- **Web manifest:** PWA icons (192/512) and `site.webmanifest` from `theme.icon` are not built yet.
- P00-12: include `storage/app/brand` in the files backup.
- The console preview covers the landing page and the app shell. Login, email and invoice previews follow with those features (P00-10, P02/P03).

**Next:** P00-10 (login pages to final quality).

### P00-09b — Complete (2026-10-26): landing page, SEO, custom code

**Delivered:**
- **Landing page at `/`** (`marketing.home`, `layouts/marketing`, partials for brand, hero, plans, announcement and footer), converted from `docs/design/templates/landing.html`. The CSS and script are the template's (`resources/css/marketing.css`, `resources/js/marketing.js`) with its tokens mapped to the theme variables, so a published colour, font or radius restyles the page with no rebuild. The kit's `welcome` view and the static `public/robots.txt` are removed.
  - **From the published theme:** brand name and mark, hero heading/subheading/badge/lede/trust items, FAQ (sanitised HTML), footer text, legal name, social links (only those set), announcement ribbon (dismissed per content, remembered in the browser), support email for "Book a demo" and "Contact".
  - **From the plan catalogue, never typed into settings:** `config/plans.php` and `App\Marketing\PlanCatalogue` hold the launch plans, VAT, trial days and annual months (BILLING_PAYMENTS.md § 1) until the billing module adds the plans table. Plan cards, the facts row, the calculator's first result and JSON-LD use it; the script gets the same numbers from a JSON block.
  - **Footer:** only links that lead somewhere (anchors, Book a demo, Log in, Privacy, Terms). Help centre, news and receipt verification join when those pages exist.
- **`/privacy` and `/terms`** render `site.privacy_policy` and `site.terms` (sanitised on save) in the same layout.
- **Search and sharing (§ 9.4)** via `App\Marketing\PublicSite`: title (`seo.title`, else name — tagline; other pages through `seo.title_pattern`), description, canonical and `og:url` on `seo.canonical_origin` (else `APP_URL`), Open Graph, Twitter card, theme colour and JSON-LD `Organization` + `SoftwareApplication` with the Starter price.
  - **Indexing:** `seo.indexing_enabled`, unset meaning production only. Off → `noindex, nofollow`, `robots.txt` is `Disallow: /`, `sitemap.xml` is 404. On → `robots.txt` disallows `/login`, `/register`, `/o/`, `/portal`, `/owner`, `/pay/`, `/verify/`, `/install`, `/dashboard`, `/settings` and links the sitemap (marketing pages, cached 10 minutes). The console path is never mentioned.
  - **Sign-in and app pages** now print `noindex, nofollow` (shared `partials/head`).
  - `og:image`, logo and favicon switch to the uploaded brand images once P00-09c adds the brand image routes.
- **Custom code (§ 9.5)** is printed only on the marketing layout: CSS in `<style data-custom-css>`, head code at the end of `<head>`, body code after `<body>` and before `</body>`. It is left out for signed-in users, in the console preview and with `?safe=1`.
- **Content-Security-Policy on public pages** (`PublicPageHeaders`): scripts, frames and connections only from the site and `custom_code.allowed_hosts`; inline scripts allowed (analytics snippets are inline); `object-src 'none'`, `frame-ancestors 'self'`; plus `nosniff` and a referrer policy.
- **Console preview** of the landing page with the draft theme at `/{ADMIN_PATH}/theme-preview/landing` (staff only, preview banner, noindex, no custom code).
- **Theme fix:** `--canvas` is now a fixed tint per neutral (`#f7f8fb` for slate, as in the template) instead of a 50/100 mix, which was one shade bluer.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **674 tests / 1,954 assertions** (17 new in `tests/Feature/Marketing/`). The test case now starts each request on the published theme, as production does.
- **AC: matches the approved template at desktop and mobile.**
  - **Structure:** a test compares the tag and class of every element under `<main>` with the template file; they are identical.
  - **Desktop (1440×900):** in the browser, every section starts at the same offset with the same height as the template. The computed colour, background, border, font size, weight, radius, shadow and box size of all 820 elements in the nav, hero and main match, apart from colour-syntax differences (`color-mix` vs `rgba`, same values), 1 px text widths (bundled vs Google-hosted Inter), and animation states. One real difference was found and fixed: the import bar's amber segment used the old `--color-accent` name.
  - **Mobile (375×812):** every section height matches and there is no horizontal scroll.
  - **Only intended difference:** the footer is shorter, since it shows only working links and no social links are set by default.
  - **Interactions:** the menu, calculator (250 units → Scale ₦102,500, ₦410 per unit) and yearly toggle work. No console errors.
- **Drafts:** website text and colours appear only after publishing; the landing preview shows the draft to staff only.
- **SEO and custom code:** tags, title pattern, indexing on/off, production default, robots/sitemap content, safe mode, signed-in users, sign-in pages and the console preview, and the CSP host list are each tested.
- **Drills:** release build `0.9.0-rc.1`, verify drill passed (its tamper target moved from `public/robots.txt`, now a route, to `public/favicon.svg`), install drill passed with a new check that `robots.txt` keeps crawlers off `/login` and never names the console path.

**Follow-ups:**
- The font plugin emits a `.woff` `@font-face` next to each `.woff2`, and browsers fetch both for some weights (about 120 KB extra). Look for a woff2-only option when fonts are next touched.
- Help centre, news and receipt verification links return to the footer with P03 (verify page) and the content pages.

**Next:** P00-09c (console settings pages and image uploads).

### P00-09a — Complete (2026-10-26): theme engine, app shell

P00-09 is split into sub-tasks (IMPLEMENTATION_PLAN.md § 3.1): **09a** theme engine and app shell; **09b** landing page, SEO and custom code; **09c** console settings pages and image uploads. P00-09 is marked complete when all three are done.

**Delivered:**
- **Settings registry for PLATFORM_CONSOLE.md §§ 9.2–9.5** (`config/settings.php`, 53 keys): `theme.*`, `site.*`, `seo.*`, `custom_code.*`, each with defaults from the approved templates and the rules in the spec (plain text rejects `<>`; hex colours; fixed lists for font, neutral and radius; three trust items and three benefits; https-only social links and canonical origin; title pattern must contain `{page}`; 50 KB custom code; no `</style` in custom CSS). The P00-07 placeholder `site.name` became `theme.name`.
  - **New in the settings store:** `item_rules` validate list and object items, and `html` fields go through `App\Support\SafeHtml`. That is a Symfony HtmlSanitizer policy allowing only text formatting, headings, lists, tables and https/mailto links; scripts, handlers, iframes and `javascript:` are dropped.
  - **`SettingsStore::validated()`** checks a value without storing it.
- **Colours** (`App\Theme\Color`, `Palette`):
  - **Conversion:** exact hex↔OKLCH, with gamut mapping by chroma reduction, and WCAG contrast.
  - **Scales:** generated from one hex. The colour lands on its closest step, and the other steps follow Tailwind's blue (primary) or amber (accent) lightness, chroma and hue drift. **The defaults reproduce the approved template's tokens exactly.**
  - **Dark brand surface:** `--hero-deep` is always ≥ 9:1 against white.
  - **Neutrals:** four neutral scales are available.
- **`Theme`, `ThemeManager`, `ThemeContext`:**
  - **Draft:** edits go into one draft, validated when saved.
  - **Publish:** writes only the changed keys through the settings store (each audited) and keeps a numbered snapshot in the new `setting_versions` table. The previous version is archived, a theme log entry records who published, and any version can be restored. Reset-to-defaults and discard are available.
  - **Isolation:** `ThemeContext` (scoped) gives every request the **published** theme. Only the staff-only console route `/{ADMIN_PATH}/theme-preview/app` (no-store, noindex) switches to the draft.
- **CSS variables everywhere:**
  - **`<x-theme-styles />` in `<head>`:** prints the theme as an unlayered `:root` rule, overriding Tailwind 4's `@theme` defaults in `app.css` with no rebuild. It loads only the chosen font and sets `theme-color`.
  - **Variable names:** as in the template, except `--color-brand-accent*` (Flux owns `--color-accent`) and `--gp-radius*` (Tailwind owns `--radius-*`).
  - **Flux:** its accent now follows the brand primary.
  - **Filament:** reads its primary palette, font (local) and name from the theme on every request, and prints the same variables.
- **Fonts:** Inter, Plus Jakarta Sans, Manrope and DM Sans are bundled through Vite (`@fontsource`, 400–800). Each page preloads only the chosen family.
- **App shell:**
  - The starter kit's Laravel logo is replaced by a generated GoProperty mark in theme colours (sidebar and auth layouts), and the product name comes from the theme.
  - The sidebar has a workspace switcher placeholder, workspace and surface navigation, and a mobile bottom navigation (Home, Rentals, Properties, Account).
  - The forced dark mode is removed; the default is light, per § 9.9.
  - Page templates `<x-page>` (heading, description, actions) and `<x-empty-state>` are used on the dashboard and all three surfaces.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **657 tests / 1,836 assertions** (65 new: `tests/Unit/Theme/PaletteTest.php`, `tests/Feature/Theme/*`).
- **AC: publishing a colour updates the customer app and Filament.** After publishing `#0F766E`, `/dashboard`, `/o/{slug}`, `/portal` and `/owner` print `--color-primary-700:#0f766e`, and the console prints Filament's `--primary-700` for the same colour.
- **AC: a draft does not leak.** With a draft colour and name saved, `/login`, the customer pages and the console show only the published values. The preview shows the draft to staff, and redirects guests and customers to the console sign-in.
- **Other tests:**
  - only the chosen font is loaded;
  - the theme name is used in titles and the console brand;
  - drafts merge, publish creates numbered versions, restore and reset work;
  - every validation rule from the spec is enforced, and FAQ and legal HTML is cleaned;
  - palette exactness, monotonic scales and ≥ 9:1 hero contrast hold for 8 colours.

**Next:** P00-09b (landing page).

### P00-08 — Complete (2026-10-26)

**Decision (product owner, 2026-10-26):** the staff session cookie uses `Path=/`, not `Path=/{ADMIN_PATH}` as ONBOARDING_COMPLIANCE.md § 1 said. Livewire, which Filament runs on, has one update endpoint at the site root, so a path-scoped cookie would break every console action. Separation comes from the cookie name and the guard: staff use `gp_staff_session` and the `staff` guard, and customer pages never read it. The doc is updated.

**Delivered:**
- **Console panel:** `ConsolePanelProvider`, a Filament panel `console` at `/{ADMIN_PATH}` (or on `ADMIN_DOMAIN`, read from config). It signs in on the **`staff` guard** (new guard and provider on `platform_staff`), uses Filament's login page and dashboard, has no widgets, and placeholder colours until P00-09. `PlatformStaff` implements `FilamentUser`, and only `active` staff may enter. Resources and pages are discovered in `App\Platform\Filament`, the only namespace the tenancy rule allows to read across organizations. If `ADMIN_PATH` breaks the PLATFORM_CONSOLE.md § 10 rules, the console **is not registered at all** (a critical log line), rather than served at a guessable path.
- **Staff session** (`UseConsoleSession`, global, before any session starts; `App\Platform\Console\ConsoleRequests`):
  - **Which requests use it:** console URLs, and Livewire updates whose component snapshots all come from console pages. Livewire records each page's path in its snapshot.
  - **Cookie:** `gp_staff_session`, `HttpOnly`, `SameSite=Strict`, `Secure` (except a local copy on http), 30-minute idle lifetime.
  - **IP allowlist:** the optional `CONSOLE_IP_ALLOWLIST` gives other addresses a plain 404.
- **Probe logging:** `/admin`, `/administrator`, `/wp-admin`, `/wp-login.php`, `/phpmyadmin` and `/cpanel` return a normal 404 and are logged as "Console path probe" with path and IP (`config/console.php`).
- **Route skeleton** (`routes/web.php`, ONBOARDING_COMPLIANCE.md § 1):
  - `/` and `/login` as before;
  - `/o/{organization}`, using the `organization` middleware from P00-07;
  - `/portal` and `/owner`, which are cross-organization and set no context;
  - `/pay/{reference}` and `/verify/{code}`, named routes with their formats checked, returning 404 until those features exist;
  - the console at `/{ADMIN_PATH}`.

  The three signed-in surfaces have empty shells on the app layout, and the sidebar links to each of the user's organizations plus "My rentals" and "My properties" (the starter kit's GitHub and docs links are gone).
- **Demo data:** `DatabaseSeeder` adds "Demo Realty" (`/o/demo-realty`) with `test@example.com` as a member, and on local copies only, an active staff account `staff@example.com` (password `password`).
- **Filament assets:** Composer's `post-autoload-dump` now runs `filament:assets`, so CI, the deploy tarball and the release zip always carry them; they are git-ignored. `config('app.admin_domain')` is new. The install drill now also checks the installed console (sign-in page 200, console root 302, `/admin` 404).
- Docs: ONBOARDING_COMPLIANCE.md § 1 (cookie), PLATFORM_CONSOLE.md intro, AGENT.md § 4.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci` twice: Pint passed, Larastan 0 errors, Pest **592 tests / 1,522 assertions**, 1 skipped (38 new in `tests/Feature/Console/`).
- **AC 1 (panel auth-protected and empty):** guests are redirected to the console sign-in. Active staff see a dashboard with no widgets; invited and deactivated staff get 403. Signing in through Filament's login form authenticates only the `staff` guard, and a customer's credentials are refused there.
- **AC 2 (a customer session cannot reach it):** a signed-in customer is redirected to the console sign-in. The console sets only `gp_staff_session` and customer pages only the customer cookie. The console path never appears on `/`, `/login`, `/dashboard`, `/portal` or `/owner`. The Livewire cookie selection is unit-tested, including mixed snapshots and `/{path}-evil`. The IP allowlist, probe logging, `ADMIN_DOMAIN` hosting, and an invalid `ADMIN_PATH` are also tested; the last one checks a separate process's route list, with no console routes for `admin`, `short` or `Has-Capitals-1`.
- **AC 3 (seeded demo user sees all three shells):** `SurfacesTest` runs `DatabaseSeeder`, signs in through `POST /login`, and opens `/o/demo-realty`, `/portal` and `/owner`.
- **In a real browser** (built-in browser, `php -S` with the seeded data in a throwaway database):
  - **Console sign-in:** staff signed in through Filament's form, which posts to the site-wide `/livewire-…/update` endpoint, and reached the empty console.
  - **Customer sign-in:** the demo user, in the same browser, signed in and opened all three shells through the sidebar.
  - **Separate sessions:** the console stayed signed in while the customer was signed in. Signing out of the console left the customer signed in, and signing in again with the customer cookie present worked.
  - **Errors:** `/admin` returned 404, and the server printed no PHP warnings.

  The dev server, database and lock file were removed afterwards.
- **Drills on a fresh `goproperty-0.8.0-rc.1.zip`:** the release drill passed, including the secret scan of Filament's published assets. The install drill passed (same local `intl` caveat as P00-06), with the console reachable at the generated path, under shared-host limits and with cached config.

**Follow-ups:**
- P00-10: staff sign-in to final quality, mandatory 2FA (enforced at first sign-in), lockout, Turnstile; `goproperty:staff-reset-2fa`.
- P00-09: theme the console from the platform theme; replace the kit's header layout links (`layouts/app/header.blade.php` still links to the starter kit) and add the org switcher.
- P01-07: staff roles (spatie, `staff` guard) and the first account's `super-admin` role; per-staff `ip_allowlist`.
- P01-01: landing rules after sign-in (ONBOARDING_COMPLIANCE.md § 1: one org → `/o/{slug}/today`, several → picker, tenant → `/portal`); `/portal` and `/owner` access limited to tenants and invited owners once those records exist.
- P04 / P03: the pay page and document verification behind the reserved routes.

**Next task:** P00-09 (design system and platform theme).

### Security update: Symfony advisories — 2026-10-26

Not a plan task; follows up the advisories found during P00-07.

**Delivered:**
- `symfony/http-foundation` 8.0.15 → **8.1.9** (CVE-2026-108082: ReDoS through the `Cache-Control` request header; shipped in the release package) and `symfony/yaml` 8.0.15 → **8.1.9** (CVE-2026-109413; dev only, through Laravel Sail). Only these two packages changed; the other Symfony components stay on their current versions.
- The blocker was `config.platform.php` = 8.4.0 in `composer.json`, because the patched Symfony releases need PHP 8.4.1. It is now **8.4.1**, and `require.php` is `^8.4.1`.
- The minimum is aligned everywhere, so a host that passes our checks also passes Composer's platform check:
  - The old-PHP guard (`bootstrap/php-version.php`) checks 80401 and names the full version ("GoProperty needs PHP 8.4.1 or newer; this server runs 8.4.0."). Previously an 8.4.0 host would have passed our guard and then failed with Composer's own error.
  - `RequirementsChecker::MINIMUM_PHP` is now 8.4.1.
  - The `Old PHP guard` CI job and the release drill match the new message, with the image's patch version allowed to vary. The CI pattern was checked against matching, wrong-version, old-wording and fatal-error outputs.
- Docs: INSTALLER.md § 2 and § 5, ARCHITECTURE.md § 12.1, shared-hosting.md § 1, ci.md, release.md. Effect on hosts: none in practice (8.4.1 shipped in December 2024, and panels keep PHP 8.4 on the latest patch).

**Tests and evidence (local, MariaDB 11.4.12):** `composer audit` reports no advisories. `composer ci` passed twice: Pint passed, Larastan 0 errors, Pest 554 tests / 1,425 assertions (1 skipped, as before). On a fresh `goproperty-0.7.1-rc.1.zip`, the package's platform check requires 80401, `http-foundation` is 8.1.9 and `yaml` is absent. The release drill passed, and the install drill passed (same local `intl` caveat as P00-06). Not run here: the guard on real PHP 7.4/8.1 (Docker, CI only).

### P00-07 — Complete (2026-10-26)

**Delivered:**
- **Modules:**
  - **Packages:** `nwidart/laravel-modules` 13.2.0, which autoloads modules through `wikimedia/composer-merge-plugin` 2.1.0 (`extra.merge-plugin` reads `Modules/*/composer.json`).
  - **`config/modules.php`:** trimmed so generated modules share the app's single Vite build. No per-module `package.json`, `vite.config.js`, asset folders, API routes or Blade layout are generated. Module `composer.json` files use the `goproperty/` vendor name.
  - **No modules yet;** Core lives in the main `App` namespace, as ARCHITECTURE.md § 8 allows.
- **Tenancy core** (ARCHITECTURE.md § 4):
  - **`OrganizationContext`:** scoped singleton, so it is fresh per request and per job. API: `set`, `get`, `id`, `has`, `clear`, and `run()`, which restores the previous context even on failure.
  - **`BelongsToOrganization` + `OrganizationScope`:** queries and creates **without a context throw `MissingOrganizationContext`** (fail closed). `organization_id` is filled from the context. Creating a row in another organization, or moving one, throws `CrossOrganizationWrite`.
  - **`SetOrganizationContext` middleware** (alias `organization`):
    - looks the organization up by slug and checks membership;
    - gives non-members and unknown slugs the same 404;
    - redirects an old slug (`previous_slugs`) with a 301, keeping the path and query;
    - sets the context and `URL::defaults`;
    - runs **before route model binding**, so models bound from the URL are scoped.
  - **Larastan rule** `Tools\PHPStan\Rules\OrganizationScopeBypassRule` (registered in `phpstan.neon`, analysed itself). Outside `App\Platform` and `Modules\Platform` it rejects removing `OrganizationScope` (by class string, instance or array), and `withoutGlobalScopes()` on an organization-owned model or one it cannot identify. It reads models through `Builder` and `Relation` generics and follows nested trait use.
- **Organizations** (pulled into Core; ARCHITECTURE.md § 8 puts organizations there, and the context needs them):
  - `organizations` table with every DATABASE_SCHEMA.md § 1 column except foreign keys to tables that do not exist yet (`logo_media_id` stays a plain id), plus `organization_user` (unique membership, `is_default`).
  - `Organization` model: enum, encrypted and money casts; routed by slug; `cac_number` hidden from serialisation; activity log; table defaults declared on the model. `User::organizations()` and `belongsToOrganization()`.
- **Base enums** (`App\Enums`): `OrganizationType`, `OrganizationStatus`, `TransactionTier` (int), `Currency` (NGN, ₦, 100 kobo), with a `HasValues` trait for validation rules.
- **`App\Support\Money`:** immutable integer kobo with no floats.
  - **Parsing:** exact from whole naira or strings (`"₦1,250,000.50"`, with comma grouping checked and at most 2 decimals).
  - **Arithmetic:** `plus`, `minus` and `times`, with bounds checked *before* each operation, because an overflowing PHP integer silently becomes a float.
  - **Rounding:** `percentage()` in basis points and `dividedBy()`, with exact integer rounding in every PHP 8.4 `RoundingMode` (default half away from zero).
  - **Splitting:** `allocate()` by largest remainder and `split()`; the parts always sum to the total.
  - **Comparisons and output:** comparisons, `sum`, `min` and `max`; `format()` gives `₦1,250,000` with kobo only when non-zero (UX_DESIGN.md § 1) and grouping done on digit strings, so huge values stay exact; `toDecimalString()`; JSON as kobo.
  - **`MoneyCast`:** `*_kobo` columns read back as Money and accept writes only as Money, so a naira number cannot be stored as kobo.
- **Settings store** (`App\Settings`): `settings` table with global rows stored as `global`/0, because MySQL allows repeated NULLs in unique indexes. A registry declares each key with type, default, rules, scope, secret flag, permission and step-up (`config/settings.php` has `site.name` and the two Turnstile keys; modules call `define()`). `SettingsStore` is typed, validated and cached per scope. Secrets are encrypted in the database and the cache, and logged only as `[secret]`. Organization keys use the current context, and every change and reset is audited.
- **Activity log:** `spatie/laravel-activitylog` 5.1.3, with the `activity_log` table as published. `Organization` changes are logged (CAC number excluded) and so are settings changes. `clean_after_days` is raised to 7 years with a "never schedule `activitylog:clean`" note, because the default (365) would delete audit entries that PLATFORM_CONSOLE.md § 7 keeps for 7 years.
- `tools/` (dev only) is excluded from the release package. Docs: ARCHITECTURE.md § 4 and § 8, AGENT.md § 2–3, DATABASE_SCHEMA.md § 8.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci` twice: Pint passed, Larastan 0 errors (the new rule finds no bypass in `app/`), Pest **553 tests / 1,424 assertions**, 1 skipped (the module-boundary check, until modules exist).
- **AC 1 (scoped model queried without a context fails):**
  - **At run time:** `OrganizationScopeTest` shows get, count, find, first, exists, bulk update, bulk delete and create all throw without a context. With a context, each organization sees only its own rows, cannot find another's row by id, and cannot create in or move rows to another organization. `SetOrganizationContextTest` covers membership 404s, old-slug redirects, guests, and a foreign record bound from the URL returning 404.
  - **Statically:** `OrganizationScopeBypassRuleTest` (PHPStan `RuleTestCase` with Larastan) flags all 9 bypass forms in a fixture and stays silent for the Platform namespaces, non-tenant models and other scopes. Fixture tables live in throwaway databases, so the real schema stays GoProperty's own.
- **AC 2 (money helper unit-tested):** `tests/Unit/MoneyTest.php`, 88 cases: parsing and rejection, overflow on every operation, all 8 rounding modes, allocation sums, formatting and round-trips.
- Also: `SettingsStoreTest` (encryption in the database, cache and log, isolation, audit, validation), `OrganizationTest` and `ModuleSkeletonTest` (module generated into a temporary folder).
- **Package and install drills on a fresh `goproperty-0.7.0-rc.1.zip`** (same local `intl` caveat as P00-06): the release drill passed, including the secret scan of the new vendor code. The install drill passed with all 11 migrations under shared-host limits, with the schema still identical to a plain migrate.
- While testing, found and fixed: organization defaults (`allow_support_access`, `platform_covered`) were unknown to a new model, so every later save logged them as changed from null; and `cac_number` serialised in plain text.

**Follow-ups:**
- ~~Security: two Symfony advisories~~ Resolved in the security update above (both packages on 8.1.9, PHP platform 8.4.1).
- P01-01: spatie teams roles on `organization_user`, org switcher, and cross-tenant probes on every Phase-1 model.
- P00-09: theme, website and SEO keys plus `setting_versions` (draft/publish) on the settings registry.
- P00-11: activity log extra columns, hash chain, both guards as causer, per-log retention.
- Queued jobs touching organization data must call `OrganizationContext::run()`; consider a job middleware when the first such job appears.

**Next task:** P00-08 (console panel shell and route skeleton).

### P00-06 — In review (2026-10-26)

**Delivered** ([INSTALLER.md § 7](../INSTALLER.md), § 12):
- **`InstallationSteps`**: the installer's steps for the CLI and the web wizard (P01-09). Each step is safe to repeat:
  - `ensureApplicationKey`: create `.env` from `.env.example`, reuse a valid key or generate one, switch it into the process.
  - `configureSite`: probe, then write the site and database settings plus `HOSTING_PROFILE=shared`, `APP_TIMEZONE=Africa/Lagos`, `APP_DEBUG=false`, and file cache and sessions until the tables exist. It refuses database settings that differ from the checkpoint.
  - `buildDatabase`: batched migrations with progress, then `InstallSeeder`.
  - `configureMail` and `sendTestEmail` (SMTP).
  - `createSuperAdmin`: 12+ characters and a breached-password check; an existing staff account is accepted only with the same email and password.
  - `finalize`, then `writeInstallationRecord` (mode 0600, with `APP_KEY`).
  - Stages move only forward, in the checkpoint and the database row.
- **`php artisan goproperty:install`**: interactive (Laravel Prompts) or `--no-interaction`. It runs the server check with cPanel and Plesk fixes, then validates every answer before writing anything. Passwords come only from files or hidden prompts, with a warning when others can read the file. It refuses to run once installed, and prints the console URL, the cron line and the record path.
- **`php artisan goproperty:setup-token`**: a 40-character token printed once, with only its SHA-256 stored (mode 0600); a new token replaces the old one. `SetupToken::verify()` is for the P01-09 unlock.
- **`php artisan goproperty:check-installation {--json}`** (`InstallationHealth`): server requirements, pending migrations and missing tables, the three markers, production settings (key, debug off, unique console path, https, hosting profile) and scheduler and queue-tick heartbeats under 5 minutes old. Exits 1 on any failure.
- **`php artisan goproperty:finish-install`**: recovery for a finish stopped at `cleanup_pending`, which no other path could complete. Hidden **`goproperty:schema-snapshot`** for the drill.
- **`CronLine`**: panel CLI binaries first (cPanel ea-php84, CloudLinux alt-php84, Plesk 8.4), else PHP_BINARY only if it is a CLI binary (never php-fpm or lsphp); paths are quoted.
- **`platform_staff` table and `PlatformStaff` model, pulled forward from P00-08** so the installer can create the first super-admin. 2FA columns are nullable until first console sign-in; the guard, console and roles stay in P00-08. **`SchemaSnapshot`**: tables, columns, indexes, foreign keys and the migration list from `information_schema` (base tables only, not views).
- **Install drill** `scripts/release/install-drill.sh <zip>`, run by the new workflow `install-drill.yml` on MySQL 8.0 and 8.4 and MariaDB 10.6 and 11.4 (nightly, on pull requests touching migrations, seeders or the installer, by hand) and before every release (`release.yml` now needs it). It unpacks the split layout and runs `goproperty:install --no-interaction` under `max_execution_time=30`, `memory_limit=256M`, with `exec`, `proc_open` and pcntl disabled. Then:
  - a second install is refused;
  - after one cron run, `check-installation` passes;
  - `public_html` serves `/` and `/login` with 200 and `/install` with 404;
  - `verify-package` passes on the installed copy;
  - the schema is diffed against a plain `migrate` of the same zip.
- `config/app.php` timezone now reads `APP_TIMEZONE` (was hard-coded UTC, so the installer's Africa/Lagos had no effect). The workflows now install every PHP extension the requirements check needs (sodium, exif and others were missing from the list). [install-recovery.md](runbooks/install-recovery.md) is new (markers disagreeing, failed migration, lost access, start over).
- Doc corrections: INSTALLER.md § 5 step 3 said the installer writes `BROADCAST_CONNECTION=log`, but P00-03 chose `null` (via `HOSTING_PROFILE=shared`). § 7 now lists the real options. § 12 notes that the wizard-over-HTTP and upgrade drills are still to come.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **417 tests / 1,157 assertions** in parallel (64 new in `InstallCommandTest`, `InstallerCommandsTest` and `CronLineTest`; the gate also passed twice in a row before the last three tests were added). `InstallCommandTest` covers:
  - a full install into a throwaway database;
  - schema equal to a plain migrate, and a deliberate column change detected;
  - a second install refused, and resume after an interruption;
  - changed database settings and a different super-admin refused;
  - missing or invalid answers caught before `.env` is written;
  - breached and short passwords, and a refused database password;
  - a failed test email, a readable password file, and interactive prompts.
- **AC 2 (schema matches a plain migrate):** proven in Pest on every run and by the install drill below.
- **AC 1 (install drill):** on a freshly built `goproperty-0.6.0-rc.2.zip`, every step passed with MariaDB 11.4.12, including `check-installation` healthy (12 checks) and an identical schema snapshot. **Local caveat:** neither PHP build on this machine has `intl`. The real drill correctly stopped at the server check, so the local run used a scratch copy of the script that drops `intl` from the unpacked checker and restores it before `verify-package`. The repository script and the zip were unchanged. PHP 8.4.27 with sodium was used. The release drill (P00-04) also passed on the same zip.
- Not run here: MySQL 8 and MariaDB 10.6 (no servers on this machine) and the workflows themselves (GitHub Actions blocked, P00-02). Those are exactly what the AC names, hence In review.

**To finish:** once Actions starts jobs, run **Install drill** by hand (or let the nightly run) and confirm all four database jobs pass, then mark P00-06 complete.

**Follow-ups:**
- P00-08: `staff` guard and panel on `platform_staff`. Give the first staff account the `super-admin` role when roles exist (it is created without one). Enforce 2FA enrolment at first sign-in.
- P00-10: `goproperty:staff-reset-2fa`, which the recovery runbook names.
- P01-09: wizard steps on `InstallationSteps`; unlock with `SetupToken::verify()`; S3 storage setup and test (the CLI only warns for now); a drill that walks the wizard over HTTP.
- Upgrade drill (previous release → new release), INSTALLER.md § 12.
- If offline hosts matter: the breached-password check fails open but logs an error when the service is unreachable.

**Next task:** P00-07 (module skeleton and Core).

### P00-05 — Complete (2026-10-26)

**Delivered** (`App\Installer\…`, [INSTALLER.md § 3](../INSTALLER.md)):
- **Markers** (`InstallationMarkers`): lock file `storage/app/installed.lock`; `installation_state` table (one row, migration `2026_10_26_000000`, documented in DATABASE_SCHEMA.md § 8); checkpoint `storage/app/private/install.checkpoint` holding the stage (`InstallStage`: configured → migrating → owner_ready → cleanup_pending → finalized), an HMAC fingerprint of the database settings and an HMAC over both, keyed by `APP_KEY`. Installer closed if any marker says finished, or the checkpoint is unreadable or forged. The database marker is read only once `APP_KEY` exists, through a 3-second-timeout copy of the default connection. If the database can't be reached, the installer stays closed when a checkpoint exists and open when none does. Site open only when the lock and the row both say finalized.
- **`AwaitApplicationKey`** (first global middleware): with no usable key (missing, or not a valid 32-byte key), every GET/HEAD redirects to `/install` and writes get a plain 503 with `Retry-After`. No session, cookie, database or log use.
- **`EnsureSiteInstalled`** (second): "GoProperty is being set up" (503, `noindex`, `no-store`) on every public route until finalized; "temporarily unavailable" when the lock exists but the row can't be read. `/install` and `/up` pass.
- **`/install`** (`routes/install.php`, outside the web group, `EnsureInstallerOpen` → 404 once closed): first screen with the version and a pass/fail summary per group (PHP, Folders, Security, Build), with no paths or values. The pages are plain PHP views plus a static `public/install.css`, so they work before `storage/` is writable or the manifest is confirmed. Unlock and wizard steps are P01-09. (Shipping `install.css` closes that P00-04 follow-up early.)
- **`RequirementsChecker`**: every blocking and advisory check in § 5 step 2, each with cPanel and Plesk fixes. Writability is proved with a real probe file; HTTPS is required except on localhost/`*.test`; document-root and HTTPS checks are web-only. Host facts go through `ServerEnvironment`, so tests can simulate a poor host.
- **`EnvironmentFile`**: creates `.env` from `.env.example` (mode 0600). It sets keys in place, removes duplicates and appends new keys. Values are bare or double-quoted with `\`, `"` and `$` escaped, so `${…}` never expands, and each one is parsed back to prove it round-trips. Control characters are refused. Writes are atomic (`AtomicFile`: temp file → fsync → rename), and errors name the key, never the value.
- **`DatabaseProbe`** + `DatabaseSettings` (validated; password hidden from dumps): temporary connection with a 3 s timeout and MySQL 8.0 / MariaDB 10.6 minimums. Tables are listed **in the chosen database only**: Laravel's default lists every database the user can see. It refuses foreign tables and a finished install, and accepts our own tables from an earlier attempt. Driver errors become plain advice (refused password, no access, missing database, unreachable host); anything else is scrubbed of host, user and password. `OwnTables` reads `Schema::create('…')` names from the migration files; a test checks that a migrated database holds nothing else.
- **`BatchedMigrator`**: `progress()` ("34 of 112") and `runBatch(max, seconds)`, one migration per step (default 5 per batch, 20 s budget, at least one per batch). It resumes from Laravel's `migrations` table and reports a failing migration by name with a cleaned message. **`FreshDatabaseReset`** ("empty the database and start again", § 6) drops only GoProperty's tables, only while the installer is open, never when `organizations` has rows, and only after the database name is typed exactly.
- **`InstallSeeder`**: the installer's idempotent seeders (empty until roles, plans, tiers, theme and email templates exist). `DatabaseSeeder` calls it, and on a `local` copy writes both markers so `migrate --seed` gives a working dev site.
- **`Finalizer`**, in § 5 order: checkpoint → `cleanup_pending` first (so a later failure leaves the installer closed); `ADMIN_PATH=gp-control-xxxxxx` (`AdminPath`, rules from PLATFORM_CONSOLE.md § 10, an existing unique path kept on rerun); `SESSION_DRIVER`/`CACHE_STORE=database`; token, challenge, unlock file, grant and attempt files deleted; lock + row finalized; config/route/view/event caches in-process (`ArtisanOptimizer`; a failure is a warning, not a failed finish); `install.log` entry without the console path. `config('app.admin_path')` added for P00-08.
- `config/installer.php`, `install` log channel, `APP_NAME` default `GoProperty`. The release drill now checks the gates on the packaged app.
- Fixed while testing: `$` in PHP regexes also matches before a trailing newline, so `localhost\n` passed host validation. All installer and version patterns now use `D` (end of string only); regression tests added for hosts, `.env` key names and console paths.
- Docs: INSTALLER.md § 2, § 3 (how each marker is read), § 12; DATABASE_SCHEMA.md § 8; PLATFORM_CONSOLE.md § 10 (reserved words); AGENT.md § 2; [release.md](runbooks/release.md) § 3.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci` twice in a row: Pint passed, Larastan 0 errors, Pest **353 tests / 962 assertions** in parallel (186 new or reworked: `tests/Feature/Installer/*`, `tests/Drill/FreshCopyDrillTest.php`, `tests/Unit/AdminPathTest.php`). Every test starts with a temporary installer folder and an installed site, so tests never touch the checkout's own markers.
- **AC 1 (fresh copy → /install, no database, no error log):**
  - In-process: with no key, every GET redirects and every write gets a 503, with zero database queries, no session cookie and no error log call.
  - Real server: `php -S` on this checkout with `APP_KEY` empty, the database pointed at port 1 (nothing listening) and an empty private storage folder. `/`, `/login`, `/register`, `/dashboard` and `/up` returned 302 to `/install`; POST `/login` returned 503; `/install` returned 200 without a cookie. The log folder stayed empty and the server printed no PHP warnings.
  - Packaged app: the release drill on a fresh `goproperty-0.5.0-rc.1.zip` showed the same results in both layouts.
- **AC 2 (any one marker keeps the installer closed):** `/install` returns 404 with only the lock file, only a finalized row, only a `cleanup_pending`/`finalized` checkpoint, a corrupt, forged or re-keyed checkpoint, or a recorded database that can't be reached. It stays 200 for configured/migrating/owner_ready.
- Not run here: MySQL 8 (CI covers MySQL 8.0/8.4 once Actions runs); the GitHub workflows (P00-02 blocker).

**Follow-ups:**
- P00-06: `goproperty:setup-token`, `goproperty:install` and `goproperty:check-installation` drive these services; write `docs/runbooks/install-recovery.md` (markers disagreeing, start over), which INSTALLER.md § 9 names.
- P01-09: unlock (token and file check, same-origin, `flock` rate limit), grant, wizard steps and finish screen, using `/install` routes on the web stack after unlock; the detailed requirements report (`RequirementCheck::$detail`, `cpanel`, `plesk`).
- P00-11: move the finish record from `install.log` to the audit log (who, when, version, IP).
- P00-08: read `config('app.admin_path')` for the panel path.
- Later seeders (roles, plans, tiers, theme, email templates) go into `InstallSeeder::SEEDERS`; `InstallSeederTest` checks they are idempotent.

**Next task:** P00-06 (installer CLI + install drill).

### P00-04 — In review (2026-10-26)

**Delivered:**
- **Release zip** built by `scripts/release/build-package.sh <version> <dir>`: `goproperty-<version>.zip` + `.zip.sha256`. Inside: `README.txt` (which layout to use), `preferred/goproperty/` and `split/goproperty-app/` + `split/public_html/` ([INSTALLER.md § 2](../INSTALLER.md)). Each layout has production `vendor/`, built Vite assets, `.env.example`, empty `storage/` and `bootstrap/cache/` (Laravel's package caches are removed and rebuilt on first boot), `VERSION`, and `SHA256SUMS` (`sha256sum` format; every file except `storage/` and `bootstrap/cache/`; public files listed as `public/…` in both layouts). Same commit → same entries, order and timestamps. 55 MB zip; 17,112 files in the preferred layout, 17,113 in the split one.
- **Split layout:** `public_html/index.php` is generated from `public/index.php` (paths to `../goproperty-app/`, plus `$app->usePublicPath(__DIR__)`; the build fails if `index.php` changes shape). `bootstrap/app.php` now reads an optional `bootstrap/public-path.php`, written only into the split layout, so artisan and cron use `public_html` too (edit it for Plesk's `httpdocs`).
- **Shared build step** `scripts/release/stage.sh`: tracked files → `composer install --no-dev --optimize-autoloader` → `npm ci && npm run build` → dev files removed. `scripts/deploy/build-release.sh` (SSH deploy) now uses it too; its output is unchanged.
- **`php artisan goproperty:verify-package [path] [--public=]`** (`App\Release\PackageVerifier`, `ReleaseZip`, `DirectoryTree`, `ZipTree`): checks a zip in place (unsafe names are refused before anything is unpacked), an unpacked layout, or with no argument the running installation. Refuses: changed, missing or added files against `SHA256SUMS`; symlinks; `..`/absolute/backslash paths; duplicate zip entries; entries outside the layouts; `.env*` (only `*.example` allowed), `auth.json`, key files, SQLite files (by name and by header), `.git`/`node_modules`; dev dependencies (`installed.json` `dev` flag and `composer.lock` `packages-dev`); secret-shaped strings (private keys, AWS, Paystack/Stripe, GitHub, Slack, Google, filled-in `APP_KEY`, DSNs with keys; reported by kind, never printed); a missing Vite manifest or asset; entry points without the old-PHP guard; a split layout without its public path; a zip that does not match its `.sha256` or whose `VERSION` differs from its name. On a live site it ignores the site's own `.env`, `storage/`, `bootstrap/cache/`, symlinked `storage`/`.env` from the SSH deploy, and what hosting panels add (`error_log`, `.user.ini`, `.well-known/`, `cgi-bin/`, cPanel's PHP-handler block in `.htaccess`). Prints at most 50 problems. A folder with neither `VERSION` nor `SHA256SUMS` gets one line ("not an unpacked release package").
- **Old-PHP guard** `bootstrap/php-version.php`, loaded first by `artisan` and `public/index.php`: on PHP < 8.4 prints "GoProperty needs PHP 8.4 or newer; this server runs 8.1." (exit 1 on the CLI, HTTP 500 plain text on the web). No PHP 8 syntax; excluded from Pint so `declare(strict_types=1)` is not added.
- **Release workflow** `.github/workflows/release.yml`: on tags `vX.Y.Z` / `vX.Y.Z-suffix` → `CI` → build → release drill (with old-PHP checks on `php:7.4-cli` and `php:8.1-cli`) → `gh release create` with the zip and `.sha256` (suffix versions as pre-releases). Manual runs build a test package from any branch and keep it as a 7-day artifact (`actions/upload-artifact` v7.0.2, pinned by SHA). `ci.yml` now runs on branch pushes only (tags go through Release) and has a new job **`Old PHP guard`** (artisan and `index.php` on PHP 7.4 and 8.1).
- **Release drill** `scripts/release/verify-drill.sh <zip>` (§ 3 of the runbook): verifies the zip and both layouts with the package's own artisan, refuses a changed file, an added file and a changed zip entry, serves both layouts with `php -S` (`/up`, `/`, a built asset → 200), re-verifies after running, and checks the old-PHP sentence in Docker when `OLD_PHP_IMAGES` is set.
- Docs: [docs/runbooks/release.md](runbooks/release.md) (new: cutting a release, contents, drill, verifying on the server), INSTALLER.md § 2 and § 12, [ci.md](runbooks/ci.md) (fifth required check `Old PHP guard`), [shared-hosting.md](runbooks/shared-hosting.md) § 2–3, AGENT.md § 2. `/dist/` and `/bootstrap/public-path.php` are git-ignored.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **167 tests / 343 assertions** in parallel (89 new: every verifier rule, zip checks, the command, the PHP guard message and its PHP-7-safe syntax).
- **AC 1 (zip passes):** built `goproperty-0.4.0-rc.1.zip` with `build-package.sh`; `verify-drill.sh` passed: the package's own `goproperty:verify-package` passed the zip (34,225 files in about 5 s, 110 MB peak memory) and both unpacked layouts (the split layout found its public files in `public_html` from artisan); both layouts served `/up`, `/` and a built font with HTTP 200 under `php -S`, and still verified afterwards.
- **AC 2 (tampering fails):** a changed `public/robots.txt` (checksum mismatch), an added `public_html/added.php` (not listed) and a changed zip entry (inner checksum and `.sha256` both) were each refused with exit 1.
- Not run here: Docker is not accessible on this machine, so the old-PHP guard was not executed on PHP 7.4/8.1 locally (unit-tested on 8.4: message, passes 8.4, no PHP 8 tokens; loaded before Composer). The Release workflow and the `Old PHP guard` CI job were YAML-checked only (`actionlint` not installed) and have not run: GitHub Actions still fails every job in seconds (latest CI run 12:15 UTC), the P00-02 blocker.
- Local caveat: no `.env` in this checkout, so `composer ci` ran with a throwaway `APP_KEY` in the environment (CI creates `.env` and runs `key:generate`).

**To finish:** once GitHub Actions starts jobs, push a test tag (for example `v0.1.0-rc.1`) or run Release by hand, confirm the drill passes (including PHP 7.4/8.1) and the release is published, then mark P00-04 complete.

**Follow-ups:**
- P01-09: add the installer's static `public/install.css` to the package (INSTALLER.md § 2).
- P00-06: the install drill can reuse `build-package.sh` and the split layout from `verify-drill.sh`.
- The SSH deploy tarball has no `VERSION`/`SHA256SUMS`, so `goproperty:verify-package` on a CI-deployed server reports "not an unpacked release package". Add them to `build-release.sh` if the console's **System → Version** page (P08) needs them.
- The zip holds two copies of `vendor/` (55 MB). If an owner's File Manager upload limit is lower, publish one zip per layout instead.
- The launch checklist (IMPLEMENTATION_PLAN.md § 4) asks for a **signed** release zip; signing (e.g. GitHub artifact attestations) is not part of P00-04.
- The old-PHP Docker images are pinned by tag, not digest.

**Next task:** P00-05 (installer core services).

### P00-03 — In review (2026-10-26)

**Delivered:**
- **One profile switch.** `HOSTING_PROFILE=shared|vps` (`config/hosting.php`) sets the defaults for `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER`, `BROADCAST_CONNECTION` (published `config/broadcasting.php`), `REALTIME_DRIVER`, `PDF_DRIVER` and `QUEUE_TICK_ENABLED`; each variable still overrides it. Shared: database drivers, broadcasting `null`, polling every 15 s, dompdf, tick on. VPS: Redis, Reverb (60 s fallback poll), Chrome, tick off. `.env.example` now sets only `HOSTING_PROFILE`.
- **In-process queue tick** (`App\Hosting\Queue\QueueTick` + `TickWorker`, a subclass of Laravel's worker): scheduled with `Schedule::call()` every minute, last in `routes/console.php`. Drains the `database` queue with four lanes (payments = `billing`, notifications, default, bulk = `pdf` + `imports`), one job per lane in turn, per-lane caps, 45 s budget, memory and lost-connection stops, a 120 s cache lock, and a heartbeat. Retries use `QUEUE_TICK_TRIES` (3) and `QUEUE_TICK_BACKOFF` (30 s); failed jobs go to `failed_jobs` (Laravel's `queue:work` command does that, not its worker, so the tick does it itself). Job timeouts only when the pcntl functions work. A scheduler heartbeat runs every minute too.
- **Scheduler fix for shared hosts:** Laravel's scheduler calls `pcntl_async_signals()` before every event whenever the pcntl extension is loaded. On hosts that load pcntl but disable its functions, every scheduled event failed (found by the drill). `HostingServiceProvider` turns that signal hook off when the functions are missing.
- **PDF driver interface** `App\Pdf\PdfRenderer` (via `PdfManager`): `dompdf` (dompdf/dompdf 3.1.6; remote fetches, PHP and JS off; chroot to `resources/` and `public/`) and `chrome` through a Gotenberg service over HTTP (`PDF_CHROME_URL`, optional basic auth), so the PHP server needs neither Node nor `proc_open`. Browsershot is not used. Both let the template's `@page` rule win.
- **Realtime:** `App\Hosting\Realtime` (`usesWebsockets()`, `pollSeconds()`) for P07's live screens.
- **`php artisan goproperty:hosting`:** shows profile, drivers, whether `exec`/`proc_open` exist, heartbeats; exits 1 when settings cannot work together (unknown profile, tick off on shared hosting, jobs pushed to a queue the tick does not drain, Reverb without broadcasting or the Pusher SDK, Redis without a client, Chrome without a Gotenberg URL).
- **Deploy pipeline:** `.github/workflows/deploy.yml` (manual: environment + maintenance flag) calls `ci.yml` (now also `workflow_call`, `HOSTING_PROFILE=shared`), builds with `scripts/deploy/build-release.sh`, uploads with `scp` using pinned host keys, and runs `scripts/deploy/remote-deploy.sh` over SSH: unpack → link `shared/.env` and `shared/storage` → `goproperty:hosting` → optional `down` → `migrate --force` → config/route/view/event caches in the new release → atomic `current` switch → `queue:restart` → `up` → keep 5 releases. Then it checks `APP_URL/up`. GitHub environment settings are listed in [shared-hosting.md § 3](runbooks/shared-hosting.md).
- **Tests run on the shared profile:** `phpunit.xml` sets `HOSTING_PROFILE=shared` and no longer forces array cache or sessions (only the queue stays `sync`). `tests/TestCase.php` re-points the database cache store at the parallel worker's database (Fortify's rate limiter builds it before Laravel switches databases, and its reconnect was dropping each test's transaction).
- Docs: ARCHITECTURE.md § 2 and § 12.1, INSTALLER.md § 8, BRANDING_DOCUMENTS.md § 5, AGENT.md § 2 and § 4, README, [shared-hosting.md](runbooks/shared-hosting.md) § 2–3, § 5–6, [ci.md](runbooks/ci.md). The runbook's `BROADCAST_CONNECTION=log` became `null`, so broadcast payloads (possible PII) never reach the logs.

**Tests and evidence (local, MariaDB 11.4.12):**
- `composer ci`: Pint passed, Larastan 0 errors, Pest **79 tests / 220 assertions** in parallel (45 new: queue tick, schedule rules, profile defaults and overrides, configuration check, PDF drivers, cron drill). The parallel suite passed 3 runs in a row.
- **AC 2, cron drill** (`tests/Drill/CronDrillTest.php`): `php -d disable_functions=exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,pcntl_fork,pcntl_alarm,pcntl_signal,pcntl_async_signals artisan schedule:run` ran a queued job in the child process, which confirmed `exec`, `proc_open` and `pcntl_signal` were absent; jobs table empty, both heartbeats written.
- **AC 1** passes locally (suite on the shared profile). Not yet seen in CI: GitHub Actions still fails every job in seconds without a runner (runs on 2026-10-26 up to 11:32 UTC), the P00-02 blocker.
- **Deploy smoke test:** built a release (18 MB, no dev packages) and ran `remote-deploy.sh` twice against a local folder laid out like the host, with PHP restricted as above. First deploy migrated an empty database and switched `current`; the second ran with `MAINTENANCE=1 KEEP_RELEASES=1` (down → migrate → caches → switch → up, old release pruned). Duplicate and unsafe release names were refused. Served from `current/public`: `/up`, `/`, `/login` 200 and `/dashboard` 302 to `/login`. The cron line from `current/` ran a queued `Artisan::queue('inspire')` job. The SSH/scp steps and the workflow itself were not run (no host yet, PRE-04; Actions blocked), and `actionlint` was not available, so the workflows were only YAML-checked.
- Local caveat: this machine's PHP has no `intl`, so Composer ran with `--ignore-platform-req=ext-intl`; CI installs intl. MySQL 8.x was not run locally this time; CI covers MySQL 8.0/8.4 and MariaDB 10.6/11.4.

**To finish:** once GitHub Actions starts jobs, push and confirm the four `CI (…)` jobs are green (they include the cron drill), then mark P00-03 complete.

**Follow-ups:**
- P00-13: trigger `deploy.yml` on merges to `main` (staging) and set the staging environment variables and secret.
- P00-04 can reuse `scripts/deploy/build-release.sh` for the release zip.
- P03-05: bundle Inter for dompdf (fonts cache in `storage/framework/cache/dompdf`) and run the dompdf vs Chrome visual diff against a Gotenberg container.
- P07-03: use `Realtime::pollSeconds()` for `wire:poll`; installing Reverb adds `pusher/pusher-php-server`, which `goproperty:hosting` checks for.
- P08-03: read `Heartbeats` (`scheduler`, `queue-tick`) for the operations dashboard and the stopped-scheduler alert.

**Next task:** P00-04.

### P00-02 — Blocked (2026-10-26)

**Delivered:**
- `composer ci`: config clear → `pint --parallel --test` → `phpstan analyse` (Larastan level 7) → `php artisan test --parallel`. `composer test` now runs Pest only; `composer lint` fixes style.
- `.github/workflows/ci.yml`: runs `composer ci` on every push (and on demand), one job per database: `CI (MySQL 8.0)`, `CI (MySQL 8.4)`, `CI (MariaDB 10.6)`, `CI (MariaDB 11.4)`. MySQL 8.0 stays in the matrix because [docs/runbooks/shared-hosting.md § 1](runbooks/shared-hosting.md) promises 8.0+, although it reached end of life in April 2026. PHP 8.4, `npm ci` + `npm run build`, Composer cache. Actions pinned to commit SHAs (checkout v7.0.1, setup-php 2.40.0, setup-node v7.1.0, cache v6.1.0); passes `actionlint` 1.7.12.
- `.github/dependabot.yml`: weekly grouped updates for the pinned actions only (Composer/npm stay pinned by hand).
- [docs/runbooks/ci.md](runbooks/ci.md): what runs, how to reproduce locally against any server, and the branch-protection settings for `main`. AGENT.md § 2 and the README now point at `composer ci`.
- Larastan stays at level 7 (the kit default), above the level-6 minimum in the task.

**Tests and evidence (local):** `composer ci` passed against MySQL 8.4.11, MariaDB 10.6.27 and MariaDB 11.4.12 (each: Pint passed, Larastan 0 errors, Pest 34 tests / 83 assertions, 4 parallel workers each with its own `testing_test_N` database; the MariaDB runs used the `mariadb` connection). MySQL 8.0 could not be run locally (removed from nixpkgs at end of life); it is covered by its CI job.

**Why blocked:**
1. **CI jobs never start.** Run [40315055647](https://github.com/thekingchild/Goproperty/actions/runs/40315055647) (commit `64aeb52`) failed in 2–3 seconds on all four jobs, twice (one rerun). No runner was assigned and no step ran, so there are no logs. On a private repository on a personal account this is GitHub refusing to start jobs, usually a failed payment or a spending limit of $0 once included minutes are used up. The repo owner should open the run page (the annotation states the reason) and check **Settings → Billing and plans** (Actions usage, spending limit, payment method). After that, re-run the workflow or push any commit.
2. **Branch protection on `main`** needs a repository admin in the GitHub settings (the tools available here cannot set it). Settings to apply are in [docs/runbooks/ci.md § 3](runbooks/ci.md).

**To finish:** once the four CI jobs are green on the latest commit and branch protection is on, mark P00-02 complete.

**Next task:** P00-03 (does not depend on P00-02).

### P00-01 — Complete (2026-10-26)

**Delivered:** Laravel application skeleton at the repository root, from the official Livewire starter kit (`laravel/livewire-starter-kit` commit `b1e4f70`).

- Fortify auth with every kit feature kept: registration, email verification, 2FA (TOTP + recovery codes), passkeys, password confirmation. Flux UI components.
- Filament 5 installed as a package only. The staff panel, `staff` guard and `ADMIN_PATH` come in P00-08, so no admin panel is reachable yet.
- Pest 4: the kit's PHPUnit tests are rewritten in Pest style; `tests/Pest.php` applies `RefreshDatabase` to feature tests.
- Laravel Sail (`compose.yaml`): PHP 8.4 runtime, `mysql:8.4`, `axllent/mailpit:v1.32.0`. No Redis (shared-hosting profile uses database drivers).
- `.env.example` and `phpunit.xml` default to MySQL (Sail values; tests use the `testing` database Sail creates). Local mail goes to Mailpit.
- Pint enforces `declare(strict_types=1)` ([AGENT.md § 5](../AGENT.md)); Larastan runs at level 7 (kit default, above the level-6 minimum).
- Font: Inter, bundled from `@fontsource/inter` through the Vite font plugin and served locally ([UX_DESIGN.md § 6](../UX_DESIGN.md)); the kit's Bunny Fonts download was removed.
- Kit steps skipped on purpose: the ⚡ emoji filename prefix for Livewire components (emoji filenames are fragile in zips, FTP and cPanel file managers; Livewire works without it), the `--teams` option (organizations are our own design, [DATABASE_SCHEMA.md](../DATABASE_SCHEMA.md)), Laravel Boost, and the kit's GitHub workflow (PHP 8.3 + SQLite; CI is P00-02).

**Resolved versions** (`composer.lock`, `package-lock.json`). `composer.json` pins runtime and test packages to patch releases (`~X.Y.Z`) and sets `config.platform.php` to `8.4.0`, so updates never pull packages that need a newer PHP than the hosts run.

| Package | Version |
| --- | --- |
| PHP (minimum / tested) | ^8.4 / 8.4.27 |
| laravel/framework | 13.38.0 |
| livewire/livewire | 4.4.8 |
| livewire/flux | 2.21.0 |
| livewire/blaze | 1.0.20 |
| laravel/fortify | 1.41.0 |
| filament/filament | 5.10.3 |
| pestphp/pest / pest-plugin-laravel | 4.7.8 / 4.1.0 (PHPUnit 12.5.33) |
| larastan/larastan | 3.13.2 (PHPStan 2.3.2) |
| laravel/pint | 1.32.1 |
| laravel/sail | 1.69.0 |
| MySQL (Sail image / tested) | 8.4 / 8.4.11 |
| tailwindcss, @tailwindcss/vite | 4.3.3 |
| laravel-vite-plugin / vite-plus | 3.2.0 / 1.4.0 |
| @fontsource/inter | 5.3.0 |
| @laravel/passkeys | 0.2.0 |

**Tests and evidence:** fresh `git clone` → `composer install` → `key:generate` → `migrate --seed` on MySQL 8.4.11 → `npm ci` → `npm run build` → `composer test`: Pint passed, Larastan 0 errors, Pest 34 tests / 83 assertions passed. The app booted with `php artisan serve`; `/`, `/login`, `/register`, `/forgot-password` returned 200, `/dashboard` redirected guests to `/login`, and the seeded `test@example.com` user signed in to the dashboard in a browser. The Docker daemon was not available in the build environment, so `sail up` itself was not run; MySQL 8.4 ran natively instead.

**Follow-ups:**
- The kit's "delete account" setting hard-deletes the user row, which conflicts with [AGENT.md § 3](../AGENT.md) rule 7 (no hard deletes). Replace it with soft delete/deactivation when user accounts are reworked in P01; NDPA erasure stays in P09.
- Kit registration, login and welcome pages are stock placeholders: P00-09 (landing), P00-10 (login with email **or phone**), P01 (organization signup).
- Pest 5 has been released (needs PHP 8.4 and Laravel ≥ 13.23). The plan specifies Pest 4, so it stays on 4.7; decide on an upgrade separately.
- The theme font list in [PLATFORM_CONSOLE.md § 9](../PLATFORM_CONSOLE.md) (Plus Jakarta Sans, Manrope, DM Sans) should be bundled the same way as Inter in P00-09.

**Next task:** P00-02.

### Plan split into tasks — 2026-10-26

- The phases in `IMPLEMENTATION_PLAN.md` § 3 were split into 83 numbered tasks (PRE, P00–P09), each with its deliverable and acceptance criteria, following the Membersflow layout. No application code exists yet.
- Phase weeks follow the § 2 overview (P02 weeks 6–8, P03 weeks 9–12, P04 week 13); the old detail headings said weeks 6–7, 8–11 and 12.
- Where the original plan gave a deliverable no acceptance criterion, the task's acceptance was written from the deliverable and the companion docs. Review those before starting each phase.

### PRE-05 — Complete (2026-10-25)

- Design checkpoint: SocietyFlow reference in `docs/design/reference/`; approved designs `docs/design/templates/landing.html`, `login.html`, `login-2fa.html` (commit `8ba112e`).
