// Phase 6 real-browser checks in headless Chrome via the DevTools protocol (no extra dependencies).
// Run through the harness so the browser, server, and fixtures share one isolated database:
//   php tests/run-phase6.php browser
import { spawn, execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const base = (process.env.PARKNGO_TEST_BASE_URL || 'http://127.0.0.1:8091') + '/index.php';
const php = process.env.PHP_BINARY || 'C:\\xampp\\php\\php.exe';
const chromePath = ['C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'].find(existsSync);
if (!/_test$/.test(process.env.DB_DATABASE || '')) { console.error('Use an isolated *_test database.'); process.exit(1); }
if (!chromePath) { console.error('Chrome or Edge is required for browser checks.'); process.exit(1); }

let checks = 0;
const check = (condition, label) => { if (!condition) throw new Error('FAIL ' + label); checks++; console.log('PASS ' + label); };
const fixture = (...args) => JSON.parse(execFileSync(php, ['tests/fixtures/phase6-browser-fixture.php', ...args.map(String)], { encoding: 'utf8' }));
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

const profile = mkdtempSync(join(tmpdir(), 'p6-chrome-'));
const port = 9333;
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
/** A mobile-sized tab in its own browser context (separate cookies: driver vs kiosk). */
async function tab() {
    const { browserContextId } = await send('Target.createBrowserContext');
    const { targetId } = await send('Target.createTarget', { url: 'about:blank', browserContextId });
    const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
    await send('Page.enable', {}, sessionId); await send('Runtime.enable', {}, sessionId); await send('DOM.enable', {}, sessionId);
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true }, sessionId);
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

try {
    await connect();
    const data = fixture('setup');

    // --- Driver: mobile dashboard extension controls, Maya hand-off, server verification ---
    const driver = await tab();
    await navigate(driver, 'page=login');
    await submit(driver, `document.querySelector('#email').value=${JSON.stringify(data.driver.email)}; document.querySelector('#password').value=${JSON.stringify(data.driver.password)}; document.querySelector('#email').form.requestSubmit();`);
    await navigate(driver, 'page=dashboard');
    const controls = await evaluate(driver, `(() => { const card = document.querySelector('#active-session'); const boxes = [...document.querySelectorAll('input[name="blocks[]"]')]; const button = [...document.querySelectorAll('button')].find(b => b.textContent.includes('Pay extension')); const r = button?.getBoundingClientRect(); return { card: !!card, values: boxes.map(b => b.value), visible: !!r && r.width > 0 && r.height >= 40 && r.right <= innerWidth }; })()`);
    check(controls.card && controls.values.join(',') === '1,2,3' && controls.visible, 'mobile dashboard renders the active session with 1/2/3-hour blocks and a tappable Pay extension button');
    check(await noHorizontalScroll(driver), 'dashboard fits a 390px phone viewport without horizontal scrolling');
    await evaluate(driver, `document.querySelector('input[name="blocks[]"][value="1"]').click(); document.querySelector('input[name="blocks[]"][value="2"]').click();`);
    check((await evaluate(driver, `[...document.querySelectorAll('input[name="blocks[]"]:checked')].map(b => b.value).join('+')`)) === '1+2', 'driver selects a 1+2 hour combination with real clicks');
    await evaluate(driver, `[...document.querySelectorAll('button')].find(b => b.textContent.includes('Pay extension')).click()`);
    await sleep(2500);
    const attempt = fixture('latest', data.extendSession);
    check(attempt.type === 'PARKING_EXTENSION' && attempt.duration === 180 && attempt.amount === 6000 && attempt.status === 'checkout_created', 'browser submission creates one 3-hour extension checkout priced at 60.00 server-side');
    check((await evaluate(driver, 'location.href')).includes('fake-maya.invalid') || (await evaluate(driver, 'location.href')).startsWith('chrome-error'), 'browser is handed off to the Maya checkout URL');
    await navigate(driver, `page=payment-return&reference=${attempt.reference}`);
    check((await text(driver)).includes('awaiting independent server verification'), 'returning before Maya reports success shows a pending status, not success');
    fixture('provider', attempt.checkout, 'PAYMENT_SUCCESS');
    await submit(driver, `[...document.querySelectorAll('button')].find(b => b.textContent.includes('Refresh payment status')).click()`);
    check((await text(driver)).includes('Your parking session has been extended'), 'Refresh payment status verifies server-side and reports the extension');
    check(fixture('session', data.extendSession).paid_until > data.paidUntil, 'paid-until moved forward only after verification');
    await navigate(driver, 'page=dashboard');
    check((await text(driver)).includes('within its paid period'), 'dashboard reflects the verified extension');

    // --- Service worker never caches pages, payment, or ticket responses ---
    await navigate(driver, 'page=dashboard'); await sleep(800); await navigate(driver, 'page=dashboard'); await sleep(800);
    const cached = await evaluate(driver, `(async () => { const urls = []; for (const key of await caches.keys()) { const cache = await caches.open(key); for (const request of await cache.keys()) urls.push(request.url); } return { controlled: !!navigator.serviceWorker?.controller, urls }; })()`);
    check(cached.urls.every(url => new URL(url).pathname.includes('/assets/')) && !cached.urls.some(url => /page=|payment|ticket|kiosk|api\.php/.test(url)), `service worker cache holds only static assets (${cached.urls.length} cached, controlled=${cached.controlled})`);
    const noStore = await evaluate(driver, `fetch('index.php?page=dashboard').then(r => r.headers.get('cache-control'))`);
    check(noStore === 'no-store', 'authenticated pages are served with Cache-Control: no-store');

    // --- Kiosk: activation, local QR decoding, Confirm Exit, manual entry, invalid image ---
    const kiosk = await tab();
    await navigate(kiosk, 'page=kiosk-activate');
    await submit(kiosk, `document.querySelector('#activation-code').value=${JSON.stringify(data.kioskCode)}; document.querySelector('#activation-code').form.requestSubmit();`);
    check((await text(kiosk)).includes('Browser Gate'), 'admin-issued activation code activates the kiosk in the browser');
    check(await noHorizontalScroll(kiosk), 'kiosk page fits a phone-width viewport');
    const { root } = await send('DOM.getDocument', {}, kiosk);
    const { nodeId } = await send('DOM.querySelector', { nodeId: root.nodeId, selector: '#kiosk-qr-image' }, kiosk);
    check((await evaluate(kiosk, `document.querySelector('#kiosk-qr-image').name`)) === '', 'the QR image input has no name, so the image itself is never uploaded');
    await send('DOM.setFileInputFiles', { nodeId, files: [data.invalidPng] }, kiosk);
    for (let i = 0; i < 50 && !(await evaluate(kiosk, `document.querySelector('#kiosk-qr-status').textContent`)).includes('No readable'); i++) await sleep(100);
    check((await evaluate(kiosk, `document.querySelector('#kiosk-code').value`)) === '' && (await evaluate(kiosk, `document.querySelector('#kiosk-qr-status').textContent`)).includes('No readable QR'), 'an image without a QR code is rejected locally');
    await send('DOM.setFileInputFiles', { nodeId, files: [data.qrPng] }, kiosk);
    for (let i = 0; i < 50 && !(await evaluate(kiosk, `document.querySelector('#kiosk-qr-status').textContent`)).includes('QR ticket decoded locally'); i++) await sleep(100);
    check((await evaluate(kiosk, `document.querySelector('#kiosk-code').value`)) === data.qrToken, 'QR screenshot is decoded in the browser to the exact ticket token');
    await submit(kiosk, `document.querySelector('#kiosk-ticket-form').requestSubmit()`);
    const verified = await text(kiosk);
    check(verified.includes('Ticket verified') && (await evaluate(kiosk, `!![...document.querySelectorAll('button')].find(b => b.textContent.includes('Confirm Exit'))`)), 'server verifies the decoded ticket and shows a separate Confirm Exit step');
    check(fixture('session', data.qrSession).status === 'active', 'verification alone does not close the session');
    await submit(kiosk, `[...document.querySelectorAll('button')].find(b => b.textContent.includes('Confirm Exit')).click()`);
    check((await text(kiosk)).includes('Exit confirmed') && fixture('session', data.qrSession).status === 'completed', 'Confirm Exit closes the QR-uploaded session');
    await navigate(kiosk, 'page=kiosk-home');
    await evaluate(kiosk, `(() => { const input = document.querySelector('#kiosk-manual-code'); input.value = ${JSON.stringify(data.manualCode)}; input.dispatchEvent(new Event('input', { bubbles: true })); })()`);
    check((await evaluate(kiosk, `document.querySelector('#kiosk-qr-status').textContent`)).includes('Manual code entered'), 'manual ticket-code entry is acknowledged');
    await submit(kiosk, `document.querySelector('#kiosk-ticket-form').requestSubmit()`);
    await submit(kiosk, `[...document.querySelectorAll('button')].find(b => b.textContent.includes('Confirm Exit')).click()`);
    check((await text(kiosk)).includes('Exit confirmed') && fixture('session', data.manualSession).status === 'completed', 'manual code verification and Confirm Exit close the session');
    await navigate(kiosk, 'page=kiosk-home');
    await evaluate(kiosk, `document.querySelector('#kiosk-ticket-form').requestSubmit()`);
    check((await evaluate(kiosk, `document.querySelector('#kiosk-qr-status').textContent`)).includes('Upload a QR image or enter'), 'submitting with no QR or code is blocked in the browser');
    await navigate(kiosk, 'page=dashboard');
    check((await text(kiosk)).includes('Access denied'), 'the kiosk browser cannot open the driver dashboard');

    console.log(`\n${checks} Phase 6 browser checks passed.`);
    process.exitCode = 0;
} catch (error) {
    console.error(error.message); process.exitCode = 1;
} finally {
    try { await send('Browser.close'); } catch { /* already closed */ }
    await sleep(500);
    if (chrome.exitCode === null) chrome.kill();
    try { rmSync(profile, { recursive: true, force: true }); } catch { /* Chrome may still hold files briefly */ }
}
