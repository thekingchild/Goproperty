// P09-03: load test of the hot paths on the shared-hosting profile
// (docs/runbooks/load-test.md). Agency staff open the dashboard (workspace
// home and Reports), the bills list (with a filter, a search and a page
// change, as Livewire updates) and their busiest Building Group; tenants
// open the portal home, their bills and the group, then leave the feed
// open, polling every 15 s as the page does. Data: LoadTestSeeder.
//
//   k6 run -e BASE_URL=http://127.0.0.1:8124 tests/Load/hot-paths.js
//
// -e STAFF=15 -e TENANTS=35 -e MINUTES=10 set the load; -e PASSWORD the
// seeder's password. The thresholds are the latency budget (P09-03,
// docs/plans/P09-subtasks.md): k6 exits non-zero when one is missed.

import http from 'k6/http';
import { check, fail, group, sleep } from 'k6';
import { Counter } from 'k6/metrics';

const BASE = (__ENV.BASE_URL || 'http://127.0.0.1:8124').replace(/\/$/, '');
const SLUG = __ENV.SLUG || 'load-agency';
const PASSWORD = __ENV.PASSWORD || 'load-test-passphrase';
const STAFF = parseInt(__ENV.STAFF || '15', 10);
const TENANTS = parseInt(__ENV.TENANTS || '35', 10);
const MINUTES = parseInt(__ENV.MINUTES || '10', 10);
// Units of the group's building with a tenant: the seeder leaves every 20th empty.
const GROUP_UNITS = parseInt(__ENV.GROUP_UNITS || '100', 10);

const serverErrors = new Counter('server_errors');

// Sign-ins are limited per IP address (FortifyServiceProvider::
// SIGN_INS_PER_ADDRESS, 120 a minute) and every virtual user comes from this
// one: they sign in 2.5 s apart (24 a minute, well under it), so the ramp
// takes 2.5 s a person and is the same at any limit.
const RAMP_SECONDS = Math.ceil((STAFF + TENANTS) * 2.5);
const ramp = (vus) => [
    { duration: `${RAMP_SECONDS}s`, target: vus },
    { duration: `${MINUTES}m`, target: vus },
    { duration: '30s', target: 0 },
];

export const options = {
    // Each virtual user signs in once and keeps its session, like a person.
    noCookiesReset: true,
    scenarios: {
        staff: { executor: 'ramping-vus', exec: 'staff', startVUs: 0, stages: ramp(STAFF), gracefulRampDown: '30s' },
        tenants: { executor: 'ramping-vus', exec: 'tenant', startVUs: 0, stages: ramp(TENANTS), gracefulRampDown: '30s' },
    },
    // The budget: server time as k6 sees it on the same machine.
    thresholds: {
        'http_req_duration{kind:page}': ['p(95)<800', 'p(50)<300'],
        'http_req_duration{kind:action}': ['p(95)<500'],
        'http_req_duration{kind:poll}': ['p(95)<250'],
        'http_req_failed{kind:page}': ['rate<0.01'],
        'http_req_failed{kind:action}': ['rate<0.01'],
        'http_req_failed{kind:poll}': ['rate<0.01'],
        server_errors: ['count==0'],
        checks: ['rate>0.99'],
    },
    summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

// --- helpers -----------------------------------------------------------

function decode(html) {
    return html.replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&#39;/g, "'")
        .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
}

function note(res) {
    if (res.status >= 500) {
        serverErrors.add(1, { name: res.request && res.request.url });
    }
    return res;
}

function page(path, name) {
    const res = note(http.get(`${BASE}${path}`, { tags: { kind: 'page', name }, redirects: 0 }));
    check(res, { [`${name}: 200`]: (r) => r.status === 200 });
    return res;
}

/** The page's Livewire endpoint, CSRF token and the snapshot of the component whose name contains `component`. */
function livewire(res, component) {
    const body = res.body || '';
    const uri = (body.match(/data-update-uri="([^"]+)"/) || [])[1];
    const csrf = (body.match(/data-csrf="([^"]+)"/) || [])[1] || (body.match(/name="csrf-token" content="([^"]+)"/) || [])[1];
    const snapshots = [...body.matchAll(/wire:snapshot="([^"]+)"/g)].map((m) => decode(m[1]));
    const snapshot = snapshots.find((s) => {
        try { return JSON.parse(s).memo.name.includes(component); } catch (e) { return false; }
    });
    return uri && csrf && snapshot ? { uri, csrf, snapshot } : null;
}

/** One Livewire update; returns the component's next state (null on failure). */
function action(state, name, updates, calls, kind = 'action') {
    const url = /^https?:/.test(state.uri) ? state.uri : `${BASE}${state.uri}`;
    const res = note(http.post(url, JSON.stringify({
        _token: state.csrf,
        components: [{
            snapshot: state.snapshot,
            updates: updates || {},
            calls: (calls || []).map(([method, params]) => ({ method, params: params || [], metadata: {} })),
        }],
    }), {
        headers: { 'Content-Type': 'application/json', 'X-Livewire': '1', 'X-CSRF-TOKEN': state.csrf, Accept: 'application/json' },
        tags: { kind, name },
    }));
    const ok = check(res, { [`${name}: 200`]: (r) => r.status === 200 });
    if (!ok) {
        return null;
    }
    const next = res.json('components.0.snapshot');
    return next ? { uri: state.uri, csrf: state.csrf, snapshot: next } : null;
}

function signIn(identifier) {
    const form = http.get(`${BASE}/login`, { tags: { kind: 'signin', name: 'login form' } });
    const token = (form.body.match(/name="_token" value="([^"]+)"/) || [])[1];
    if (!token) {
        fail(`no sign-in form for ${identifier} (status ${form.status})`);
    }
    const res = http.post(`${BASE}/login`, { _token: token, identifier, password: PASSWORD }, {
        tags: { kind: 'signin', name: 'login' }, redirects: 5,
    });
    if (res.status !== 200 || res.url.endsWith('/login')) {
        fail(`sign-in refused for ${identifier} (status ${res.status}, at ${res.url})`);
    }
}

function think(min, max) {
    sleep(min + Math.random() * (max - min));
}

// --- agency staff --------------------------------------------------------

const STAFF_ACCOUNTS = ['owner.load@example.com', 'admin.load@example.com', 'agent.load@example.com'];

export function staff() {
    const account = STAFF_ACCOUNTS[(__VU - 1) % STAFF_ACCOUNTS.length];
    if (__ITER === 0) {
        sleep(((__VU - 1) * 2.5) % RAMP_SECONDS);
        signIn(account);
    }

    group('dashboard', () => {
        page(`/o/${SLUG}`, 'workspace home');
        think(2, 5);
        // Agents may not see Reports (reports.view); they see the bills of their buildings.
        if (account.startsWith('agent.')) {
            return;
        }
        const reports = page(`/o/${SLUG}/reports`, 'reports');
        const state = livewire(reports, 'reports');
        think(3, 6);
        if (state) {
            // A different date range: the cache keys on the dates.
            const year = new Date().getFullYear();
            action(state, 'reports: date range', { from: `${year}-01-01` });
        }
    });
    think(3, 8);

    group('bills list', () => {
        const bills = page(`/o/${SLUG}/bills`, 'bills');
        let state = livewire(bills, 'bill-index');
        think(2, 5);
        if (state) {
            state = action(state, 'bills: overdue filter', { status: 'overdue' });
        }
        think(2, 4);
        if (state) {
            state = action(state, 'bills: search', { status: '', search: 'Adebayo' });
        }
        think(2, 4);
        if (state) {
            action(state, 'bills: page 2', { search: '' }, [['gotoPage', [2, 'page']]]);
        }
        think(2, 4);
        page(`/o/${SLUG}/bills?status=paid&page=3`, 'bills (paid, page 3)');
    });
    think(3, 8);

    group('group feed', () => {
        const groups = page(`/o/${SLUG}/groups`, 'groups');
        const id = (groups.body.match(new RegExp(`/o/${SLUG}/groups/(\\d+)`)) || [])[1];
        if (id) {
            const feed = page(`/o/${SLUG}/groups/${id}`, 'group (workspace)');
            const state = livewire(feed, 'group-show');
            think(5, 10);
            if (state) {
                action(state, 'group poll (workspace)', {}, [['refreshFeed']], 'poll');
            }
        }
    });
    think(5, 15);
}

// --- tenants ---------------------------------------------------------------

export function tenant() {
    if (__ITER === 0) {
        sleep(((__VU - 1) * 2.5) % RAMP_SECONDS);
        // tenant{u}.load@example.com for the group building's let units.
        const units = [];
        for (let u = 1; u <= GROUP_UNITS; u++) {
            if (u % 20 !== 0) units.push(u);
        }
        signIn(`tenant${units[(__VU - 1) % units.length]}.load@example.com`);
    }

    page('/portal', 'portal home');
    think(3, 6);
    page('/portal/bills', 'portal bills');
    think(3, 6);

    const groups = page('/portal/groups', 'portal groups');
    const id = (groups.body.match(/\/portal\/groups\/(\d+)/) || [])[1];
    if (!id) {
        return;
    }
    const feed = page(`/portal/groups/${id}`, 'group (portal)');
    let state = livewire(feed, 'group');
    // The page polls every 15 s while open; a visit keeps it open about a minute.
    for (let i = 0; i < 4 && state; i++) {
        sleep(15);
        state = action(state, 'group poll (portal)', {}, [['refreshFeed']], 'poll');
    }
    think(5, 20);
}
