// Phase 7 admin workflows in headless Chrome via the DevTools protocol (no extra dependencies).
// Run through the harness so the browser, server, and fixtures share one isolated database:
//   php tests/run-phase7.php p7_browser
import { spawn, execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const base = (process.env.PARKNGO_TEST_BASE_URL || 'http://127.0.0.1:8091') + '/index.php';
const php = process.env.PHP_BINARY || 'C:\\xampp\\php\\php.exe';
const evidence = process.env.FAKE_MAYA_STATE ? join(process.env.FAKE_MAYA_STATE, '..') : tmpdir();
const chromePath = ['C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'].find(existsSync);
if (!/_test$/.test(process.env.DB_DATABASE || '')) { console.error('Use an isolated *_test database.'); process.exit(1); }
if (!chromePath) { console.error('Chrome or Edge is required for browser checks.'); process.exit(1); }

let checks = 0;
const check = (condition, label) => { if (!condition) throw new Error('FAIL ' + label); checks++; console.log('PASS ' + label); };
const fixture = (...args) => JSON.parse(execFileSync(php, ['tests/fixtures/phase7-browser-fixture.php', ...args.map(String)], { encoding: 'utf8' }));
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

const profile = mkdtempSync(join(tmpdir(), 'p7-chrome-'));
const port = 9334;
const chrome = spawn(chromePath, ['--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', '--disable-gpu', '--disable-extensions', 'about:blank'], { stdio: 'ignore' });

let socket; let nextId = 0; const pending = new Map(); const listeners = [];
function send(method, params = {}, sessionId) {
    const id = ++nextId;
    socket.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
    return new Promise((resolve, reject) => pending.set(id, { resolve, reject, method }));
}
function waitFor(method, sessionId, timeout = 15000) {
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('Timed out waiting for ' + method)), timeout);
        listeners.push({ method, sessionId, resolve: params => { clearTimeout(timer); resolve(params); } });
    });
}
async function connect() {
    for (let i = 0; i < 100; i++) {
        try { const version = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); socket = new WebSocket(version.webSocketDebuggerUrl); break; }
        catch { await sleep(100); }
    }
    if (!socket) throw new Error('Chrome DevTools endpoint did not start.');
    await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
    socket.onmessage = ({ data }) => {
        const message = JSON.parse(data);
        if (message.id && pending.has(message.id)) {
            const { resolve, reject, method } = pending.get(message.id); pending.delete(message.id);
            message.error ? reject(new Error(method + ': ' + message.error.message)) : resolve(message.result);
            return;
        }
        for (let i = listeners.length - 1; i >= 0; i--) {
            if (listeners[i].method === message.method && listeners[i].sessionId === message.sessionId) { listeners.splice(i, 1)[0].resolve(message.params); }
        }
    };
}
/** A tab in its own browser context (separate cookies per admin). */
async function tab(width = 390, height = 844, mobile = true) {
    const { browserContextId } = await send('Target.createBrowserContext');
    const { targetId } = await send('Target.createTarget', { url: 'about:blank', browserContextId });
    const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
    await send('Page.enable', {}, sessionId); await send('Runtime.enable', {}, sessionId); await send('DOM.enable', {}, sessionId);
    await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: mobile ? 2 : 1, mobile }, sessionId);
    // Skip the first-run onboarding overlay so it never covers controls.
    await send('Page.addScriptToEvaluateOnNewDocument', { source: "try { localStorage.setItem('png-onboarded', '1'); sessionStorage.setItem('png-splash-seen', '1'); } catch (e) {}" }, sessionId);
    return sessionId;
}
async function evaluate(sessionId, expression) {
    const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }, sessionId);
    if (result.exceptionDetails) throw new Error('Page script failed: ' + (result.exceptionDetails.exception?.description || result.exceptionDetails.text));
    return result.result.value;
}
async function navigate(sessionId, query) { const loaded = waitFor('Page.loadEventFired', sessionId); await send('Page.navigate', { url: `${base}?${query}` }, sessionId); await loaded; }
/** Run a page action that submits a form and wait for the next document load. */
async function submit(sessionId, expression) { const loaded = waitFor('Page.loadEventFired', sessionId); await evaluate(sessionId, expression); await loaded; }
const text = sessionId => evaluate(sessionId, 'document.body.innerText');
const noHorizontalScroll = sessionId => evaluate(sessionId, 'document.documentElement.scrollWidth <= window.innerWidth + 1');
const toast = sessionId => evaluate(sessionId, "document.querySelector('.toast')?.innerText || ''");
async function screenshot(sessionId, name) {
    const { data } = await send('Page.captureScreenshot', { format: 'png' }, sessionId);
    writeFileSync(join(evidence, name), Buffer.from(data, 'base64'));
}
async function login(sessionId, user) {
    await navigate(sessionId, 'page=login');
    await submit(sessionId, `document.querySelector('#email').value=${JSON.stringify(user.email)}; document.querySelector('#password').value=${JSON.stringify(user.password)}; document.querySelector('#email').form.requestSubmit();`);
}

try {
    await connect();
    const data = fixture('setup');

    // --- Mobile admin dashboard: live, database-derived metrics in the existing theme ---
    const phone = await tab();
    await login(phone, data.admin);
    check((await evaluate(phone, 'location.search')).includes('page=admin-dashboard'), 'admin login lands on the administrator dashboard');
    const metrics = await evaluate(phone, `Object.fromEntries([...document.querySelectorAll('.metric')].map(m => [m.querySelector('.metric-label').innerText, Number(m.querySelector('.metric-value').innerText.replace(/,/g, ''))]))`);
    check(metrics['Active sessions'] === data.activeSessions && metrics['Overdue sessions'] === 1 && metrics['Facilities'] >= 1, 'dashboard tiles show database-derived session counts');
    check(await noHorizontalScroll(phone), 'dashboard fits a 390px phone viewport without horizontal scrolling');
    const theme = await evaluate(phone, `({ primary: getComputedStyle(document.documentElement).getPropertyValue('--primary').trim(), tile: getComputedStyle(document.querySelector('.metric')).backgroundColor, tab: !!document.querySelector('.tabbar a[aria-current]') })`);
    check(theme.primary.toUpperCase() === '#8B9A6E' && theme.tile === 'rgb(234, 226, 214)' && theme.tab, 'dashboard keeps the sage palette, surface tiles, and bottom navigation');
    await screenshot(phone, 'p7-dashboard-mobile.png');

    // --- Desktop: operations pages may use more width; existing pages keep the phone shell ---
    const desk = await tab(1280, 900, false);
    await login(desk, data.admin);
    const layout = await evaluate(desk, `({ wide: document.body.classList.contains('admin-wide'), shell: document.querySelector('#app-shell').getBoundingClientRect().width, columns: getComputedStyle(document.querySelector('.metric-grid')).gridTemplateColumns.split(' ').length })`);
    check(layout.wide && layout.shell > 900 && layout.columns === 4, 'desktop dashboard widens the shell and shows four metric columns');
    await screenshot(desk, 'p7-dashboard-desktop.png');
    await navigate(desk, 'page=admin-facilities');
    check(!(await evaluate(desk, "document.body.classList.contains('admin-wide')")) && (await evaluate(desk, "document.querySelector('#app-shell').getBoundingClientRect().width")) <= 480, 'existing inventory pages keep the phone-width layout');

    // --- Occupancy correction through the UI ---
    await navigate(phone, `page=admin-occupancy&facility_id=${data.facility}`);
    check(await noHorizontalScroll(phone), 'occupancy page fits the phone viewport (tables scroll inside their container)');
    await evaluate(phone, `document.querySelector('#slot-${data.idleSlot} details').open = true;`);
    await submit(phone, `(() => { const row = document.querySelector('#slot-${data.idleSlot}'); row.querySelector('select[name="action"]').value = 'mark_maintenance'; row.querySelector('input[name="reason"]').value = 'Broken bay light reported by guard'; row.querySelector('form').requestSubmit(); })()`);
    check((await toast(phone)).includes('Occupancy correction recorded') && fixture('slot', data.idleSlot).status === 'maintenance', 'a slot correction submitted in the browser is applied and confirmed');
    await navigate(phone, `page=admin-occupancy&facility_id=${data.facility}`);
    check((await evaluate(phone, `document.querySelector('#slot-${data.idleSlot} .status-pill').innerText`)) === 'Maintenance', 'the slot now shows its derived maintenance state');

    // --- Active-session exception: preview, explicit confirmation, close ---
    await navigate(phone, `page=admin-session-exception&session_id=${data.overdueSession}`);
    await submit(phone, `document.querySelector('#exception-facility').value='${data.facility}'; document.querySelector('#exception-category').value='kiosk_unavailable'; document.querySelector('#exception-reason').value='Exit kiosk offline; driver escorted out by the attendant'; document.querySelector('#exception-reason').form.requestSubmit();`);
    const confirmText = await text(phone);
    check(confirmText.includes('Confirm the exception') && confirmText.includes('unsettled obligation') && fixture('session', data.overdueSession).status === 'active', 'the preview shows the unpaid overtime that will remain and changes nothing');
    await submit(phone, `document.querySelector('input[name="confirm"]').click(); document.querySelector('input[name="confirm"]').form.requestSubmit();`);
    const closed = fixture('session', data.overdueSession);
    check((await toast(phone)).includes('remains recorded for reconciliation') && closed.status === 'completed' && closed.obligation === 'unsettled', 'confirming closes the session and keeps the overtime obligation');

    // --- Refund: request, approve, record the Maya refund, confirm from provider evidence ---
    await navigate(phone, `page=admin-refunds&payment=${data.payment}`);
    await submit(phone, `document.querySelector('#refund-amount').value='15.00'; document.querySelector('#refund-reason').value='Barrier fault charged the driver twice'; document.querySelector('#refund-reason').form.requestSubmit();`);
    let refund = fixture('refund', data.payment);
    check((await toast(phone)).includes('no money has been returned') && refund.status === 'requested' && refund.amount_centavos === 1500, 'a refund request is created from the browser');
    await submit(phone, `(() => { const form = [...document.querySelectorAll('form')].find(f => f.querySelector('select[name="action"] option[value="approve"]')); form.querySelector('input[name="reason"]').value = 'CCTV confirms the double charge'; form.querySelector('select[name="action"]').value = 'approve'; form.requestSubmit(); })()`);
    check((await toast(phone)).includes('not complete until Maya confirms') && fixture('refund', data.payment).status === 'approved', 'approval says the refund is not complete until Maya confirms it');
    const providerId = 'rfd-browser-' + Date.now();
    await submit(phone, `(() => { const input = document.querySelector('input[name="provider_refund_id"]'); input.value = '${providerId}'; input.form.requestSubmit(); })()`);
    fixture('provider-refund', data.checkout, providerId, 1500, 'SUCCESS');
    await submit(phone, `[...document.querySelectorAll('button')].find(b => b.innerText.includes('Check with Maya')).closest('form').requestSubmit()`);
    refund = fixture('refund', data.payment);
    check((await toast(phone)).includes('Maya confirms the refund') && refund.status === 'provider_confirmed', 'the refund is confirmed only after Maya reports it complete');
    await navigate(phone, 'page=admin-refunds&status=all');
    check((await text(phone)).includes('Refund confirmed by Maya'), 'the refund list shows the provider-confirmed state');

    // --- Pricing change from the form ---
    await navigate(phone, `page=admin-pricing&facility_id=${data.facility}`);
    await submit(phone, `document.querySelector('#rate-initial_centavos').value='65.00'; document.querySelector('#rate-succeeding_hour_centavos').value='27.50'; document.querySelector('#rate-overtime_hour_centavos').value='30.00'; document.querySelector('#rate-reason').value='Browser test rate review'; document.querySelector('#rate-reason').form.requestSubmit();`);
    const rate = fixture('rate', data.facility);
    check((await toast(phone)).includes('keep their original rates') && rate.initial_centavos === 6500 && rate.succeeding_hour_centavos === 2750 && rate.id !== data.rate && rate.created_by === data.admin.id, 'a pricing change from the form creates a new rate version');

    // --- Reports: same numbers on screen and in the browser-fetched exports ---
    await navigate(phone, `page=admin-reports&type=revenue&facility_id=${data.facility}`);
    const gross = await evaluate(phone, `[...document.querySelectorAll('.metric')].find(m => m.innerText.includes('Gross verified receipts')).querySelector('.metric-value').innerText`);
    const csv = await evaluate(phone, `fetch(document.querySelector('a[href*="format=csv"]').href).then(async r => ({ type: r.headers.get('content-type'), body: await r.text() }))`);
    check(csv.type.startsWith('text/csv') && csv.body.includes('"Gross verified receipts",' + gross.replace(/[₱,]/g, '')), 'the browser CSV export matches the on-screen gross receipts');
    const pdf = await evaluate(phone, `fetch(document.querySelector('a[href*="format=pdf"]').href).then(async r => ({ type: r.headers.get('content-type'), head: (await r.text()).slice(0, 8) }))`);
    check(pdf.type === 'application/pdf' && pdf.head === '%PDF-1.4', 'the browser PDF export link returns a PDF');
    check(await noHorizontalScroll(phone), 'the report page fits the phone viewport');
    await screenshot(phone, 'p7-reports-mobile.png');

    // --- An admin without sensitive permissions sees monitoring, not sensitive controls ---
    const plain = await tab();
    await login(plain, data.plain);
    await navigate(plain, `page=admin-pricing&facility_id=${data.facility}`);
    check(!(await evaluate(plain, "!!document.querySelector('#rate-reason')")) && (await text(plain)).includes('needs the Manage pricing permission'), 'pricing controls are hidden without manage_pricing');
    await navigate(plain, 'page=admin-refunds&status=all');
    check((await text(plain)).includes('Refund approval permission required'), 'refund decisions are hidden without approve_refunds');
    await navigate(plain, `page=admin-session-exception&session_id=${data.overdueSession}`);
    check((await text(plain)).includes('no longer active'), 'the closed session cannot be closed again');

    console.log(`\n${checks} Phase 7 browser checks passed. Screenshots: ${evidence}`);
} finally {
    try { socket?.close(); } catch { /* already closed */ }
    if (chrome.exitCode === null) chrome.kill();
    try { rmSync(profile, { recursive: true, force: true }); } catch { /* Chrome may still hold files briefly */ }
}
