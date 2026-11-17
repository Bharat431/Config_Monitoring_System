<?php

namespace Tests\Support;

/**
 * Small validator for the subset of JSON Schema used by the committed
 * contract documents. Keeping this dependency-free avoids adding a runtime
 * package just to assert that our own payloads match their published schema.
 *
 * Supported keywords: boolean schemas, $ref (local pointers), type, enum,
 * const, required, properties, additionalProperties, pattern, minLength,
 * minimum and items.
 */
final class JsonSchemaValidator
{
    /** @param array<string, mixed> $schema */
    private function __construct(private readonly array $schema)
    {
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string> validation errors (empty when valid)
     */
    public static function validate(mixed $data, array $schema): array
    {
        return (new self($schema))->check($data, $schema, '$');
    }

    /** @return list<string> */
    private function check(mixed $data, mixed $schema, string $path): array
    {
        if ($schema === true) {
            return [];
        }
        if ($schema === false) {
            return [$path . ' is not allowed by the schema'];
        }
        if (! is_array($schema)) {
            return [$path . ' has an unsupported schema definition'];
        }

        $errors = [];

        if (is_string($ref = $schema['$ref'] ?? null)) {
            $target = $this->resolveRef($ref);
            if ($target === null) {
                return [$path . ' references unknown schema ' . $ref];
            }

            return $this->check($data, $target, $path);
        }

        if (isset($schema['type']) && ! $this->matchesType($data, $schema['type'])) {
            return [$path . ' must be ' . implode(' or ', (array) $schema['type']) . ', got ' . get_debug_type($data)];
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($data, $schema['enum'], true)) {
            $errors[] = $path . ' is not one of the allowed values';
        }

        if (array_key_exists('const', $schema) && $data !== $schema['const']) {
            $errors[] = $path . ' must equal ' . var_export($schema['const'], true);
        }

        if (is_string($data)) {
            $pattern = $schema['pattern'] ?? null;
            if (is_string($pattern) && @preg_match('~' . $pattern . '~u', $data) !== 1) {
                $errors[] = $path . ' does not match the required pattern';
            }

            $min = $schema['minLength'] ?? null;
            if (is_int($min) && mb_strlen($data) < $min) {
                $errors[] = $path . ' is shorter than ' . $min . ' characters';
            }

            $max = $schema['maxLength'] ?? null;
            if (is_int($max) && mb_strlen($data) > $max) {
                $errors[] = $path . ' is longer than ' . $max . ' characters';
            }
        }

        if ((is_int($data) || is_float($data)) && isset($schema['minimum']) && is_numeric($schema['minimum'])) {
            if ($data < $schema['minimum']) {
                $errors[] = $path . ' must be at least ' . $schema['minimum'];
            }
        }

        if (is_array($data)) {
            $properties   = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            $isObjectLike = ! array_is_list($data) || $data === [];

            if ($isObjectLike) {
                $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
                foreach ($required as $key) {
                    if (! array_key_exists($key, $data)) {
                        $errors[] = $path . ' is missing required property ' . $key;
                    }
                }

                foreach ($properties as $key => $childSchema) {
                    if (array_key_exists($key, $data)) {
                        $errors = [...$errors, ...$this->check($data[$key], $childSchema, $path . '.' . $key)];
                    }
                }

                $additional = $schema['additionalProperties'] ?? true;
                if ($additional === false || is_array($additional)) {
                    foreach ($data as $key => $value) {
                        if (array_key_exists($key, $properties)) {
                            continue;
                        }
                        if ($additional === false) {
                            $errors[] = $path . ' has unexpected property ' . $key;
                        } else {
                            $errors = [...$errors, ...$this->check($value, $additional, $path . '.' . $key)];
                        }
                    }
                }
            }

            $items = $schema['items'] ?? null;
            if (($items !== null) && array_is_list($data)) {
                foreach ($data as $index => $value) {
                    $errors = [...$errors, ...$this->check($value, $items, $path . '[' . $index . ']')];
                }
            }
        }

        return $errors;
    }

    private function matchesType(mixed $data, string|array $expected): bool
    {
        foreach ((array) $expected as $type) {
            $matches = match ($type) {
                'object'  => is_array($data),
                'array'   => is_array($data) && array_is_list($data),
                'string'  => is_string($data),
                'integer' => is_int($data),
                'number'  => is_int($data) || is_float($data),
                'boolean' => is_bool($data),
                'null'    => $data === null,
                default   => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    private function resolveRef(string $ref): mixed
    {
        if (! str_starts_with($ref, '#/')) {
            return null;
        }

        $current = $this->schema;
        foreach (explode('/', substr($ref, 2)) as $token) {
            $token = str_replace(['~1', '~0'], ['/', '~'], $token);
            if (! is_array($current) || ! array_key_exists($token, $current)) {
                return null;
            }
            $current = $current[$token];
        }

        return $current;
    }
}
