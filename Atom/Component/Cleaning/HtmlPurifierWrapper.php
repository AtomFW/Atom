<?php

declare(strict_types=1);

namespace Atom\Component\Cleaning;

use HTMLPurifier;
use HTMLPurifier_Config;
use InvalidArgumentException;
use RuntimeException;

/**
 * Wrapper over HTMLPurifier storing serializer cache inside runtime/cache/htmlpurifier
 * instead of vendor/ by default.
 */
final class HtmlPurifierWrapper
{
    private HTMLPurifier $purifier;
    private HTMLPurifier_Config $config;

    /** @var array<string, mixed> */
    private array $options = [];

    /**
     * @param array<string, mixed>|HTMLPurifier_Config|HTMLPurifier $source
     * @param array<string, mixed> $extraOptions
     */
    public function __construct(array|HTMLPurifier_Config|HTMLPurifier $source = [], array $extraOptions = [])
    {
        if (!\class_exists(HTMLPurifier::class)) {
            throw new RuntimeException('HTMLPurifier class not found. Install ezyang/htmlpurifier via Composer.');
        }

        if ($source instanceof HTMLPurifier) {
            $this->purifier = $source;
            $this->config = $this->createDefaultConfig();
            $this->options = [];
            return;
        }

        if ($source instanceof HTMLPurifier_Config) {
            $this->config = $source;
            $this->purifier = new HTMLPurifier($this->config);
            $this->options = [];
            return;
        }

        $opts = $extraOptions !== [] ? \array_merge($source, $extraOptions) : $source;
        $this->options = $opts;
        $this->config = $this->createConfigFromArray($opts);
        $this->purifier = new HTMLPurifier($this->config);
    }

    private function createDefaultConfig(): HTMLPurifier_Config
    {
        $config = HTMLPurifier_Config::createDefault();
        $serializerDir = $this->resolveDefaultSerializerDir();
        if ($serializerDir !== null) {
            $config->set('Cache.SerializerPath', $serializerDir);
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function createConfigFromArray(array $options): HTMLPurifier_Config
    {
        $config = HTMLPurifier_Config::createDefault();
        if (!\array_key_exists('Cache.SerializerPath', $options)) {
            $serializerDir = $this->resolveDefaultSerializerDir();
            if ($serializerDir !== null) {
                $config->set('Cache.SerializerPath', $serializerDir);
            }
        }

        foreach ($options as $k => $v) {
            $config->set((string) $k, $v);
        }

        return $config;
    }

    private function resolveDefaultSerializerDir(): ?string
    {
        $dir = \dirname(__DIR__, 3) . \DIRECTORY_SEPARATOR . 'runtime' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR . 'htmlpurifier';
        if (\is_dir($dir) || \mkdir($dir, 0775, true) || \is_dir($dir)) {
            return $dir;
        }

        return null;
    }

    /**
     * @param array<string, mixed>|HTMLPurifier_Config $cfg
     */
    public function setConfig(array|HTMLPurifier_Config $cfg): void
    {
        if ($cfg instanceof HTMLPurifier_Config) {
            $this->config = $cfg;
            $this->options = [];
        } else {
            $this->options = $cfg;
            $this->config = $this->createConfigFromArray($cfg);
        }

        $this->rebuildPurifier();
    }

    /**
     * @param array<string, mixed> $options
     */
    public function mergeOptions(array $options): void
    {
        $this->options = \array_merge($this->options, $options);
        foreach ($options as $k => $v) {
            $this->config->set((string) $k, $v);
        }

        $this->rebuildPurifier();
    }

    public function setOption(string $key, mixed $value): void
    {
        $this->options[$key] = $value;
        $this->config->set($key, $value);
        $this->rebuildPurifier();
    }

    public function getOption(string $key): mixed
    {
        return $this->options[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function resetConfig(): void
    {
        $this->options = [];
        $this->config = $this->createDefaultConfig();
        $this->rebuildPurifier();
    }

    private function rebuildPurifier(): void
    {
        $this->purifier = new HTMLPurifier($this->config);
    }

    public function getInstance(): HTMLPurifier
    {
        return $this->purifier;
    }

    public function getConfig(): HTMLPurifier_Config
    {
        return $this->config;
    }

    public function rebuildAfterManualConfigChange(): void
    {
        $this->rebuildPurifier();
    }

    /**
     * @param callable(HTMLPurifier_Config): void $cb
     */
    public function withConfig(callable $cb): void
    {
        $cb($this->config);
        $this->rebuildPurifier();
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (\method_exists($this->purifier, $name)) {
            return $this->purifier->{$name}(...$arguments);
        }

        if (\str_starts_with($name, 'config_')) {
            $configMethod = \substr($name, \strlen('config_'));
            if (\method_exists($this->config, $configMethod)) {
                $result = $this->config->{$configMethod}(...$arguments);
                if ($configMethod === 'set') {
                    $this->rebuildPurifier();
                }
                return $result;
            }
        }

        throw new InvalidArgumentException(\sprintf('Method %s::%s does not exist.', self::class, $name));
    }

    public function purify(string $html, mixed $configSpec = null): string
    {
        return $configSpec === null
            ? $this->purifier->purify($html)
            : $this->purifier->purify($html, $configSpec);
    }

    /**
     * @param array<int|string, mixed> $items
     * @return array<int|string, mixed>
     */
    public function purifyArray(array $items, mixed $configSpec = null): array
    {
        $out = [];
        foreach ($items as $k => $v) {
            $out[$k] = \is_string($v) ? $this->purify($v, $configSpec) : $v;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function listPurifierPublicMethods(): array
    {
        $ref = new \ReflectionObject($this->purifier);
        $names = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->isConstructor() || $m->isStatic()) {
                continue;
            }
            $names[] = $m->getName();
        }
        \sort($names);

        return $names;
    }

    /**
     * @param array<string, mixed> $mergedOptions
     */
    public function withMergedOptions(array $mergedOptions): self
    {
        return new self(\array_merge($this->options, $mergedOptions));
    }

    /**
     * @param array<string, mixed> $opts
     */
    public function applyOptions(array $opts): static
    {
        $this->mergeOptions($opts);
        return $this;
    }
}
