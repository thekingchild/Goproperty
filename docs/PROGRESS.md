# GoProperty — Progress Ledger

The status of every task in [IMPLEMENTATION_PLAN.md § 3](../IMPLEMENTATION_PLAN.md). Rules for updating it are in [IMPLEMENTATION_PLAN.md § 3.1](../IMPLEMENTATION_PLAN.md) and [AGENT.md § 6](../AGENT.md).

Status values: `Not started` · `In progress` · `Blocked` · `In review` · `Complete (YYYY-MM-DD)`.

## Current state

- **In progress:** none
- **In review:** P00-03 (hosting profiles): done and verified locally; needs one green CI run once GitHub Actions starts jobs
- **Next task:** P00-04 (release package); PRE-01 to PRE-04 continue with the product owner
- **Blocked:** P00-02: GitHub Actions jobs are not being given a runner (account-level), and branch protection on `main` needs a repo admin. This also holds P00-03's CI check

## Board

| Phase | Tasks | Complete | Status |
| --- | --- | --- | --- |
| PRE | 5 | 1 | In progress |
| P00 | 13 | 1 | In progress |
| P01 | 9 | 0 | Not started |
| P02 | 7 | 0 | Not started |
| P03 | 13 | 0 | Not started |
| P04 | 3 | 0 | Not started |
| P05 | 6 | 0 | Not started |
| P06 | 11 | 0 | Not started |
| P07 | 4 | 0 | Not started |
| P08 | 4 | 0 | Not started |
| P09 | 8 | 0 | Not started |
| **Total** | **83** | **2** | |

## Ledger (newest first)

Each entry: task ID and status, date, what was delivered, tests run and results, follow-ups, next task.

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
