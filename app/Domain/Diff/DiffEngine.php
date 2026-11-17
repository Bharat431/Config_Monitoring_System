<?php

namespace App\Domain\Diff;

/**
 * Structural diff engine for nested configuration maps.
 *
 * Design notes:
 *  - Nested associative arrays are compared recursively.
 *  - Lists and empty arrays are atomic values (no index-level noise).
 *  - A scalar changing to a map (or vice versa) is reported as a type change,
 *    not as a remove+add pair.
 */
class DiffEngine
{
    public function __construct(private readonly Flattener $flattener = new Flattener())
    {
    }

    public function diff(array $old, array $new): ChangeSet
    {
        $changes = [
            'added'        => [],
            'removed'      => [],
            'updated'      => [],
            'type_changes' => [],
        ];

        $this->compare($old, $new, '', true, true, true, $changes);

        return new ChangeSet(
            $changes['added'],
            $changes['removed'],
            $changes['updated'],
            $changes['type_changes'],
        );
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function compare(
        mixed $old,
        mixed $new,
        string $path,
        bool $oldExists,
        bool $newExists,
        bool $isRoot,
        array &$changes,
    ): void {
        if (! $oldExists && ! $newExists) {
            return;
        }

        if ($oldExists && ! $newExists) {
            $this->collect($this->flattener->flattenFrom($old, $path), $changes['removed']);
            if ($old === [] || $old === null) {
                $changes['removed'][$path] = $old;
            }

            return;
        }

        if (! $oldExists && $newExists) {
            $this->collect($this->flattener->flattenFrom($new, $path), $changes['added']);
            if ($new === [] || $new === null) {
                $changes['added'][$path] = $new;
            }

            return;
        }

        $oldIsObject = $this->flattener->isObject($old);
        $newIsObject = $this->flattener->isObject($new);

        // Root maps are always traversed, even when one/both are empty.
        if ($isRoot && is_array($old) && is_array($new)) {
            $oldIsObject = true;
            $newIsObject = true;
        }

        if ($oldIsObject && $newIsObject) {
            $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
            foreach ($keys as $key) {
                $segment   = $this->flattener->pathSegment((string) $key);
                $childPath = $path === '' ? $segment : $path . '.' . $segment;
                $this->compare(
                    $old[$key] ?? null,
                    $new[$key] ?? null,
                    $childPath,
                    array_key_exists($key, $old),
                    array_key_exists($key, $new),
                    false,
                    $changes,
                );
            }

            return;
        }

        $oldType = $this->flattener->typeOf($old);
        $newType = $this->flattener->typeOf($new);

        if ($oldType !== $newType) {
            $changes['type_changes'][$path] = [
                'oldType' => $oldType,
                'newType' => $newType,
                'old'     => $old,
                'new'     => $new,
            ];

            return;
        }

        if ($this->valueChanged($old, $new)) {
            $changes['updated'][$path] = ['old' => $old, 'new' => $new];
        }
    }

    /**
     * Copy already-pathed leaves into a change bucket.
     *
     * @param array<string, mixed> $leaves
     * @param array<string, mixed> $target
     */
    private function collect(array $leaves, array &$target): void
    {
        foreach ($leaves as $leafPath => $value) {
            $target[$leafPath] = $value;
        }
    }

    private function valueChanged(mixed $old, mixed $new): bool
    {
        if (is_array($old) && is_array($new)) {
            return $old !== $new;
        }

        return $old !== $new;
    }
}
