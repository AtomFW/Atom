<?php

declare(strict_types=1);

namespace Atom\Component\Scheme;

use Psr\Log\LoggerInterface;
use Spatie\SchemaOrg\Schema;
use Stringable;

/**
 * Fluent wrapper around Spatie\SchemaOrg types producing JSON-LD scripts.
 *
 * @method static self webPage()
 * @method self url(string $url)
 * @method self image(string $image)
 * @method self inLanguage(string $lang)
 * @method self name(string $name)
 */
final class SchemaWrapper implements Stringable
{
    /** @var list<object|array<string, mixed>> */
    private array $graph = [];

    public function __construct(
        private object $instance,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param array<int, mixed> $arguments
     */
    public static function __callStatic(string $name, array $arguments): static
    {
        if (!\class_exists(Schema::class)) {
            throw new \RuntimeException(
                'Spatie\SchemaOrg\Schema class not found. Install spatie/schema-org via Composer.',
            );
        }

        if (\method_exists(Schema::class, $name)) {
            return new self(Schema::{$name}(...$arguments));
        }

        $alt = \ucfirst($name);
        if (\method_exists(Schema::class, $alt)) {
            return new self(Schema::{$alt}(...$arguments));
        }

        throw new \BadMethodCallException(\sprintf('No static factory %s::%s()', Schema::class, $name));
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        $this->logger?->debug('SchemaWrapper::__call', ['method' => $name, 'args' => $arguments]);

        if (!\method_exists($this->instance, $name)) {
            throw new \BadMethodCallException(
                \sprintf('Method %s not found on underlying instance of %s', $name, $this->instance::class),
            );
        }

        $result = $this->instance->{$name}(...$arguments);

        return \is_object($result) ? new self($result, $this->logger) : $result;
    }

    public function unwrap(): object
    {
        return $this->instance;
    }

    /**
     * @param object|array<string, mixed> $item
     */
    public function addGraphItem(object|array $item): static
    {
        $this->graph[] = $item instanceof self ? $item->unwrap() : $item;
        return $this;
    }

    public function mergeGraph(self $other): static
    {
        foreach ($other->graph as $g) {
            $this->graph[] = $g;
        }

        return $this;
    }

    public function toJsonLd(bool $pretty = false): string
    {
        if ($this->graph !== []) {
            $graphArray = [$this->jsonSerializeSafe($this->instance)];
            foreach ($this->graph as $g) {
                $graphArray[] = \is_array($g) ? $g : $this->jsonSerializeSafe($g);
            }

            return $this->encodeJson([
                '@context' => 'https://schema.org',
                '@graph' => $graphArray,
            ], $pretty);
        }

        $serialized = $this->jsonSerializeSafe($this->instance);
        if (\is_array($serialized) && !isset($serialized['@context'])) {
            $serialized = \array_merge(['@context' => 'https://schema.org'], $serialized);
        }

        return $this->encodeJson($serialized, $pretty);
    }

    public function toScript(bool $pretty = false): string
    {
        $json = $this->toJsonLd($pretty);
        return '<script type="application/ld+json">' . ($pretty ? "\n" . $json . "\n" : $json) . '</script>';
    }

    private function jsonSerializeSafe(object $obj): mixed
    {
        if ($obj instanceof \JsonSerializable) {
            return $obj->jsonSerialize();
        }

        if (\method_exists($obj, 'jsonSerialize')) {
            return $obj->jsonSerialize();
        }

        if (\method_exists($obj, 'toArray')) {
            return $obj->toArray();
        }

        if (\method_exists($obj, 'toArrayable')) {
            return $obj->toArrayable();
        }

        return $this->publicPropertiesToArray($obj);
    }

    /**
     * @return array<string, mixed>
     */
    private function publicPropertiesToArray(object $obj): array
    {
        $out = [];
        foreach ((array) $obj as $k => $v) {
            $key = \preg_replace('/^\x00.*\x00/', '', (string) $k) ?? (string) $k;
            $out[$key] = $v;
        }

        return $out;
    }

    private function encodeJson(mixed $data, bool $pretty = false): string
    {
        $flags = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG;
        if ($pretty) {
            $flags |= \JSON_PRETTY_PRINT;
        }

        $json = \json_encode($data, $flags);
        if ($json === false) {
            throw new \RuntimeException('json_encode error: ' . \json_last_error_msg());
        }

        return $json;
    }

    /**
     * @param class-string|null $class
     * @return list<string>
     */
    public static function listPublicMethods(?string $class = null): array
    {
        $target = $class ?? Schema::class;
        if (!\class_exists($target)) {
            throw new \InvalidArgumentException("Class {$target} not found.");
        }

        $ref = new \ReflectionClass($target);
        $methods = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->isStatic() || $m->isConstructor()) {
                continue;
            }
            $methods[] = $m->getName();
        }

        \sort($methods);
        return \array_values(\array_unique($methods));
    }

    /**
     * @return array{class: class-string, graph_count: int}
     */
    public function debugInfo(): array
    {
        return [
            'class' => $this->instance::class,
            'graph_count' => \count($this->graph),
        ];
    }

    /**
     * @param array<int, mixed> $args
     */
    public static function createType(string $type, array $args = []): static
    {
        if (\method_exists(Schema::class, $type)) {
            return new self(Schema::{$type}(...$args));
        }

        $alt = \lcfirst(\ucfirst($type));
        if (\method_exists(Schema::class, $alt)) {
            return new self(Schema::{$alt}(...$args));
        }

        throw new \InvalidArgumentException("Schema type factory {$type} not found on " . Schema::class);
    }

    #[\Override]
    public function __toString(): string
    {
        try {
            return $this->toScript();
        } catch (\Throwable) {
            return '';
        }
    }
}
