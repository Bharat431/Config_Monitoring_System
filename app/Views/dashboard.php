<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="dark light">
    <meta name="theme-color" content="#edf2fb">
    <title>Config Monitor · Configuration change observability</title>
    <meta name="description" content="Real-time configuration change monitoring with reliable webhook delivery.">
    <link rel="icon" href="<?= esc($assetBase) ?>favicon.ico">
    <link rel="stylesheet" href="<?= esc($assetBase) ?>assets/css/dashboard.css">
</head>
<body>
<div class="shell" id="app">
    <div class="sidebar-backdrop" data-close-nav hidden></div>

    <aside class="sidebar" id="sidebar" aria-label="Primary navigation">
        <div class="sidebar-head">
            <a class="brand" href="#/overview" aria-label="Config Monitor home">
                <span class="brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 32 32" fill="none">
                        <defs>
                            <linearGradient id="cm-brand-grad" x1="4" y1="4" x2="28" y2="29" gradientUnits="userSpaceOnUse">
                                <stop stop-color="#ff6a8f"/>
                                <stop offset=".52" stop-color="#d10f3f"/>
                                <stop offset="1" stop-color="#4f8bff"/>
                            </linearGradient>
                        </defs>
                        <rect x="3.6" y="3.6" width="24.8" height="24.8" rx="8.2" stroke="url(#cm-brand-grad)" stroke-width="1.9"/>
                        <path d="M8.6 18.7l3.3-7 3.1 8.1 3-5.4 1.6 3.1h3.8" stroke="url(#cm-brand-grad)" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>
                        <circle cx="23.9" cy="8.4" r="1.9" fill="#ff8fa9"/>
                    </svg>
                </span>
                <span class="brand-copy">
                    <span class="brand-name">Config<span>Monitor</span></span>
                    <span class="brand-sub">change observability</span>
                </span>
            </a>
            <button class="icon-btn nav-toggle" type="button" data-action="toggle-nav" aria-expanded="true" aria-controls="sidebar" aria-label="Collapse navigation" title="Collapse navigation">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <rect class="toggle-rail" x="4.5" y="5.4" width="4.1" height="13.2" rx="1.8" fill="currentColor" opacity=".2"/>
                    <rect x="3.2" y="4.2" width="17.6" height="15.6" rx="4.2" stroke="currentColor" stroke-width="1.6"/>
                    <path d="M9.5 4.7v14.6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                    <path class="toggle-chevron" d="M14.7 9.5 12.2 12l2.5 2.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
        </div>

        <nav class="nav" id="nav">
            <span class="nav-label">Monitor</span>
            <a href="#/overview" data-route="overview" class="nav-item" data-tip="Overview" aria-label="Overview">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3.6" y="3.6" width="7" height="7" rx="2.2" stroke="currentColor" stroke-width="1.6"/><rect x="13.4" y="3.6" width="7" height="7" rx="2.2" stroke="currentColor" stroke-width="1.6"/><rect x="3.6" y="13.4" width="7" height="7" rx="2.2" stroke="currentColor" stroke-width="1.6"/><rect x="13.4" y="13.4" width="7" height="7" rx="2.2" stroke="currentColor" stroke-width="1.6"/></svg>
                <span>Overview</span>
            </a>
            <a href="#/files" data-route="files" class="nav-item" data-tip="Watched files" aria-label="Watched files">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M13.6 3.4H7.4A2.4 2.4 0 0 0 5 5.8v12.4a2.4 2.4 0 0 0 2.4 2.4h9.2a2.4 2.4 0 0 0 2.4-2.4V8.6l-5.4-5.2Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M13.6 3.4v5.2H19M9 13.2h5.6M9 16.8h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>Watched files</span>
                <span class="nav-badge" data-count="files"></span>
            </a>
            <a href="#/events" data-route="events" class="nav-item" data-tip="Change events" aria-label="Change events">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="8.6" stroke="currentColor" stroke-width="1.6"/><path d="M7 12.4h2.2l1.6-3.9 2.3 7 1.5-3.1H17" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>Change events</span>
                <span class="nav-dot" data-live="events" title="live"></span>
            </a>
            <a href="#/deliveries" data-route="deliveries" class="nav-item" data-tip="Deliveries" aria-label="Deliveries">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20.6 4.1 3.9 10.4c-.6.2-.6 1 .1 1.2l5.9 1.9 1.9 5.9c.2.7 1 .7 1.2.1l6.3-16.7c.2-.6-.4-1-.7-.7Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="m9.9 13.5 5.1-5.1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                <span>Deliveries</span>
            </a>
            <a href="#/dlq" data-route="dlq" class="nav-item" data-tip="Dead letters" aria-label="Dead letters">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3.8c.5 0 1 .3 1.3.8l7.4 13.3c.6 1-.2 2.3-1.4 2.3H5.3c-1.2 0-2-1.3-1.4-2.3l7.4-13.3c.3-.5.8-.8 1.3-.8Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M12 9v4.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="16.6" r="1.05" fill="currentColor"/></svg>
                <span>Dead letters</span>
                <span class="nav-badge warn" data-count="dlq"></span>
            </a>

            <span class="nav-label">Configure</span>
            <a href="#/targets" data-route="targets" class="nav-item" data-tip="Notification targets" aria-label="Notification targets">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="7.6" stroke="currentColor" stroke-width="1.5" stroke-dasharray="2.4 3.2"/><circle cx="12" cy="12" r="3.5" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="12" r="1.15" fill="currentColor"/></svg>
                <span>Notification targets</span>
            </a>
            <a href="#/settings" data-route="settings" class="nav-item" data-tip="Settings &amp; demo" aria-label="Settings and demo">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7.6h8.4M17.2 7.6H20M4 16.4h2.8M11.6 16.4H20" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="14.8" cy="7.6" r="2.6" stroke="currentColor" stroke-width="1.6"/><circle cx="9.2" cy="16.4" r="2.6" stroke="currentColor" stroke-width="1.6"/></svg>
                <span>Settings &amp; demo</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="status-card" id="sidebar-health">
                <span class="status-dot" data-health-dot></span>
                <div>
                    <strong data-health-label>Connecting…</strong>
                    <span data-health-detail>system health</span>
                </div>
            </div>
            <div class="sidebar-meta">
                <span>v<span id="app-version">1.0.0</span></span>
                <span id="sidebar-clock"></span>
            </div>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <button class="icon-btn menu-btn" type="button" data-open-nav aria-label="Open navigation">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4.5 7.5h15M4.5 12h10.5M4.5 16.5h15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </button>

            <div class="topbar-title">
                <div class="breadcrumbs"><span>Workspace</span><span class="sep">/</span><span id="crumb">Overview</span></div>
                <h1 id="page-title">Overview</h1>
            </div>

            <div class="topbar-actions">
                <span class="live-pill" id="live-pill" data-state="on">
                    <span class="live-dot"></span><span id="live-text">Live</span>
                </span>
                <button class="icon-btn" type="button" data-action="toggle-poll" title="Pause live updates" aria-label="Toggle live updates">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="8.6" stroke="currentColor" stroke-width="1.5" opacity=".45"/><path d="M9.6 9.2v5.6M14.4 9.2v5.6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                </button>
                <button class="icon-btn" type="button" data-action="refresh" title="Refresh now" aria-label="Refresh now">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M19.4 12a7.4 7.4 0 1 1-2.2-5.3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M19.6 4.4v4.8h-4.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/></svg>
                </button>
                <button class="btn btn-primary demo-only" type="button" data-action="demo-change" hidden>
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M13.2 2.6 5.1 13.4c-.4.5 0 1.2.6 1.2h4.5l-1 6.2c-.1.7.7 1.1 1.2.6l8.1-10.8c.4-.5 0-1.2-.6-1.2h-4.5l1-6.2c.1-.7-.7-1.1-1.2-.6Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                    Simulate change
                </button>
            </div>
        </header>

        <div class="degraded-banner" id="degraded-banner" hidden></div>

        <section class="view" id="view" aria-live="polite">
            <div class="page-loader" id="page-loader">
                <span class="spinner"></span>
                <span>Loading workspace…</span>
            </div>
        </section>
    </main>
</div>

<div class="drawer-backdrop" id="drawer-backdrop" hidden data-close-drawer></div>
<aside class="drawer" id="drawer" hidden aria-modal="true" role="dialog" aria-label="Details">
    <div class="drawer-head">
        <div>
            <span class="eyebrow" id="drawer-eyebrow">Details</span>
            <h2 id="drawer-title">—</h2>
        </div>
        <button class="icon-btn" type="button" data-close-drawer aria-label="Close details">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
        </button>
    </div>
    <div class="drawer-body" id="drawer-body"></div>
</aside>

<div class="modal-backdrop" id="modal-backdrop" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <div class="modal-head">
            <div>
                <span class="eyebrow" id="modal-eyebrow">Action</span>
                <h2 id="modal-title">—</h2>
            </div>
            <button class="icon-btn" type="button" data-close-modal aria-label="Close dialog">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </button>
        </div>
        <div class="modal-body" id="modal-body"></div>
        <div class="modal-foot" id="modal-foot"></div>
    </div>
</div>

<div class="toasts" id="toasts" aria-live="assertive" aria-atomic="false"></div>

<noscript>
    <div class="noscript">The dashboard needs JavaScript. The REST API remains available at <code>/api/*</code>.</div>
</noscript>

<script>
    window.__CM_BOOT__ = <?= $bootJson ?>;
    window.__CM_INITIAL__ = <?= $initialJson ?>;
</script>
<script src="<?= esc($assetBase) ?>assets/js/dashboard.js" defer></script>
</body>
</html>
