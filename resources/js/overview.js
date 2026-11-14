/*
 * Overview page behaviour (R-51). No framework, no inline script: this file is bundled with the app.
 *
 * The queue and its detail pane live in server-rendered markup that Livewire re-renders every 30 s. Three things must
 * survive that refresh, so they are held here, outside the markup, and re-applied after every morph:
 *   1. which row is selected (and the All / Exceptions / Alerts filter),
 *   2. the text someone is half-way through typing in a detail form,
 *   3. keyboard focus.
 * Polling is also paused while a field in the detail pane has focus or text in it (the poll sentinel is hidden: Livewire
 * skips a `.visible` poll whose element has no box).
 */
const state = { sel: null, filter: 'all' };

const root = () => document.querySelector('[data-ov-root]');
const rows = () => Array.from(document.querySelectorAll('[data-ov-row]'));
const visibleRows = () => rows().filter((r) => !r.hidden);
const fieldsWithText = () => Array.from(document.querySelectorAll('[data-ov-detail] input:not([type=hidden]):not([type=checkbox]), [data-ov-detail] select, [data-ov-detail] textarea'))
    .filter((f) => (f.tagName === 'SELECT' ? f === document.activeElement : f.value !== ''));

function holdPolling() {
    const sentinel = document.querySelector('[data-ov-poll]');
    if (!sentinel) return;
    const typing = document.activeElement && document.activeElement.closest && document.activeElement.closest('[data-ov-detail]') && /^(INPUT|SELECT|TEXTAREA)$/.test(document.activeElement.tagName);
    sentinel.hidden = Boolean(typing || fieldsWithText().length > 0);
}

function apply({ swap = false, scroll = false } = {}) {
    const all = rows();
    if (all.length === 0) return;
    if (!state.sel || !all.some((r) => r.dataset.ovRow === state.sel)) {
        const first = all.find((r) => r.getAttribute('aria-selected') === 'true') || all[0];
        state.sel = first.dataset.ovRow;
    }
    all.forEach((r) => { r.hidden = state.filter !== 'all' && r.dataset.kind !== state.filter; });
    if (visibleRows().length > 0 && !visibleRows().some((r) => r.dataset.ovRow === state.sel)) state.sel = visibleRows()[0].dataset.ovRow;

    all.forEach((r) => {
        const on = r.dataset.ovRow === state.sel;
        r.setAttribute('aria-selected', on ? 'true' : 'false');
        r.tabIndex = on ? 0 : -1;
    });
    document.querySelectorAll('[data-ov-filter]').forEach((b) => b.setAttribute('aria-pressed', b.dataset.ovFilter === state.filter ? 'true' : 'false'));
    document.querySelectorAll('[data-ov-detail]').forEach((d) => {
        const on = d.dataset.ovDetail === state.sel;
        if (d.hidden === on) {
            d.hidden = !on;
            if (on && swap) { d.classList.add('is-swapping'); d.addEventListener('animationend', () => d.classList.remove('is-swapping'), { once: true }); }
        }
    });
    if (scroll) {
        const d = document.querySelector('[data-ov-detail="' + state.sel + '"]');
        if (d && window.matchMedia('(max-width: 880px)').matches) d.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    }
    holdPolling();
}

function select(id, opts = {}) {
    state.sel = id;
    try {
        const url = new URL(window.location.href);
        url.searchParams.set('sel', id);
        window.history.replaceState(window.history.state, '', url);
    } catch (e) { /* the URL is a convenience, never a requirement */ }
    apply({ swap: true, scroll: opts.scroll });
}

document.addEventListener('click', (e) => {
    const t = e.target;
    if (!(t instanceof Element)) return;
    const filter = t.closest('[data-ov-filter]');
    if (filter) { state.filter = filter.dataset.ovFilter; apply({ swap: true }); return; }
    if (t.closest('[data-ov-back]')) {
        e.preventDefault();
        const row = document.querySelector('[data-ov-row="' + state.sel + '"]');
        if (row) { row.scrollIntoView({ block: 'center' }); row.focus({ preventScroll: true }); }
        return;
    }
    const row = t.closest('[data-ov-row]');
    if (row) select(row.dataset.ovRow, { scroll: true });
});

document.addEventListener('keydown', (e) => {
    const row = e.target instanceof Element ? e.target.closest('[data-ov-row]') : null;
    if (!row) return;
    const list = visibleRows();
    const i = list.indexOf(row);
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        const next = list[Math.max(0, Math.min(list.length - 1, i + (e.key === 'ArrowDown' ? 1 : -1)))];
        if (next) { select(next.dataset.ovRow); next.focus(); }
    } else if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        select(row.dataset.ovRow, { scroll: true });
        const d = document.querySelector('[data-ov-detail="' + row.dataset.ovRow + '"]');
        const first = d && d.querySelector('input:not([type=hidden]), select, button');
        if (first) first.focus();
    }
});

['focusin', 'input', 'change'].forEach((ev) => document.addEventListener(ev, (e) => { if (e.target instanceof Element && e.target.closest('[data-ov-detail]')) holdPolling(); }));
document.addEventListener('focusout', () => setTimeout(holdPolling, 0));

function arrive() {
    const r = root();
    if (!r) return;
    apply();
    r.classList.add('is-arriving');
    window.setTimeout(() => r.classList.remove('is-arriving'), 900);
}

document.addEventListener('DOMContentLoaded', arrive);
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('commit', ({ component, succeed }) => {
        // Remember focus and typed text, let Livewire morph, then put the page back exactly as it was. Only for the component that
        // renders this page: the header search, the bell and the switcher are Livewire components too (R-52), and a commit of theirs
        // must not pull focus back into a box the person has just left with Escape.
        const page = root();
        const scope = component && component.el ? component.el : null;
        if (!page || !scope || !(scope === page || scope.contains(page) || page.contains(scope))) return;
        const active = document.activeElement;
        const key = active && active.id ? active.id : null;
        const sel = active && active.selectionStart !== undefined ? [active.selectionStart, active.selectionEnd] : null;
        const typed = new Map(fieldsWithText().filter((f) => f.id).map((f) => [f.id, f.value]));
        succeed(() => {
            window.requestAnimationFrame(() => {
                typed.forEach((value, id) => { const f = document.getElementById(id); if (f && f.value !== value) f.value = value; });
                apply();
                if (key) { const f = document.getElementById(key); if (f && document.activeElement !== f) { f.focus({ preventScroll: true }); if (sel && f.setSelectionRange) { try { f.setSelectionRange(sel[0], sel[1]); } catch (e) { /* not a text field */ } } } }
            });
        });
    });
});
