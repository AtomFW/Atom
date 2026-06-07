<?php

declare(strict_types=1);

namespace Atom\Component\Scheme;

use Spatie\SchemaOrg\Schema;
use Psr\Log\LoggerInterface;

/**
 * SchemaWrapper
 *
 * - Lightweight wrapper around Spatie\SchemaOrg (Schema and returned types).
 * - Proxies all method calls to underlying Spatie types (magic __call / __callStatic).
 * - Fluent: if proxied method returns a Schema object (or type) we wrap it again.
 * - Provides helpers: toScript(), toJsonLd(), addGraphItem(), mergeGraph(), listPublicMethods(), unwrap().
 *
 * Usage examples are below the class.
 */
final class SchemaWrapper
{
    /**
     * Underlying Spatie object (Schema type instance) — can be any object returned by Schema::<type>().
     *
     * @var object
     */
    private object $instance;

    /**
     * Optional graph items that will be included when rendering @graph.
     *
     * @var object[] underlying spatie objects or arrays (jsonSerialize results)
     */
    private array $graph = [];

    /**
     * Optional logger for debug (not required).
     */
    private ?LoggerInterface $logger;

    /**
     * Create wrapper from an underlying Spatie Schema object (or any object).
     *
     * @param object $instance object returned from Spatie\SchemaOrg\Schema::<type>() or any other Spatie object
     * @param LoggerInterface|null $logger optional PSR-3 logger
     */
    public function __construct(object $instance, ?LoggerInterface $logger = null)
    {
        $this->instance = $instance;
        $this->logger = $logger;
    }

    /**
     * Static factory: forward static calls to Spatie\SchemaOrg\Schema::<type>(...$args)
     *
     * Example: SchemaWrapper::person()->name('Jan')->toScript();
     *
     * @param string $name
     * @param array<int,mixed> $arguments
     * @return static
     */
    public static function __callStatic(string $name, array $arguments): static
    {
        if (!class_exists(Schema::class)) {
            throw new \RuntimeException(
                'Spatie\SchemaOrg\Schema class not found. Install spatie/schema-org via Composer.'
            );
        }

        // Attempt to call Schema::<name>(...$arguments)
        if (method_exists(Schema::class, $name)) {
            $obj = Schema::{$name}(...$arguments);
            return new static($obj);
        }

        // try PascalCase / camel case fallback: allow both person and Person
        $alt = ucfirst($name);
        if (method_exists(Schema::class, $alt)) {
            $obj = Schema::{$alt}(...$arguments);
            return new static($obj);
        }

        throw new \BadMethodCallException(\sprintf('No static factory %s::%s()', Schema::class, $name));
    }

    /**
     * Magic proxy for instance methods.
     * If proxied call returns an object (Spatie Schema type) we wrap it and return wrapper for chaining.
     * If it returns primitive/array we return it directly.
     *
     * @param string $name
     * @param array<int,mixed> $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments): mixed
    {
        if ($this->logger) {
            $this->logger->debug('SchemaWrapper::__call', ['method' => $name, 'args' => $arguments]);
        }

        if (!method_exists($this->instance, $name)) {
            throw new \BadMethodCallException(
                \sprintf('Method %s not found on underlying instance of %s',
                $name,
                \get_class($this->instance))
            );
        }

        $result = $this->instance->{$name}(...$arguments);

        // If result is an object (likely a Spatie type), wrap it (so chaining stays in wrapper)
        if (is_object($result)) {
            return new static($result, $this->logger);
        }

        // primitive/array/etc -> return directly
        return $result;
    }

    /**
     * Return raw underlying object (Spatie type). Useful for advanced operations.
     *
     * @return object
     */
    public function unwrap(): object
    {
        return $this->instance;
    }

    /**
     * Add an item (raw Spatie wrapper or wrapper instance) to the internal @graph list.
     *
     * @param object|array $item
     * @return $this
     */
    public function addGraphItem(object|array $item): static
    {
        if ($item instanceof self) {
            $this->graph[] = $item->unwrap();
        } elseif (is_object($item)) {
            $this->graph[] = $item;
        } elseif (is_array($item)) {
            // Accept already serialized arrays (jsonSerialize)
            $this->graph[] = $item;
        } else {
            throw new \InvalidArgumentException('Graph item must be wrapper, object, or array.');
        }

        return $this;
    }

    /**
     * Merge (append) graph items from another wrapper.
     *
     * @param SchemaWrapper $other
     * @return $this
     */
    public function mergeGraph(SchemaWrapper $other): static
    {
        foreach ($other->graph as $g) {
            $this->graph[] = $g;
        }
        return $this;
    }

    /**
     * Render JSON-LD string for this instance.
     * If graph items exist the output will be a JSON-LD with "@graph": [...]
     *
     * @param bool $pretty If true formats JSON (for debug)
     * @return string JSON (not wrapped in <script>)
     */
    public function toJsonLd(bool $pretty = false): string
    {
        // If graph items present -> produce @graph combined JSON-LD
        if (!empty($this->graph)) {
            $graphArray = [];
            // include main instance as first item
            $main = $this->jsonSerializeSafe($this->instance);
            $graphArray[] = $main;
            foreach ($this->graph as $g) {
                $graphArray[] = $this->jsonSerializeSafe($g);
            }
            $out = [
                '@context' => 'https://schema.org',
                '@graph' => $graphArray,
            ];
            return $this->encodeJson($out, $pretty);
        }

        // otherwise normally serialize the underlying instance
        $serialized = $this->jsonSerializeSafe($this->instance);
        // ensure context present
        if (is_array($serialized) && !isset($serialized['@context'])) {
            $serialized = array_merge(['@context' => 'https://schema.org'], $serialized);
        }
        return $this->encodeJson($serialized, $pretty);
    }

    /**
     * Return full <script type="application/ld+json">...</script> block
     */
    public function toScript(bool $pretty = false): string
    {
        $json = $this->toJsonLd($pretty);
        return '<script type="application/ld+json">' . ($pretty ? "\n" . $json . "\n" : $json) . '</script>';
    }

    /**
     * Helper: safe jsonSerialize of objects that provide jsonSerialize() method,
     * otherwise attempt to call toArray()/toArrayable or cast to array.
     *
     * @param object $obj
     * @return array|mixed
     */
    private function jsonSerializeSafe(object $obj): mixed
    {
        // If object implements JsonSerializable
        if ($obj instanceof \JsonSerializable) {
            return $obj->jsonSerialize();
        }

        // Try jsonSerialize method if exists
        if (method_exists($obj, 'jsonSerialize')) {
            return $obj->jsonSerialize();
        }

        // Some Spatie types have method toArray() or toArrayable
        if (method_exists($obj, 'toArray')) {
            return $obj->toArray();
        }
        if (method_exists($obj, 'toArrayable')) {
            return $obj->toArrayable();
        }

        // fallback: try casting to array of public props (best-effort)
        return $this->publicPropertiesToArray($obj);
    }

    /**
     * Attempt to convert public properties of object to array (fallback).
     *
     * @param object $obj
     * @return array
     */
    private function publicPropertiesToArray(object $obj): array
    {
        $out = [];
        foreach ((array)$obj as $k => $v) {
            // remove null-byte class key prefix if exists
            $key = preg_replace('/^\x00.*\x00/', '', $k);
            $out[$key] = $v;
        }
        return $out;
    }

    /**
     * Encode JSON with stable flags.
     *
     * @param mixed $data
     * @param bool $pretty
     * @return string
     */
    private function encodeJson(mixed $data, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $json = json_encode($data, $flags);
        if ($json === false) {
            // fallback: attempt json last error reporting
            $err = json_last_error_msg();
            throw new \RuntimeException('json_encode error: ' . $err);
        }
        return $json;
    }

    /**
     * List public methods of a class (helper). If no class provided, list methods of Schema class.
     *
     * @param string|null $class
     * @return string[] list of public method names
     */
    public static function listPublicMethods(?string $class = null): array
    {
        $class = $class ?? Schema::class;
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Class {$class} not found.");
        }
        $ref = new \ReflectionClass($class);
        $methods = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->isStatic() || $m->isConstructor()) {
                continue;
            }
            $methods[] = $m->getName();
        }
        sort($methods);
        return array_values(array_unique($methods));
    }

    /**
     * Dump debug info — optional
     *
     * @return array<string,mixed>
     */
    public function debugInfo(): array
    {
        return [
            'class' => \get_class($this->instance),
            'graph_count' => \count($this->graph),
        ];
    }

    /**
     * Convenience: create wrapper by calling Schema::<type>(...$args) with case-insensitive name.
     *
     * Example: SchemaWrapper::createType('person', ['name'=>'x']) -> equivalent to Schema::person()->name('x')
     *
     * @param string $type
     * @param array<int,mixed> $args
     * @return static
     */
    public static function createType(string $type, array $args = []): static
    {
        $name = $type;
        if (method_exists(Schema::class, $name)) {
            $obj = Schema::{$name}(...$args);
            return new static($obj);
        }
        $alt = lcfirst(ucfirst($name));
        if (method_exists(Schema::class, $alt)) {
            $obj = Schema::{$alt}(...$args);
            return new static($obj);
        }
        throw new \InvalidArgumentException("Schema type factory {$type} not found on " . Schema::class);
    }

    /**
     * __toString renders JSON-LD script
     */
    public function __toString(): string
    {
        try {
            return $this->toScript();
        } catch (\Throwable $e) {
            return '';
        }
    }
}
