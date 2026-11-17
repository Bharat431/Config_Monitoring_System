/* Settings route — loaded on first visit to #/settings. */
(() => {
    'use strict';
    const CM = window.CM;
    const {
        BOOT, POLL_MS, KEY_STORE, state, e, escapeAttr, I,
        setView, healthRow,
    } = CM;

    async function renderSettings() {
        const key = state.apiKey || localStorage.getItem(KEY_STORE) || '';
        setView(`
            <div class="page-intro">
                <div>
                    <h2>Settings &amp; demo tools</h2>
                    <p>Runtime configuration is environment driven. Demo actions are gated by <code>CONFIGMON_DEMO_MODE</code>.</p>
                </div>
            </div>

            <div class="grid-2">
                <div class="card">
                    <div class="card-head"><h3>API access</h3></div>
                    <div class="card-body stack">
                        <p class="muted small">When <code>CONFIGMON_API_KEY</code> is set, the management API requires <code>X-API-Key</code> or a bearer token. The key is kept in this browser only.</p>
                        <div class="field"><label for="settings-key">API key</label><input class="input" id="settings-key" type="password" autocomplete="off" placeholder="not set" value="${escapeAttr(key)}"></div>
                        <div class="row">
                            <button class="btn btn-primary" type="button" data-act="save-key">${I.check} Save key</button>
                            <button class="btn btn-ghost" type="button" data-act="clear-key">Clear</button>
                        </div>
                        <div class="detail"><span>Current state</span><strong>${BOOT.apiKeyRequired ? 'API key required by server' : 'API is open (development)'}</strong></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head"><h3>Runtime</h3></div>
                    <div class="card-body">
                        <div class="health-list">
                            ${healthRow('Version', BOOT.version || '1.0.0')}
                            ${healthRow('Demo mode', BOOT.demoMode ? 'enabled' : 'disabled', BOOT.demoMode ? 'good' : '')}
                            ${healthRow('Polling', `${Math.round(POLL_MS / 1000)}s`, 'good')}
                            ${healthRow('Watcher shard', `${(BOOT.shards?.shard || 1)} / ${(BOOT.shards?.shards || 1)}`)}
                            ${healthRow('Watch roots', (BOOT.watchRoots || []).join(', ') || '—')}
                        </div>
                    </div>
                </div>
            </div>

            ${BOOT.demoMode ? `<div class="card">
                <div class="card-head"><h3>Demo workspace</h3><span class="card-sub">writes to <code>${e((BOOT.watchRoots || [])[0] || 'writable/sample-configs')}</code></span></div>
                <div class="card-body stack">
                    <p class="muted small">Seed representative .env, JSON, YAML and .properties files, register them with the watcher and create a local webhook target. Then simulate updates, type changes, deletions and parse errors to exercise the pipeline.</p>
                    <div class="row" style="flex-wrap:wrap">
                        <button class="btn btn-primary" type="button" data-act="seed">${I.plus} Seed demo workspace</button>
                        <button class="btn" type="button" data-act="seed-reset">${I.refresh} Reset sample files</button>
                    </div>
                    <div class="grid-3" style="gap:10px">
                        <div class="field"><label>File</label><select class="select" id="demo-file">
                            ${(state.overview?.files?.total ? [] : ['.env', 'app.json', 'services.yaml', 'application.properties']).map((file) => `<option>${e(file)}</option>`).join('')}
                        </select></div>
                        <div class="field"><label>Change kind</label><select class="select" id="demo-kind">
                            <option value="update">update</option>
                            <option value="type_change">type change</option>
                            <option value="create">delete + recreate</option>
                            <option value="delete">delete</option>
                            <option value="parse_error">parse error</option>
                        </select></div>
                        <div class="field"><label>&nbsp;</label><button class="btn" type="button" data-act="demo-change-form">${I.play} Simulate</button></div>
                    </div>
                </div>
            </div>` : `<div class="card"><div class="card-head"><h3>Demo mode disabled</h3></div><div class="card-body"><p class="muted small">Set <code>CONFIGMON_DEMO_MODE=true</code> in <code>.env</code> to enable the in-app demo seed and change simulator.</p></div></div>`}

            <div class="card">
                <div class="card-head"><h3>API quick reference</h3></div>
                <div class="card-body stack">
                    ${apiLine('GET', '/health', 'Liveness + dependency status')}
                    ${apiLine('GET', '/api/files', 'List monitored files')}
                    ${apiLine('POST', '/api/watch', 'Register a file (JSON: path, debounce_ms, baseline)')}
                    ${apiLine('DELETE', '/api/watch/:id', 'Stop watching a file')}
                    ${apiLine('GET', '/api/events', 'Paginated change events (?type=&file=&page=)')}
                    ${apiLine('POST', '/api/events/:id/replay', 'Re-deliver an event')}
                    ${apiLine('GET', '/api/deliveries', 'Delivery queue rows')}
                    ${apiLine('GET', '/api/dlq', 'Unresolved dead letters')}
                    ${apiLine('POST', '/api/dlq/:id/retry', 'Requeue a dead letter')}
                    ${apiLine('GET', '/api/metrics', 'Prometheus exposition')}
                </div>
            </div>
        `);
    }

    function apiLine(method, path, description) {
        const tone = { GET: 'info', POST: 'good', PATCH: 'warn', DELETE: 'bad' }[method] || 'plain';
        return `<div class="row" style="align-items:flex-start;gap:10px">
            <span class="badge ${tone} plain" style="min-width:60px;justify-content:center">${method}</span>
            <div style="min-width:0"><div class="mono small">${e(path)}</div><div class="faint small">${e(description)}</div></div>
        </div>`;
    }

    CM.routes.settings = { render: renderSettings };
})();
