'use strict';

const planningQuery = new URLSearchParams(window.location.search);
document.querySelectorAll('[data-planning-field]').forEach(field => {
    const value = planningQuery.get(field.name);
    if (value !== null) field.value = value;
});
const menu = document.querySelector('.menu-toggle');
const navigation = document.querySelector('#navigation');
menu?.addEventListener('click', () => {
    const open = menu.getAttribute('aria-expanded') !== 'true';
    menu.setAttribute('aria-expanded', String(open));
    navigation.classList.toggle('is-open', open);
});
document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && menu?.getAttribute('aria-expanded') === 'true') {
        menu.setAttribute('aria-expanded', 'false');
        navigation.classList.remove('is-open');
        menu.focus();
    }
});

const map = document.querySelector('#parking-data');
if (map) {
    const form = document.querySelector('#map-query');
    const feedback = document.querySelector('#map-feedback');
    const refresh = document.querySelector('#refresh-map');
    const rows = document.querySelector('#map-rows');
    const details = document.querySelector('#selection-details');
    let selectedId = document.querySelector('.slot.selected')?.dataset.slotId ?? null;
    let data = null;
    let applied = new URLSearchParams(new FormData(form));
    let controller = null;
    let sequence = 0;
    refresh.hidden = false;

    const clear = () => {
        selectedId = null;
        document.querySelectorAll('.slot.selected').forEach(slot => {
            slot.classList.remove('selected'); slot.setAttribute('aria-pressed', 'false');
        });
        document.querySelector('#selection-empty').hidden = false;
        details.hidden = true;
    };
    const select = (slot, button) => {
        clear(); selectedId = String(slot.id);
        button.classList.add('selected'); button.setAttribute('aria-pressed', 'true');
        document.querySelector('#selection-empty').hidden = true; details.hidden = false;
        document.querySelector('#selected-slot').textContent = slot.label;
        document.querySelector('#selected-floor').textContent = data.floor.label;
        document.querySelector('#selected-location').textContent = data.facility.name;
        document.querySelector('#selected-rate').textContent = data.rate ? `${new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP'}).format(data.rate.initial_centavos / 100)} / ${data.rate.initial_minutes} min` : 'Not configured';
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
                const direction = document.createElement('span'); direction.textContent = 'DRIVE LANE · ONE WAY';
                lane.append('→', direction, '→'); rows.append(lane);
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
                const name = document.createElement('span'); name.textContent = slot.label;
                button.append(icon, name); row.append(button);
                button.addEventListener('click', () => select(slot, button));
                if (String(slot.id) === previous && slot.status === 'available') select(slot, button);
            }
            rows.append(row);
        });
        if (!data.slots.length) { const message = document.createElement('p'); message.textContent = 'No slots have been added to this floor.'; rows.append(message); }
        if (previous && !selectedId) feedback.textContent += ' Your selected space is no longer available; choose another.';
    };
    const load = async () => {
        controller?.abort(); controller = new AbortController();
        const thisRequest = ++sequence;
        refresh.disabled = true; map.setAttribute('aria-busy', 'true');
        document.querySelectorAll('.slot').forEach(slot => { slot.disabled = true; });
        feedback.textContent = 'Checking availability…'; feedback.classList.remove('error-text');
        const query = new URLSearchParams(applied); query.delete('page'); query.set('resource', 'availability');
        try {
            const response = await fetch(`api.php?${query}`, {signal: controller.signal, headers: {'Accept': 'application/json'}, cache: 'no-store'});
            const body = await response.json();
            if (!response.ok) throw new Error(body.error || 'Availability could not be loaded.');
            if (thisRequest !== sequence) return;
            data = body.data;
            feedback.textContent = `${data.counts.available} of ${data.slots.length} spaces available. Checked ${new Date(data.checked_at).toLocaleTimeString('en-PH', {timeZone: 'Asia/Manila'})} Manila time.`;
            if (data.layout_note) feedback.textContent += ` ${data.layout_note}`;
            document.querySelector('#map-window').textContent = data.window.label;
            render();
        } catch (error) {
            if (error.name === 'AbortError' || thisRequest !== sequence) return;
            clear(); feedback.classList.add('error-text');
            feedback.textContent = error instanceof TypeError ? 'Connection interrupted. Use Refresh availability to try again.' : error.message;
        } finally {
            if (thisRequest === sequence) { refresh.disabled = false; map.setAttribute('aria-busy', 'false'); }
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
    load();
}

document.querySelectorAll('[data-hold-expires]').forEach(clock => {
    const expires = Date.parse(clock.dataset.holdExpires);
    const server = Date.parse(clock.dataset.serverNow);
    const started = performance.now();
    if (!Number.isFinite(expires) || !Number.isFinite(server)) return;
    const tick = () => {
        const seconds = Math.max(0, Math.ceil((expires - server - (performance.now() - started)) / 1000));
        clock.textContent = seconds > 0 ? `Hold time remaining: ${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}` : 'Hold time elapsed. Refresh status to confirm with the server.';
        if (!seconds) clearInterval(timer);
    };
    const timer = setInterval(tick, 1000); tick();
});
