<?php

namespace App\Controllers\Api;

use App\Controllers\BaseApiController;
use App\Support\Time;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class Files extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $rows = model('MonitoredFileModel')->orderBy('id', 'ASC')->findAll();

        $files = array_map([$this, 'present'], $rows);

        return $this->ok($files, [
            'count'  => count($files),
            'by_status' => $this->countByStatus($files),
        ]);
    }

    public function show(string $id): ResponseInterface
    {
        $file = model('MonitoredFileModel')->find((int) $id);
        if ($file === null) {
            return $this->notFound('Monitored file not found.');
        }

        $snapshot = null;
        if (! empty($file['current_snapshot_id'])) {
            $row = model('FileSnapshotModel')->find((int) $file['current_snapshot_id']);
            if ($row !== null) {
                $snapshot = [
                    'id'          => (int) $row['id'],
                    'version'     => (int) $row['version'],
                    'hash'        => $row['hash'],
                    'size'        => (int) $row['size'],
                    'leaf_count'  => (int) $row['leaf_count'],
                    'captured_at' => Time::isoFromDb((string) $row['captured_at']),
                ];
            }
        }

        $events = model('ChangeEventModel')
            ->select('id, event_id, file_name, change_type, summary_added, summary_updated, summary_removed, summary_type_changes, created_at')
            ->where('monitored_file_id', (int) $id)
            ->orderBy('id', 'DESC')
            ->findAll(20);

        $presented = array_map(static fn (array $event): array => [
            'id'          => (int) $event['id'],
            'event_id'    => $event['event_id'],
            'file_name'   => $event['file_name'],
            'change_type' => $event['change_type'],
            'summary'     => [
                'added'        => (int) $event['summary_added'],
                'updated'      => (int) $event['summary_updated'],
                'removed'      => (int) $event['summary_removed'],
                'type_changes' => (int) $event['summary_type_changes'],
            ],
            'created_at'  => Time::isoFromDb((string) $event['created_at']),
        ], $events);

        return $this->ok([
            'file'      => $this->present($file),
            'snapshot'  => $snapshot,
            'recent_events' => $presented,
        ]);
    }

    public function store(): ResponseInterface
    {
        $body = $this->payload();
        $path = trim((string) ($body['path'] ?? ''));

        if ($path === '') {
            return $this->fail('Field "path" is required.', 422, 'validation_error');
        }

        if (strlen($path) > 512) {
            return $this->fail('Field "path" is too long (max 512).', 422, 'validation_error');
        }

        $debounce = isset($body['debounce_ms']) ? (int) $body['debounce_ms'] : null;
        if ($debounce !== null && ($debounce < 0 || $debounce > 60000)) {
            return $this->fail('Field "debounce_ms" must be between 0 and 60000.', 422, 'validation_error');
        }

        $baseline = $this->bool($body['baseline'] ?? true, true);

        try {
            $result = service('watcherService')->addWatch($path, $debounce, $baseline, $this->actor());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 'invalid_path');
        } catch (Throwable $e) {
            service('jsonLogger')->error('Failed to add watch', ['component' => 'api', 'error' => $e]);

            return $this->fail('Unable to add the file to the watch list.', 500, 'internal_error');
        }

        $this->audit('watch.add', $result['file']['path'] ?? $path, [
            'created'  => $result['created'],
            'baseline' => $result['baseline']['outcome'] ?? null,
        ]);

        return $this->created([
            'file'     => $this->present($result['file']),
            'created'  => $result['created'],
            'baseline' => $result['baseline'],
        ]);
    }

    public function update(string $id): ResponseInterface
    {
        $file = model('MonitoredFileModel')->find((int) $id);
        if ($file === null) {
            return $this->notFound('Monitored file not found.');
        }

        $body = $this->payload();
        if (! array_key_exists('enabled', $body)) {
            return $this->fail('Field "enabled" is required.', 422, 'validation_error');
        }

        try {
            $updated = service('watcherService')->setEnabled((int) $id, $this->bool($body['enabled']));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 'validation_error');
        }

        $this->audit('watch.update', $updated['path'] ?? (string) $id, ['enabled' => $this->bool($body['enabled'])]);

        return $this->ok(['file' => $this->present($updated)]);
    }

    public function delete(string $identifier): ResponseInterface
    {
        $identifier = trim((string) ($this->request->getGet('path') ?? $this->request->getGet('id') ?? $identifier));
        $identifier = urldecode($identifier);

        try {
            $removed = service('watcherService')->removeWatch($identifier);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 'invalid_path');
        }

        if (! $removed) {
            return $this->notFound('Monitored file not found.');
        }

        $this->audit('watch.remove', $identifier);

        return $this->ok(['removed' => true, 'identifier' => $identifier]);
    }

    public function scan(): ResponseInterface
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $body   = $this->payload();
        $force  = $this->bool($body['force'] ?? true, true);
        $fileId = isset($body['file_id']) ? (int) $body['file_id'] : null;
        $path   = isset($body['path']) ? trim((string) $body['path']) : null;

        try {
            if ($fileId !== null && $fileId > 0) {
                $file = model('MonitoredFileModel')->find($fileId);
                if ($file === null) {
                    return $this->notFound('Monitored file not found.');
                }
                $result = service('watcherService')->scanFile($file, false, null, $force);
            } elseif ($path !== null && $path !== '') {
                $result = service('watcherService')->scanPath($path, false, null, $force);
            } else {
                $result = service('watcherService')->scanAll(false);
            }
        } catch (Throwable $e) {
            service('jsonLogger')->error('Manual scan failed', ['component' => 'api', 'error' => $e]);

            return $this->fail('Scan failed: ' . $e->getMessage(), 500, 'scan_failed');
        }

        $this->audit('watch.scan', $path ?? ($fileId !== null ? (string) $fileId : 'all'), [
            'force' => $force,
        ]);

        return $this->ok(['result' => $result]);
    }

    /**
     * @param array<string, mixed> $file
     *
     * @return array<string, mixed>
     */
    private function present(array $file): array
    {
        return [
            'id'              => (int) $file['id'],
            'path'            => $file['path'],
            'display_name'    => $file['display_name'],
            'extension'       => $file['extension'],
            'enabled'         => (bool) $file['enabled'],
            'status'          => $file['status'],
            'debounce_ms'     => (int) $file['debounce_ms'],
            'hash'            => $file['last_hash'],
            'parse_ok'        => (bool) $file['last_parse_ok'],
            'last_error'      => $file['last_error'],
            'last_seen_at'    => Time::isoFromDb($file['last_seen_at']),
            'last_changed_at' => Time::isoFromDb($file['last_changed_at']),
            'last_scan_at'    => Time::isoFromDb($file['last_scan_at']),
            'pending'         => ! empty($file['pending_hash']),
            'created_at'      => Time::isoFromDb($file['created_at']),
        ];
    }

    /**
     * @param list<array<string, mixed>> $files
     *
     * @return array<string, int>
     */
    private function countByStatus(array $files): array
    {
        $out = [];
        foreach ($files as $file) {
            $status = (string) $file['status'];
            $out[$status] = ($out[$status] ?? 0) + 1;
        }

        return $out;
    }
}
