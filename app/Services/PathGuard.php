<?php

namespace App\Services;

use App\Domain\Parsing\ConfigParseException;
use Config\Monitor;

/**
 * Validates and canonicalises watch paths.
 *
 * All paths are stored with forward slashes so the same file maps to exactly
 * one monitored row regardless of the separator used by the caller.
 */
class PathGuard
{
    public function __construct(private readonly Monitor $config)
    {
    }

    /**
     * @return array{path: string, extension: string, display_name: string}
     *
     * @throws \InvalidArgumentException
     */
    public function assertAllowed(string $path): array
    {
        $path = trim($path);
        if ($path === '') {
            throw new \InvalidArgumentException('A non-empty file path is required.');
        }

        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Invalid path.');
        }

        $candidate = $this->toAbsolute($path);
        $parent    = dirname($candidate);
        $parentReal = realpath($parent);

        if ($parentReal === false || ! is_dir($parentReal)) {
            throw new \InvalidArgumentException(sprintf('Directory does not exist: %s', $this->slashes($parent)));
        }

        $canonical = is_file($candidate)
            ? realpath($candidate)
            : rtrim($this->slashes($parentReal), '/') . '/' . basename($candidate);

        if ($canonical === false) {
            throw new \InvalidArgumentException('Unable to resolve the file path.');
        }

        $canonical = $this->slashes((string) $canonical);

        if (is_dir($canonical)) {
            throw new \InvalidArgumentException('Watching a directory is not supported; pass a file path.');
        }

        if (! $this->withinRoots($canonical)) {
            throw new \InvalidArgumentException(sprintf(
                'Path is outside the allowed watch roots (%s).',
                implode(', ', $this->config->watchRoots()),
            ));
        }

        $extension = $this->extensionOf($canonical);
        if (! in_array($extension, $this->config->allowedExtensions, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Extension "%s" is not supported. Allowed: %s.',
                $extension,
                implode(', ', $this->config->allowedExtensions),
            ));
        }

        return [
            'path'         => $canonical,
            'extension'    => $extension,
            'display_name' => basename($canonical),
        ];
    }

    public function normalize(string $path): string
    {
        $path = $this->toAbsolute(trim($path));
        $parentReal = realpath(dirname($path));

        if (is_file($path)) {
            $real = realpath($path);
            if ($real !== false) {
                return $this->slashes($real);
            }
        }

        if ($parentReal !== false) {
            return rtrim($this->slashes($parentReal), '/') . '/' . basename($path);
        }

        return $this->slashes($path);
    }

    public function extensionOf(string $path): string
    {
        $base = strtolower(basename($path));

        if ($base === '.env' || str_starts_with($base, '.env.')) {
            return 'env';
        }

        return strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
    }

    public function withinRoots(string $canonical): bool
    {
        $canonical = rtrim($this->slashes($canonical), '/');
        $sensitive = DIRECTORY_SEPARATOR !== '\\';

        foreach ($this->config->watchRoots() as $root) {
            $root = rtrim($this->slashes((string) $root), '/');

            $matches = $sensitive
                ? str_starts_with($canonical . '/', $root . '/')
                : str_starts_with(strtolower($canonical . '/'), strtolower($root . '/'));

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    private function toAbsolute(string $path): string
    {
        $path = $this->slashes($path);

        $isAbsolute = str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:\//', $path) === 1
            || str_starts_with($path, '//');

        if ($isAbsolute) {
            return $path;
        }

        // Relative paths are resolved against the first watch root.
        $root = $this->config->watchRoots()[0] ?? WRITEPATH . 'sample-configs';

        return rtrim($this->slashes($root), '/') . '/' . ltrim($path, '/');
    }

    private function slashes(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
