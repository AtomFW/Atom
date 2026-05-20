<?php

declare(strict_types=1);

namespace Atom\Component\Cleaning;

use HTMLPurifier;
use HTMLPurifier_Config;
use InvalidArgumentException;
use RuntimeException;

/**
 * HtmlPurifierWrapper
 *
 * - Wraps HTMLPurifier instance and proxies all public method calls to it.
 * - Provides convenient config helpers: setOption, getOption, setOptions, resetConfig.
 * - Accepts constructor input as: array $options, HTMLPurifier_Config or an existing HTMLPurifier instance.
 *
 *
 * Usage:
 *   $wrapper = new HtmlPurifierWrapper(['Core.Encoding' => 'UTF-8', 'HTML.SafeIframe' => true]);
 *   $clean = $wrapper->purify($dirtyHtml);
 */
final class HtmlPurifierWrapper
{
    private HTMLPurifier $purifier;
    private HTMLPurifier_Config $config;
    /** store last provided options (flat array of key => mixed) for introspection */
    private array $options = [];

    /**
     * Constructor accepts:
     *  - HTMLPurifier instance (will be used directly)
     *  - HTMLPurifier_Config instance (will create HTMLPurifier from it)
     *  - array of options (keyed by config keys like 'Core.Encoding', 'HTML.SafeIframe', etc.)
     *
     * @param array<string,mixed>|HTMLPurifier_Config|HTMLPurifier $source
     * @param array<string,mixed> $extraOptions additional options to merge (only when $source is array)
     *
     * @throws RuntimeException if HTMLPurifier classes are not available
     */
    public function __construct(array|HTMLPurifier_Config|HTMLPurifier $source = [], array $extraOptions = [])
    {
        if (!class_exists(HTMLPurifier::class)) {
            throw new RuntimeException('HTMLPurifier class not found. Install ezyang/htmlpurifier via Composer.');
        }

        if ($source instanceof HTMLPurifier) {
            $this->purifier = $source;
            // We attempt to extract config from purifier if possible.
            // HTMLPurifier does not provide a direct getter for config; so we create a default config for wrapper use.
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

        // $source is array of options
        $opts = $source;
        if (!empty($extraOptions)) {
            $opts = \array_merge($opts, $extraOptions);
        }
        $this->options = $opts;
        $this->config = $this->createConfigFromArray($opts);
        $this->purifier = new HTMLPurifier($this->config);
    }

    /**
     * Create a default HTMLPurifier_Config instance.
     *
     * @return HTMLPurifier_Config
     */
    private function createDefaultConfig(): HTMLPurifier_Config
    {
        return HTMLPurifier_Config::createDefault();
    }

    /**
     * Create HTMLPurifier_Config from a flat array of options.
     *
     * Keys should be full config keys, e.g. 'Core.Encoding', 'HTML.SafeIframe'.
     *
     * @param array<string,mixed> $options
     * @return HTMLPurifier_Config
     */
    private function createConfigFromArray(array $options): HTMLPurifier_Config
    {
        $config = HTMLPurifier_Config::createDefault();
        foreach ($options as $k => $v) {
            // allow nested arrays for groups? HTMLPurifier uses flat keys; we keep it simple.
            $config->set((string)$k, $v);
        }
        return $config;
    }

    /**
     * Replace current config with provided array or HTMLPurifier_Config and rebuild internal purifier.
     *
     * @param array<string,mixed>|HTMLPurifier_Config $cfg
     * @return void
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
     * Merge provided options into current config and rebuild purifier.
     *
     * @param array<string,mixed> $options
     */
    public function mergeOptions(array $options): void
    {
        $this->options = \array_merge($this->options, $options);
        // apply to config
        foreach ($options as $k => $v) {
            $this->config->set((string)$k, $v);
        }
        $this->rebuildPurifier();
    }

    /**
     * Set a single config option and rebuild purifier.
     *
     * @param string $key e.g. 'Core.Encoding'
     * @param mixed $value
     */
    public function setOption(string $key, mixed $value): void
    {
        $this->options[$key] = $value;
        $this->config->set($key, $value);
        $this->rebuildPurifier();
    }

    /**
     * Get a single config option (if present in stored options), otherwise null.
     *
     * Note: HTMLPurifier_Config does not provide a generic get() in all versions; we rely on our stored map.
     *
     * @param string $key
     * @return mixed|null
     */
    public function getOption(string $key): mixed
    {
        return $this->options[$key] ?? null;
    }

    /**
     * Get all options (as last set by constructor / setConfig / setOption / mergeOptions).
     *
     * @return array<string,mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Reset config to HTMLPurifier defaults (clears stored options).
     */
    public function resetConfig(): void
    {
        $this->options = [];
        $this->config = $this->createDefaultConfig();
        $this->rebuildPurifier();
    }

    /**
     * Rebuild underlying HTMLPurifier instance using current $this->config.
     */
    private function rebuildPurifier(): void
    {
        // Recreate purifier with new config
        $this->purifier = new HTMLPurifier($this->config);
    }

    /**
     * Provide direct access to the underlying HTMLPurifier instance.
     *
     * @return HTMLPurifier
     */
    public function getInstance(): HTMLPurifier
    {
        return $this->purifier;
    }

    /**
     * Provide direct access to the HTMLPurifier_Config instance.
     * Note:
     * - mutating returned config will not automatically rebuild purifier until you call rebuild() or setConfig again.
     *
     * @return HTMLPurifier_Config
     */
    public function getConfig(): HTMLPurifier_Config
    {
        return $this->config;
    }

    /**
     * Rebuild purifier after manual modifications to config obtained by getConfig().
     *
     * @return void
     */
    public function rebuildAfterManualConfigChange(): void
    {
        $this->rebuildPurifier();
    }

    /**
     * Run callable with the config as parameter. Useful for advanced manipulations (addElement, addAttribute, etc.)
     *
     * Example:
     *   $wrapper->withConfig(function(HTMLPurifier_Config $c) {
     *       $def = $c->getHTMLDefinition(true);
     *       $def->addElement(...);
     *   });
     *
     * @param callable $cb function(HTMLPurifier_Config $config): void
     */
    public function withConfig(callable $cb): void
    {
        $cb($this->config);
        // caller possibly mutated config; rebuild purifier to apply changes
        $this->rebuildPurifier();
    }

    /**
     * Magic call: delegate any other public method invoked on wrapper to internal HTMLPurifier instance.
     *
     * This ensures the wrapper "exposes" all public methods of HTMLPurifier without listing them manually.
     *
     * @param string $name
     * @param array<int,mixed> $arguments
     * @return mixed
     *
     * @throws InvalidArgumentException if target method does not exist
     */
    public function __call(string $name, array $arguments): mixed
    {
        // allow calling methods that exist on purifier
        if (method_exists($this->purifier, $name)) {
            // forward call and return result
            return $this->purifier->{$name}(...$arguments);
        }

        // Also allow calling methods on the config object via "config::<method>" notation
        // e.g. $wrapper->config_set('HTML.DefinitionID', 'myid');
        if (str_starts_with($name, 'config_')) {
            $configMethod = substr($name, \strlen('config_'));
            if (method_exists($this->config, $configMethod)) {
                return $this->config->{$configMethod}(...$arguments);
            }
        }

        throw new InvalidArgumentException(\sprintf('Method %s::%s does not exist.', self::class, $name));
    }

    /**
     * Helper: call purifier->purify() (explicit typed wrapper).
     *
     * @param string $html
     * @param mixed|null $configSpec optional config id or context accepted by HTMLPurifier (passed-through)
     * @return string cleaned html
     */
    public function purify(string $html, mixed $configSpec = null): string
    {
        // direct call to purifier->purify; uses __call if necessary
        if ($configSpec === null) {
            return $this->purifier->purify($html);
        }
        return $this->purifier->purify($html, $configSpec);
    }

    /**
     * Helper: convenience for purifying an array of values.
     *
     * @param array<int|string,string> $items
     * @param mixed|null $configSpec
     * @return array<int|string,string>
     */
    public function purifyArray(array $items, mixed $configSpec = null): array
    {
        $out = [];
        foreach ($items as $k => $v) {
            if (!is_string($v)) {
                $out[$k] = $v;
                continue;
            }
            $out[$k] = $this->purify($v, $configSpec);
        }
        return $out;
    }

    /**
     * Utility: return a list of public method names available on underlying HTMLPurifier instance.
     *
     * @return array<int,string>
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
        sort($names);
        return $names;
    }

    /**
     * Create a clone of this wrapper with merged options (does not mutate original).
     *
     * @param array<string,mixed> $mergedOptions
     * @return HtmlPurifierWrapper
     */
    public function withMergedOptions(array $mergedOptions): HtmlPurifierWrapper
    {
        $newOpts = array_merge($this->options, $mergedOptions);
        return new self($newOpts);
    }

    /**
     * Convenience: set multiple options and return $this for fluent usage.
     *
     * @param array<string,mixed> $opts
     * @return $this
     */
    public function applyOptions(array $opts): static
    {
        $this->mergeOptions($opts);
        return $this;
    }
}
