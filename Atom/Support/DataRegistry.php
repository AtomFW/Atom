<?php

declare(strict_types=1);

namespace Atom\Support;

use ArrayAccess;
use ArrayIterator;
use Atom\Config\DotConfig;
use Closure;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Override;
use Traversable;

/**
 * Bounded, Swoole-safe dot-notation application data registry (`'jakaśAplikacjia.core.version'`).
 *
 * Allows application developers to declare arbitrary UTF-8 dot-separated keys, assign any data,
 * read values via dot-paths OR directly from the underlying nested PHP array (`toArray()` / `$bag['ns']['key']`),
 * and automatically expose registered keys to HTML views (`{{jakaśAplikacjia.core.version}}`).
 *
 * @implements ArrayAccess<string, mixed>
 * @implements IteratorAggregate<string, mixed>
 */
final class DataRegistry implements ArrayAccess, Countable, IteratorAggregate
{
    private const int DEFAULT_MAX_KEYS = 4096;

    /** @var array<string, mixed> */
    private array $tree = [];

    /** @var array<string, true> */
    private array $registeredPaths = [];

    private int $maxKeys;

    /**
     * @param array<string, mixed> $initialData
     */
    public function __construct(array $initialData = [], int $maxKeys = self::DEFAULT_MAX_KEYS)
    {
        $this->maxKeys = \clamp($maxKeys, 16, 100_000);
        if ($initialData !== []) {
            $this->merge($initialData);
        }
    }

    /**
     * Assigns any value to a dot-separated path (e.g. `'jakaśAplikacjia.core.version'`) and stores it
     * inside the nested associative array structure.
     */
    public function set(string $path, mixed $value): self
    {
        $cleanPath = $this->validatePath($path);

        if (!isset($this->registeredPaths[$cleanPath]) && \count($this->registeredPaths) >= $this->maxKeys) {
            $oldest = \array_key_first($this->registeredPaths);
            if (\is_string($oldest)) {
                unset($this->registeredPaths[$oldest]);
                $this->tree = DotConfig::removeFromArray($this->tree, $oldest);
            }
        }

        $this->registeredPaths[$cleanPath] = true;
        $this->tree = DotConfig::setInArray($this->tree, $cleanPath, $value);

        return $this;
    }

    /**
     * Batch-assigns multiple dot-paths or nested associative arrays.
     *
     * @param array<int|string, mixed> $entries
     */
    public function merge(array $entries, string $prefix = ''): self
    {
        foreach ($entries as $key => $val) {
            if (!\is_string($key) || \trim($key) === '') {
                continue;
            }

            $composed = $prefix !== '' ? $prefix . '.' . \trim($key) : \trim($key);

            if (\is_array($val) && $val !== [] && !\array_is_list($val) && !\str_contains($key, '.')) {
                /** @var array<string, mixed> $val */
                $this->merge($val, $composed);
            } else {
                $this->set($composed, $val);
            }
        }

        return $this;
    }

    /**
     * Retrieves a value by dot-notation (`'jakaśAplikacjia.core.version'`) or top-level branch (`'jakaśAplikacjia'`).
     * Automatically evaluates lazy stateless closures when stored.
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $sentinel = new \stdClass();
        $found = DotConfig::getFromArray($this->tree, $path, $sentinel);
        if ($found === $sentinel) {
            return $default;
        }

        if ($found instanceof Closure) {
            return $found($this);
        }

        return $found;
    }

    /**
     * Checks whether a dot-path or array branch is defined in the registry.
     */
    public function has(string $path): bool
    {
        return DotConfig::hasInArray($this->tree, $path);
    }

    /**
     * Removes a dot-path or branch from the registry.
     */
    public function remove(string $path): self
    {
        $clean = \trim($path);
        if ($clean === '') {
            return $this;
        }

        unset($this->registeredPaths[$clean]);
        foreach (\array_keys($this->registeredPaths) as $registered) {
            if (\str_starts_with($registered, $clean . '.')) {
                unset($this->registeredPaths[$registered]);
            }
        }

        $this->tree = DotConfig::removeFromArray($this->tree, $clean);
        return $this;
    }

    /**
     * Clears all stored application data (safe per-request reset for Swoole workers).
     */
    public function clear(): self
    {
        $this->tree = [];
        $this->registeredPaths = [];

        return $this;
    }

    /**
     * Returns the full nested PHP associative array so developers can read values directly
     * via `$data->toArray()['jakaśAplikacjia']['core']['version']`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->tree;
    }

    /**
     * Alias for `toArray()`.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->tree;
    }

    /**
     * Lists all explicitly declared dot-paths.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return \array_keys($this->registeredPaths);
    }

    #[Override]
    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    #[Override]
    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    #[Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $key = (string) ($offset ?? '');
        if (\trim($key) === '') {
            throw new InvalidArgumentException('DataRegistry key must be a non-empty string.');
        }

        $this->set($key, $value);
    }

    #[Override]
    public function offsetUnset(mixed $offset): void
    {
        $this->remove((string) $offset);
    }

    #[Override]
    public function count(): int
    {
        return \count($this->registeredPaths);
    }

    #[Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->tree);
    }

    private function validatePath(string $path): string
    {
        $trimmed = \trim($path);
        if ($trimmed === '' || \str_contains($trimmed, "\0")) {
            throw new InvalidArgumentException('DataRegistry dot-path cannot be empty or contain null bytes.');
        }

        if (\preg_match('/^[\p{L}\p{N}_\-]+(?:\.[\p{L}\p{N}_\-]+)*$/u', $trimmed) !== 1) {
            throw new InvalidArgumentException("Invalid DataRegistry dot-path syntax: '{$path}'.");
        }

        return $trimmed;
    }
}
