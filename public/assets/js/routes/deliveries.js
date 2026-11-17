/* Deliveries route — loaded on first visit to #/deliveries. */
(() => {
    'use strict';
    const CM = window.CM;
    const {
        state, $, e, escapeAttr, fmtNum, fmtDate, timeAgo,
        statusBadge, emptyState, pagination, I, setView, debounce, api,
    } = CM;

    async function renderDeliveries(silent = false) {
        const filters = state.pages.deliveries;
        const params = new URLSearchParams({ page: filters.page, per_page: filters.per_page });
        if (filters.status) params.set('status', filters.status);
        const response = await api(`/api/deliveries?${params.toString()}`);
        const rows = response.data || [];
        const meta = response.meta || {};

        const body = rows.length
            ? `<div class="table-wrap"><table class="data">
                <thead><tr><th>Queued</th><th>Event</th><th>Target</th><th>Status</th><th>Attempts</th><th>Latency</th><th>Last error</th><th></th></tr></thead>
                <tbody>${rows.map((d) => `
                    <tr>
                        <td class="nowrap" title="${escapeAttr(fmtDate(d.created_at, true))}">${e(timeAgo(d.created_at))}</td>
                        <td><div class="primary-cell"><strong>${e(d.file_name)}</strong><span>${e((d.event_id || '').slice(0, 13))}…</span></div></td>
                        <td><div class="primary-cell"><strong>${e(d.target_name)}</strong><span>${e(d.target_type)} · #${d.target_id}</span></div></td>
                        <td>${statusBadge(d.status)}</td>
                        <td class="num">${fmtNum(d.attempts)}${d.deferrals ? ` <span class="faint">(+${fmtNum(d.deferrals)} deferrals)</span>` : ''}</td>
                        <td class="num">${d.latency_ms === null || d.latency_ms === undefined ? '—' : `${fmtNum(d.latency_ms)} ms`}</td>
                        <td><span class="faint small truncate" title="${escapeAttr(d.last_error || '')}">${e(d.last_error || '—')}</span></td>
                        <td><div class="actions"><button class="btn btn-ghost btn-sm" type="button" data-act="event" data-id="${escapeAttr(d.event_id)}">${I.eye}</button></div></td>
                    </tr>`).join('')}</tbody>
            </table></div>${pagination(meta, 'deliveries')}`
            : emptyState('No deliveries', 'Deliveries appear after a change event is fanned out to an enabled target.', I.send);

        setView(`
            <div class="page-intro">
                <div>
                    <h2>Delivery log</h2>
                    <p>At-least-once delivery with exponential backoff, jitter and per-target circuit breakers.</p>
                </div>
                <div class="row">
                    <a class="btn" href="#/dlq">${I.alert} Dead letters</a>
                    <button class="btn btn-primary" type="button" data-act="refresh">${I.refresh} Refresh</button>
                </div>
            </div>

            <div class="toolbar">
                <div class="toolbar-group">
                    <div class="field"><label>Status</label><select class="select input-sm" id="delivery-status">
                        <option value="">All statuses</option>
                        ${['pending', 'processing', 'delivered', 'dead'].map((status) => `<option value="${status}" ${filters.status === status ? 'selected' : ''}>${status}</option>`).join('')}
                    </select></div>
                    <div class="field"><label>Per page</label><select class="select input-sm" id="delivery-per-page">
                        ${[10, 20, 50, 100].map((n) => `<option value="${n}" ${filters.per_page === n ? 'selected' : ''}>${n}</option>`).join('')}
                    </select></div>
                </div>
                <span class="spacer"></span>
                <span class="faint small">${fmtNum(meta.total || 0)} queue rows</span>
            </div>

            <div class="card">${body}</div>
        `);

        const status = $('#delivery-status');
        if (status) status.addEventListener('change', (event) => { filters.status = event.target.value; filters.page = 1; renderDeliveries(true); });
        const perPage = $('#delivery-per-page');
        if (perPage) perPage.addEventListener('change', (event) => { filters.per_page = Number(event.target.value); filters.page = 1; renderDeliveries(true); });
    }

    CM.routes.deliveries = { render: renderDeliveries };
})();
