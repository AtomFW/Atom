<?php

declare(strict_types=1);

use Atom\Config\EnvironmentVariables;

if (!\function_exists('env')) {
    /**
     * Retrieves and normalizes a configuration value from `$_ENV`, falling back to `$default`.
     */
    function env(string $key, mixed $default = null): mixed
    {
        return EnvironmentVariables::getEnvironmentVariable($key, $default);
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
