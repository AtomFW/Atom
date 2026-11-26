<?php

declare(strict_types=1);

namespace Atom\Factory;

use ReflectionClass;
use RuntimeException;

/**
 * Stateless-friendly class factory with bounded file-to-class resolution cache (PHP 8.6 `array_last`).
 */
final class ClassFactory
{
    private const int MAX_CACHE_SIZE = 256;

    /**
     * @var array<string, class-string> Bounded file-path => class-name map so `require_once` works on reloads
     */
    private static array $resolvedClasses = [];

    /**
     * @var array<string, array{file: string, class: class-string, instance: object}>
     */
    private array $instances = [];

    /**
     * @param list<mixed> $constructorArgs
     */
    public function addInstanceFromFile(
        string $filePath,
        bool $saveInstance = true,
        array $constructorArgs = [],
    ): object {
        $realPath = \realpath($filePath);
        $canonicalPath = $realPath !== false ? $realPath : $filePath;

        if (!\is_file($canonicalPath)) {
            throw new RuntimeException("File not found: {$filePath}");
        }

        if (isset($this->instances[$canonicalPath])) {
            return $this->instances[$canonicalPath]['instance'];
        }

        $className = self::$resolvedClasses[$canonicalPath] ?? null;

        if ($className === null) {
            $before = \get_declared_classes();
            require_once $canonicalPath;
            $after = \get_declared_classes();

            $newClasses = \array_values(\array_diff($after, $before));
            if ($newClasses === []) {
                throw new RuntimeException("Not found class in file: {$filePath}");
            }

            /** @var class-string $discovered */
            $discovered = \array_last($newClasses);
            $className = $discovered;

            if (\count(self::$resolvedClasses) >= self::MAX_CACHE_SIZE) {
                $oldest = \array_key_first(self::$resolvedClasses);
                unset(self::$resolvedClasses[$oldest]);
            }
            self::$resolvedClasses[$canonicalPath] = $className;
        }

        $reflection = new ReflectionClass($className);
        $instance = $constructorArgs === []
            ? $reflection->newInstance()
            : $reflection->newInstanceArgs($constructorArgs);

        if ($saveInstance) {
            if (\count($this->instances) >= self::MAX_CACHE_SIZE) {
                $oldestKey = \array_key_first($this->instances);
                unset($this->instances[$oldestKey]);
            }

            $this->instances[$canonicalPath] = [
                'file' => $canonicalPath,
                'class' => $className,
                'instance' => $instance,
            ];
        }

        return $instance;
    }

    /**
     * @return array<string, array{file: string, class: class-string, instance: object}>
     */
    public function getInstances(): array
    {
        return $this->instances;
    }
}
