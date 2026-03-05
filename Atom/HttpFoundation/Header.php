<?php

declare(strict_types=1);

/*
    Header class
    the header is manager header structure
*/

namespace Atom\HttpFoundation;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use ArrayIterator;
use InvalidArgumentException;
use Psr\Http\Message\MessageInterface;

/**
 * HeaderCollection
 *
 * - Immutable by default optionally (pass $immutable = false to mutate in-place)
 * - Uses case-insensitive header keys internally, preserves original-case for output
 *
 * Comments and docblocks in English.
 */
final class Header implements ArrayAccess, IteratorAggregate, Countable
{
    /** @var array<string, string[]>  Lower-cased header name => array of values */
    private array $map = [];

    /** @var array<string, string>  Lower-cased header name => original canonical case ("Content-Type") */
    private array $originalNames = [];

    private bool $immutable;

    /**
     * Static flag to ensure header_register_callback() is called only once per PHP request lifecycle
     */
    private static bool $headerCallbackRegistered = false;


    /**
     * @param array<string, string|string[]> $headers initial headers (name => value or array of values)
     * @param bool $immutable if true methods like set/add/remove will return new instance instead of mutating
     */
    public function __construct(array $headers = [], bool $immutable = false, bool $registerHeaderCallback = true)
    {
        // set to false even
        // if $immutable is set to true due to setting initial headers without changing the $immutable settings
        $this->immutable = false;

        // Register header callback to capture headers set by PHP functions (e.g. setcookie) if enabled
        foreach ($headers as $name => $value) {
            $this->set($name, $value);
        }

        $this->immutable = $immutable;

        // auto-register header callback on construction if enabled and not already registered (e.g. for fromGlobals)
        if ($registerHeaderCallback) {
            $this->registerHeaderCallback();
        }
    }

    /**
     * Create from PHP globals / server variables (fallback polyfill for getallheaders).
     */
    public static function fromGlobals(bool $immutable = false): self
    {
        $raw = function_exists('getallheaders') ? (array) getallheaders() : self::fromServerSuperglobals();
        $parsed = [];
        foreach ($raw as $k => $v) {
            $parsed[$k] = $v;
        }
        return new self($parsed, $immutable);
    }

    /**
     * Polyfill to extract headers from $_SERVER (works on most SAPI).
     */
    private static function fromServerSuperglobals(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = $value;
                continue;
            }
            // Content-Type, Content-Length are not prefixed with HTTP_
            if ($key === 'CONTENT_TYPE') {
                $headers['Content-Type'] = $value;
                continue;
            }
            if ($key === 'CONTENT_LENGTH') {
                $headers['Content-Length'] = $value;
                continue;
            }
        }
        return $headers;
    }

    /**
     * Normalize header key to lowercase trimmed.
     */
    private static function normalizeKey(string $name): string
    {
        return strtolower(trim($name));
    }

    /**
     * Canonicalize header name for output: Title-Case (Content-Type)
     */
    private static function canonicalizeName(string $name): string
    {
        $name = trim($name);
        $parts = explode('-', $name);
        $parts = array_map(fn($p) => ucfirst(strtolower($p)), $parts);
        return implode('-', $parts);
    }

    /**
     * Set header (replace existing values). Accepts string or array of strings.
     *
     * If immutable mode is enabled, returns new instance. Otherwise returns $this.
     *
     * @param string $name
     * @param string|string[] $value
     */
    public function set(string $name, string|array $value): static
    {
        $instance = $this->mutableInstance();
        $key = self::normalizeKey($name);
        $values = \is_array($value) ? array_values($value) : [$value];
        $values = array_map('strval', $values);
        $instance->map[$key] = $values;
        $instance->originalNames[$key] = self::canonicalizeName($name);
        return $instance;
    }

    /**
     * Add header value(s) without removing existing ones.
     *
     * @param string $name
     * @param string|string[] $value
     */
    public function add(string $name, string|array $value): static
    {
        $instance = $this->mutableInstance();
        $key = self::normalizeKey($name);
        $values = \is_array($value) ? array_values($value) : [$value];
        $values = array_map('strval', $values);
        if (!isset($instance->map[$key])) {
            $instance->map[$key] = [];
            $instance->originalNames[$key] = self::canonicalizeName($name);
        }
        $instance->map[$key] = \array_merge($instance->map[$key], $values);
        return $instance;
    }

    /**
     * Prepend a value for a header (useful for Cache-Control ordering).
     */
    public function prepend(string $name, string $value): static
    {
        $instance = $this->mutableInstance();
        $key = self::normalizeKey($name);
        if (!isset($instance->map[$key])) {
            $instance->map[$key] = [];
            $instance->originalNames[$key] = self::canonicalizeName($name);
        }
        array_unshift($instance->map[$key], $value);
        return $instance;
    }

    /**
     * Remove header entirely.
     */
    public function remove(string $name): static
    {
        $instance = $this->mutableInstance();
        $key = self::normalizeKey($name);
        unset($instance->map[$key], $instance->originalNames[$key]);
        return $instance;
    }

    /**
     * Get first header value as string (joins multiple values with comma if present).
     */
    public function get(string $name, ?string $default = null): ?string
    {
        $key = self::normalizeKey($name);
        if (!isset($this->map[$key]) || \count($this->map[$key]) === 0) {
            return $default;
        }
        // Per RFC, some headers are comma-joined; return comma-joined by default
        return implode(', ', $this->map[$key]);
    }

    /**
     * Get all values for header as array.
     *
     * @return string[]
     */
    public function getAll(string $name): array
    {
        $key = self::normalizeKey($name);
        return $this->map[$key] ?? [];
    }

    public function has(string $name): bool
    {
        $key = self::normalizeKey($name);
        return isset($this->map[$key]) && count($this->map[$key]) > 0;
    }

    /**
     * Return count of distinct header names.
     */
    public function count(): int
    {
        return count($this->map);
    }

    /**
     * IteratorAggregate: iterate as name => values[]
     * Preserves canonical name casing for keys.
     *
     * @return ArrayIterator<string, string[]>
     */
    public function getIterator(): ArrayIterator
    {
        $out = [];
        foreach ($this->map as $k => $values) {
            $out[$this->originalNames[$k] ?? $k] = $values;
        }
        return new ArrayIterator($out);
    }

    // ArrayAccess: treat offset as header name; offsetGet returns first value (comma-joined) or null
    public function offsetExists(mixed $offset): bool
    {
        if (!\is_string($offset)) {
            return false;
        }

        return $this->has($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (!\is_string($offset)) {
            return null;
        }

        return $this->get($offset, null);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!\is_string($offset)) {
            throw new InvalidArgumentException('Header name must be a string');
        }
        $this->set($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        if (!\is_string($offset)) {
            return;
        }

        $this->remove($offset);
    }

    /**
     * Return array representation: canonical name => array of values
     *
     * @return array<string, string[]>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->map as $k => $values) {
            $out[$this->originalNames[$k] ?? $k] = $values;
        }
        return $out;
    }

    /**
     * Return header lines: ["Name: value", ...]
     * If header has multiple values, each becomes its own line.
     *
     * @return string[]
     */
    public function toLines(): array
    {
        $lines = [];
        foreach ($this->map as $k => $values) {
            $name = $this->originalNames[$k] ?? $k;
            foreach ($values as $v) {
                $lines[] = $name . ': ' . $v;
            }
        }
        return $lines;
    }

    /**
     * Return raw string representation (CRLF separated).
     */
    public function toString(string $eol = "\r\n"): string
    {
        return implode($eol, $this->toLines());
    }

    /**
     * Send headers to client using header() and optionally set response code.
     *
     * Replaces or appends depending on $replace param.
     */
    public function send(bool $replace = true, ?int $responseCode = null): void
    {
        if (headers_sent($file, $line)) {
            throw new \RuntimeException("Headers already sent in $file:$line");
        }

        foreach ($this->map as $k => $values) {
            $name = $this->originalNames[$k] ?? $k;
            foreach ($values as $v) {
                // Use header() with replace=false to allow multiple same-name headers
                header(\sprintf('%s: %s', $name, $v), $replace);
            }
        }

        if ($responseCode !== null) {
            http_response_code($responseCode);
        }
    }

    /**
     * Parse and return Content-Type header structured info:
     * ['mime'=>'text/html', 'params' => ['charset' => 'utf-8']]
     */
    public function parseContentType(): ?array
    {
        $raw = $this->get('Content-Type', '');

        if ($raw === '') {
            return null;
        }

        $parts = \array_map('trim', \explode(';', $raw));
        $mime = \array_shift(array: $parts);
        $params = [];
        foreach ($parts as $p) {
            if ($p === '') {
                continue;
            }

            [$k, $v] = \array_map('trim', \array_merge(explode('=', $p, 2), ['']));
            $k = strtolower($k);
            $v = trim($v, " \t\n\r\0\x0B\"'");
            $params[$k] = $v;
        }
        return ['mime' => \strtolower($mime), 'params' => $params];
    }

    /**
     * Parse Cache-Control header into associative flags/values.
     * E.g. "max-age=3600, public" => ['max-age' => '3600', 'public' => true]
     *
     * @return array<string, string|bool>
     */
    public function parseCacheControl(): array
    {
        $raw = $this->get('Cache-Control', '');
        if ($raw === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $raw));
        $out = [];
        foreach ($parts as $p) {
            if ($p === '') {
                continue;
            }

            if (str_contains($p, '=')) {
                [$k, $v] = array_map('trim', explode('=', $p, 2));
                $out[strtolower($k)] = $v;
            } else {
                $out[strtolower($p)] = true;
            }
        }
        return $out;
    }

     /**
     * Parse Accept-like headers (Accept, Accept-Language, Accept-Encoding)
     * Returns array of ['value' => string, 'q' => float] ordered by q desc then appearance order
     */
    private function parseWeightedHeaderRaw(string $raw): array
    {
        $items = [];
        if ($raw === '') {
            return $items;
        }

        $parts = array_map('trim', explode(',', $raw));
        $index = 0;
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $segments = array_map('trim', explode(';', $part));
            $value = array_shift($segments);
            $q = 1.0;
            foreach ($segments as $seg) {
                if (str_starts_with($seg, 'q=')) {
                    $qVal = substr($seg, 2);
                    $q = is_numeric($qVal) ? (float) $qVal : $q;
                    break;
                }
            }
            $items[] = ['value' => $value, 'q' => $q, 'index' => $index++];
        }
        // sort by q desc, then index asc to keep stable tie-breaking
        usort($items, function ($a, $b) {
            if ($a['q'] === $b['q']) {
                return $a['index'] <=> $b['index'];
            }
            return $b['q'] <=> $a['q'];
        });
        // drop index now
        return array_map(fn($it) => ['value' => $it['value'], 'q' => $it['q']], $items);
    }

    public function parseAccept(): array
    {
        $raw = $this->get('Accept', '');
        return $this->parseWeightedHeaderRaw($raw);
    }

    public function parseAcceptLanguage(): array
    {
        $raw = $this->get('Accept-Language', '');
        return $this->parseWeightedHeaderRaw($raw);
    }

    public function parseAcceptEncoding(): array
    {
        $raw = $this->get('Accept-Encoding', '');
        return $this->parseWeightedHeaderRaw($raw);
    }

    /**
     * Convert PSR-7 MessageInterface headers into HeaderCollection
     */
    public static function fromPsr7(MessageInterface $message, bool $immutable = false): self
    {
        $headers = [];
        foreach ($message->getHeaders() as $name => $values) {
            $headers[$name] = $values;
        }
        return new self($headers, $immutable);
    }

    /**
     * Apply headers from this collection to a PSR-7 MessageInterface instance.
     * Returns a new MessageInterface instance (PSR-7 objects are immutable).
     */
    public function toPsr7(MessageInterface $message): MessageInterface
    {
        // Remove any existing headers that we will replace
        foreach ($this->toArray() as $name => $values) {
            // withoutHeader exists on PSR-7 MessageInterface
            $message = $message->withoutHeader($name);
            foreach ($values as $v) {
                $message = $message->withAddedHeader($name, $v);
            }
        }
        return $message;
    }

    /**
     * Register a single header_register_callback for this request lifecycle.
     * Returns true if callback was registered now, false if it had been registered earlier.
     */
    public function registerHeaderCallback(?callable $callback = null): bool
    {
        if (static::$headerCallbackRegistered) {
            return false;
        }

        if ($callback === null) {
            $callback = fn() => $this->send();
        }

        header_register_callback($callback);
        static::$headerCallbackRegistered = true;
        return true;
    }

    /**
     * Helper: create a mutable copy or return $this according to $this->immutable.
     */
    private function mutableInstance(): self
    {
        if ($this->immutable) {
            // clone and return new mutable instance (preserve immutability on original)
            $clone = clone $this;
            // cloned instance should keep immutable flag as the same (so subsequent ops still return clones)
            // this design keeps consistent immutable behaviour: operations always return an instance.
            return $clone;
        }
        return $this;
    }

    // Prevent external cloning to avoid surprises (we clone internally above)
    private function __clone()
    {
        $this->map = array_map(function ($a) {
            return array_values($a);
        }, $this->map);
        $this->originalNames = \array_merge([], $this->originalNames);
    }

    /**
     * Parse a raw header line like "Name: value" and add to collection.
     *
     * @param string $line
     * @return void
     */
    public function addRawLine(string $line): void
    {
        if (!str_contains($line, ':')) {
            return;
        }
        [$name, $value] = array_map('trim', explode(':', $line, 2));
        $this->add($name, $value);
    }

    /**
     * Convenience: create a copy that is immutable.
     */
    public function withImmutable(bool $immutable = true): self
    {
        $clone = clone $this;
        $clone->immutable = $immutable;
        return $clone;
    }
}
