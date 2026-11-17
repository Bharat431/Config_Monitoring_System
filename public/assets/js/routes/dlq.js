/* Dead-letter queue route — loaded on first visit to #/dlq. */
(() => {
    'use strict';
    const CM = window.CM;
    const {
        state, e, escapeAttr, fmtNum, fmtDate, timeAgo,
        emptyState, pagination, I, setView, api, toast,
    } = CM;

    async function renderDlq(silent = false) {
        const filters = state.pages.dlq;
        const params = new URLSearchParams({ page: filters.page, per_page: filters.per_page });
        const response = await api(`/api/dlq?${params.toString()}`);
        const rows = response.data || [];
        const meta = response.meta || {};

        const body = rows.length
            ? `<div class="table-wrap"><table class="data">
                <thead><tr><th>Failed</th><th>Event</th><th>Reason</th><th>Attempts</th><th>Error</th><th></th></tr></thead>
                <tbody>${rows.map((d) => `
                    <tr>
                        <td class="nowrap" title="${escapeAttr(fmtDate(d.created_at, true))}">${e(timeAgo(d.created_at))}</td>
                        <td><div class="primary-cell"><strong>${e(d.event_id)}</strong><span>target #${d.target_id}</span></div></td>
                        <td><span class="badge bad plain">${e(d.reason)}</span></td>
                        <td class="num">${fmtNum(d.attempts)}</td>
                        <td><span class="faint small truncate" title="${escapeAttr(d.error || '')}">${e(d.error || '—')}</span></td>
                        <td><div class="actions">
                            <button class="btn btn-ghost btn-sm" type="button" data-act="event" data-id="${escapeAttr(d.event_id)}">${I.eye} Event</button>
                            <button class="btn btn-ghost btn-sm" type="button" data-act="retry-dlq" data-id="${d.id}">${I.play} Retry</button>
                        </div></td>
                    </tr>`).join('')}</tbody>
            </table></div>${pagination(meta, 'dlq')}`
            : emptyState('Dead-letter queue is empty', 'Failed deliveries land here after retries are exhausted. Retry them once the downstream recovers.', I.check);

        setView(`
            <div class="page-intro">
                <div>
                    <h2>Dead-letter queue</h2>
                    <p>${fmtNum(meta.total || 0)} unresolved ${Number(meta.total) === 1 ? 'delivery' : 'deliveries'} retained for manual replay.</p>
                </div>
                <div class="row">
                    <button class="btn btn-primary" type="button" data-act="refresh">${I.refresh} Refresh</button>
                </div>
            </div>
            <div class="card">${body}</div>
        `);
    }

    CM.routes.dlq = {
        render: renderDlq,
        actions: {
            'retry-dlq': async (el) => {
                try {
                    await api(`/api/dlq/${el.dataset.id}/retry`, { method: 'POST' });
                    toast('Dead letter re-queued.', { type: 'good' });
                    renderDlq(true);
                } catch (error) { toast(error.message, { type: 'bad' }); }
            },
        },
    };
})();
