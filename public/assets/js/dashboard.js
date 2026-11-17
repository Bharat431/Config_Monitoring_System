/* ==========================================================================
   Config Monitor dashboard — dependency-free SPA
   ========================================================================== */
(() => {
    'use strict';

    const BOOT = window.__CM_BOOT__ || {};
    const INITIAL = window.__CM_INITIAL__ || {};
    const API_BASE = (BOOT.baseUrl || '/').replace(/\/+$/, '');
    const POLL_MS = Math.max(5000, Number(BOOT.pollIntervalMs) || 15000);
    const KEY_STORE = 'cm.apiKey';
    const NAV_STORE = 'cm.nav.collapsed';

    const state = {
        route: 'overview',
        routeData: {},
        overview: INITIAL.overview || null,
        health: INITIAL.health || null,
        files: [],
        poll: true,
        timer: null,
        chart: null,
        lastError: null,
        unauthorized: false,
        apiKey: localStorage.getItem(KEY_STORE) || '',
        navCollapsed: localStorage.getItem(NAV_STORE) === '1',
        pages: {
            events: { page: 1, per_page: 20, file: '', type: '' },
            deliveries: { page: 1, per_page: 20, status: '' },
            dlq: { page: 1, per_page: 20 },
            files: { q: '', status: '' },
        },
        modal: { onSubmit: null, onCancel: null },
    };

    let navPeekLock = false;

    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    /* ---------------------------------------------------------------------
       Utilities
       --------------------------------------------------------------------- */

    const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    const e = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ESCAPES[c]);

    const numberFmt = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });
    const fmtNum = (value) => numberFmt.format(Number(value) || 0);
    const fmtPct = (value) => (value === null || value === undefined ? '—' : `${Number(value).toFixed(1)}%`);
    const shortHash = (hash) => (hash ? String(hash).slice(0, 10) : '—');
    const pad = (n) => String(n).padStart(2, '0');

    function fmtDate(iso, withSeconds = false) {
        if (!iso) return '—';
        const date = new Date(iso);
        if (Number.isNaN(date.getTime())) return String(iso);
        return date.toLocaleString(undefined, {
            year: 'numeric', month: 'short', day: '2-digit',
            hour: '2-digit', minute: '2-digit',
            ...(withSeconds ? { second: '2-digit' } : {}),
        });
    }

    function fmtClock(iso) {
        if (!iso) return '—';
        const date = new Date(iso);
        if (Number.isNaN(date.getTime())) return String(iso);
        return `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
    }

    function timeAgo(iso) {
        if (!iso) return 'never';
        const ts = new Date(iso).getTime();
        if (Number.isNaN(ts)) return String(iso);
        const seconds = Math.max(0, Math.round((Date.now() - ts) / 1000));
        if (seconds < 45) return `${seconds}s ago`;
        const minutes = Math.round(seconds / 60);
        if (minutes < 60) return `${minutes}m ago`;
        const hours = Math.round(minutes / 60);
        if (hours < 24) return `${hours}h ago`;
        const days = Math.round(hours / 24);
        return `${days}d ago`;
    }

    function statusBadge(status) {
        const map = {
            active: 'good', error: 'bad', parse_error: 'warn', paused: 'warn',
            pending: 'info', missing: 'bad', deleted: 'warn',
            delivered: 'good', dead: 'bad', processing: 'info',
            created: 'good', modified: 'info', parse_error: 'warn',
            enabled: 'good', disabled: 'warn',
        };
        const cls = map[status] || 'plain';
        return `<span class="badge ${cls}">${e(status || 'unknown')}</span>`;
    }

    function typeBadge(type) {
        const map = { created: 'good', modified: 'info', deleted: 'bad', parse_error: 'warn', test: 'violet' };
        return `<span class="badge ${map[type] || 'plain'}">${e(type || 'unknown')}</span>`;
    }

    function statusBadgeClass(status) {
        const map = {
            ok: 'good', error: 'bad', degraded: 'warn',
            active: 'good', pending: 'warn', paused: 'warn', missing: 'bad', deleted: 'warn',
        };
        return map[status] || '';
    }

    function debounce(fn, ms = 280) {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), ms);
        };
    }

    function escapeAttr(value) {
        return e(value).replace(/`/g, '&#96;');
    }

    const I = {
        file: '<svg viewBox="0 0 24 24" fill="none"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M14 3v5h5" stroke="currentColor" stroke-width="1.7"/></svg>',
        pulse: '<svg viewBox="0 0 24 24" fill="none"><path d="M3 12h4l2-6 4 12 2-6h6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        send: '<svg viewBox="0 0 24 24" fill="none"><path d="m21 3-9 9M21 3l-6.5 18-3-7.5L4 10.5 21 3Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
        alert: '<svg viewBox="0 0 24 24" fill="none"><path d="M12 9v4m0 3h.01M10.3 4.6 2.8 17.4A1.6 1.6 0 0 0 4.2 20h15.6a1.6 1.6 0 0 0 1.4-2.6L13.7 4.6a1.9 1.9 0 0 0-3.4 0Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        plus: '<svg viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>',
        play: '<svg viewBox="0 0 24 24" fill="none"><path d="m8 5 11 7-11 7V5Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
        refresh: '<svg viewBox="0 0 24 24" fill="none"><path d="M20 12a8 8 0 1 1-2.3-5.6M20 4v5h-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        eye: '<svg viewBox="0 0 24 24" fill="none"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.7"/></svg>',
        trash: '<svg viewBox="0 0 24 24" fill="none"><path d="M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        pause: '<svg viewBox="0 0 24 24" fill="none"><path d="M9 5v14M15 5v14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
        check: '<svg viewBox="0 0 24 24" fill="none"><path d="m5 13 4 4L19 7" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        x: '<svg viewBox="0 0 24 24" fill="none"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        clock: '<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.7"/><path d="M12 7.5V12l3 2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>',
        inbox: '<svg viewBox="0 0 24 24" fill="none"><path d="M3 13h5l2 3h4l2-3h5M5 5h14l2 8v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4l2-8Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
        target: '<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="12" r="1" fill="currentColor"/></svg>',
    };

    function iconButton(label, action, icon, attrs = '', cls = 'icon-btn') {
        return `<button class="${cls}" type="button" data-act="${e(action)}" ${attrs} title="${e(label)}" aria-label="${e(label)}">${icon}</button>`;
    }

    function emptyState(title, message, icon = I.inbox) {
        return `<div class="empty-state"><div class="icon">${icon}</div><strong>${e(title)}</strong><span>${e(message)}</span></div>`;
    }

    /* ---------------------------------------------------------------------
       HTTP
       --------------------------------------------------------------------- */

    class ApiError extends Error {
        constructor(message, status, payload) {
            super(message);
            this.status = status;
            this.payload = payload;
        }
    }

    function apiUrl(path) {
        if (/^https?:\/\//i.test(path)) return path;
        return `${API_BASE}${path.startsWith('/') ? path : '/' + path}`;
    }

    async function api(path, options = {}) {
        const headers = { Accept: 'application/json', ...(options.headers || {}) };
        const key = state.apiKey || localStorage.getItem(KEY_STORE) || '';
        if (key) headers['X-API-Key'] = key;

        let body = options.body;
        if (body && typeof body !== 'string' && !(body instanceof FormData)) {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(body);
        }

        let response;
        try {
            response = await fetch(apiUrl(path), { credentials: 'same-origin', ...options, headers, body });
        } catch (networkError) {
            throw new ApiError('Network error: ' + networkError.message, 0, null);
        }

        const text = await response.text();
        let payload = null;
        if (text) {
            try { payload = JSON.parse(text); } catch { payload = { raw: text }; }
        }

        if (!response.ok) {
            if (response.status === 401) {
                state.unauthorized = true;
                stopPolling();
                promptApiKey();
            }
            const message = payload?.error?.message || payload?.raw || `Request failed (${response.status})`;
            throw new ApiError(message, response.status, payload);
        }

        state.unauthorized = false;
        return payload;
    }

    /* ---------------------------------------------------------------------
       Toasts / modal / drawer
       --------------------------------------------------------------------- */

    function toast(message, opts = {}) {
        const type = opts.type || 'info';
        const title = opts.title || { good: 'Done', warn: 'Warning', bad: 'Error', info: 'Notice' }[type] || 'Notice';
        const root = $('#toasts');
        const el = document.createElement('div');
        el.className = `toast ${type}`;
        el.innerHTML = `<span class="bar"></span><div class="body"><strong>${e(title)}</strong><span>${e(message)}</span></div><button class="x" type="button" aria-label="Dismiss">×</button>`;
        const remove = () => {
            if (!el.isConnected) return;
            el.classList.add('leaving');
            setTimeout(() => el.remove(), 240);
        };
        el.querySelector('.x').addEventListener('click', remove);
        root.appendChild(el);
        setTimeout(remove, opts.timeout || 5000);
    }

    function openModal({ title, eyebrow = 'Action', body, foot = '', wide = false, onSubmit = null, onCancel = null, dismissable = true }) {
        const backdrop = $('#modal-backdrop');
        const modal = backdrop.querySelector('.modal');
        $('#modal-title').textContent = title || 'Action';
        $('#modal-eyebrow').textContent = eyebrow;
        $('#modal-body').innerHTML = body || '';
        $('#modal-foot').innerHTML = foot || '';
        modal.classList.toggle('wide', !!wide);
        backdrop.dataset.dismissable = dismissable ? '1' : '0';
        state.modal.onSubmit = onSubmit;
        state.modal.onCancel = onCancel;
        backdrop.hidden = false;
        setTimeout(() => {
            const first = backdrop.querySelector('input, select, textarea, button.btn-primary');
            if (first) first.focus();
        }, 60);
    }

    function closeModal({ cancelled = true } = {}) {
        const backdrop = $('#modal-backdrop');
        if (backdrop.hidden) return;
        const handler = state.modal.onCancel;
        state.modal.onSubmit = null;
        state.modal.onCancel = null;
        backdrop.hidden = true;
        $('#modal-body').innerHTML = '';
        $('#modal-foot').innerHTML = '';
        if (cancelled && typeof handler === 'function') handler();
    }

    function openDrawer({ title, eyebrow = 'Details', html }) {
        $('#drawer-title').textContent = title || 'Details';
        $('#drawer-eyebrow').textContent = eyebrow;
        $('#drawer-body').innerHTML = html || '';
        $('#drawer').hidden = false;
        $('#drawer-backdrop').hidden = false;
    }

    function closeDrawer() {
        $('#drawer').hidden = true;
        $('#drawer-backdrop').hidden = true;
        $('#drawer-body').innerHTML = '';
    }

    function confirmDialog({ title, message, confirmLabel = 'Confirm', danger = false }) {
        return new Promise((resolve) => {
            openModal({
                title,
                eyebrow: danger ? 'Destructive action' : 'Please confirm',
                body: `<p class="muted" style="line-height:1.6">${e(message)}</p>`,
                foot: `<button class="btn btn-ghost" type="button" data-modal="cancel">Cancel</button>
                       <button class="btn ${danger ? 'btn-danger' : 'btn-primary'}" type="button" data-modal="submit">${e(confirmLabel)}</button>`,
                onSubmit: () => { closeModal({ cancelled: false }); resolve(true); },
                onCancel: () => resolve(false),
            });
        });
    }

    function promptApiKey() {
        if (!$('#modal-backdrop').hidden) return;
        openModal({
            title: 'API key required',
            eyebrow: 'Authentication',
            dismissable: false,
            body: `<p class="muted small" style="margin-bottom:10px">This deployment protects the management API with a shared key. The key is stored only in this browser.</p>
                   <div class="field"><label for="api-key-input">X-API-Key / Bearer token</label>
                   <input class="input" id="api-key-input" type="password" autocomplete="off" placeholder="Paste the API key…" value="${escapeAttr(state.apiKey)}"></div>
                   <p class="faint small" style="margin-top:8px">Configured with <code>CONFIGMON_API_KEY</code> on the server.</p>`,
            foot: `<button class="btn btn-ghost" type="button" data-modal="cancel">Continue without key</button>
                   <button class="btn btn-primary" type="button" data-modal="submit">Save key</button>`,
            onSubmit: () => {
                const value = ($('#api-key-input')?.value || '').trim();
                state.apiKey = value;
                localStorage.setItem(KEY_STORE, value);
                closeModal({ cancelled: false });
                toast(value ? 'API key saved for this browser.' : 'API key cleared.', { type: value ? 'good' : 'warn' });
                startPolling();
                route(true);
            },
        });
    }

    /* ---------------------------------------------------------------------
       Shell / navigation
       --------------------------------------------------------------------- */

    const ROUTES = {
        overview: { title: 'Overview', crumb: 'Overview' },
        files: { title: 'Watched files', crumb: 'Watched files' },
        events: { title: 'Change events', crumb: 'Change events' },
        deliveries: { title: 'Delivery log', crumb: 'Deliveries' },
        dlq: { title: 'Dead-letter queue', crumb: 'Dead letters' },
        targets: { title: 'Notification targets', crumb: 'Targets' },
        settings: { title: 'Settings & demo', crumb: 'Settings' },
    };

    function setHeader(route) {
        const meta = ROUTES[route] || ROUTES.overview;
        $('#page-title').textContent = meta.title;
        $('#crumb').textContent = meta.crumb;
        document.title = `${meta.title} · Config Monitor`;
        $$('.nav-item').forEach((link) => link.classList.toggle('active', link.dataset.route === route));
    }

    function currentRoute() {
        const raw = (location.hash || '#/overview').replace(/^#\/?/, '');
        const route = raw.split('?')[0].split('/')[0] || 'overview';
        return ROUTES[route] ? route : 'overview';
    }

    function closeNav() {
        $('#sidebar').classList.remove('open');
        $('.sidebar-backdrop').hidden = true;
    }

    function openNav() {
        $('#sidebar').classList.add('open');
        $('.sidebar-backdrop').hidden = false;
    }

    function applyNavState() {
        const collapsed = document.documentElement.classList.toggle('nav-collapsed', state.navCollapsed);
        const sidebar = $('#sidebar');
        if (sidebar) sidebar.classList.remove('peeking');

        const btn = $('[data-action="toggle-nav"]');
        if (!btn) return;
        btn.setAttribute('aria-expanded', String(!collapsed));
        btn.setAttribute('aria-label', collapsed ? 'Expand navigation' : 'Collapse navigation');
        btn.title = collapsed ? 'Expand navigation' : 'Collapse navigation';
    }

    function toggleNav() {
        state.navCollapsed = !state.navCollapsed;
        // While the pointer is still inside, do not let hover-peek instantly
        // undo a collapse click. The lock clears when the pointer leaves.
        navPeekLock = state.navCollapsed;
        try { localStorage.setItem(NAV_STORE, state.navCollapsed ? '1' : '0'); } catch (_) { /* storage unavailable */ }
        applyNavState();
    }

    function showLoading() {
        $('#view').innerHTML = `<div class="page-loader"><span class="spinner"></span><span>Loading workspace…</span></div>`;
    }

    function showFatal(error) {
        $('#view').innerHTML = `<div class="card"><div class="empty-state">
            <div class="icon">${I.alert}</div>
            <strong>Unable to load this view</strong>
            <span>${e(error.message || error)}</span>
            <div class="row" style="justify-content:center;margin-top:14px">
                <button class="btn btn-primary" type="button" data-act="refresh">${I.refresh} Retry</button>
            </div>
        </div></div>`;
    }

    async function route(force = false) {
        const next = currentRoute();
        const changed = next !== state.route;
        state.route = next;
        setHeader(next);

        if (changed) {
            state.routeData = {};
            $('#degraded-banner').hidden = true;
        }

        if (force || changed || !$('#view').dataset.ready) {
            showLoading();
        }

        try {
            await renderRoute(next);
            $('#view').dataset.ready = '1';
        } catch (error) {
            if (error.status !== 401) showFatal(error);
        }
    }

    /* ---------------------------------------------------------------------
       Lazy route chunks — a page's renderer is fetched on first visit.
       --------------------------------------------------------------------- */

    const CHUNK_BASE = `${BOOT.baseUrl}assets/js/routes/`;
    const chunkLoads = Object.create(null);

    function loadChunk(name) {
        if (chunkLoads[name]) return chunkLoads[name];
        chunkLoads[name] = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = `${CHUNK_BASE}${name}.js`;
            script.async = true;
            script.onload = () => {
                const mod = CM.routes[name];
                if (mod) resolve(mod);
                else reject(new Error(`Route module "${name}" did not register`));
            };
            script.onerror = () => {
                delete chunkLoads[name]; // allow a later retry
                reject(new Error(`Could not load the ${name} module`));
            };
            document.head.appendChild(script);
        });
        return chunkLoads[name];
    }

    async function renderRoute(routeName, silent = false) {
        if (routeName === 'overview') return renderOverview(silent);
        const mod = await loadChunk(routeName);
        return mod.render(silent);
    }

    function setView(html) {
        $('#view').innerHTML = `<div class="page">${html}</div>`;
        $('#view').dataset.ready = '1';
    }

    // Shared surface used by the lazily-loaded route chunks.
    const CM = window.CM = {
        BOOT, POLL_MS, KEY_STORE, state, I, ROUTES,
        $, $$, e, escapeAttr, fmtNum, fmtPct, shortHash, fmtDate, fmtClock, timeAgo,
        statusBadge, typeBadge, statusBadgeClass, iconButton, emptyState, detail, pagination,
        api, apiUrl, debounce,
        toast, openModal, closeModal, openDrawer, closeDrawer, confirmDialog,
        setView, loadOverview, loadHealth, renderRoute, refreshAll, healthRow,
        routes: Object.create(null),
    };

    function applyBoot() {
        $('#app-version').textContent = BOOT.version || '1.0.0';
        $$('.demo-only').forEach((el) => { el.hidden = !BOOT.demoMode; });
        if (!BOOT.demoMode) $$('.demo-only').forEach((el) => el.remove());
        if (BOOT.apiKeyRequired && !state.apiKey) promptApiKey();
    }

    function startClock() {
        const tick = () => {
            const el = $('#sidebar-clock');
            if (el) el.textContent = new Date().toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        };
        tick();
        setInterval(tick, 1000);
    }

    /* ---------------------------------------------------------------------
       Polling
       --------------------------------------------------------------------- */

    function startPolling() {
        stopPolling();
        if (!state.poll || state.unauthorized) return;
        state.timer = setInterval(() => {
            if (document.hidden) return;
            if (!$('#modal-backdrop').hidden || !$('#drawer').hidden) return;
            pollRefresh();
        }, POLL_MS);
    }

    function stopPolling() {
        if (state.timer) clearInterval(state.timer);
        state.timer = null;
    }

    function setPoll(enabled) {
        state.poll = enabled;
        const pill = $('#live-pill');
        const text = $('#live-text');
        if (pill) pill.dataset.state = enabled ? 'on' : 'paused';
        if (text) text.textContent = enabled ? 'Live' : 'Paused';
        const btn = $('[data-action="toggle-poll"]');
        if (btn) btn.title = enabled ? 'Pause live updates' : 'Resume live updates';
        if (enabled) startPolling(); else stopPolling();
        toast(enabled ? `Polling every ${Math.round(POLL_MS / 1000)}s.` : 'Live updates paused.', { type: 'info', timeout: 2500 });
    }

    async function pollRefresh() {
        try {
            if (state.route === 'settings') return; // static page
            await renderRoute(state.route, true);
        } catch (error) {
            if (error.status === 401) return;
            // Silent polling failures must not disrupt the page.
            console.warn('poll failed', error);
        }
    }

    /* ---------------------------------------------------------------------
       Overview
       --------------------------------------------------------------------- */

    async function renderOverview(silent = false) {
        let data = state.overview;
        if (!data || !silent) {
            const response = await api('/api/stats');
            data = response.data;
            state.overview = data;
        }

        const files = data.files || {};
        const events = data.events || {};
        const notifications = data.notifications || {};
        const queue = data.queue || {};
        const targets = data.targets || {};
        const health = data.health || {};

        const queueDepth = Number(queue.pending || 0) + Number(queue.processing || 0);
        const successRate = notifications.success_rate;
        const recent = events.recent || [];
        const activePct = files.total ? Math.round((Number(files.active || 0) / Number(files.total || 1)) * 100) : 0;

        const recentRows = recent.length
            ? `<div class="table-wrap"><table class="data">
                <thead><tr><th>When</th><th>File</th><th>Type</th><th>Changes</th><th>Trace</th><th></th></tr></thead>
                <tbody>${recent.map((ev) => `
                    <tr>
                        <td class="nowrap" title="${escapeAttr(fmtDate(ev.created_at, true))}">${e(timeAgo(ev.created_at))}</td>
                        <td><div class="primary-cell"><strong>${e(ev.file_name)}</strong><span>${e((ev.event_id || '').slice(0, 8))}</span></div></td>
                        <td>${typeBadge(ev.change_type)}</td>
                        <td class="num"><span class="muted">+${fmtNum(ev.summary?.added)}&nbsp; ~${fmtNum(ev.summary?.updated)}&nbsp; −${fmtNum(ev.summary?.removed)}</span></td>
                        <td><span class="mono faint">${e((ev.trace_id || '').slice(0, 10) || '—')}</span></td>
                        <td><div class="actions"><button class="btn btn-ghost btn-sm" type="button" data-act="event" data-id="${escapeAttr(ev.event_id)}">${I.eye} Details</button></div></td>
                    </tr>`).join('')}</tbody>
            </table></div>`
            : emptyState('No change events yet', 'Seed the demo workspace or point a watcher at a config file.', I.pulse);

        const targetsLine = targets.total
            ? `${fmtNum(targets.enabled)} of ${fmtNum(targets.total)} targets enabled`
            : 'No notification targets configured';

        setView(`
            <div class="page-intro">
                <div>
                    <h2>Configuration change observability</h2>
                    <p>Watch configuration files, diff structural changes and deliver signed events to your downstream systems.</p>
                </div>
                <div class="row">
                    <button class="btn" type="button" data-act="refresh">${I.refresh} Refresh</button>
                    <a class="btn btn-primary" href="#/events">${I.pulse} Browse events</a>
                </div>
            </div>

            <div class="kpi-grid">
                ${kpi('Files watched', fmtNum(files.active), `${fmtNum(files.total)} tracked · ${fmtNum(files.error)} in error`, I.file, 'var(--accent)', activePct)}
                ${kpi('Events · 24h', fmtNum(events.last_24h), `${fmtNum(events.total)} total events`, I.pulse, 'var(--accent-2)')}
                ${kpi('Delivery success', fmtPct(successRate), `${fmtNum(notifications.delivered)} delivered · ${fmtNum(notifications.dead)} dead`, I.send, 'var(--good)', successRate || 0)}
                ${kpi('Queue depth', fmtNum(queueDepth), `${fmtNum(queue.pending)} pending · ${fmtNum(queue.processing)} processing`, I.clock, queueDepth > 0 ? 'var(--warn)' : 'var(--accent-3)')}
            </div>

            <div class="grid-2">
                <div class="card">
                    <div class="card-head">
                        <h3>Change activity</h3><span class="card-sub">last 24 hours</span>
                        <span class="spacer"></span>
                        <span class="badge plain">${fmtNum(events.last_24h)} in window</span>
                    </div>
                    <div class="chart-wrap" id="chart-wrap">
                        <canvas class="chart-canvas" id="chart-events" role="img" aria-label="Hourly change activity"></canvas>
                        <div class="chart-tooltip" id="chart-tooltip"></div>
                    </div>
                    <div class="legend">
                        <span><i style="background:var(--good)"></i>created</span>
                        <span><i style="background:var(--accent-2)"></i>modified</span>
                        <span><i style="background:var(--bad)"></i>deleted</span>
                        <span><i style="background:var(--warn)"></i>parse errors</span>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head">
                        <h3>System health</h3><span class="spacer"></span>
                        ${statusBadge(state.health?.status || 'unknown')}
                    </div>
                    <div class="card-body">
                        <div class="health-list">
                            ${healthRow('Database', state.health?.database || 'unknown', state.health?.database === 'ok' ? 'good' : 'bad')}
                            ${healthRow('Watcher', health.watcher ? timeAgo(health.watcher.at) : 'not running', health.watcher ? 'good' : 'warn')}
                            ${healthRow('Queue worker', health.worker ? timeAgo(health.worker.at) : 'not running', health.worker ? 'good' : 'warn')}
                            ${healthRow('Last scan', health.last_scan ? timeAgo(health.last_scan.at) : 'never', health.last_scan ? 'good' : 'warn')}
                            ${healthRow('Circuit breakers', `${fmtNum(health.circuit_open)} open`, Number(health.circuit_open) > 0 ? 'bad' : 'good')}
                            ${healthRow('Dead letters', `${fmtNum(notifications.dlq_open)} unresolved`, Number(notifications.dlq_open) > 0 ? 'bad' : 'good')}
                        </div>
                        <div class="queue-grid" style="margin-top:14px">
                            ${queueStat('pending', queue.pending)}
                            ${queueStat('processing', queue.processing)}
                            ${queueStat('delivered', queue.delivered)}
                            ${queueStat('dead', queue.dead)}
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <h3>Recent changes</h3>
                    <span class="card-sub">${targetsLine}</span>
                    <span class="spacer"></span>
                    <a class="btn btn-ghost btn-sm" href="#/events">View all ${I.eye}</a>
                </div>
                ${recentRows}
            </div>
        `);

        drawChart(events.series || []);
        updateSidebarHealth();
        refreshSummaryBadges();
        hideBanner();
    }

    function kpi(label, value, foot, icon, color, barPct = null) {
        return `<div class="card kpi" style="--kpi-color:${color}">
            <div class="kpi-top"><span class="kpi-label">${e(label)}</span><span class="kpi-icon">${icon}</span></div>
            <div class="kpi-value">${value}</div>
            ${barPct === null ? '' : `<div class="kpi-bar"><span style="width:${Math.max(2, Math.min(100, Number(barPct)))}%"></span></div>`}
            <div class="kpi-foot">${e(foot)}</div>
        </div>`;
    }

    function healthRow(label, value, tone = '') {
        return `<div class="health-row"><span class="label">${e(label)}</span><span class="value ${tone}">${e(value)}</span></div>`;
    }

    function queueStat(key, value) {
        return `<div class="queue-stat ${e(key)}"><span>${e(key)}</span><strong>${fmtNum(value)}</strong></div>`;
    }

    /* ------------------------------ chart ---------------------------------- */

    function drawChart(series) {
        const canvas = $('#chart-events');
        if (!canvas) return;
        state.chart = { series: series || [] };

        const wrap = canvas.parentElement;
        const rect = canvas.getBoundingClientRect();
        if (!rect.width || !rect.height) return;

        const dpr = window.devicePixelRatio || 1;
        canvas.width = Math.round(rect.width * dpr);
        canvas.height = Math.round(rect.height * dpr);

        const ctx = canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, rect.width, rect.height);

        const css = getComputedStyle(document.documentElement);
        const color = (name, fallback) => (css.getPropertyValue(name) || fallback).trim();
        const textColor = color('--muted', '#a0b3c1');
        const gridColor = color('--chart-grid', 'rgba(169,188,203,.1)');
        const colors = {
            created: color('--good', '#5bd69c'),
            modified: color('--accent-2', '#7fa5bd'),
            deleted: color('--bad', '#ff8fa9'),
            parse_error: color('--warn', '#e8c47a'),
        };

        const padLeft = 38;
        const padRight = 12;
        const padTop = 14;
        const padBottom = 28;
        const plotW = Math.max(10, rect.width - padLeft - padRight);
        const plotH = Math.max(10, rect.height - padTop - padBottom);
        const buckets = series.length ? series : [];
        const totals = buckets.map((b) => Number(b.total) || 0);
        const rawMax = Math.max(1, ...totals);
        const step = niceStep(rawMax / 4);
        const maxY = Math.max(step, Math.ceil(rawMax / step) * step);

        ctx.font = '11px ' + (getComputedStyle(document.body).fontFamily || 'sans-serif');
        ctx.textBaseline = 'middle';

        // Grid + Y labels
        for (let i = 0; i <= 4; i++) {
            const value = (maxY / 4) * i;
            const y = padTop + plotH - (plotH * i) / 4;
            ctx.strokeStyle = gridColor;
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(padLeft, Math.round(y) + 0.5);
            ctx.lineTo(padLeft + plotW, Math.round(y) + 0.5);
            ctx.stroke();
            ctx.fillStyle = textColor;
            ctx.textAlign = 'right';
            ctx.fillText(String(Math.round(value)), padLeft - 8, y);
        }

        if (!buckets.length) {
            ctx.fillStyle = textColor;
            ctx.textAlign = 'center';
            ctx.fillText('No activity in this window', padLeft + plotW / 2, padTop + plotH / 2);
            state.chart.bars = [];
            return;
        }

        const slot = plotW / buckets.length;
        const barW = Math.max(2, Math.min(26, slot * 0.56));
        const bars = [];

        buckets.forEach((bucket, index) => {
            const x = padLeft + slot * index + (slot - barW) / 2;
            const values = ['created', 'modified', 'deleted', 'parse_error'].map((key) => Number(bucket[key]) || 0);
            let cursor = padTop + plotH;

            values.forEach((value, seriesIndex) => {
                if (value <= 0) return;
                const height = (value / maxY) * plotH;
                cursor -= height;
                ctx.fillStyle = colors[['created', 'modified', 'deleted', 'parse_error'][seriesIndex]];
                roundedRect(ctx, x, cursor, barW, Math.max(1, height - 0.5), Math.min(3, barW / 2));
                ctx.fill();
            });

            bars.push({
                index,
                x: x + barW / 2,
                top: padTop + plotH - ((Number(bucket.total) || 0) / maxY) * plotH,
                bucket,
            });

            const every = Math.max(1, Math.ceil(buckets.length / 7));
            if (index % every === 0 || index === buckets.length - 1) {
                ctx.fillStyle = textColor;
                ctx.textAlign = 'center';
                ctx.fillText(bucket.label || '', padLeft + slot * index + slot / 2, padTop + plotH + 15);
            }
        });

        state.chart.bars = bars;
        state.chart.rect = { left: rect.left, top: rect.top, width: rect.width, height: rect.height };
        bindChartHover(wrap, canvas);
    }

    function niceStep(value) {
        if (value <= 1) return 1;
        const exponent = Math.floor(Math.log10(value));
        const fraction = value / Math.pow(10, exponent);
        const nice = fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 5 ? 5 : 10;
        return nice * Math.pow(10, exponent);
    }

    function roundedRect(ctx, x, y, w, h, r) {
        const radius = Math.min(r, w / 2, h);
        ctx.beginPath();
        ctx.moveTo(x + radius, y);
        ctx.arcTo(x + w, y, x + w, y + h, radius);
        ctx.arcTo(x + w, y + h, x, y + h, 0);
        ctx.arcTo(x, y + h, x, y, 0);
        ctx.arcTo(x, y, x + w, y, radius);
        ctx.closePath();
    }

    let chartHoverBound = false;
    function bindChartHover(wrap, canvas) {
        if (chartHoverBound) return;
        chartHoverBound = true;

        const tooltip = $('#chart-tooltip');
        const hide = () => tooltip.classList.remove('visible');

        wrap.addEventListener('mousemove', (event) => {
            if (!state.chart?.bars?.length) return hide();
            const rect = canvas.getBoundingClientRect();
            const x = event.clientX - rect.left;
            const y = event.clientY - rect.top;
            const slot = (rect.width - 50) / state.chart.bars.length;
            const index = Math.floor((x - 38) / slot);
            const bar = state.chart.bars[index];
            if (!bar || x < 38 || y < 10) return hide();

            const b = bar.bucket;
            tooltip.innerHTML = `<strong>${e(b.label || '')}</strong>
                <div class="row"><span>Total</span><b>${fmtNum(b.total)}</b></div>
                <div class="row"><span>Added</span><b>${fmtNum(b.created)}</b></div>
                <div class="row"><span>Updated</span><b>${fmtNum(b.modified)}</b></div>
                <div class="row"><span>Removed</span><b>${fmtNum(b.deleted)}</b></div>
                <div class="row"><span>Parse errors</span><b>${fmtNum(b.parse_error)}</b></div>`;
            tooltip.style.left = `${Math.min(rect.width - 76, Math.max(76, bar.x))}px`;
            tooltip.style.top = `${Math.max(30, bar.top)}px`;
            tooltip.classList.add('visible');
        });

        wrap.addEventListener('mouseleave', hide);
    }

    let chartResizeObserver = null;
    function ensureChartObserver() {
        if (chartResizeObserver || typeof ResizeObserver === 'undefined') return;
        chartResizeObserver = new ResizeObserver(debounce(() => {
            if (state.chart?.series && $('#chart-events')) drawChart(state.chart.series);
        }, 120));
        chartResizeObserver.observe($('#view'));
    }

    /* ---------------------------------------------------------------------
       Sidebar health / badges
       --------------------------------------------------------------------- */

    function updateSidebarHealth() {
        $('#sidebar-health').innerHTML = `
            <span class="status-dot ${statusBadgeClass(state.health?.status)}" data-health-dot></span>
            <div><strong data-health-label>${e(healthLabel(state.health?.status))}</strong><span data-health-detail>system health</span></div>`;
    }

    function healthLabel(status) {
        return { ok: 'All systems healthy', degraded: 'Degraded', error: 'Outage', unknown: 'Unknown' }[status] || 'Unknown';
    }

    function refreshSummaryBadges() {
        const data = state.overview;
        if (!data) return;
        const filesBadge = $('[data-count="files"]');
        if (filesBadge) filesBadge.textContent = data.files?.active ? String(data.files.active) : '';
        const dlqBadge = $('[data-count="dlq"]');
        if (dlqBadge) dlqBadge.textContent = data.notifications?.dlq_open ? String(data.notifications.dlq_open) : '';
    }

    function hideBanner() {
        const banner = $('#degraded-banner');
        if (!banner) return;
        if (state.health && state.health.status !== 'ok' && (state.health.reasons || []).length) {
            banner.hidden = false;
            banner.innerHTML = `${I.alert}<span><strong>System ${e(state.health.status)}:</strong> ${e((state.health.reasons || []).join(', ').replaceAll('_', ' '))}</span>`;
        } else {
            banner.hidden = true;
        }
    }

    async function loadHealth() {
        try {
            const response = await api('/health');
            state.health = response.data;
        } catch {
            // keep the last known health
        }
    }


    async function loadOverview(silent = true) {
        const response = await api('/api/stats');
        state.overview = response.data;
        refreshSummaryBadges();
        return response.data;
    }



    function detail(label, value) {
        return `<div class="detail"><span>${e(label)}</span><strong>${value}</strong></div>`;
    }

    function pagination(meta, kind) {
        const page = Number(meta.page || 1);
        const totalPages = Math.max(1, Number(meta.total_pages || 1));
        const total = Number(meta.total || 0);
        return `<div class="pagination">
            <span class="info">Page ${page} of ${totalPages} · ${fmtNum(total)} records</span>
            <button class="btn btn-ghost btn-sm" type="button" data-act="page" data-kind="${kind}" data-page="${Math.max(1, page - 1)}" ${page <= 1 ? 'disabled' : ''}>‹ Prev</button>
            <button class="btn btn-ghost btn-sm" type="button" data-act="page" data-kind="${kind}" data-page="${Math.min(totalPages, page + 1)}" ${page >= totalPages ? 'disabled' : ''}>Next ›</button>
        </div>`;
    }






    /* ---------------------------------------------------------------------
       Actions
       --------------------------------------------------------------------- */

    // Actions owned by a lazily-loaded page are dispatched to that page's chunk.
    const ACTION_ROUTE = {
        'event': 'events',
        'add-watch': 'files', 'file-detail': 'files', 'scan-file': 'files',
        'toggle-file': 'files', 'remove-file': 'files',
        'add-target': 'targets', 'edit-target': 'targets', 'test-target': 'targets',
        'delete-target': 'targets', 'toggle-target': 'targets',
        'retry-dlq': 'dlq',
    };

    async function handleAction(action, el) {
        const id = el.dataset.id;
        switch (action) {
            case 'refresh':
                await refreshAll(true);
                return;
            case 'page': {
                const kind = el.dataset.kind;
                if (state.pages[kind]) {
                    state.pages[kind].page = Number(el.dataset.page) || 1;
                    await renderRoute(state.route);
                }
                return;
            }
            case 'replay-event': {
                const ok = await confirmDialog({
                    title: 'Replay this event?',
                    message: 'The event will be re-queued for every enabled notification target. Consumers can de-duplicate using X-Event-Id.',
                    confirmLabel: 'Replay event',
                });
                if (!ok) return;
                try {
                    const result = await api(`/api/events/${encodeURIComponent(id)}/replay`, { method: 'POST' });
                    toast(`Re-queued to ${result.data.requeued} queue row(s).`, { type: 'good' });
                    closeDrawer();
                    renderRoute(state.route);
                } catch (error) { toast(error.message, { type: 'bad' }); }
                return;
            }
            case 'event-json': {
                try {
                    const response = await api(`/api/events/${encodeURIComponent(id)}`);
                    const payload = response.data.event.payload;
                    openModal({
                        title: 'Canonical payload',
                        eyebrow: 'Contract v1',
                        wide: true,
                        body: `<pre class="code">${e(JSON.stringify(payload, null, 2))}</pre>`,
                        foot: `<button class="btn btn-primary" type="button" data-modal="cancel">Close</button>`,
                    });
                } catch (error) { toast(error.message, { type: 'bad' }); }
                return;
            }
            case 'seed':
            case 'seed-reset': {
                const reset = action === 'seed-reset';
                const ok = await confirmDialog({
                    title: reset ? 'Reset demo files?' : 'Seed demo workspace?',
                    message: reset
                        ? 'Sample files will be overwritten with their original contents. Watched rows are preserved.'
                        : 'Create sample .env, JSON, YAML and .properties files, register them and ensure a local webhook target exists.',
                    confirmLabel: reset ? 'Reset files' : 'Seed workspace',
                    danger: reset,
                });
                if (!ok) return;
                try {
                    const response = await api('/api/demo/seed', { method: 'POST', body: { reset } });
                    toast(`Seeded ${response.data.watched.length} watch(es).`, { type: 'good' });
                    await refreshAll(true);
                } catch (error) { toast(error.message, { type: 'bad' }); }
                return;
            }
            case 'demo-change':
                openDemoModal();
                return;
            case 'demo-change-form': {
                const file = $('#demo-file')?.value || null;
                const kind = $('#demo-kind')?.value || 'update';
                await simulateChange(file, kind);
                return;
            }
            case 'save-key': {
                const value = ($('#settings-key')?.value || '').trim();
                state.apiKey = value;
                localStorage.setItem(KEY_STORE, value);
                toast(value ? 'API key saved.' : 'API key cleared.', { type: value ? 'good' : 'warn' });
                return;
            }
            case 'clear-key': {
                state.apiKey = '';
                localStorage.removeItem(KEY_STORE);
                const input = $('#settings-key');
                if (input) input.value = '';
                toast('API key cleared from this browser.', { type: 'warn' });
                return;
            }
        }

        const routeName = ACTION_ROUTE[action];
        if (!routeName) return;
        const mod = await loadChunk(routeName);
        if (mod.actions && mod.actions[action]) await mod.actions[action](el);
    }

    async function simulateChange(file, kind) {
        try {
            const response = await api('/api/demo/change', { method: 'POST', body: { file, kind } });
            const count = response.data.events?.length || 0;
            toast(count ? `Detected ${count} event(s) in ${response.data.file}.` : `Wrote ${response.data.file} (no event: content unchanged).`, { type: count ? 'good' : 'warn' });
            await refreshAll(true);
        } catch (error) { toast(error.message, { type: 'bad' }); }
    }

    function openDemoModal() {
        openModal({
            title: 'Simulate a configuration change',
            eyebrow: 'Demo mode',
            body: `<p class="muted small" style="margin-bottom:12px">Mutates one of the demo sample files and forces an immediate scan, bypassing the debounce window.</p>
                <div class="grid-2" style="gap:12px">
                    <div class="field"><label for="demo-modal-file">File</label><select class="select" id="demo-modal-file">
                        ${['.env', 'app.json', 'services.yaml', 'application.properties'].map((file) => `<option>${e(file)}</option>`).join('')}
                    </select></div>
                    <div class="field"><label for="demo-modal-kind">Kind</label><select class="select" id="demo-modal-kind">
                        <option value="update">update (modified)</option>
                        <option value="type_change">type change</option>
                        <option value="create">delete + recreate</option>
                        <option value="delete">delete</option>
                        <option value="parse_error">parse error</option>
                    </select></div>
                </div>`,
            foot: `<button class="btn btn-ghost" type="button" data-modal="cancel">Cancel</button>
                   <button class="btn btn-primary" type="button" data-modal="submit">${I.play} Run simulation</button>`,
            onSubmit: async () => {
                const file = $('#demo-modal-file')?.value || null;
                const kind = $('#demo-modal-kind')?.value || 'update';
                closeModal({ cancelled: false });
                await simulateChange(file, kind);
            },
        });
    }

    async function refreshAll(force = false) {
        try {
            const [overview, health] = await Promise.all([
                api('/api/stats').catch(() => null),
                api('/health').catch(() => null),
            ]);
            if (overview) state.overview = overview.data;
            if (health) state.health = health.data;
            refreshSummaryBadges();
            updateSidebarHealth();
            hideBanner();
            if (force || state.route === 'overview') {
                await renderRoute(state.route);
            } else {
                await renderRoute(state.route);
            }
        } catch (error) {
            if (error.status !== 401) toast(error.message, { type: 'bad' });
        }
    }

    /* ---------------------------------------------------------------------
       Event wiring
       --------------------------------------------------------------------- */

    function wire() {
        // Global click delegation
        document.addEventListener('click', (event) => {
            const navLink = event.target.closest('.nav-item');
            if (navLink) closeNav();

            const openNavBtn = event.target.closest('[data-open-nav]');
            if (openNavBtn) { openNav(); return; }

            if (event.target.closest('[data-close-nav]')) { closeNav(); return; }
            if (event.target.closest('[data-close-drawer]')) { closeDrawer(); return; }
            if (event.target.closest('[data-close-modal]')) { closeModal(); return; }

            const modalButton = event.target.closest('[data-modal]');
            if (modalButton) {
                event.preventDefault();
                if (modalButton.dataset.modal === 'submit') {
                    const handler = state.modal.onSubmit;
                    if (typeof handler === 'function') handler();
                } else {
                    closeModal();
                }
                return;
            }

            const actionEl = event.target.closest('[data-act]');
            if (actionEl) {
                // A switch input fires change, not click; guard against double handling.
                if (actionEl.matches('input[type="checkbox"]')) return;
                event.preventDefault();
                handleAction(actionEl.dataset.act, actionEl);
                return;
            }

            const globalAction = event.target.closest('[data-action]');
            if (globalAction) {
                const action = globalAction.dataset.action;
                if (action === 'refresh') refreshAll(true);
                else if (action === 'toggle-poll') setPoll(!state.poll);
                else if (action === 'toggle-nav') toggleNav();
                else if (action === 'demo-change') openDemoModal();
                return;
            }

            // Clicking the modal backdrop (not the dialog itself) cancels.
            if (event.target.id === 'modal-backdrop') {
                if (event.target.dataset.dismissable !== '0') closeModal();
                return;
            }
            if (event.target.id === 'drawer-backdrop') closeDrawer();
        });

        document.addEventListener('change', (event) => {
            const toggle = event.target.closest('[data-act="toggle-target"]');
            if (toggle) handleAction('toggle-target', toggle);
        });

        const sidebar = $('#sidebar');
        if (sidebar) {
            const peek = () => {
                if (state.navCollapsed && !navPeekLock) sidebar.classList.add('peeking');
            };
            sidebar.addEventListener('pointermove', (event) => {
                if (event.pointerType === 'touch') return;
                if (event.target.closest('.nav, .sidebar-footer')) peek();
            });
            $$('.nav, .sidebar-footer', sidebar).forEach((zone) => zone.addEventListener('pointerenter', peek));
            sidebar.addEventListener('pointerleave', () => {
                navPeekLock = false;
                sidebar.classList.remove('peeking');
            });
        }

        // Warm a page's chunk as soon as its nav item is hovered, so the
        // first click usually renders without waiting on the network.
        $$('.nav-item[data-route]').forEach((link) => {
            link.addEventListener('pointerenter', () => {
                const name = link.dataset.route;
                if (name && name !== 'overview') loadChunk(name).catch(() => {});
            }, { once: true });
        });

        window.addEventListener('hashchange', () => route());
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && state.poll) pollRefresh();
        });

        window.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            if (!$('#modal-backdrop').hidden) closeModal();
            else if (!$('#drawer').hidden) closeDrawer();
            else closeNav();
        });
    }

    /* ---------------------------------------------------------------------
       Boot
       --------------------------------------------------------------------- */

    function boot() {
        applyNavState();
        startClock();
        applyBoot();
        wire();
        ensureChartObserver();
        updateSidebarHealth();
        refreshSummaryBadges();
        route(true);
        startPolling();

        if (state.overview) {
            hideBanner();
        } else {
            loadHealth().then(() => { updateSidebarHealth(); hideBanner(); });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
})();
