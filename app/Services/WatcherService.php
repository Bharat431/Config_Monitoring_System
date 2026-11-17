<?php

namespace App\Services;

use App\Domain\Diff\ChangeSet;
use App\Domain\Diff\DiffEngine;
use App\Domain\Diff\Flattener;
use App\Domain\Events\ChangeEvent;
use App\Domain\Parsing\ConfigParseException;
use App\Domain\Parsing\ParserRegistry;
use App\Infrastructure\Logging\JsonLogger;
use App\Infrastructure\Metrics\Metrics;
use App\Infrastructure\Queue\EventBus;
use App\Infrastructure\Tracing\Tracer;
use App\Models\FileSnapshotModel;
use App\Models\MonitoredFileModel;
use App\Models\SystemStateModel;
use App\Support\Time;
use Config\Monitor;
use Throwable;

/**
 * Detects changes on disk and turns them into structured events.
 *
 * Key behaviours:
 *  - hashing avoids re-parsing unchanged files
 *  - a debounce window requires the hash to be stable before processing
 *  - invalid/partial files are recorded as errors but the last good snapshot
 *    is kept so recovery produces a correct diff
 *  - snapshot + event + queue fan-out commit atomically
 */
class WatcherService
{
    public function __construct(
        private readonly Monitor $config,
        private readonly MonitoredFileModel $files,
        private readonly FileSnapshotModel $snapshots,
        private readonly ParserRegistry $parsers,
        private readonly DiffEngine $diff,
        private readonly Flattener $flattener,
        private readonly EventBus $bus,
        private readonly Metrics $metrics,
        private readonly JsonLogger $logger,
        private readonly Tracer $tracer,
        private readonly PathGuard $paths,
        private readonly SystemStateModel $state,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function scanAll(bool $baseline = false, ?int $shard = null, ?int $shards = null, ?string $traceId = null): array
    {
        $traceId ??= $this->tracer->newTraceId();
        $started   = microtime(true);
        $shards  ??= $this->config->watcherShards;
        $shard   ??= $this->config->watcherShard;

        $files = ($shards > 1)
            ? $this->files->forShard($shard, $shards)
            : $this->files->enabledFiles();

        $counts = [
            'scanned'     => 0,
            'unchanged'   => 0,
            'debounced'   => 0,
            'created'     => 0,
            'modified'    => 0,
            'deleted'     => 0,
            'parse_error' => 0,
            'missing'     => 0,
            'failed'      => 0,
        ];

        foreach ($files as $file) {
            $counts['scanned']++;
            try {
                $result = $this->scanFile($file, $baseline, $traceId);
                $counts[$result['outcome']] = ($counts[$result['outcome']] ?? 0) + 1;
            } catch (Throwable $e) {
                $counts['failed']++;
                $this->logger->error('File scan failed', [
                    'component' => 'watcher',
                    'file'      => $file['display_name'] ?? $file['path'],
                    'error'     => $e,
                ], $traceId);
            }
        }

        $enabled = $this->files->where('enabled', 1)->countAllResults();
        $errors  = $this->files->where('enabled', 1)->where('status', 'error')->countAllResults();

        $this->metrics->gauge('files_watched', (float) $enabled);
        $this->metrics->gauge('files_error', (float) $errors);
        $this->metrics->observe('scan_cycle_seconds', microtime(true) - $started);
        $this->metrics->increment('scan_cycles_total');

        $this->state->put('watcher_heartbeat', [
            'at'      => Time::isoNow(),
            'pid'     => getmypid(),
            'shard'   => $shard,
            'shards'  => $shards,
            'enabled' => $enabled,
            'errors'  => $errors,
        ]);
        $this->state->put('last_scan', [
            'at'     => Time::isoNow(),
            'counts' => $counts,
        ]);

        $this->logger->debug('Scan cycle finished', [
            'component' => 'watcher',
            'counts'    => $counts,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ], $traceId);

        return $counts;
    }

    /**
     * @param array<string, mixed> $file
     *
     * @return array{outcome: string, event: ?ChangeEvent}
     */
    public function scanFile(array $file, bool $baseline = false, ?string $traceId = null, bool $force = false): array
    {
        $traceId ??= $this->tracer->newTraceId();
        $path      = (string) $file['path'];
        $id        = (int) $file['id'];
        $now       = Time::dbNow();

        if (! is_file($path)) {
            return $this->handleMissing($file, $baseline, $traceId);
        }

        // Cheap guard before loading the file into memory.
        $statSize = @filesize($path);
        if ($statSize === false) {
            return $this->handleParseError($file, 'Unable to stat file (permission or I/O error).', null, $baseline, $traceId);
        }

        if ($statSize > $this->config->maxFileBytes) {
            return $this->handleParseError(
                $file,
                sprintf('File is too large (%d bytes; limit %d).', $statSize, $this->config->maxFileBytes),
                null,
                $baseline,
                $traceId,
            );
        }

        // Read once: the hash, size and parsed content all come from the same
        // bytes, so a write racing the scan cannot produce a hash that does not
        // match the content we actually parsed.
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return $this->handleParseError($file, 'Unable to read file contents (permission or I/O error).', null, $baseline, $traceId);
        }

        $size = strlen($contents);

        if ($size > $this->config->maxFileBytes) {
            return $this->handleParseError(
                $file,
                sprintf('File is too large (%d bytes; limit %d).', $size, $this->config->maxFileBytes),
                null,
                $baseline,
                $traceId,
            );
        }

        $hash = hash('sha256', $contents);

        if ($hash === ($file['last_hash'] ?? null) && (int) $file['last_parse_ok'] === 1) {
            $this->files->update($id, [
                'last_seen_at' => $now,
                'last_scan_at' => $now,
                'status'       => 'active',
                'pending_hash'     => null,
                'pending_since'    => null,
                'pending_since_ms' => null,
                'updated_at'       => $now,
            ]);
            $this->metrics->increment('scans_total', ['outcome' => 'unchanged']);

            return ['outcome' => 'unchanged', 'event' => null];
        }

        if (! $force && ! $baseline) {
            $debounced = $this->applyDebounce($file, $hash, $now);
            if ($debounced !== null) {
                $this->metrics->increment('scans_total', ['outcome' => 'debounced']);

                return ['outcome' => 'debounced', 'event' => null];
            }
        }

        try {
            $parsed = $this->parsers->parse($path, $contents);
        } catch (ConfigParseException $e) {
            return $this->handleParseError($file, $e->getMessage(), $hash, $baseline, $traceId, $e->context());
        } catch (Throwable $e) {
            return $this->handleParseError($file, 'Parser failure: ' . $e->getMessage(), $hash, $baseline, $traceId);
        }

        return $this->handleParsed($file, $parsed, $hash, (int) $size, $baseline, $traceId);
    }

    /**
     * @param array<string, mixed> $file
     *
     * @return array{outcome: string, event: ?ChangeEvent}
     */
    public function scanPath(string $path, bool $baseline = false, ?string $traceId = null, bool $force = false): array
    {
        $normalized = $this->paths->normalize($path);
        $file       = $this->files->findByPath($normalized);

        if ($file === null) {
            throw new \InvalidArgumentException('File is not monitored: ' . $normalized);
        }

        return $this->scanFile($file, $baseline, $traceId, $force);
    }

    /**
     * @return array{file: array<string, mixed>, baseline: ?array{outcome: string, event: ?ChangeEvent}, created: bool}
     */
    public function addWatch(string $path, ?int $debounceMs = null, bool $baseline = true, string $actor = 'api'): array
    {
        $guard      = $this->paths->assertAllowed($path);
        $debounceMs = $debounceMs === null
            ? $this->config->debounceMs
            : max(0, min(60000, $debounceMs));
        $now = Time::dbNow();

        $existing = $this->files->findByPath($guard['path']);

        if ($existing !== null) {
            $this->files->update((int) $existing['id'], [
                'enabled'     => 1,
                'debounce_ms' => $debounceMs,
                'status'      => ! empty($existing['current_snapshot_id']) ? 'active' : 'pending',
                'updated_at'  => $now,
            ]);
            $file    = $this->files->find((int) $existing['id']) ?? $existing;
            $created = false;
        } else {
            $id = $this->files->insert([
                'path'          => $guard['path'],
                'display_name'  => $guard['display_name'],
                'extension'     => $guard['extension'],
                'enabled'       => 1,
                'debounce_ms'   => $debounceMs,
                'status'        => 'pending',
                'last_parse_ok' => 1,
                'created_at'    => $now,
                'updated_at'    => $now,
            ], true);

            if ($id === false) {
                throw new \RuntimeException('Failed to persist the monitored file.');
            }

            $file    = $this->files->find((int) $id) ?? [];
            $created = true;
        }

        $baselineResult = null;
        if ($baseline && is_file($guard['path'])) {
            $traceId        = $this->tracer->newTraceId();
            $baselineResult = $this->scanFile($file, true, $traceId);
            $file           = $this->files->find((int) $file['id']) ?? $file;
        }

        $this->metrics->gauge('files_watched', (float) $this->files->where('enabled', 1)->countAllResults());

        $this->logger->info('File added to watch list', [
            'component' => 'watcher',
            'file'      => $guard['display_name'],
            'path'      => $guard['path'],
            'actor'     => $actor,
            'baseline'  => $baselineResult['outcome'] ?? null,
        ]);

        return ['file' => $file, 'baseline' => $baselineResult, 'created' => $created];
    }

    public function removeWatch(string $identifier): bool
    {
        $file = null;

        if (ctype_digit($identifier)) {
            $file = $this->files->find((int) $identifier);
        }

        if ($file === null) {
            $file = $this->files->findByPath($identifier)
                ?? $this->files->findByPath($this->paths->normalize($identifier));
        }

        if ($file === null) {
            return false;
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $this->snapshots->where('monitored_file_id', (int) $file['id'])->delete();
            $this->files->delete((int) $file['id']);
            $db->transCommit();
        } catch (Throwable $e) {
            $db->transRollback();
            throw $e;
        }

        $this->metrics->gauge('files_watched', (float) $this->files->where('enabled', 1)->countAllResults());
        $this->logger->info('File removed from watch list', [
            'component' => 'watcher',
            'file'      => $file['display_name'] ?? null,
            'path'      => $file['path'] ?? null,
        ]);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function setEnabled(int $id, bool $enabled): array
    {
        $file = $this->files->find($id);
        if ($file === null) {
            throw new \InvalidArgumentException('Monitored file not found.');
        }

        $status = $enabled
            ? (! empty($file['current_snapshot_id']) ? 'active' : 'pending')
            : 'paused';

        $this->files->update($id, [
            'enabled'    => $enabled ? 1 : 0,
            'status'     => $status,
            'updated_at' => Time::dbNow(),
        ]);

        $file = $this->files->find($id) ?? $file;
        $this->logger->info($enabled ? 'Watcher resumed' : 'Watcher paused', [
            'component' => 'watcher',
            'file'      => $file['display_name'] ?? null,
        ]);

        return $file;
    }

    /**
     * @param array<string, mixed> $file
     */
    private function applyDebounce(array $file, string $hash, string $now): ?string
    {
        $debounceMs = max(0, (int) ($file['debounce_ms'] ?? $this->config->debounceMs));
        if ($debounceMs === 0) {
            return null;
        }

        $nowMs       = Time::nowMs();
        $pendingHash = $file['pending_hash'] ?? null;

        if ($pendingHash !== $hash) {
            $this->files->update((int) $file['id'], [
                'pending_hash'     => $hash,
                'pending_since'    => $now,
                'pending_since_ms' => $nowMs,
                'last_seen_at'     => $now,
                'last_scan_at'     => $now,
                'status'           => ! empty($file['current_snapshot_id']) ? 'active' : 'pending',
                'updated_at'       => $now,
            ]);
            $this->metrics->increment('scans_debounced_total');

            return 'waiting';
        }

        // Prefer the millisecond value; fall back to the legacy DATETIME column
        // for rows written by an older version.
        $sinceMs = $file['pending_since_ms'] ?? null;
        if ($sinceMs === null && ! empty($file['pending_since'])) {
            $parsed = strtotime((string) $file['pending_since'] . ' UTC');
            $sinceMs = $parsed === false ? null : $parsed * 1000;
        }

        if ($sinceMs === null) {
            $this->files->update((int) $file['id'], [
                'pending_since'    => $now,
                'pending_since_ms' => $nowMs,
                'last_scan_at'     => $now,
                'updated_at'       => $now,
            ]);

            return 'waiting';
        }

        $elapsedMs = $nowMs - (int) $sinceMs;
        if ($elapsedMs < $debounceMs) {
            $this->files->update((int) $file['id'], [
                'last_seen_at' => $now,
                'last_scan_at' => $now,
                'updated_at'   => $now,
            ]);

            return 'waiting';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $file
     * @param array<string, mixed> $parsed
     *
     * @return array{outcome: string, event: ?ChangeEvent}
     */
    private function handleParsed(array $file, array $parsed, string $hash, int $size, bool $baseline, string $traceId): array
    {
        $now      = Time::dbNow();
        $previous = null;

        if (! empty($file['current_snapshot_id'])) {
            $snapshot = $this->snapshots->findById((int) $file['current_snapshot_id']);
            if ($snapshot !== null) {
                $decoded = json_decode((string) $snapshot['parsed'], true);
                if (is_array($decoded)) {
                    $previous = $decoded;
                }
            }
        }

        $equivalent = $previous !== null && $this->canonical($previous) === $this->canonical($parsed);

        $changeType = null;
        $changeSet  = null;
        $newSnapshot = null;

        if ($baseline) {
            if ($previous === null || ! $equivalent) {
                $newSnapshot = $this->snapshotPayload($file, $parsed, $hash, $size);
            }
        } elseif ($previous === null) {
            $changeType  = 'created';
            $changeSet   = new ChangeSet($this->flattener->flatten($parsed));
            $newSnapshot = $this->snapshotPayload($file, $parsed, $hash, $size);
        } else {
            $changeSet = $this->diff->diff($previous, $parsed);

            if (! $changeSet->isEmpty()) {
                $changeType  = 'modified';
                $newSnapshot = $this->snapshotPayload($file, $parsed, $hash, $size);
            } elseif (! $equivalent) {
                // e.g. key reordering: persist the new representation silently.
                $newSnapshot = $this->snapshotPayload($file, $parsed, $hash, $size);
            }
        }

        $event = null;
        if ($changeType !== null && $changeSet !== null) {
            $event = ChangeEvent::create(
                (int) $file['id'],
                (string) $file['display_name'],
                (string) $file['path'],
                $changeType,
                $changeSet,
                $hash,
                $traceId,
            );
        }

        $update = [
            'last_hash'        => $hash,
            'status'           => 'active',
            'last_parse_ok'    => 1,
            'last_error'       => null,
            'last_error_hash'  => null,
            'last_seen_at'     => $now,
            'last_scan_at'     => $now,
            'pending_hash'     => null,
            'pending_since'    => null,
            'pending_since_ms' => null,
            'updated_at'       => $now,
        ];

        if ($changeType !== null) {
            $update['last_changed_at'] = $now;
        }

        $result = $this->commit($file, $update, $newSnapshot, $event);

        $this->metrics->increment('scans_total', ['outcome' => $changeType ?? 'unchanged']);

        if ($changeType !== null) {
            $this->logger->info('Configuration change detected', [
                'component'   => 'watcher',
                'file'        => $file['display_name'],
                'change_type' => $changeType,
                'summary'     => $changeSet?->summary(),
            ], $traceId);
        }

        return ['outcome' => $changeType ?? 'unchanged', 'event' => $result['event']];
    }

    /**
     * @param array<string, mixed> $file
     *
     * @return array{outcome: string, event: ?ChangeEvent}
     */
    private function handleParseError(
        array $file,
        string $message,
        ?string $hash,
        bool $baseline,
        string $traceId,
        ?array $context = null,
    ): array {
        $now        = Time::dbNow();
        $lastOk     = (int) ($file['last_parse_ok'] ?? 1) === 1;
        $lastHash   = $file['last_error_hash'] ?? null;
        $shouldEmit = ! $baseline && ($lastOk || $lastHash !== $hash);

        // Keep the parser's line/snippet so operators can see exactly where the
        // partial write or syntax error happened.
        $detail = $message;
        if (is_array($context) && $context !== []) {
            $parts = [];
            if (isset($context['line'])) {
                $parts[] = 'line ' . (int) $context['line'];
            }
            if (! empty($context['snippet'])) {
                $parts[] = 'snippet: ' . mb_substr((string) $context['snippet'], 0, 300);
            }
            if ($parts !== []) {
                $detail .= ' (' . implode('; ', $parts) . ')';
            }
        }

        $update = [
            'status'          => 'error',
            'last_parse_ok'   => 0,
            'last_error'      => mb_substr($detail, 0, 2000),
            'last_error_hash' => $hash,
            'last_seen_at'    => $now,
            'last_scan_at'    => $now,
            'pending_hash'     => null,
            'pending_since'    => null,
            'pending_since_ms' => null,
            'updated_at'       => $now,
        ];

        $event = null;
        if ($shouldEmit) {
            $event = ChangeEvent::create(
                (int) $file['id'],
                (string) $file['display_name'],
                (string) $file['path'],
                'parse_error',
                null,
                $hash,
                $traceId,
                $detail,
            );
        }

        $this->commit($file, $update, null, $event);
        $this->metrics->increment('parse_errors_total');
        $this->metrics->increment('scans_total', ['outcome' => 'parse_error']);

        $logContext = [
            'component' => 'watcher',
            'file'      => $file['display_name'],
            'error'     => $detail,
            'notified'  => $shouldEmit,
        ];
        if (is_array($context)) {
            $logContext['parse_context'] = $context;
        }
        $this->logger->warn('Invalid configuration encountered; keeping last good snapshot', $logContext, $traceId);

        return ['outcome' => 'parse_error', 'event' => $event];
    }

    /**
     * @param array<string, mixed> $file
     *
     * @return array{outcome: string, event: ?ChangeEvent}
     */
    private function handleMissing(array $file, bool $baseline, string $traceId): array
    {
        $now    = Time::dbNow();
        $hadSnapshot = ! empty($file['current_snapshot_id']);

        if (! $hadSnapshot) {
            $this->files->update((int) $file['id'], [
                'status'        => 'missing',
                'last_scan_at'  => $now,
                'pending_hash'     => null,
                'pending_since'    => null,
                'pending_since_ms' => null,
                'updated_at'       => $now,
            ]);
            $this->metrics->increment('scans_total', ['outcome' => 'missing']);

            return ['outcome' => 'missing', 'event' => null];
        }

        $snapshot = $this->snapshots->findById((int) $file['current_snapshot_id']);
        $previous = $snapshot !== null ? json_decode((string) $snapshot['parsed'], true) : null;
        $removed  = is_array($previous) ? $this->flattener->flatten($previous) : [];

        $event = null;
        if (! $baseline) {
            $event = ChangeEvent::create(
                (int) $file['id'],
                (string) $file['display_name'],
                (string) $file['path'],
                'deleted',
                new ChangeSet([], $removed),
                null,
                $traceId,
            );
        }

        $update = [
            'status'              => 'deleted',
            'last_hash'           => null,
            'current_snapshot_id' => null,
            'last_parse_ok'       => 1,
            'last_changed_at'     => $now,
            'last_scan_at'        => $now,
            'pending_hash'        => null,
            'pending_since'       => null,
            'pending_since_ms'    => null,
            'updated_at'          => $now,
        ];

        $this->commit($file, $update, null, $event);
        $this->metrics->increment('scans_total', ['outcome' => 'deleted']);

        $this->logger->warn('Monitored file was deleted', [
            'component' => 'watcher',
            'file'      => $file['display_name'],
            'removed'   => count($removed),
        ], $traceId);

        return ['outcome' => 'deleted', 'event' => $event];
    }

    /**
     * @param array<string, mixed> $file
     * @param array<string, mixed> $update
     * @param array<string, mixed>|null $snapshot
     *
     * @return array{event: ?ChangeEvent, queued: int}
     */
    private function commit(array $file, array $update, ?array $snapshot, ?ChangeEvent $event): array
    {
        $db = db_connect();
        $db->transBegin();

        try {
            if ($snapshot !== null) {
                $snapshot['monitored_file_id'] = (int) $file['id'];
                $snapshot['version']           = $this->snapshots->nextVersion((int) $file['id']);
                $snapshot['created_at']        = Time::dbNow();

                $snapshotId = $this->snapshots->insert($snapshot, true);
                if ($snapshotId === false) {
                    throw new \RuntimeException('Failed to persist file snapshot.');
                }

                $update['current_snapshot_id'] = (int) $snapshotId;
            }

            $this->files->update((int) $file['id'], $update);

            $queued = -1;
            if ($event !== null) {
                $queued = $this->bus->stage($event);
            }

            $db->transCommit();
        } catch (Throwable $e) {
            $db->transRollback();
            throw $e;
        }

        if ($snapshot !== null) {
            $this->snapshots->pruneForFile((int) $file['id'], $this->config->snapshotRetention);
        }

        if ($event !== null && $queued >= 0) {
            $this->bus->recordPublished($event, $queued);
        }

        return ['event' => $event, 'queued' => $queued];
    }

    /**
     * @param array<string, mixed> $file
     * @param array<string, mixed> $parsed
     *
     * @return array<string, mixed>
     */
    private function snapshotPayload(array $file, array $parsed, string $hash, int $size): array
    {
        return [
            'hash'        => $hash,
            'size'        => $size,
            'parsed'      => json_encode(
                $parsed,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION,
            ),
            'leaf_count'  => $this->flattener->leafCount($parsed),
            'captured_at' => Time::dbNow(),
        ];
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
        }

        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->canonical($value[$key]);
        }

        return $out;
    }
}
