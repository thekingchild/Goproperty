# CI and Branch Protection

The quality gate from [AGENT.md § 2](../../AGENT.md) runs in GitHub Actions on every push to a branch ([`.github/workflows/ci.yml`](../../.github/workflows/ci.yml)). Version tags run it through the **Release** workflow instead ([release.md](release.md)).

## 1. What runs

`composer ci` runs, in order:

1. `pint --parallel --test`: code style (Laravel preset + `declare(strict_types=1)`)
2. `phpstan analyse`: Larastan at level 7 (`phpstan.neon`; the plan's minimum is 6)
3. `php artisan test --parallel`: the Pest suite, one database per worker (`testing_test_1`, `testing_test_2`, …)

The suite runs on the **shared-hosting profile** (`HOSTING_PROFILE=shared`: database cache and sessions, no broadcasting, polling, dompdf); only the queue runs jobs inline. It includes the cron drill (`tests/Drill/CronDrillTest.php`), which starts `php artisan schedule:run` with `exec`, `proc_open` and pcntl disabled and checks that a queued job ran ([INSTALLER.md § 8](../../INSTALLER.md)). The document numbering drill (`tests/Drill/DocumentNumberingDrillTest.php`, P03-04a) starts six PHP processes that take numbers at once on their own database connections, some rolling back, and checks the numbers are exactly 1…N. The lease activation drill (`tests/Drill/LeaseActivationDrillTest.php`, P03-03a) starts four processes that each try to activate every draft lease of three units (three drafts per unit) at once, and checks that exactly one lease per unit becomes active, with contract numbers 1…N and one deposit entry each. The bill generation drill (`tests/Drill/BillGenerationDrillTest.php`, P03-04b) starts four processes that generate the same organization's rent bills at once (leases behind by several periods, without the job's lock) and checks that every period is billed exactly once with invoice numbers 1…N, then that six processes paying one bill at once never settle it beyond its amount. The **Deploy** and **Release** workflows call this workflow first ([shared-hosting.md § 3](shared-hosting.md), [release.md](release.md)).

CI runs it once per database, because cPanel/Plesk hosts often provide MariaDB rather than MySQL ([ARCHITECTURE.md § 2](../../ARCHITECTURE.md)):

| Job | Image | Laravel connection |
| --- | --- | --- |
| `CI (MySQL 8.0)` | `mysql:8.0` | `mysql` |
| `CI (MySQL 8.4)` | `mysql:8.4` | `mysql` |
| `CI (MariaDB 10.6)` | `mariadb:10.6` | `mariadb` |
| `CI (MariaDB 11.4)` | `mariadb:11.4` | `mariadb` |

These are the oldest and newest LTS releases of each engine that shared hosts still run.

**Documents (P03-05a):** each CI job also runs **Gotenberg** (`gotenberg/gotenberg:8.37.0-chromium`, headless Chrome over HTTP) as a second service on port 3000 (`PDF_CHROME_URL=http://127.0.0.1:3000`; the driver stays dompdf), and installs `poppler-utils` when the runner lacks it (`pdftoppm`, `pdftotext`). `tests/Feature/Documents/DocumentKitVisualDiffTest.php` and `InvoiceReceiptTemplatesTest.php` render the kit's sample page and the invoice and receipt in every layout with dompdf and with Chrome and compare the rasterised pages (`tests/Support/PdfVisualDiff.php`, [BRANDING_DOCUMENTS.md § 5](../../BRANDING_DOCUMENTS.md)); the invoice and receipt web pages are also measured at 360 px through Gotenberg's screenshot route (`tests/Support/NarrowScreen.php`). On CI (`CI` set) a missing Gotenberg or poppler fails those tests; elsewhere they are skipped with the reason, and the dompdf half always runs.

**Install drill** ([`.github/workflows/install-drill.yml`](../../.github/workflows/install-drill.yml)): builds the release package and installs its split layout from the command line under shared-host limits, then compares the schema with a plain `migrate` ([release.md § 3](release.md), [INSTALLER.md § 12](../../INSTALLER.md)). It runs nightly, on pull requests that touch migrations, seeders or the installer, before every release, and by hand, on the same four databases (`Install drill (MySQL 8.0)` …). It is not a required check, because most pull requests do not run it.

A fifth job, `Old PHP guard`, runs `artisan` and `public/index.php` from the checkout on PHP 7.4 and 8.1 (Docker images `php:7.4-cli`, `php:8.1-cli`) and expects the one-sentence "GoProperty needs PHP 8.4.1 or newer; this server runs 8.1.x." message instead of an error ([INSTALLER.md § 2](../../INSTALLER.md)). Each job also runs `npm ci` and `npm run build`, because views need the Vite manifest. Actions are pinned to commit SHAs; Dependabot proposes updates weekly.

## 2. Running it locally

With Sail: `./vendor/bin/sail composer ci`. Sail's MySQL container creates the `testing` database and grants `testing%` to the `sail` user, so parallel test databases work.

Against another server, override the connection for one run (environment variables win over `.env` and `phpunit.xml`):

```bash
DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=testing \
DB_USERNAME=root DB_PASSWORD=secret composer ci
```

The user needs rights to create `testing_*` databases for `--parallel`.

The Chrome half of the document visual-diff tests runs when Gotenberg answers at `PDF_CHROME_URL` (default `http://127.0.0.1:3000`): Sail starts it (`compose.yaml` service `gotenberg`; set `PDF_CHROME_URL=http://gotenberg:3000` inside Sail), or run `docker run --rm -p 3000:3000 gotenberg/gotenberg:8.37.0-chromium`. Without it those tests are skipped locally.

## 3. Branch protection on `main`

Set once by a repository admin, in **Settings → Rules → Rulesets → New branch ruleset** (or **Settings → Branches → Add classic branch protection rule**):

- **Target:** the default branch (`main`).
- **Restrict deletions** and **Block force pushes**: on.
- **Require a pull request before merging**: on (at least 1 approval once there is a second developer).
- **Require status checks to pass**: on, with "require branches to be up to date" on. Add the five checks `CI (MySQL 8.0)`, `CI (MySQL 8.4)`, `CI (MariaDB 10.6)`, `CI (MariaDB 11.4)` and `Old PHP guard`. They appear in the picker after the workflow has run once.
- **Bypass list:** empty, so admins follow the same rule.

If a job is renamed or a database version is added, update the required checks in the same change, or merges will wait for a check that never reports.
