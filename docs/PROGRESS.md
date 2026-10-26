# GoProperty — Progress Ledger

The status of every task in [IMPLEMENTATION_PLAN.md § 3](../IMPLEMENTATION_PLAN.md). Rules for updating it are in [IMPLEMENTATION_PLAN.md § 3.1](../IMPLEMENTATION_PLAN.md) and [AGENT.md § 6](../AGENT.md).

Status values: `Not started` · `In progress` · `Blocked` · `In review` · `Complete (YYYY-MM-DD)`.

## Current state

- **In progress:** none
- **In review:** P00-03 (hosting profiles), P00-04 (release package) and P00-06 (installer CLI + install drill): done and verified locally; each needs one green run in GitHub Actions (CI for P00-03, a tagged Release for P00-04, the Install drill on MySQL 8 and MariaDB 10.6 for P00-06) once Actions starts jobs
- **Next task:** P00-09 (design system and platform theme); PRE-01 to PRE-04 continue with the product owner
- **Blocked:** P00-02: GitHub Actions jobs are not being given a runner (account-level), and branch protection on `main` needs a repo admin. This also holds P00-03's CI check, P00-04's release run and P00-06's install drill

## Board

| Phase | Tasks | Complete | Status |
| --- | --- | --- | --- |
| PRE | 5 | 1 | In progress |
| P00 | 13 | 4 | In progress |
| P01 | 9 | 0 | Not started |
| P02 | 7 | 0 | Not started |
| P03 | 13 | 0 | Not started |
| P04 | 3 | 0 | Not started |
| P05 | 6 | 0 | Not started |
| P06 | 11 | 0 | Not started |
| P07 | 4 | 0 | Not started |
| P08 | 4 | 0 | Not started |
| P09 | 8 | 0 | Not started |
| **Total** | **83** | **5** | |

## Ledger (newest first)

Each entry: task ID and status, date, what was delivered, tests run and results, follow-ups, next task.

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
