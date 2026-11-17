/* Watched files route — loaded on first visit to #/files. */
(() => {
    'use strict';
    const CM = window.CM;
    const {
        BOOT, state, $, e, escapeAttr, fmtNum, shortHash, fmtDate, timeAgo,
        statusBadge, statusBadgeClass, typeBadge, emptyState, detail, I,
        setView, debounce, api, loadOverview, toast, openModal, closeModal,
        openDrawer, confirmDialog,
    } = CM;

    async function renderFiles(silent = false) {
        const response = await api('/api/files');
        state.files = response.data || [];

        const filters = state.pages.files;
        const term = filters.q.trim().toLowerCase();
        const list = state.files.filter((file) => {
            if (filters.status && file.status !== filters.status) return false;
            if (!term) return true;
            return `${file.display_name} ${file.path}`.toLowerCase().includes(term);
        });

        const cards = list.length
            ? `<div class="file-grid">${list.map(fileCard).join('')}</div>`
            : emptyState('No matching files', term || filters.status ? 'Adjust the filters or add a new watch.' : 'Add a file path to start monitoring configuration changes.', I.file);

        setView(`
            <div class="page-intro">
                <div>
                    <h2>${fmtNum(state.files.length)} monitored ${state.files.length === 1 ? 'file' : 'files'}</h2>
                    <p>Hash-based detection with debounce, snapshot retention and parse-error recovery.</p>
                </div>
                <div class="row">
                    ${BOOT.demoMode ? `<button class="btn" type="button" data-act="seed">${I.plus} Seed samples</button>` : ''}
                    <button class="btn btn-primary" type="button" data-act="add-watch">${I.plus} Add watch</button>
                </div>
            </div>

            <div class="toolbar">
                <div class="toolbar-group">
                    <input class="input input-sm" id="file-search" type="search" placeholder="Search name or path…" value="${escapeAttr(filters.q)}" style="min-width:240px">
                    <select class="select input-sm" id="file-status">
                        <option value="">All statuses</option>
                        ${['active', 'pending', 'paused', 'error', 'missing', 'deleted'].map((status) => `<option value="${status}" ${filters.status === status ? 'selected' : ''}>${status}</option>`).join('')}
                    </select>
                </div>
                <span class="spacer"></span>
                <span class="faint small">${fmtNum(list.length)} shown</span>
                <button class="btn btn-ghost btn-sm" type="button" data-act="refresh">${I.refresh} Refresh</button>
            </div>

            ${cards}
        `);

        const search = $('#file-search');
        if (search) search.addEventListener('input', debounce((event) => { filters.q = event.target.value; renderFiles(true); }, 150));
        const status = $('#file-status');
        if (status) status.addEventListener('change', (event) => { filters.status = event.target.value; renderFiles(true); });
    }

    function fileCard(file) {
        const statusClass = statusBadgeClass(file.status);
        const isEnv = file.extension === 'env';
        const ext = isEnv ? '.env' : (file.extension || 'file').toUpperCase();
        const pending = file.pending ? '<span class="badge info plain">debouncing</span>' : '';
        const errorLine = file.last_error
            ? `<div class="small" style="color:var(--bad);word-break:break-word">${e(file.last_error)}</div>`
            : '';

        return `<div class="card file-card">
            <div class="file-head">
                <span class="file-icon">${e(ext)}</span>
                <div class="grow" style="min-width:0">
                    <div class="file-name">${e(file.display_name)}</div>
                    <div class="file-path" title="${escapeAttr(file.path)}">${e(file.path)}</div>
                </div>
                ${statusBadge(file.status)}
            </div>
            <div class="file-meta">
                <span class="badge plain">${e(file.enabled ? 'enabled' : 'paused')}</span>
                <span class="badge plain">${e(file.parse_ok ? 'parse ok' : 'parse error')}</span>
                <span class="badge plain">debounce ${fmtNum(file.debounce_ms)}ms</span>
                ${pending}
            </div>
            ${errorLine}
            <div class="small faint row" style="justify-content:space-between">
                <span>hash <span class="mono">${e(shortHash(file.hash))}</span></span>
                <span title="${escapeAttr(fmtDate(file.last_changed_at, true))}">changed ${e(timeAgo(file.last_changed_at))}</span>
            </div>
            <div class="file-foot">
                <button class="btn btn-ghost btn-sm" type="button" data-act="file-detail" data-id="${file.id}">${I.eye} Details</button>
                <button class="btn btn-ghost btn-sm" type="button" data-act="scan-file" data-id="${file.id}">${I.refresh} Scan</button>
                <span class="spacer"></span>
                <button class="btn btn-ghost btn-sm" type="button" data-act="toggle-file" data-id="${file.id}" data-enabled="${file.enabled ? '1' : '0'}">${file.enabled ? I.pause : I.play}</button>
                <button class="btn btn-ghost btn-sm" type="button" data-act="remove-file" data-id="${file.id}" data-name="${escapeAttr(file.display_name)}">${I.trash}</button>
            </div>
        </div>`;
    }

    function openAddWatchModal() {
        openModal({
            title: 'Add a file to watch',
            eyebrow: 'Watcher',
            body: `<div class="field"><label for="watch-path">File path</label>
                    <input class="input" id="watch-path" placeholder="${escapeAttr((BOOT.watchRoots || [])[0] || '/path/to/config')}/app.json">
                    <span class="hint">Paths must be inside an allowed watch root. Relative paths resolve against the first root.</span></div>
                   <div class="field"><label for="watch-debounce">Debounce (ms)</label>
                    <input class="input" id="watch-debounce" type="number" min="0" max="60000" value="750"></div>
                   <label class="switch"><input id="watch-baseline" type="checkbox" checked><span class="track"></span><span class="switch-label">Capture a baseline snapshot immediately</span></label>`,
            foot: `<button class="btn btn-ghost" type="button" data-modal="cancel">Cancel</button>
                   <button class="btn btn-primary" type="button" data-modal="submit">${I.plus} Add watch</button>`,
            onSubmit: async () => {
                const path = ($('#watch-path')?.value || '').trim();
                if (!path) { toast('Enter a file path.', { type: 'warn' }); return; }
                try {
                    const result = await api('/api/watch', {
                        method: 'POST',
                        body: {
                            path,
                            debounce_ms: Number($('#watch-debounce')?.value || 750),
                            baseline: $('#watch-baseline')?.checked !== false,
                        },
                    });
                    closeModal({ cancelled: false });
                    toast(`Watching ${result.data.file.display_name}`, { type: 'good' });
                    renderFiles(true);
                    loadOverview(true).catch(() => {});
                } catch (error) {
                    toast(error.message, { type: 'bad' });
                }
            },
        });
    }

    async function openFileDetail(id) {
        openDrawer({ title: 'File details', eyebrow: 'Watched file', html: `<div class="page-loader"><span class="spinner"></span></div>` });
        try {
            const response = await api(`/api/files/${id}`);
            const { file, snapshot, recent_events: events } = response.data;
            const eventRows = (events || []).length
                ? `<div class="timeline">${events.map((ev) => `
                    <div class="timeline-item ${ev.change_type === 'deleted' || ev.change_type === 'parse_error' ? 'bad' : 'good'}">
                        <div class="t">${typeBadge(ev.change_type)} ${e(ev.summary.added)} added · ${e(ev.summary.updated)} updated · ${e(ev.summary.removed)} removed</div>
                        <div class="s">${e(fmtDate(ev.created_at, true))} · ${e(ev.event_id)}</div>
                    </div>`).join('')}</div>`
                : `<p class="muted small">No events recorded for this file yet.</p>`;

            openDrawer({
                title: file.display_name,
                eyebrow: 'Watched file',
                html: `
                    <div class="detail-grid">
                        ${detail('Status', statusBadge(file.status))}
                        ${detail('Enabled', file.enabled ? 'yes' : 'no')}
                        ${detail('Extension', file.extension)}
                        ${detail('Debounce', `${fmtNum(file.debounce_ms)} ms`)}
                        ${detail('Last changed', fmtDate(file.last_changed_at, true))}
                        ${detail('Last scan', fmtDate(file.last_scan_at, true))}
                        ${detail('SHA-256', `<span class="mono">${e(file.hash || '—')}</span>`)}
                        ${detail('Parse state', file.parse_ok ? 'ok' : 'error')}
                    </div>
                    ${file.last_error ? `<div class="detail" style="border-color:var(--bad)"><span>Last error</span><strong style="color:var(--bad)">${e(file.last_error)}</strong></div>` : ''}
                    <div class="detail"><span>Path</span><strong class="mono">${e(file.path)}</strong></div>
                    <div class="card">
                        <div class="card-head"><h3>Snapshot</h3></div>
                        <div class="card-body">${snapshot ? `<div class="detail-grid">
                            ${detail('Version', snapshot.version)}
                            ${detail('Leaves', snapshot.leaf_count)}
                            ${detail('Size', `${fmtNum(snapshot.size)} bytes`)}
                            ${detail('Captured', fmtDate(snapshot.captured_at, true))}
                        </div>` : '<p class="muted small">No snapshot captured yet.</p>'}</div>
                    </div>
                    <div class="card">
                        <div class="card-head"><h3>Recent events</h3><span class="spacer"></span><span class="badge plain">${events?.length || 0}</span></div>
                        <div class="card-body">${eventRows}</div>
                    </div>
                `,
            });
        } catch (error) {
            openDrawer({ title: 'File details', eyebrow: 'Watched file', html: `<p style="color:var(--bad)">${e(error.message)}</p>` });
        }
    }

    CM.routes.files = {
        render: renderFiles,
        actions: {
            'add-watch': () => openAddWatchModal(),
            'file-detail': (el) => openFileDetail(el.dataset.id),
            'scan-file': async (el) => {
                const id = el.dataset.id;
                try {
                    const response = await api('/api/scan', { method: 'POST', body: { file_id: Number(id), force: true } });
                    const outcome = response.data.result.outcome || 'scanned';
                    toast(`Scan finished: ${outcome}.`, { type: ['created', 'modified', 'deleted'].includes(outcome) ? 'good' : 'info' });
                    renderFiles(true);
                    CM.loadOverview(true).catch(() => {});
                } catch (error) { toast(error.message, { type: 'bad' }); }
            },
            'toggle-file': async (el) => {
                const id = el.dataset.id;
                const enabled = el.dataset.enabled === '1';
                try {
                    await api(`/api/files/${id}`, { method: 'PATCH', body: { enabled: !enabled } });
                    toast(enabled ? 'Watcher paused.' : 'Watcher resumed.', { type: 'good' });
                    renderFiles(true);
                } catch (error) { toast(error.message, { type: 'bad' }); }
            },
            'remove-file': async (el) => {
                const id = el.dataset.id;
                const ok = await confirmDialog({
                    title: 'Stop watching this file?',
                    message: `Snapshots for “${el.dataset.name || id}” will be deleted. Existing events are retained.`,
                    confirmLabel: 'Stop watching',
                    danger: true,
                });
                if (!ok) return;
                try {
                    await api(`/api/watch/${id}`, { method: 'DELETE' });
                    toast('File removed from the watch list.', { type: 'good' });
                    CM.closeDrawer();
                    renderFiles(true);
                    CM.loadOverview(true).catch(() => {});
                } catch (error) { toast(error.message, { type: 'bad' }); }
            },
        },
    };
})();
