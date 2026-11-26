<?php

declare(strict_types=1);

namespace Atom\Modules;

use Atom\Exception\IO\Generative\FileNotFoundGenerativeException;
use Atom\Factory\ClassFactory;
use Atom\FileSytem\FileSystem;
use Atom\Log\T4LOG;

final class Modul
{
    private static ClassFactory $factory;
    private static array $modules = [];

    public function __construct(private array $config, private FileSystem $filesystem, private T4LOG $logger)
    {
        $this->reload();
    }

    public function reload($force = false): void
    {
        self::$factory = new ClassFactory();

        foreach ($this->config as $key => $value) {
            $dir = __DIR__ . "\\" . $value;
            $path = $dir . DIRECTORY_SEPARATOR . $value . ".php";

            if (!$this->filesystem->exists($dir)) {
                $this->logger->error(
                    \sprintf("Module not found: %s", $value),
                    ['path' => $value, 'type' => 'FileNotFoundException']
                );

                throw new FileNotFoundGenerativeException("Module not found: %s in %s", $value, $dir);
            }

            if (!$this->filesystem->exists($path)) {
                $this->logger->error(
                    \sprintf("File module not found: %s", $value),
                    ['path' => $value, 'type' => 'FileModuleNotFoundException']
                );

                throw new FileNotFoundGenerativeException("File module not found: %s in %s", $value, $path);
            }

            if ($force || !isset(self::$modules[$value])) {
                self::$modules[$value] = self::$factory->addInstanceFromFile($path, false);
                $this->logger->debug(
                    \sprintf("Module loaded: %s", $value),
                    ['path' => $value, 'type' => 'ModuleLoaded']
                );
            }
        }
    }

    public static function getAll(): array
    {
        return self::$modules;
    }

    public function __get(string $args): object|false
    {
        $this->logger->debug(
            \sprintf("Get module: %s", $args),
            ['path' => $args, 'type' => 'GetModule']
        );
        return isset(self::$modules[$args]) ? self::$modules[$args] : false;
    }
}
