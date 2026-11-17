/* Change events route — loaded on first visit to #/events. */
(() => {
    'use strict';
    const CM = window.CM;
    const {
        state, $, e, escapeAttr, fmtNum, fmtDate, fmtClock, timeAgo,
        typeBadge, statusBadge, emptyState, detail, pagination, I,
        setView, debounce, api, openDrawer,
    } = CM;

    async function renderEvents(silent = false) {
        const filters = state.pages.events;
        const params = new URLSearchParams({ page: filters.page, per_page: filters.per_page });
        if (filters.file) params.set('file', filters.file);
        if (filters.type) params.set('type', filters.type);

        const response = await api(`/api/events?${params.toString()}`);
        const rows = response.data || [];
        const meta = response.meta || {};

        const body = rows.length
            ? `<div class="table-wrap"><table class="data">
                <thead><tr><th>Time</th><th>File</th><th>Type</th><th>Changes</th><th>Trace</th><th>Replays</th><th></th></tr></thead>
                <tbody>${rows.map((ev) => `
                    <tr>
                        <td class="nowrap" title="${escapeAttr(fmtDate(ev.created_at, true))}">
                            <div class="primary-cell"><strong>${e(fmtClock(ev.created_at))}</strong><span>${e(fmtDate(ev.created_at))}</span></div>
                        </td>
                        <td><div class="primary-cell"><strong>${e(ev.file_name)}</strong><span>${e((ev.event_id || '').slice(0, 13))}…</span></div></td>
                        <td>${typeBadge(ev.change_type)}</td>
                        <td class="num"><span class="badge plain">+${fmtNum(ev.summary.added)}</span> <span class="badge plain">~${fmtNum(ev.summary.updated)}</span> <span class="badge plain">−${fmtNum(ev.summary.removed)}</span> <span class="badge plain">!${fmtNum(ev.summary.type_changes)}</span></td>
                        <td><span class="mono faint">${e((ev.trace_id || '').slice(0, 10) || '—')}</span></td>
                        <td class="num">${fmtNum(ev.replay_count)}</td>
                        <td><div class="actions">
                            <button class="btn btn-ghost btn-sm" type="button" data-act="event" data-id="${escapeAttr(ev.event_id)}">${I.eye} View</button>
                            <button class="btn btn-ghost btn-sm" type="button" data-act="replay-event" data-id="${escapeAttr(ev.event_id)}">${I.play}</button>
                        </div></td>
                    </tr>`).join('')}</tbody>
            </table></div>${pagination(meta, 'events')}`
            : emptyState('No events match', 'Try clearing the filters or trigger a change from the demo tools.', I.pulse);

        setView(`
            <div class="page-intro">
                <div>
                    <h2>Change events</h2>
                    <p>Every detected change is persisted with a canonical payload and fanned out to enabled targets.</p>
                </div>
                <div class="row">
                    <a class="btn" href="#/deliveries">${I.send} Deliveries</a>
                    <button class="btn btn-primary" type="button" data-act="refresh">${I.refresh} Refresh</button>
                </div>
            </div>

            <div class="toolbar">
                <div class="toolbar-group">
                    <div class="field"><label>File</label><input class="input input-sm" id="event-file" type="search" placeholder="filename contains…" value="${escapeAttr(filters.file)}" style="min-width:200px"></div>
                    <div class="field"><label>Type</label><select class="select input-sm" id="event-type">
                        <option value="">All types</option>
                        ${['created', 'modified', 'deleted', 'parse_error'].map((type) => `<option value="${type}" ${filters.type === type ? 'selected' : ''}>${type}</option>`).join('')}
                    </select></div>
                    <div class="field"><label>Per page</label><select class="select input-sm" id="event-per-page">
                        ${[10, 20, 50, 100].map((n) => `<option value="${n}" ${filters.per_page === n ? 'selected' : ''}>${n}</option>`).join('')}
                    </select></div>
                </div>
                <span class="spacer"></span>
                <span class="faint small">${fmtNum(meta.total || 0)} events</span>
            </div>

            <div class="card">${body}</div>
        `);

        const fileInput = $('#event-file');
        if (fileInput) fileInput.addEventListener('input', debounce((event) => { filters.file = event.target.value; filters.page = 1; renderEvents(true); }, 250));
        const typeInput = $('#event-type');
        if (typeInput) typeInput.addEventListener('change', (event) => { filters.type = event.target.value; filters.page = 1; renderEvents(true); });
        const perPage = $('#event-per-page');
        if (perPage) perPage.addEventListener('change', (event) => { filters.per_page = Number(event.target.value); filters.page = 1; renderEvents(true); });
    }

    async function openEventDetail(eventId) {
        openDrawer({ title: 'Change event', eyebrow: 'Event detail', html: `<div class="page-loader"><span class="spinner"></span></div>` });
        try {
            const response = await api(`/api/events/${encodeURIComponent(eventId)}`);
            const { event, deliveries, attempts } = response.data;
            const payload = event.payload || {};
            const changes = payload.changes || {};
            const sections = [
                ['added', 'added', 'Added', I.plus],
                ['updated', 'updated', 'Updated', I.refresh],
                ['removed', 'removed', 'Removed', I.trash],
                ['type_changes', 'typechange', 'Type changes', I.alert],
            ].map(([key, cls, label, icon]) => {
                const items = changes[key] || {};
                const rows = Object.entries(items);
                return `<div class="diff-block">
                    <div class="diff-head">${label} · ${fmtNum(rows.length)}</div>
                    <div class="diff-body">${rows.length ? rows.map(([path, value]) => `
                        <div class="diff-row ${cls}">
                            <span class="k">${e(path)}</span>
                            <span class="v">${e(typeof value === 'object' ? JSON.stringify(value) : JSON.stringify(value) ?? 'null')}</span>
                        </div>`).join('') : `<div class="diff-row"><span class="k faint">No ${label.toLowerCase()}</span><span></span></div>`}</div>
                </div>`;
            }).join('');

            const deliveryRows = (deliveries || []).length
                ? deliveries.map((d) => `<div class="timeline-item ${d.status === 'delivered' ? 'good' : d.status === 'dead' ? 'bad' : 'warn'}">
                    <div class="t">${statusBadge(d.status)} target #${d.target_id} · ${fmtNum(d.attempts)} attempt(s)</div>
                    <div class="s">latency ${d.latency_ms ?? '—'}ms · ${e(d.last_error || 'no error')}</div>
                </div>`).join('')
                : `<p class="muted small">No deliveries were queued (no enabled target at publish time).</p>`;

            const attemptRows = (attempts || []).length
                ? `<div class="timeline">${attempts.map((a) => `<div class="timeline-item ${a.outcome === 'success' ? 'good' : 'bad'}">
                    <div class="t">#${a.attempt} ${e(a.outcome)} · HTTP ${a.status_code ?? '—'} · ${a.latency_ms ?? '—'}ms</div>
                    <div class="s">${e(fmtDate(a.created_at, true))}${a.error ? ' · ' + e(a.error) : ''}</div>
                </div>`).join('')}</div>`
                : `<p class="muted small">No delivery attempts recorded.</p>`;

            openDrawer({
                title: event.file_name,
                eyebrow: `${event.change_type} · ${event.event_id}`,
                html: `
                    <div class="detail-grid">
                        ${detail('Type', typeBadge(event.change_type))}
                        ${detail('Detected', fmtDate(event.created_at, true))}
                        ${detail('Trace', `<span class="mono">${e(event.trace_id || '—')}</span>`)}
                        ${detail('Replays', fmtNum(event.replay_count))}
                        ${detail('Hash', `<span class="mono">${e(event.payload?.hash || '—')}</span>`)}
                        ${detail('Monitored file', `<span class="mono">#${e(event.payload?.meta?.monitored_file_id ?? '—')}</span>`)}
                    </div>
                    ${event.error ? `<div class="detail" style="border-color:var(--bad)"><span>Error</span><strong style="color:var(--bad)">${e(event.error)}</strong></div>` : ''}
                    <div class="detail"><span>Path</span><strong class="mono">${e(event.file_path)}</strong></div>
                    <div class="row">
                        <button class="btn btn-primary btn-sm" type="button" data-act="replay-event" data-id="${escapeAttr(event.event_id)}">${I.play} Replay to enabled targets</button>
                        <button class="btn btn-ghost btn-sm" type="button" data-act="event-json" data-id="${escapeAttr(event.event_id)}">${I.file} Raw payload</button>
                    </div>
                    <h3 style="font-size:13px;margin-top:4px">Structural changes</h3>
                    ${sections}
                    <div class="card">
                        <div class="card-head"><h3>Delivery state</h3><span class="spacer"></span><span class="badge plain">${deliveries?.length || 0}</span></div>
                        <div class="card-body"><div class="timeline">${deliveryRows}</div></div>
                    </div>
                    <div class="card">
                        <div class="card-head"><h3>Attempts</h3><span class="spacer"></span><span class="badge plain">${attempts?.length || 0}</span></div>
                        <div class="card-body">${attemptRows}</div>
                    </div>
                    <details class="detail"><summary class="muted small" style="cursor:pointer">Canonical webhook payload</summary>
                        <pre class="code" style="margin-top:10px">${e(JSON.stringify(payload, null, 2))}</pre>
                    </details>
                `,
            });
        } catch (error) {
            openDrawer({ title: 'Change event', eyebrow: 'Event detail', html: `<p style="color:var(--bad)">${e(error.message)}</p>` });
        }
    }

    CM.routes.events = {
        render: renderEvents,
        actions: {
            'event': (el) => openEventDetail(el.dataset.id),
        },
    };
})();
