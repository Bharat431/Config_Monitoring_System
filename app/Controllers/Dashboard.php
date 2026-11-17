<?php

namespace App\Controllers;

use App\Support\Time;
use Throwable;

class Dashboard extends BaseController
{
    public function index(): string
    {
        $config = service('monitorConfig');
        $base   = rtrim($this->request->getUri()->getBaseURL(), '/') . '/';

        $boot = [
            'baseUrl'        => $base,
            'apiKeyRequired' => (string) $config->apiKey !== '',
            'demoMode'       => (bool) $config->demoMode,
            'pollIntervalMs' => max(2000, (int) $config->pollIntervalMs * 2),
            'version'        => '1.0.0',
            'time'           => Time::isoNow(),
            'watchRoots'     => $config->watchRoots(),
            'shards'         => [
                'shard'  => $config->watcherShard,
                'shards' => $config->watcherShards,
            ],
        ];

        $initial = null;
        $health  = null;

        try {
            $initial = service('stats')->overview();
        } catch (Throwable $e) {
            service('jsonLogger')->error('Dashboard overview unavailable', [
                'component' => 'dashboard',
                'error'     => $e,
            ]);
        }

        try {
            $health = service('stats')->health();
        } catch (Throwable) {
            // The dashboard still renders; the UI shows a degraded banner.
        }

        $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

        return view('dashboard', [
            'boot'      => $boot,
            'bootJson'  => json_encode($boot, $jsonFlags) ?: '{}',
            'initialJson' => json_encode(['overview' => $initial, 'health' => $health], $jsonFlags) ?: '{}',
            'assetBase' => $base,
        ]);
    }
}
