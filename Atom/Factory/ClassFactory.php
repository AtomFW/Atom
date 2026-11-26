<?php

declare(strict_types=1);

namespace Atom\Factory;

use \ReflectionClass;
use \RuntimeException;

/**
 * Class ClassFactory
 */
class ClassFactory
{
    /**
     * @var object[]
     */
    private static array $instances = [];

    /**
     * @param string $filePath
     * @param bool $saveInstance
     * @param array $constructorArgs
     * @return object
     */
    public function addInstanceFromFile(
        string $filePath,
        bool $saveInstance = true,
        array $constructorArgs = []
    ): object {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: {$filePath}");
        }

        if (self::$instances && isset(self::$instances[$filePath])) {
            return self::$instances[$filePath]['instance'];
        }

        $before = get_declared_classes();

        require_once $filePath;

        $after = get_declared_classes();

        $newClasses = array_diff($after, $before);

        if (empty($newClasses)) {
            throw new RuntimeException(
                "Not found class in file. {$filePath}"
            );
        }

        $className = end($newClasses);

        $reflection = new ReflectionClass($className);

        $instance = $reflection->newInstanceArgs($constructorArgs);

        if ($saveInstance) {
            $this->instances[$filePath] = [
                'file' => $filePath,
                'class' => $className,
                'instance' => $instance,
            ];
        }

        return $instance;
    }

    /**
     * @return object[]
     */
    public function getInstances(): array
    {
        return $this->instances;
    }
}
