<?php

namespace App\Domain\Diff;

class Flattener
{
    /**
     * Flatten a nested configuration map into dot-notation leaves.
     *
     * Associative arrays are traversed; lists and empty arrays are treated as
     * atomic values (so re-ordering or appending to a list is one change
     * instead of index churn).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function flatten(array $data, string $prefix = ''): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $segment = $this->pathSegment((string) $key);
            $path    = $prefix === '' ? $segment : $prefix . '.' . $segment;

            if ($this->isObject($value)) {
                $out += $this->flatten($value, $path);
                continue;
            }

            $out[$path] = $value;
        }

        return $out;
    }

    /**
     * Escape a literal key so that a key containing a dot cannot collide with
     * a nested path (e.g. {"a": {"b": 1}, "a.b": 2}).
     */
    public function pathSegment(string $key): string
    {
        return str_replace(['\\', '.'], ['\\\\', '\\.'], $key);
    }

    /**
     * Flatten a value whose parent path has already been built (and escaped).
     * Child keys are still escaped so dotted keys inside the subtree stay
     * distinguishable from real nesting.
     *
     * @return array<string, mixed>
     */
    public function flattenFrom(mixed $value, string $rawPath): array
    {
        if (! $this->isObject($value)) {
            return [$rawPath => $value];
        }

        $out = [];
        foreach ($value as $key => $child) {
            $segment   = $this->pathSegment((string) $key);
            $childPath = $rawPath === '' ? $segment : $rawPath . '.' . $segment;
            $out += $this->flattenFrom($child, $childPath);
        }

        return $out;
    }

    public function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    public function isObject(mixed $value): bool
    {
        return is_array($value) && $value !== [] && ! array_is_list($value);
    }

    /**
     * Friendly, language agnostic type name used in type_changes payloads.
     */
    public function typeOf(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return 'boolean';
        }

        if (is_int($value)) {
            return 'integer';
        }

        if (is_float($value)) {
            return 'number';
        }

        if (is_string($value)) {
            return 'string';
        }

        if (is_array($value)) {
            return $this->isObject($value) ? 'object' : 'array';
        }

        return get_debug_type($value);
    }

    public function leafCount(array $data): int
    {
        return count($this->flatten($data));
    }
}
