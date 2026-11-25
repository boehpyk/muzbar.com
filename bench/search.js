// Search latency benchmark (ADR-0026). Runs only inside the pinned k6 image — not a Node build step.
// Runbook: docs/infrastructure.md §Search: latency benchmark.
//
//   BENCH_MODE      service | explain | load
//   BENCH_BASE      http://nginx-bench            (k6 joins the internal `muzbar_bench` network)
//   BENCH_SCENARIOS /bench/out/scenarios.json     (the seeder's stdout: {"B1": [path, ...], ...})
//
// service: 1 client, 50 sequential requests per scenario — what one request costs, nothing queued.
// explain: 1 client, every URL once — run with auto_explain on, so the plans land in postgres' log.
// load:    10 requests/s arriving regardless of answers (open model, ADR-0026 d.1 as amended
//          2026-11-25); per scenario 10 s warm-up + 30 s measured, one scenario after another.
//          The exit code is the verdict (AC-10): p95 < 200 ms for B1–B8, nothing dropped.
//
// Every request checks the status AND the results marker; without that an empty database "passes".

import http from 'k6/http';
import { check } from 'k6';

const MODE = __ENV.BENCH_MODE || 'service';
const BASE = __ENV.BENCH_BASE || 'http://nginx-bench';
const SCENARIOS = JSON.parse(open(__ENV.BENCH_SCENARIOS || '/bench/out/scenarios.json'));
const NAMES = Object.keys(SCENARIOS).sort();
const GATING = NAMES.filter((n) => n !== 'B9');

const RATE = 10;        // requests per second
const MAX_CLIENTS = 50;  // in flight at most; past this k6 drops arrivals, which fails the run
const WARMUP_S = 10;
const MEASURE_S = 30;
const SERVICE_REQUESTS = 50;

function thresholds() {
    // Load mode: the verdict counts only measured, gating requests — a warm-up or B9 error is
    // reported, not gated. The other modes gate everything (AC-8's empty-database run relies on it).
    const t = MODE === 'load' ? {} : { http_req_failed: ['rate==0'], checks: ['rate==1'] };
    if (MODE === 'load') {
        // An open model hides overload as skipped arrivals instead of slow answers. B9 (last,
        // ungated) overloads 4 cores at this rate by design, so only gating scenarios count.
        for (const name of GATING) {
            t[`dropped_iterations{scenario:${name}}`] = ['count==0'];
            t[`http_req_failed{bn:${name},phase:measure}`] = ['rate==0'];
            t[`checks{bn:${name},phase:measure}`] = ['rate==1'];
        }
    }
    for (const name of NAMES) {
        // A threshold also creates the per-scenario sub-metric the summary reports.
        const key = MODE === 'load' ? `http_req_duration{bn:${name},phase:measure}` : `http_req_duration{bn:${name}}`;
        t[key] = MODE === 'load' && GATING.includes(name) ? ['p(95)<200'] : ['max>=0'];
    }
    return t;
}

function scenarios() {
    if (MODE !== 'load') {
        return { sequence: { executor: 'per-vu-iterations', vus: 1, iterations: 1, maxDuration: '30m', exec: 'sequence' } };
    }
    const s = {};
    let offset = 0;
    for (const name of NAMES) {
        const common = {
            executor: 'constant-arrival-rate', rate: RATE, timeUnit: '1s', preAllocatedVUs: RATE, maxVUs: MAX_CLIENTS,
            exec: 'hammer', gracefulStop: '5s', env: { BN: name },
        };
        s[`${name}_warmup`] = { ...common, duration: `${WARMUP_S}s`, startTime: `${offset}s`, tags: { bn: name, phase: 'warmup' } };
        s[name] = { ...common, duration: `${MEASURE_S}s`, startTime: `${offset + WARMUP_S}s`, tags: { bn: name, phase: 'measure' } };
        offset += WARMUP_S + MEASURE_S + 5;
    }
    return s;
}

export const options = {
    scenarios: scenarios(),
    thresholds: thresholds(),
    summaryTrendStats: ['med', 'p(95)', 'max', 'avg', 'count'],
    discardResponseBodies: false,
};

function get(name, path) {
    const res = http.get(BASE + path, { tags: { bn: name } });
    check(res, {
        'status 200': (r) => r.status === 200,
        'has results': (r) => typeof r.body === 'string' && r.body.includes('class="search-hit"'),
        // B7 is the S-18 fallback: an exact match would mean the typo is not one.
        'similar page (B7 only)': (r) => name !== 'B7' || (typeof r.body === 'string' && r.body.includes('class="search-similar"')),
    }, { bn: name });
}

export function sequence() {
    for (const name of NAMES) {
        const urls = SCENARIOS[name];
        const count = MODE === 'explain' ? urls.length : SERVICE_REQUESTS;
        for (let i = 0; i < count; i++) {
            get(name, urls[i % urls.length]);
        }
    }
}

export function hammer() {
    const urls = SCENARIOS[__ENV.BN];
    // Spread the clients over the scenario's URLs, and move each client along on every iteration.
    get(__ENV.BN, urls[(__VU + __ITER) % urls.length]);
}
