<?php

namespace App\Domain\Diff;

class ChangeSet
{
    /**
     * @param array<string, mixed> $added
     * @param array<string, mixed> $removed
     * @param array<string, array{old: mixed, new: mixed}> $updated
     * @param array<string, array{oldType: string, newType: string, old: mixed, new: mixed}> $typeChanges
     */
    public function __construct(
        private readonly array $added = [],
        private readonly array $removed = [],
        private readonly array $updated = [],
        private readonly array $typeChanges = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function added(): array
    {
        return $this->added;
    }

    /**
     * @return array<string, mixed>
     */
    public function removed(): array
    {
        return $this->removed;
    }

    /**
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function updated(): array
    {
        return $this->updated;
    }

    /**
     * @return array<string, array{oldType: string, newType: string, old: mixed, new: mixed}>
     */
    public function typeChanges(): array
    {
        return $this->typeChanges;
    }

    public function isEmpty(): bool
    {
        return $this->added === []
            && $this->removed === []
            && $this->updated === []
            && $this->typeChanges === [];
    }

    public function count(): int
    {
        return count($this->added)
            + count($this->removed)
            + count($this->updated)
            + count($this->typeChanges);
    }

    /**
     * @return array{added: int, updated: int, removed: int, type_changes: int}
     */
    public function summary(): array
    {
        return [
            'added'        => count($this->added),
            'updated'      => count($this->updated),
            'removed'      => count($this->removed),
            'type_changes' => count($this->typeChanges),
        ];
    }

    /**
     * @return array{added: array<string, mixed>, updated: array<string, mixed>, removed: array<string, mixed>, type_changes: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'added'        => $this->added,
            'updated'      => $this->updated,
            'removed'      => $this->removed,
            'type_changes' => $this->typeChanges,
        ];
    }
}
