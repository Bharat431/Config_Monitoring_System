<?php

namespace App\Domain\Events;

use App\Domain\Diff\ChangeSet;
use App\Support\Time;
use App\Support\Uuid;

class ChangeEvent
{
    /**
     * @param array<string, mixed> $changes
     * @param array{added: int, updated: int, removed: int, type_changes: int} $summary
     */
    public function __construct(
        public readonly string $eventId,
        public readonly int $monitoredFileId,
        public readonly string $fileName,
        public readonly string $filePath,
        public readonly string $changeType,
        public readonly array $changes,
        public readonly array $summary,
        public readonly ?string $hash,
        public readonly string $timestamp,
        public readonly string $traceId,
        public readonly ?string $error = null,
    ) {
    }

    public static function create(
        int $monitoredFileId,
        string $fileName,
        string $filePath,
        string $changeType,
        ?ChangeSet $changeSet,
        ?string $hash,
        string $traceId,
        ?string $error = null,
    ): self {
        $changes = $changeSet?->toArray() ?? [
            'added'        => [],
            'updated'      => [],
            'removed'      => [],
            'type_changes' => [],
        ];
        $summary = $changeSet?->summary() ?? [
            'added'        => 0,
            'updated'      => 0,
            'removed'      => 0,
            'type_changes' => 0,
        ];

        return new self(
            Uuid::v4(),
            $monitoredFileId,
            $fileName,
            $filePath,
            $changeType,
            $changes,
            $summary,
            $hash,
            Time::isoNow(),
            $traceId,
            $error,
        );
    }

    /**
     * Canonical webhook payload (contract v1).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id'   => $this->eventId,
            'source'     => 'config-monitor',
            'version'    => '1.0',
            'type'       => $this->changeType,
            'file'       => $this->fileName,
            'path'       => $this->filePath,
            'timestamp'  => $this->timestamp,
            'changes'    => $this->changes,
            'summary'    => $this->summary,
            'hash'       => $this->hash,
            'trace_id'   => $this->traceId,
            'error'      => $this->error,
            'meta'       => [
                'monitored_file_id' => $this->monitoredFileId,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabaseRow(): array
    {
        return [
            'event_id'             => $this->eventId,
            'monitored_file_id'    => $this->monitoredFileId,
            'file_name'            => $this->fileName,
            'file_path'            => $this->filePath,
            'change_type'          => $this->changeType,
            'payload'              => json_encode(
                $this->toArray(),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION,
            ),
            'summary_added'        => $this->summary['added'],
            'summary_updated'      => $this->summary['updated'],
            'summary_removed'      => $this->summary['removed'],
            'summary_type_changes' => $this->summary['type_changes'],
            'hash'                 => $this->hash,
            'trace_id'             => $this->traceId,
            'error'                => $this->error,
            'created_at'           => Time::dbNow(),
        ];
    }
}
