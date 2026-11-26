<?php

declare(strict_types=1);

namespace Atom\HttpFoundation;

use ArrayAccess;
use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Override;
use Psr\Http\Message\MessageInterface;
use RuntimeException;

/**
 * Case-insensitive HTTP Header collection with optional immutability and CRLF-injection protection.
 *
 * @implements ArrayAccess<mixed, string|null>
 * @implements IteratorAggregate<string, list<string>>
 */
final class Header implements ArrayAccess, IteratorAggregate, Countable
{
    /** @var array<string, list<string>> Lower-cased header name => list of values */
    private array $map = [];

    /** @var array<string, string> Lower-cased header name => canonical case ("Content-Type") */
    private array $originalNames = [];

    private bool $immutable;

    /**
     * Static flag to ensure header_register_callback() is called only once per SAPI lifecycle.
     */
    private static bool $headerCallbackRegistered = false;

    /**
     * @param array<string, string|string[]> $headers
     */
    public function __construct(array $headers = [], bool $immutable = false, bool $registerHeaderCallback = true)
    {
        $this->immutable = false;

        foreach ($headers as $name => $value) {
            $this->set((string) $name, $value);
        }

        $this->immutable = $immutable;

        if ($registerHeaderCallback) {
            $this->registerHeaderCallback();
        }
    }

    /**
     * Create from PHP server variables / SAPI headers.
     *
     * @param array<string, mixed>|null $server Optional explicit server map (Swoole / testing)
     */
    public static function fromGlobals(bool $immutable = false, ?array $server = null): self
    {
        if ($server !== null) {
            return new self(self::fromServerArray($server), $immutable);
        }

        $raw = (\PHP_SAPI !== 'cli' && \function_exists('getallheaders'))
            ? (array) \getallheaders()
            : self::fromServerArray($_SERVER);

        return new self($raw, $immutable);
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function fromServerArray(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $strVal = (string) $value;
            if (\str_starts_with($key, 'HTTP_')) {
                $name = \substr($key, 5)
                    |> (static fn(string $s): string => \str_replace('_', ' ', $s))
                    |> \strtolower(...)
                    |> \ucwords(...)
                    |> (static fn(string $s): string => \str_replace(' ', '-', $s));
                $headers[$name] = $strVal;
                continue;
            }
            if ($key === 'CONTENT_TYPE') {
                $headers['Content-Type'] = $strVal;
                continue;
            }
            if ($key === 'CONTENT_LENGTH') {
                $headers['Content-Length'] = $strVal;
            }
        }

        return $headers;
    }

    private static function normalizeKey(string $name): string
    {
        self::assertSafeHeaderToken($name);
        return \strtolower(\trim($name));
    }

    private static function canonicalizeName(string $name): string
    {
        $parts = \explode('-', \trim($name));
        $parts = \array_map(static fn(string $p): string => \ucfirst(\strtolower($p)), $parts);
        return \implode('-', $parts);
    }

    private static function sanitizeValue(mixed $value): string
    {
        $str = (string) $value;
        if (\str_contains($str, "\r") || \str_contains($str, "\n") || \str_contains($str, "\0")) {
            throw new InvalidArgumentException('Invalid CRLF/null character in HTTP header value.');
        }

        return $str;
    }

    private static function assertSafeHeaderToken(string $name): void
    {
        if (\str_contains($name, "\r") || \str_contains($name, "\n") || \str_contains($name, "\0")) {
            throw new InvalidArgumentException('Invalid CRLF/null character in HTTP header name.');
        }
    }

    /**
     * @param string|string[] $value
     */
    public function set(string $name, string|array $value): static
    {
        $instance = $this->mutableInstance();
        $key = self::normalizeKey($name);
        $rawValues = \is_array($value) ? \array_values($value) : [$value];
        $values = \array_map(self::sanitizeValue(...), $rawValues);

        $instance->map[$key] = $values;
        $instance->originalNames[$key] = self::canonicalizeName($name);

        return $instance;
    }

    /**
     * @param string|string[] $value
     */
    public function add(string $name, string|array $value): static
    {
        $instance = $this->mutableInstance();
        $key = self::normalizeKey($name);
        $rawValues = \is_array($value) ? \array_values($value) : [$value];
        $values = \array_map(self::sanitizeValue(...), $rawValues);

        if (!isset($instance->map[$key])) {
            $instance->map[$key] = [];
            $instance->originalNames[$key] = self::canonicalizeName($name);
        }

        $instance->map[$key] = [...$instance->map[$key], ...$values];
        return $instance;
    }

    public function prepend(string $name, string $value): static
    {
        $instance = $this->mutableInstance();
        $key = self::normalizeKey($name);
        $clean = self::sanitizeValue($value);

        if (!isset($instance->map[$key])) {
            $instance->map[$key] = [];
            $instance->originalNames[$key] = self::canonicalizeName($name);
        }

        \array_unshift($instance->map[$key], $clean);
        return $instance;
    }

    public function remove(string $name): static
    {
        $instance = $this->mutableInstance();
        $key = self::normalizeKey($name);
        unset($instance->map[$key], $instance->originalNames[$key]);

        return $instance;
    }

    public function get(string $name, ?string $default = null): ?string
    {
        $key = self::normalizeKey($name);
        if (!isset($this->map[$key]) || $this->map[$key] === []) {
            return $default;
        }

        return \implode(', ', $this->map[$key]);
    }

    /**
     * @return list<string>
     */
    public function getAll(string $name): array
    {
        $key = self::normalizeKey($name);
        return $this->map[$key] ?? [];
    }

    public function has(string $name): bool
    {
        $key = self::normalizeKey($name);
        return isset($this->map[$key]) && $this->map[$key] !== [];
    }

    #[Override]
    public function count(): int
    {
        return \count($this->map);
    }

    /**
     * @return ArrayIterator<string, list<string>>
     */
    #[Override]
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->toArray());
    }

    #[Override]
    public function offsetExists(mixed $offset): bool
    {
        return \is_string($offset) && $this->has($offset);
    }

    #[Override]
    public function offsetGet(mixed $offset): mixed
    {
        return \is_string($offset) ? $this->get($offset, null) : null;
    }

    /**
     * @param mixed $offset
     * @param mixed $value
     */
    #[Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!\is_string($offset)) {
            throw new InvalidArgumentException('Header name must be a string');
        }
        if (!\is_string($value) && !\is_array($value)) {
            throw new InvalidArgumentException('Header value must be a string or array of strings');
        }

        $this->set($offset, $value);
    }

    #[Override]
    public function offsetUnset(mixed $offset): void
    {
        if (\is_string($offset)) {
            $this->remove($offset);
        }
    }

    /**
     * @return array<string, list<string>>
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
     * @return list<string>
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

    public function toString(string $eol = "\r\n"): string
    {
        return \implode($eol, $this->toLines());
    }

    public function send(bool $replace = true, ?int $responseCode = null): void
    {
        if (\headers_sent($file, $line)) {
            throw new RuntimeException("Headers already sent in {$file}:{$line}");
        }

        foreach ($this->map as $k => $values) {
            $name = $this->originalNames[$k] ?? $k;
            foreach ($values as $v) {
                \header(\sprintf('%s: %s', $name, $v), $replace);
            }
        }

        if ($responseCode !== null) {
            \http_response_code($responseCode);
        }
    }

    /**
     * @return array{mime: string, params: array<string, string>}|null
     */
    public function parseContentType(): ?array
    {
        $raw = $this->get('Content-Type', '');
        if ($raw === null || $raw === '') {
            return null;
        }

        $parts = \array_map(\trim(...), \explode(';', $raw));
        $mime = (string) \array_shift($parts);
        $params = [];

        foreach ($parts as $p) {
            if ($p === '') {
                continue;
            }

            [$k, $v] = \array_map(\trim(...), [...\explode('=', $p, 2), '']);
            $params[\strtolower($k)] = \trim($v, " \t\n\r\0\x0B\"'");
        }

        return ['mime' => \strtolower($mime), 'params' => $params];
    }

    /**
     * @return array<string, string|bool>
     */
    public function parseCacheControl(): array
    {
        $raw = $this->get('Cache-Control', '');
        if ($raw === null || $raw === '') {
            return [];
        }

        $parts = \array_map(\trim(...), \explode(',', $raw));
        $out = [];
        foreach ($parts as $p) {
            if ($p === '') {
                continue;
            }

            if (\str_contains($p, '=')) {
                [$k, $v] = \array_map(\trim(...), \explode('=', $p, 2));
                $out[\strtolower($k)] = $v;
            } else {
                $out[\strtolower($p)] = true;
            }
        }

        return $out;
    }

    /**
     * @return list<array{value: string, q: float}>
     */
    private function parseWeightedHeaderRaw(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $parts = \array_map(\trim(...), \explode(',', $raw));
        $items = [];
        $index = 0;

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $segments = \array_map(\trim(...), \explode(';', $part));
            $value = (string) \array_shift($segments);
            $q = 1.0;

            foreach ($segments as $seg) {
                if (\str_starts_with($seg, 'q=')) {
                    $qVal = \substr($seg, 2);
                    if (\is_numeric($qVal)) {
                        $q = \clamp((float) $qVal, 0.0, 1.0);
                    }
                    break;
                }
            }

            $items[] = ['value' => $value, 'q' => $q, 'index' => $index++];
        }

        \usort($items, static fn(array $a, array $b): int => $a['q'] === $b['q']
            ? ($a['index'] <=> $b['index'])
            : ($b['q'] <=> $a['q']));

        return \array_map(static fn(array $it): array => ['value' => $it['value'], 'q' => $it['q']], $items);
    }

    /**
     * @return list<array{value: string, q: float}>
     */
    public function parseAccept(): array
    {
        return $this->parseWeightedHeaderRaw((string) $this->get('Accept', ''));
    }

    /**
     * @return list<array{value: string, q: float}>
     */
    public function parseAcceptLanguage(): array
    {
        return $this->parseWeightedHeaderRaw((string) $this->get('Accept-Language', ''));
    }

    /**
     * @return list<array{value: string, q: float}>
     */
    public function parseAcceptEncoding(): array
    {
        return $this->parseWeightedHeaderRaw((string) $this->get('Accept-Encoding', ''));
    }

    public static function fromPsr7(MessageInterface $message, bool $immutable = false): self
    {
        $headers = [];
        foreach ($message->getHeaders() as $name => $values) {
            $headers[(string) $name] = $values;
        }

        return new self($headers, $immutable);
    }

    public function toPsr7(MessageInterface $message): MessageInterface
    {
        foreach ($this->toArray() as $name => $values) {
            $message = $message->withoutHeader($name);
            foreach ($values as $v) {
                $message = $message->withAddedHeader($name, $v);
            }
        }

        return $message;
    }

    public function registerHeaderCallback(?callable $callback = null): bool
    {
        if (self::$headerCallbackRegistered) {
            return false;
        }

        if (!\function_exists('header_register_callback') || \headers_sent()) {
            self::$headerCallbackRegistered = true;
            return true;
        }

        $cb = $callback ?? fn() => $this->send();
        \header_register_callback($cb);
        self::$headerCallbackRegistered = true;

        return true;
    }

    private function mutableInstance(): self
    {
        return $this->immutable ? clone $this : $this;
    }

    private function __clone()
    {
        $this->map = \array_map(\array_values(...), $this->map);
        $this->originalNames = [...$this->originalNames];
    }

    public function addRawLine(string $line): void
    {
        if (!\str_contains($line, ':')) {
            return;
        }

        [$name, $value] = \array_map(\trim(...), \explode(':', $line, 2));
        $this->add($name, $value);
    }

    public function withImmutable(bool $immutable = true): self
    {
        $clone = clone $this;
        $clone->immutable = $immutable;
        return $clone;
    }
}
