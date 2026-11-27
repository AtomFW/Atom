<?php

declare(strict_types=1);

use Atom\Async\Async;
use Atom\Async\Future;
use Atom\Atom;
use Atom\Config\DotConfig;
use Atom\Config\EnvironmentVariables;
use Atom\Support\AtomDataSentinel;
use Atom\Support\DataRegistry;

if (!\function_exists('env')) {
    /**
     * Retrieves and normalizes a configuration value from `$_ENV`, falling back to `$default`.
     */
    function env(string $key, mixed $default = null): mixed
    {
        return EnvironmentVariables::getEnvironmentVariable($key, $default);
    }
}

if (!\function_exists('config')) {
    /**
     * Developer-friendly dot-notation configuration reader (`'atom.core.version'`, `'connectionInformation.extension'`).
     */
    function config(string $path, mixed $default = null, bool $useDefaultChoice = true): mixed
    {
        return DotConfig::get($path, $default, $useDefaultChoice);
    }
}

if (!\function_exists('atom_config')) {
    /**
     * Alias for `config()` providing dot-notation access to Atom and application configuration.
     */
    function atom_config(string $path, mixed $default = null, bool $useDefaultChoice = true): mixed
    {
        return DotConfig::get($path, $default, $useDefaultChoice);
    }
}

if (!\function_exists('atom_data')) {
    /**
     * Developer-friendly dot-notation application data registry helper (`'jakaśAplikacjia.core.version'`).
     *
     * - `atom_data()` returns the active `DataRegistry` instance.
     * - `atom_data('app.core.version')` reads the value for that dot-path.
     * - `atom_data('app.core.version', '1.0.0')` assigns the value and returns it.
     * - `atom_data(['app.core.version' => '1.0.0'])` merges multiple entries and returns the registry array.
     *
     * @param string|array<string, mixed>|null $keyOrMap
     */
    function atom_data(
        string|array|null $keyOrMap = null,
        mixed $value = AtomDataSentinel::Unset,
        ?DataRegistry $registry = null,
    ): mixed {
        $bag = $registry
            ?? (isset(Atom::$app) && isset(Atom::$app->data) ? Atom::$app->data : (isset(Atom::$app) && isset(Atom::$app->view) ? Atom::$app->view->data() : new DataRegistry()));

        if ($keyOrMap === null) {
            return $bag;
        }

        if (\is_array($keyOrMap)) {
            $bag->merge($keyOrMap);
            return $bag->toArray();
        }

        if ($value !== AtomDataSentinel::Unset) {
            $bag->set($keyOrMap, $value);
            return $value;
        }

        return $bag->get($keyOrMap);
    }
}

if (!\function_exists('_atom_async_fiber_registry')) {
    /**
     * Zero-leak WeakMap binding active `\Fiber` instances to the `Async` engine that spawned them.
     *
     * @return \WeakMap<object, Async>
     */
    function _atom_async_fiber_registry(): \WeakMap
    {
        /** @var \WeakMap<object, Async>|null $map */
        static $map = null;
        if ($map === null) {
            /** @var \WeakMap<object, Async> $created */
            $created = new \WeakMap();
            $map = $created;
        }

        return $map;
    }
}

if (!\function_exists('atom_async')) {
    /**
     * Returns the active `Atom\Async\Async` Fiber & Swoole coroutine engine for Atom and client applications.
     */
    function atom_async(): Async
    {
        $currentFiber = \Fiber::getCurrent();
        if ($currentFiber !== null) {
            $registry = \_atom_async_fiber_registry();
            if (isset($registry[$currentFiber])) {
                return $registry[$currentFiber];
            }
        }

        if (isset(Atom::$app) && isset(Atom::$app->async)) {
            return Atom::$app->async;
        }

        return new Async();
    }
}

if (!\function_exists('async_all')) {
    /**
     * Executes an array of callables concurrently across PHP 8.6 Fibers or Swoole coroutines, preserving keys.
     *
     * @template TKey of array-key
     * @template TVal
     * @param array<TKey, callable(): TVal> $tasks
     * @return array<TKey, TVal>
     */
    function async_all(array $tasks, int $concurrency = 0, \Time\Duration|int|float|null $timeout = null): array
    {
        return atom_async()->all($tasks, $concurrency, $timeout);
    }
}

if (!\function_exists('async_spawn')) {
    /**
     * Spawns a callable asynchronously inside a PHP 8.6 `\Fiber` or Swoole coroutine and returns its `Future`.
     *
     * @template TVal
     * @param callable(mixed...): TVal $task
     * @return Future<TVal>
     */
    function async_spawn(callable $task, mixed ...$args): Future
    {
        return atom_async()->spawn($task, ...$args);
    }
}

if (!\function_exists('async_await')) {
    /**
     * Awaits an `Atom\Async\Future` with an optional `\Time\Duration` (or numeric seconds) deadline.
     *
     * @template TVal
     * @param Future<TVal> $future
     * @return TVal
     */
    function async_await(Future $future, \Time\Duration|int|float|null $timeout = null): mixed
    {
        return atom_async()->await($future, $timeout);
    }
}

if (!\function_exists('deepclone_to_array')) {
    /**
     * Zero-copy PHP 8.6 fallback for Symfony 8.1 DeepCloner when ext-deepclone is not loaded.
     *
     * @param list<class-string>|null $allowedClasses
     * @return array<string, mixed>
     */
    function deepclone_to_array(mixed $value, ?array $allowedClasses = null, bool $allowNamedClosure = false): array
    {
        $isStatic = static function (mixed $v) use (&$isStatic): bool {
            if ($v === null || \is_scalar($v) || $v instanceof \UnitEnum) {
                return true;
            }
            if (\is_array($v)) {
                return \array_all($v, $isStatic);
            }
            return false;
        };

        if ($isStatic($value)) {
            return ['value' => $value];
        }

        if ($allowedClasses !== null) {
            $allowedMap = \array_fill_keys($allowedClasses, true);
            $seen = new \WeakMap();
            $checkGraph = static function (mixed $node) use (&$checkGraph, $allowedMap, $seen): void {
                if (\is_object($node)) {
                    if ($node instanceof \UnitEnum || isset($seen[$node])) {
                        return;
                    }
                    $seen[$node] = true;
                    if (!isset($allowedMap[$node::class])) {
                        throw new \ValueError(\sprintf('Class "%s" is not allowed in deepclone_to_array().', $node::class));
                    }
                    foreach ((array) $node as $propVal) {
                        $checkGraph($propVal);
                    }
                } elseif (\is_array($node)) {
                    foreach ($node as $item) {
                        $checkGraph($item);
                    }
                }
            };
            $checkGraph($value);
        }

        return [
            'prepared' => \is_object($value) ? 0 : -1,
            'classes' => \is_object($value) ? [$value::class] : [],
            'objectMeta' => \is_object($value) ? 1 : 0,
            'serialized' => \serialize($value),
        ];
    }
}

if (!\function_exists('deepclone_from_array')) {
    /**
     * Restores deep-cloned object graphs produced by deepclone_to_array().
     *
     * @param array<string, mixed> $payload
     * @param list<class-string>|null $allowedClasses
     */
    function deepclone_from_array(array $payload, ?array $allowedClasses = null, bool $allowNamedClosure = false): mixed
    {
        if (\array_key_exists('value', $payload)) {
            return $payload['value'];
        }

        if (!isset($payload['serialized']) || !\is_string($payload['serialized'])) {
            throw new \ValueError('Invalid deepclone payload.');
        }

        $options = ['allowed_classes' => $allowedClasses ?? true];
        $restored = \unserialize($payload['serialized'], $options);

        if ($allowedClasses !== null && $restored instanceof \__PHP_Incomplete_Class) {
            throw new \ValueError('Disallowed class encountered in deepclone_from_array().');
        }

        return $restored;
    }
}
