#!/usr/bin/env node
/**
 * Admin render check — does each page actually render, in a real browser?
 *
 * Twice now a systemically broken admin UI has passed every backend gate
 * this project has: Phase 6 rendered Arabic as empty tofu boxes, and
 * Phase 7 rendered twelve admin screens completely blank (React refuses
 * to render an object as a child, so it threw and unmounted — with a 200
 * status, correct props and clean types the whole time).
 *
 * Neither is visible to Pest, tsc, ESLint or Larastan. Only executing the
 * bundle catches them, so this drives a headless Chromium over the
 * DevTools protocol, signs in as an employee, visits every admin GET
 * route and fails if a page renders nothing or throws.
 *
 * No npm dependencies: Node 22+ ships a global WebSocket, which is the
 * only thing CDP needs beyond fetch.
 *
 *   node tools/render-check.js --base http://localhost:26991 \
 *        --email admin@waqar.test --password secret [--locale ar]
 *
 * Assumes a Chromium already listening for CDP, e.g.
 *   chromium --headless --no-sandbox --remote-debugging-port=9222 \
 *            --remote-allow-origins='*'
 *
 * `--cdp` must address the browser by **IP or localhost**, never by
 * container name: Chromium refuses any CDP request whose Host header is
 * a hostname, and Node's fetch forbids overriding Host. Publish the port
 * (`-p 9222:9222`) and use 127.0.0.1, or pass the container's IP.
 *
 * Exit code 1 if any page fails, so it can gate a build.
 */

import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';

const args = Object.fromEntries(
    process.argv.slice(2).reduce((pairs, arg, i, all) => {
        if (arg.startsWith('--')) pairs.push([arg.slice(2), all[i + 1]?.startsWith('--') ? true : all[i + 1]]);
        return pairs;
    }, []),
);

const BASE = args.base || 'http://localhost:26991';
const CDP = args.cdp || 'http://127.0.0.1:9222';
const LOCALE = args.locale || 'ar';
const EMAIL = args.email;
const PASSWORD = args.password;
const ROUTES = args.routes;
// --shots <dir> also writes a full-page PNG per route, which is the only
// way to judge template fidelity (a page can render perfectly and still
// look nothing like Larkon).
const SHOTS = typeof args.shots === 'string' ? args.shots : null;
// --width narrows the emulated viewport, to check the template's own
// responsive behaviour rather than only the desktop layout.
const WIDTH = Number(args.width) || 1440;

if (!EMAIL || !PASSWORD) {
    console.error('usage: render-check.js --email <employee> --password <password> [--base URL] [--cdp URL] [--routes file]');
    process.exit(2);
}

/** Minimal CDP client over the one websocket the browser exposes. */
class Session {
    #ws;
    #id = 0;
    #pending = new Map();
    #sessionId = null;
    #loaded = null;
    exceptions = [];

    async connect() {
        const { webSocketDebuggerUrl } = await (await fetch(`${CDP}/json/version`)).json();
        this.#ws = new WebSocket(webSocketDebuggerUrl);
        await new Promise((resolve, reject) => {
            this.#ws.addEventListener('open', resolve, { once: true });
            this.#ws.addEventListener('error', reject, { once: true });
        });

        this.#ws.addEventListener('message', ({ data }) => {
            const message = JSON.parse(data);
            if (message.id && this.#pending.has(message.id)) {
                const { resolve, reject } = this.#pending.get(message.id);
                this.#pending.delete(message.id);
                message.error ? reject(new Error(JSON.stringify(message.error))) : resolve(message.result);
            } else if (message.method === 'Runtime.exceptionThrown') {
                const details = message.params.exceptionDetails;
                this.exceptions.push(details.exception?.description?.split('\n')[0] || details.text);
            } else if (message.method === 'Page.loadEventFired') {
                this.#loaded?.();
            }
        });

        const { targetId } = await this.send('Target.createTarget', { url: 'about:blank' });
        ({ sessionId: this.#sessionId } = await this.send('Target.attachToTarget', { targetId, flatten: true }));

        for (const domain of ['Page', 'Runtime', 'Network']) await this.send(`${domain}.enable`);
        await this.send('Emulation.setDeviceMetricsOverride', {
            width: WIDTH, height: 1000, deviceScaleFactor: 1, mobile: WIDTH < 768,
        });
    }

    send(method, params = {}) {
        const id = ++this.#id;
        return new Promise((resolve, reject) => {
            this.#pending.set(id, { resolve, reject });
            // sessionId is omitted entirely until a target is attached —
            // CDP rejects the field being present-but-null on the
            // browser-level calls that set it up in the first place.
            const message = { id, method, params };
            if (this.#sessionId) message.sessionId = this.#sessionId;
            this.#ws.send(JSON.stringify(message));
        });
    }

    /**
     * Waits for the real load event before settling, rather than hoping a
     * fixed sleep is long enough — a too-short guess reports a page that
     * was merely still loading as "rendered nothing", which is exactly
     * the failure this tool is supposed to detect for real.
     */
    async goto(url, { timeoutMs = 15000, hydrateMs = 10000 } = {}) {
        this.exceptions.length = 0;

        const load = new Promise((resolve) => {
            this.#loaded = resolve;
            setTimeout(resolve, timeoutMs);
        });

        await this.send('Page.navigate', { url });
        await load;
        this.#loaded = null;

        // Inertia mounts *after* load, so poll for painted content rather
        // than sleeping a guessed interval. A fixed delay that is a little
        // too short reports a page that was merely still mounting as
        // "rendered nothing" — turning the check flaky, which is worse
        // than not having it: nobody trusts a gate that cries wolf.
        const deadline = Date.now() + hydrateMs;
        while (Date.now() < deadline) {
            if (this.exceptions.length) break;
            const painted = await this.evaluate('(document.body?.innerText || "").trim().length > 0');
            if (painted === true) break;
            await new Promise((resolve) => setTimeout(resolve, 150));
        }
    }

    /** Full-page PNG, captured past the fold rather than just the viewport. */
    async screenshot(file) {
        const { cssContentSize } = await this.send('Page.getLayoutMetrics');
        const height = Math.min(Math.ceil(cssContentSize?.height || 1000), 8000);
        await this.send('Emulation.setDeviceMetricsOverride', {
            width: WIDTH, height, deviceScaleFactor: 1, mobile: WIDTH < 768,
        });
        const { data } = await this.send('Page.captureScreenshot', { format: 'png' });
        writeFileSync(file, Buffer.from(data, 'base64'));
        await this.send('Emulation.setDeviceMetricsOverride', {
            width: WIDTH, height: 1000, deviceScaleFactor: 1, mobile: WIDTH < 768,
        });
    }

    async evaluate(expression) {
        const { result, exceptionDetails } = await this.send('Runtime.evaluate', {
            expression, returnByValue: true, awaitPromise: true,
        });

        if (exceptionDetails) {
            throw new Error(exceptionDetails.exception?.description?.split('\n')[0] || exceptionDetails.text);
        }

        return result?.value;
    }

    close() {
        this.#ws.close();
    }
}

/**
 * Sign in through the real login form rather than forging a session
 * cookie — that also proves the login page itself still works.
 */
async function signIn(page) {
    await page.goto(`${BASE}/${LOCALE}/admin/login`);

    const ok = await page.evaluate(`(async () => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        if (!token) return 'no csrf token on the login page';
        const body = new URLSearchParams({ email: ${JSON.stringify(EMAIL)}, password: ${JSON.stringify(PASSWORD)}, _token: token });
        const response = await fetch(${JSON.stringify(`${BASE}/${LOCALE}/admin/login`)}, {
            method: 'POST', body, credentials: 'include', redirect: 'follow',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        return response.ok || response.status === 302 ? true : 'login returned ' + response.status;
    })()`);

    if (ok !== true) throw new Error(`sign-in failed: ${ok}`);
}

async function main() {
    const page = new Session();
    await page.connect();
    await signIn(page);

    const routes = ROUTES && typeof ROUTES === 'string'
        ? readFileSync(ROUTES, 'utf8').split('\n').map((line) => line.trim()).filter(Boolean)
        : [`/${LOCALE}/admin`];

    let failed = 0;

    if (SHOTS) mkdirSync(SHOTS, { recursive: true });

    for (const path of routes) {
        await page.goto(BASE + path);

        if (SHOTS) {
            await page.screenshot(`${SHOTS}/${path.replace(/^\/+|\/+$/g, '').replace(/\//g, '_') || 'root'}.png`);
        }

        const observed = await page.evaluate(`JSON.stringify({
            text: (document.body.innerText || '').trim().length,
            rows: document.querySelectorAll('table tbody tr').length,
            title: document.title,
            // React renders a stringified object when handed one where a
            // string was expected — the visible half of the same bug that
            // blanks a page outright.
            objects: (document.body.innerText || '').includes('[object Object]'),
            // An untranslated key renders as its own dotted name. Matched
            // against the exact catalog prefixes, not a loose "word.word":
            // /admin/roles legitimately *displays* permission names, which
            // are dotted too (treasury.manage, orders.view), and a broader
            // pattern flags that page on every run.
            rawKeys: /(?:^|\\s)(?:admin|status|productType|activity\\.(?:log|event)|inventory\\.type|treasury\\.(?:type|tx))\\.[a-zA-Z_]+/.test(document.body.innerText || ''),
        })`);

        const r = JSON.parse(observed);
        const problems = [];
        if (r.text === 0) problems.push('rendered nothing');
        if (page.exceptions.length) problems.push(`JS: ${page.exceptions[0].slice(0, 120)}`);
        if (r.objects) problems.push('rendered [object Object]');
        if (r.rawKeys) problems.push('rendered an untranslated key');

        if (problems.length) {
            failed++;
            console.log(`FAIL ${path}\n       ${problems.join('\n       ')}`);
        } else {
            console.log(`ok   ${path}  (text=${r.text} rows=${r.rows} "${r.title}")`);
        }
    }

    page.close();
    console.log(`\n${routes.length - failed}/${routes.length} pages rendered`);
    process.exit(failed ? 1 : 0);
}

main().catch((error) => {
    console.error('render-check harness error:', error.message);
    process.exit(2);
});
