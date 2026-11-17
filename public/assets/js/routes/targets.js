/* Notification targets route — loaded on first visit to #/targets. */
(() => {
    'use strict';
    const CM = window.CM;
    const {
        BOOT, $, e, escapeAttr, timeAgo, statusBadge, emptyState, I,
        setView, api, toast, confirmDialog, openModal, closeModal,
    } = CM;

    async function renderTargets(silent = false) {
        const response = await api('/api/targets');
        const targets = response.data || [];
        targetCache = targets;

        const cards = targets.length
            ? `<div class="target-grid">${targets.map(targetCard).join('')}</div>`
            : emptyState('No notification targets', 'Add a webhook, Slack, Discord or Teams endpoint to receive change events.', I.target);

        setView(`
            <div class="page-intro">
                <div>
                    <h2>Notification targets</h2>
                    <p>Webhooks are signed with HMAC-SHA256 and protected by an SSRF guard. Slack, Discord and Teams payloads are mapped automatically.</p>
                </div>
                <div class="row">
                    <button class="btn btn-primary" type="button" data-act="add-target">${I.plus} Add target</button>
                </div>
            </div>
            ${BOOT.demoMode ? `<div class="toolbar"><div class="toolbar-group"><span class="badge violet">demo mode</span><span class="small muted">A local sink target is created automatically when you seed the demo.</span></div><span class="spacer"></span><button class="btn btn-ghost btn-sm" type="button" data-act="seed">${I.plus} Seed demo target</button></div>` : ''}
            ${cards}
        `);
    }

    function targetCard(target) {
        const circuit = target.circuit || {};
        const circuitClass = circuit.state === 'open' ? 'bad' : circuit.state === 'half_open' ? 'warn' : 'good';
        const lastTest = target.last_test_ok === null || target.last_test_ok === undefined
            ? 'never tested'
            : `last test ${target.last_test_ok ? 'ok' : 'failed'} · ${timeAgo(target.last_test_at)}`;

        return `<div class="card target-card">
            <div class="target-top">
                <span class="target-type">${e((target.type || '?').slice(0, 4))}</span>
                <div class="grow" style="min-width:0">
                    <div class="target-name">${e(target.name)}</div>
                    <div class="target-url" title="${escapeAttr(target.url)}">${e(target.url)}</div>
                </div>
                ${statusBadge(target.enabled ? 'enabled' : 'disabled')}
            </div>
            <div class="target-meta">
                <span class="badge ${circuitClass}">circuit ${e(circuit.state)}</span>
                <span class="badge plain">${e(lastTest)}</span>
                ${target.has_secret ? '<span class="badge good plain">HMAC secret</span>' : ''}
                ${target.has_token ? '<span class="badge info plain">bearer token</span>' : ''}
            </div>
            <div class="row">
                <label class="switch"><input type="checkbox" data-act="toggle-target" data-id="${target.id}" ${target.enabled ? 'checked' : ''}><span class="track"></span><span class="switch-label">Enabled</span></label>
            </div>
            <div class="target-actions">
                <button class="btn btn-ghost btn-sm" type="button" data-act="test-target" data-id="${target.id}">${I.send} Test</button>
                <button class="btn btn-ghost btn-sm" type="button" data-act="edit-target" data-id="${target.id}">${I.eye} Edit</button>
                <span class="spacer"></span>
                <button class="btn btn-ghost btn-sm" type="button" data-act="delete-target" data-id="${target.id}" data-name="${escapeAttr(target.name)}">${I.trash}</button>
            </div>
        </div>`;
    }

    let targetCache = [];

    function openTargetModal(target = null) {
        const isEdit = !!target;
        openModal({
            title: isEdit ? 'Edit target' : 'Add notification target',
            eyebrow: 'Delivery',
            wide: true,
            body: `
                <div class="grid-2" style="gap:12px">
                    <div class="field"><label for="target-name">Name</label><input class="input" id="target-name" placeholder="Production webhook" value="${escapeAttr(target?.name || '')}"></div>
                    <div class="field"><label for="target-type">Type</label><select class="select" id="target-type">
                        ${['webhook', 'slack', 'discord', 'teams'].map((type) => `<option value="${type}" ${target?.type === type ? 'selected' : ''}>${type}</option>`).join('')}
                    </select></div>
                </div>
                <div class="field"><label for="target-url">Endpoint URL</label><input class="input" id="target-url" placeholder="https://hooks.example.com/config" value="${escapeAttr(target?.url || '')}">
                    <span class="hint">Private/loopback destinations require <code>CONFIGMON_ALLOW_PRIVATE_WEBHOOKS=true</code>.</span></div>
                <div class="grid-2" style="gap:12px">
                    <div class="field"><label for="target-secret">HMAC secret ${isEdit ? '(leave blank to keep)' : '(optional)'}</label><input class="input" id="target-secret" type="password" autocomplete="off" placeholder="${target?.has_secret ? '•••••••• (stored)' : 'signing secret'}"></div>
                    <div class="field"><label for="target-token">Bearer token ${isEdit ? '(leave blank to keep)' : '(optional)'}</label><input class="input" id="target-token" type="password" autocomplete="off" placeholder="${target?.has_token ? '•••••••• (stored)' : 'token'}"></div>
                </div>
                <div class="field"><label for="target-headers">Custom headers (JSON object, optional)</label>
                    <textarea class="textarea" id="target-headers" placeholder='{"X-Environment":"production"}'>${e(target?.headers && Object.keys(target.headers).length ? JSON.stringify(target.headers, null, 2) : '')}</textarea></div>
                <label class="switch"><input id="target-enabled" type="checkbox" ${target?.enabled !== false ? 'checked' : ''}><span class="track"></span><span class="switch-label">Enabled</span></label>
            `,
            foot: `<button class="btn btn-ghost" type="button" data-modal="cancel">Cancel</button>
                   <button class="btn btn-primary" type="button" data-modal="submit">${isEdit ? 'Save changes' : I.plus + ' Create target'}</button>`,
            onSubmit: async () => {
                const name = ($('#target-name')?.value || '').trim();
                const type = $('#target-type')?.value || 'webhook';
                const url = ($('#target-url')?.value || '').trim();
                if (!name || !url) { toast('Name and URL are required.', { type: 'warn' }); return; }

                const payload = { name, type, url, enabled: $('#target-enabled')?.checked !== false };
                const secret = ($('#target-secret')?.value || '').trim();
                const token = ($('#target-token')?.value || '').trim();
                const headersRaw = ($('#target-headers')?.value || '').trim();

                if (!isEdit || secret) payload.secret = secret;
                if (!isEdit || token) payload.token = token;
                if (headersRaw) {
                    try { payload.headers = JSON.parse(headersRaw); }
                    catch { toast('Custom headers must be valid JSON.', { type: 'warn' }); return; }
                } else if (isEdit) {
                    payload.headers = null;
                }

                try {
                    if (isEdit) {
                        await api(`/api/targets/${target.id}`, { method: 'PATCH', body: payload });
                    } else {
                        await api('/api/targets', { method: 'POST', body: payload });
                    }
                    closeModal({ cancelled: false });
                    toast(isEdit ? 'Target updated.' : 'Target created.', { type: 'good' });
                    renderTargets(true);
                } catch (error) {
                    toast(error.message, { type: 'bad' });
                }
            },
        });
    }

    CM.routes.targets = {
        render: renderTargets,
        actions: {
            'add-target': () => openTargetModal(),
            'edit-target': async (el) => {
                const id = el.dataset.id;
                try {
                    if (!targetCache.length) {
                        const response = await api('/api/targets');
                        targetCache = response.data || [];
                    }
                    const target = targetCache.find((t) => Number(t.id) === Number(id));
                    if (target) openTargetModal(target);
                } catch (error) { toast(error.message, { type: 'bad' }); }
            },
            'test-target': async (el) => {
                const id = el.dataset.id;
                toast('Sending a test delivery…', { type: 'info', timeout: 2000 });
                try {
                    const response = await api(`/api/targets/${id}/test`, { method: 'POST' });
                    toast(response.data.ok ? `Test delivered (HTTP ${response.data.status_code}, ${response.data.latency_ms}ms).` : `Test failed: ${response.data.error}`, { type: response.data.ok ? 'good' : 'bad', timeout: 7000 });
                    renderTargets(true);
                } catch (error) { toast(error.message, { type: 'bad' }); }
            },
            'delete-target': async (el) => {
                const id = el.dataset.id;
                const ok = await confirmDialog({
                    title: 'Delete target?',
                    message: `“${el.dataset.name || id}” will stop receiving events. Existing delivery history is retained.`,
                    confirmLabel: 'Delete target',
                    danger: true,
                });
                if (!ok) return;
                try {
                    await api(`/api/targets/${id}`, { method: 'DELETE' });
                    toast('Target deleted.', { type: 'good' });
                    targetCache = [];
                    renderTargets(true);
                } catch (error) { toast(error.message, { type: 'bad' }); }
            },
            'toggle-target': async (el) => {
                const id = el.dataset.id;
                try {
                    await api(`/api/targets/${id}`, { method: 'PATCH', body: { enabled: el.checked } });
                    toast(el.checked ? 'Target enabled.' : 'Target disabled.', { type: 'good', timeout: 2200 });
                    targetCache = [];
                    renderTargets(true);
                } catch (error) { el.checked = !el.checked; toast(error.message, { type: 'bad' }); }
            },
        },
    };
})();
