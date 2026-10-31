'use strict';

const root = document.documentElement;
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const params = new URLSearchParams(window.location.search);
const store = {
    get(area, key) { try { return window[area].getItem(key); } catch { return null; } },
    set(area, key, value) { try { window[area].setItem(key, value); } catch { /* storage blocked */ } },
};

/* ---------- Planning fields keep the last search ---------- */
document.querySelectorAll('[data-planning-field]').forEach(field => {
    const value = params.get(field.name);
    if (value !== null) field.value = value;
});

/* ---------- Back buttons: prefer real history inside the app ---------- */
document.querySelectorAll('[data-back]').forEach(link => {
    link.addEventListener('click', event => {
        let sameOrigin = false;
        try { sameOrigin = document.referrer !== '' && new URL(document.referrer).origin === window.location.origin; } catch { sameOrigin = false; }
        if (sameOrigin && window.history.length > 1) { event.preventDefault(); window.history.back(); }
    });
});

/* ---------- Password visibility toggles ---------- */
document.querySelectorAll('[data-toggle-password]').forEach(button => {
    const input = document.getElementById(button.dataset.togglePassword);
    if (!input) return;
    button.hidden = false;
    button.addEventListener('click', () => {
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(show));
        button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
});

/* ---------- Launch animation ---------- */
const splash = document.getElementById('splash');
const splashDone = new Promise(resolve => {
    if (!splash || !root.classList.contains('show-splash')) { resolve(); return; }
    store.set('sessionStorage', 'png-splash-seen', '1');
    let finished = false;
    const finish = () => {
        if (finished) return; finished = true;
        root.classList.add('splash-leaving');
        window.setTimeout(() => { root.classList.remove('show-splash', 'splash-leaving'); resolve(); }, reduceMotion ? 0 : 480);
    };
    window.setTimeout(finish, reduceMotion ? 300 : 2600);
    splash.addEventListener('click', finish);
    document.addEventListener('keydown', finish, { once: true });
});

/* ---------- Onboarding tour ---------- */
const tour = document.getElementById('onboarding');
if (tour) {
    const track = tour.querySelector('[data-ob-track]');
    const slides = [...tour.querySelectorAll('[data-ob-slide]')];
    const dots = [...tour.querySelectorAll('[data-ob-dot]')];
    const next = tour.querySelector('[data-ob-next]');
    const login = tour.querySelector('[data-ob-login]');
    const shell = document.getElementById('app-shell');
    let index = 0; let opener = null;

    const update = value => {
        index = Math.max(0, Math.min(slides.length - 1, value));
        slides.forEach((slide, i) => {
            slide.classList.toggle('is-active', i === index);
            slide.setAttribute('aria-hidden', String(i !== index));
        });
        dots.forEach((dot, i) => { if (i === index) dot.setAttribute('aria-current', 'step'); else dot.removeAttribute('aria-current'); });
        const last = index === slides.length - 1;
        next.textContent = last ? 'Get started' : 'Next';
        if (login) login.hidden = !last;
    };
    const go = value => {
        const target = Math.max(0, Math.min(slides.length - 1, value));
        track.scrollTo({ left: target * track.clientWidth, behavior: reduceMotion ? 'auto' : 'smooth' });
        update(target);
    };
    const open = () => {
        opener = document.activeElement;
        tour.hidden = false;
        shell?.setAttribute('inert', '');
        document.body.classList.add('tour-open');
        track.scrollLeft = 0; update(0);
        requestAnimationFrame(() => { tour.classList.add('is-open'); track.focus({ preventScroll: true }); });
    };
    const close = () => {
        store.set('localStorage', 'png-onboarded', '1');
        tour.classList.remove('is-open');
        shell?.removeAttribute('inert');
        document.body.classList.remove('tour-open');
        window.setTimeout(() => { tour.hidden = true; }, reduceMotion ? 0 : 320);
        if (opener && typeof opener.focus === 'function') opener.focus({ preventScroll: true });
    };

    let scrollTimer = 0;
    track.addEventListener('scroll', () => {
        window.clearTimeout(scrollTimer);
        scrollTimer = window.setTimeout(() => update(Math.round(track.scrollLeft / Math.max(1, track.clientWidth))), 60);
    }, { passive: true });
    next.addEventListener('click', () => (index === slides.length - 1 ? close() : go(index + 1)));
    tour.querySelector('[data-ob-skip]').addEventListener('click', close);
    dots.forEach(dot => dot.addEventListener('click', () => go(Number(dot.dataset.obDot))));
    tour.addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); close(); }
        if (event.key === 'ArrowRight') { event.preventDefault(); go(index + 1); }
        if (event.key === 'ArrowLeft') { event.preventDefault(); go(index - 1); }
        if (event.key === 'Tab') {
            const focusables = [...tour.querySelectorAll('button:not([hidden]), a[href]:not([hidden])')];
            const first = focusables[0]; const last = focusables[focusables.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });
    window.addEventListener('resize', () => { track.scrollLeft = index * track.clientWidth; });
    document.querySelectorAll('[data-tour-open]').forEach(button => {
        button.hidden = false;
        button.addEventListener('click', event => { event.preventDefault(); open(); });
    });

    // Show automatically on first launch for guests and drivers, but never in the middle of a payment or recovery flow.
    const page = document.body.dataset.page;
    const role = document.body.dataset.role;
    const quietPages = ['payment-return', 'payment-refresh', 'payment-start', 'reset-password', 'ticket', 'reservation-checkout', 'entry-scan', 'exit-scan'];
    const firstLaunch = store.get('localStorage', 'png-onboarded') !== '1' && ['guest', 'driver'].includes(role) && !quietPages.includes(page);
    if (params.get('tour') === '1' || firstLaunch) splashDone.then(open);
}

/* ---------- Floor chips submit straight away ---------- */
const mapForm = document.querySelector('#map-query');
document.querySelectorAll('#map-query input[name="floor_id"]').forEach(input => {
    input.addEventListener('change', () => { if (typeof mapForm.requestSubmit === 'function') mapForm.requestSubmit(); else mapForm.submit(); });
});

/* ---------- Live parking map (database availability, refreshed while visible) ---------- */
const map = document.querySelector('#parking-data');
if (map) {
    const form = mapForm;
    const feedback = document.querySelector('#map-feedback');
    const refresh = document.querySelector('#refresh-map');
    const rows = document.querySelector('#map-rows');
    const details = document.querySelector('#selection-details');
    const panel = document.querySelector('.selection-panel');
    let selectedId = document.querySelector('.slot.selected')?.dataset.slotId ?? null;
    let data = null;
    let applied = new URLSearchParams(new FormData(form));
    let controller = null;
    let sequence = 0;
    refresh.hidden = false;
    const money = value => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value);

    const clear = () => {
        selectedId = null;
        document.querySelectorAll('.slot.selected').forEach(slot => {
            slot.classList.remove('selected'); slot.setAttribute('aria-pressed', 'false');
        });
        document.querySelector('#selection-empty').hidden = false;
        details.hidden = true;
        panel?.classList.remove('has-selection');
    };
    const select = (slot, button) => {
        clear(); selectedId = String(slot.id);
        button.classList.add('selected'); button.setAttribute('aria-pressed', 'true');
        document.querySelector('#selection-empty').hidden = true; details.hidden = false;
        panel?.classList.add('has-selection');
        document.querySelector('#selected-slot').textContent = slot.label;
        document.querySelector('#selected-floor').textContent = data.floor.label;
        document.querySelector('#selected-location').textContent = data.facility.name;
        document.querySelector('#selected-rate').textContent = data.rate ? `${money(data.rate.initial_centavos / 100)} / ${data.rate.initial_minutes} min` : 'Not configured';
        const review = document.querySelector('#review-reservation');
        if (review) {
            const query = new URLSearchParams(applied); query.set('page', 'reservation-review'); query.set('slot_id', String(slot.id));
            query.delete('facility_id'); query.delete('floor_id');
            review.href = `index.php?${query}`; review.hidden = !data.rate;
        }
    };
    const render = () => {
        const previous = selectedId; clear(); rows.replaceChildren();
        const grouped = new Map();
        const columns = Math.max(1, ...data.slots.map(slot => Math.min(12, slot.position_x + 1)));
        data.slots.forEach(slot => {
            if (!grouped.has(slot.position_y)) grouped.set(slot.position_y, []);
            grouped.get(slot.position_y).push(slot);
        });
        let first = true;
        [...grouped.entries()].sort((a, b) => a[0] - b[0]).forEach(([, slots]) => {
            if (!first) {
                const lane = document.createElement('div'); lane.className = 'drive-lane';
                const label = document.createElement('span'); label.textContent = 'DRIVE LANE · ONE WAY';
                const arrowA = document.createElement('i'); const arrowB = document.createElement('i');
                arrowA.className = arrowB.className = 'lane-arrow'; arrowA.setAttribute('aria-hidden', 'true'); arrowB.setAttribute('aria-hidden', 'true');
                lane.append(arrowA, label, arrowB); rows.append(lane);
            }
            first = false;
            const row = document.createElement('div'); row.className = `slot-row cols-${columns}`;
            for (let column = 0; column < columns; column++) {
                const slot = slots.find(item => item.position_x === column);
                if (!slot) { const gap = document.createElement('span'); gap.className = 'slot-gap'; gap.setAttribute('aria-hidden', 'true'); row.append(gap); continue; }
                const button = document.createElement('button'); button.type = 'button';
                button.className = `slot ${slot.status}`; button.dataset.slotId = String(slot.id);
                button.disabled = slot.status !== 'available';
                button.setAttribute('aria-label', `${slot.label}, ${slot.status}`);
                if (!button.disabled) button.setAttribute('aria-pressed', 'false');
                const icon = document.createElement('span'); icon.className = slot.status === 'occupied' ? 'top-car' : 'slot-symbol';
                icon.setAttribute('aria-hidden', 'true'); icon.textContent = slot.status === 'occupied' ? '' : slot.status === 'available' ? 'P' : '−';
                const name = document.createElement('span'); name.className = 'slot-label'; name.textContent = slot.label;
                button.append(icon, name); row.append(button);
                button.addEventListener('click', () => select(slot, button));
                if (String(slot.id) === previous && slot.status === 'available') select(slot, button);
            }
            rows.append(row);
        });
        if (!data.slots.length) { const message = document.createElement('p'); message.textContent = 'No slots have been added to this floor.'; rows.append(message); }
        const open = document.querySelector('#map-open-count');
        if (open) open.textContent = `${data.counts.available} open`;
        if (previous && !selectedId) feedback.textContent += ' Your selected space is no longer available; choose another.';
    };
    const load = async () => {
        controller?.abort(); controller = new AbortController();
        const thisRequest = ++sequence;
        refresh.disabled = true; refresh.classList.add('is-loading'); map.setAttribute('aria-busy', 'true');
        document.querySelectorAll('.slot').forEach(slot => { slot.disabled = true; });
        feedback.textContent = 'Checking availability…'; feedback.classList.remove('error-text');
        const query = new URLSearchParams(applied); query.delete('page'); query.set('resource', 'availability');
        try {
            const response = await fetch(`api.php?${query}`, { signal: controller.signal, headers: { 'Accept': 'application/json' }, cache: 'no-store' });
            const body = await response.json();
            if (!response.ok) throw new Error(body.error || 'Availability could not be loaded.');
            if (thisRequest !== sequence) return;
            data = body.data;
            feedback.textContent = `${data.counts.available} of ${data.slots.length} spaces available. Checked ${new Date(data.checked_at).toLocaleTimeString('en-PH', { timeZone: 'Asia/Manila', hour: 'numeric', minute: '2-digit' })} Manila time.`;
            if (data.layout_note) feedback.textContent += ` ${data.layout_note}`;
            document.querySelector('#map-window').textContent = data.window.label;
            render();
        } catch (error) {
            if (error.name === 'AbortError' || thisRequest !== sequence) return;
            clear(); feedback.classList.add('error-text');
            feedback.textContent = error instanceof TypeError ? 'Connection interrupted. Use Refresh to try again.' : error.message;
        } finally {
            if (thisRequest === sequence) { refresh.disabled = false; refresh.classList.remove('is-loading'); map.setAttribute('aria-busy', 'false'); }
        }
    };
    form.addEventListener('submit', event => {
        // Existing server-rendered slot/clear controls remain usable before the first response.
        if (event.submitter?.name === 'slot_id') return;
        event.preventDefault(); clear(); applied = new URLSearchParams(new FormData(form));
        history.replaceState(null, '', `index.php?${applied}`);
        load();
    });
    document.querySelector('#clear-selection').addEventListener('click', event => { event.preventDefault(); clear(); });
    refresh.addEventListener('click', load);
    setInterval(() => { if (!document.hidden) load(); }, 45000);
    if (document.querySelector('.slot.selected')) panel?.classList.add('has-selection');
    load();
}

/* ---------- Hold countdown + progress ring ---------- */
document.querySelectorAll('[data-hold-expires]').forEach(clock => {
    const expires = Date.parse(clock.dataset.holdExpires);
    const server = Date.parse(clock.dataset.serverNow);
    const total = Number(clock.dataset.holdTotal) || 600;
    const started = performance.now();
    const ring = document.querySelector('[data-hold-ring]');
    const ringTime = document.querySelector('[data-hold-ring-time]');
    if (!Number.isFinite(expires) || !Number.isFinite(server)) return;
    const tick = () => {
        const seconds = Math.max(0, Math.ceil((expires - server - (performance.now() - started)) / 1000));
        const label = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
        clock.textContent = seconds > 0 ? `Hold time remaining: ${label}` : 'Hold time elapsed. Refresh status to confirm with the server.';
        if (ringTime) ringTime.textContent = seconds > 0 ? label : '0:00';
        if (ring) ring.style.setProperty('--progress', String(Math.min(1, seconds / total)));
        if (!seconds) clearInterval(timer);
    };
    const timer = setInterval(tick, 1000); tick();
});

/* ---------- Admin live views: periodic reload while visible and idle ---------- */
document.querySelectorAll('[data-auto-refresh]').forEach(section => {
    const seconds = Math.max(30, Number(section.dataset.autoRefresh) || 60);
    setInterval(() => {
        const editing = document.activeElement && document.activeElement.matches('input, select, textarea');
        if (document.visibilityState === 'visible' && !editing) window.location.reload();
    }, seconds * 1000);
});

/* ---------- Installable app: cache static assets only ---------- */
if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => { navigator.serviceWorker.register('sw.js').catch(() => { /* optional */ }); });
}
