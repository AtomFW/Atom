<?php

declare(strict_types=1);

namespace Atom\Factory;

use PhpToken;
use ReflectionClass;
use RuntimeException;

/**
 * Stateless-friendly class factory with bounded file-to-class resolution cache (PHP 8.6 `\array_last`)
 * and token-based fallback when classes were already autoloaded by Composer / OPcache.
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
            if ($newClasses !== []) {
                /** @var class-string $discovered */
                $discovered = \array_last($newClasses);
                $className = $discovered;
            } else {
                $className = self::extractDeclaredClassFromFile($canonicalPath);
                if ($className === null) {
                    throw new RuntimeException("Not found class in file: {$filePath}");
                }
            }

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
     * Resolves a class FQCN from PHP source tokens when the file was already loaded by Composer / Swoole worker.
     *
     * @return class-string|null
     */
    private static function extractDeclaredClassFromFile(string $canonicalPath): ?string
    {
        $source = \file_get_contents($canonicalPath);
        if ($source === false || $source === '') {
            return null;
        }

        $tokens = PhpToken::tokenize($source);
        $namespace = '';
        $lastClass = null;
        $total = \count($tokens);

        for ($i = 0; $i < $total; $i++) {
            $tok = $tokens[$i];

            if ($tok->id === \T_NAMESPACE) {
                $nsParts = [];
                for ($j = $i + 1; $j < $total; $j++) {
                    $next = $tokens[$j];
                    if ($next->id === \T_NAME_QUALIFIED || $next->id === \T_STRING) {
                        $nsParts[] = $next->text;
                    } elseif ($next->text === ';' || $next->text === '{') {
                        $i = $j;
                        break;
                    }
                }
                $namespace = \implode('\\', $nsParts);
                continue;
            }

            if ($tok->id === \T_CLASS) {
                $prevIdx = $i - 1;
                while ($prevIdx >= 0 && $tokens[$prevIdx]->isIgnorable()) {
                    $prevIdx--;
                }
                if ($prevIdx >= 0 && ($tokens[$prevIdx]->id === \T_DOUBLE_COLON || $tokens[$prevIdx]->id === \T_NEW)) {
                    continue;
                }

                for ($j = $i + 1; $j < $total; $j++) {
                    if ($tokens[$j]->isIgnorable()) {
                        continue;
                    }
                    if ($tokens[$j]->id === \T_STRING) {
                        $candidate = $namespace !== '' ? $namespace . '\\' . $tokens[$j]->text : $tokens[$j]->text;
                        if (\class_exists($candidate, false)) {
                            $lastClass = $candidate;
                        }
                    }
                    break;
                }
            }
        }

        return $lastClass;
    }

    /**
     * @return array<string, array{file: string, class: class-string, instance: object}>
     */
    public function getInstances(): array
    {
        return $this->instances;
    }
}
