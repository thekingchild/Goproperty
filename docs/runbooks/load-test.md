# Runbook — Load Test of the Hot Paths (P09-03)

How to run the load test on the shared-hosting profile, what it measures, and the latency budget it checks. The script is [`tests/Load/hot-paths.js`](../../tests/Load/hot-paths.js) (k6); the data comes from `Database\Seeders\LoadTestSeeder`. Results of the first run are in § 5.

## 1. What it measures

Two kinds of people at once, each signed in with their own session:

- **Agency staff** (owner, administrator, and an agent assigned to 5 buildings): the dashboard (workspace home and **Reports**, then a different date range), the bills list (the page, then the OVERDUE filter, a search and page 2 as Livewire updates, then a page of paid bills), and the busiest Building Group (the page, then a poll). Agents skip Reports (they may not see it).
- **Tenants** of the busiest building: the portal home, **My bills**, the group list and the group, then the group left open for a minute, polling every 15 s as the page does.

Requests are tagged `kind:page` (a full page), `kind:action` (a Livewire update the person asked for) and `kind:poll` (the feed's own poll). The thresholds in the script are the budget (§ 3): k6 exits non-zero when one is missed.

## 2. Running it

### Data

`LoadTestSeeder` builds, through the modules' own writers (so the rows, audit log included, are the ones real use makes), one agency at the top of the shared-hosting profile ([ARCHITECTURE.md § 12](../../ARCHITECTURE.md): move to a VPS at ~5,000 units across ~50 customers): **Load Test Realty Ltd** (`load-agency`), 40 buildings and 1,075 units (the first building, Palm Court, has 100 and the busy group), 95% let on monthly leases, a year of rent bills issued a week before each month and paid on the due date (one tenant in four owes the last month, one in ten the last three), and 300 group posts with 1,500 comments and 3,000 reactions over the last 50 days; plus 4 smaller agencies (250 units, 6 months each). About 40 minutes. Sizes are environment variables (`LOADTEST_*`, see the class). It refuses to run in production.

```bash
php artisan db:seed --class=LoadTestSeeder --force
```

Sign-ins: `owner.load@example.com`, `admin.load@example.com`, `agent.load@example.com`, and `tenant{n}.load@example.com` for the tenants of Palm Court (n from 1 to 100, not multiples of 20: those flats are empty), all with the password `load-test-passphrase` (or `LOADTEST_PASSWORD`).

### The site under test

On **staging** (the real host): seed it, then run k6 from another machine against its address. Expect slower numbers than § 5 if the host is slower; the budget is for the shared host.

Locally, as for § 5: the release package's layout (`composer install --no-dev`, built assets), installed with `goproperty:install` under the shared-host limits, `php artisan optimize`, served by PHP's own server on **2 CPU cores** with 8 workers, OPcache on, `memory_limit=256M`, `max_execution_time=30`, `exec`/`proc_open` disabled, `HOSTING_PROFILE=shared` (database cache, sessions and queue), MariaDB 11.4 on the same machine, `schedule:run` every minute. Mail goes to the `log` mailer (an API mail provider in production).

```bash
PHP_CLI_SERVER_WORKERS=8 taskset -c 6,7 php -d memory_limit=256M -d max_execution_time=30 \
  -d opcache.enable=1 -d opcache.enable_cli=1 -S 127.0.0.1:8124 -t public
```

### The load

```bash
k6 run -e BASE_URL=http://127.0.0.1:8124 -e STAFF=15 -e TENANTS=35 -e MINUTES=10 tests/Load/hot-paths.js
```

15 staff and 35 tenants at once is a busy hour for an agency of this size (each person thinks 2–20 s between clicks). `-e STAFF=… -e TENANTS=…` raise it to find where the site starts to slow.

## 3. The latency budget

Server time as k6 measures it next to the server (network time to Nigeria comes on top), at 15 staff and 35 tenants on 2 cores:

| Requests | Budget |
| --- | --- |
| Full pages (`kind:page`) | p95 under 800 ms, median under 300 ms |
| Livewire updates (`kind:action`) | p95 under 500 ms |
| The group's poll (`kind:poll`) | p95 under 250 ms |
| Errors | no 5xx; under 1% failed requests of each kind; 99% of checks pass |

Agreed by the product owner on 2026-11-09 (docs/plans/P09-subtasks.md, P09-03 default 86).

## 4. Lasting guards

The suite keeps the hot paths from growing a query per row (N+1): each runs the same queries for a few rows as for many.

- `Modules/Billing/tests/Feature/BillListQueriesTest.php`: the bills list, for the organization and a narrowed agent, a search and a Livewire update.
- `Modules/BuildingGroup/tests/Feature/GroupFeedQueriesTest.php`: the group page in the portal and on the workspace, and the poll (at most 20 queries).
- `Modules/Tenants/tests/Feature/Portal/PortalQueriesTest.php`: the portal home and My bills.
- `Modules/Reports/tests/Feature/ReportQueriesTest.php`: a cold Reports load over a year, and the Lagos month edge.

`Tests\Support\QueryCounter` counts a request's queries, leaving out sessions, the cache and the queue.

## 5. Results

First run, 2026-11-09, locally as § 2 describes (k6 v2.3.0; 15 staff and 35 tenants; 10 minutes at full load; ~5.5 requests a second). Server time in ms:

| Requests | Before the fixes: median / p95 | After: median / p95 |
| --- | --- | --- |
| Group page, portal | 1,843 / 4,497 | 333 / 876 |
| Group page, workspace | 1,002 / 4,304 | 467 / 958 |
| Reports | 841 / 2,733 | 336 / 665 |
| Reports, another date range | 2,418 / 5,975 | 167 / 414 |
| Bills list | 528 / 1,100 | 329 / 627 |
| Bills: filter, search, page 2 | 217–254 / 556–667 | 151–187 / 318–362 |
| Portal home, My bills, groups | 237–296 / 564–700 | 154–158 / 347–354 |
| Group poll (portal) | 136 / 387 | 90 / 198 |
| **All pages** | **449 / 2,780** | **272 / 645** |
| **All Livewire updates** | — / 2,720 | — / 353 |
| **All polls** | — / 407 | — / 199 |

No 5xx and no failed request in either run; every threshold of § 3 passes after the fixes. What the measurements found, and the fix (docs/plans/P09-subtasks.md, P09-03 defaults 89–93):

- **Reports worked a year out from ~12,000 bill and ~12,000 payment models** (12 s cold for a year of this agency, a 30-second shared-host limit away from failing): now sums and plain rows, 1.9 s cold for a year, ~1.2 s for a month; the 5-minute cache then answers.
- **The Reports cache held one date range per agency**: members on different dates pushed each other's figures out on every load. It now keeps the last 4.
- **The group feed filtered every reaction and comment of the page for each post and comment** (555 ms of the portal group page): now counted once per page (~75 ms).
- **The same lookups ran again and again in one request**: the plan's features (two queries, ~10 times a page) and a tenant's group membership (two queries, 9 times on a group page). Both are now kept for the request.
- **The new-device sign-in email was sent inside the sign-in**: an unreachable mail provider failed the sign-in. It is now queued.

Sign-ins are limited per IP address (30 a minute at the time of this run, 120 since: product owner, 2026-11-09); the script spaces its sign-ins 2.5 s apart to stay well under it (all its users come from one address).

**Headroom:** at twice the load (30 staff and 70 tenants, 5 minutes, ~7.2 requests a second on the same 2 cores) nothing fails, but the server is full: page p95 2.3 s, Livewire updates 1.8 s, polls 1.5 s. One 2-core shared account carries a busy hour of the largest agency the shared profile is meant for, with roughly a third to spare. When the console's System operations shows requests slowing like this, it is time for Profile B ([ARCHITECTURE.md § 12](../../ARCHITECTURE.md)).
