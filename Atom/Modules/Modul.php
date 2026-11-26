<?php

declare(strict_types=1);

namespace Atom\Modules;

use Atom\Exception\IO\Generative\FileNotFoundGenerativeException;
use Atom\Factory\ClassFactory;
use Atom\FileSytem\FileSystem;
use Atom\Log\T4LOG;

/**
 * Dynamic module loader with cross-platform path handling and traversal protection.
 */
final class Modul
{
    private ClassFactory $factory;

    /** @var array<string, object> */
    private array $loadedModules = [];

    /** @var array<string, object> Backward-compatible snapshot for `Modul::getAll()` */
    private static array $modules = [];

    /**
     * @param array<int|string, string> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly FileSystem $filesystem,
        private readonly T4LOG $logger,
    ) {
        $this->factory = new ClassFactory();
        $this->reload();
    }

    public function reload(bool $force = false): void
    {
        if ($force) {
            $this->factory = new ClassFactory();
        }

        foreach ($this->config as $moduleName) {
            $safeName = \preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $moduleName) ?? '';
            if ($safeName === '' || $safeName !== (string) $moduleName) {
                throw new FileNotFoundGenerativeException('Invalid module name: %s in %s', (string) $moduleName, __DIR__);
            }

            $dir = __DIR__ . \DIRECTORY_SEPARATOR . $safeName;
            $path = $dir . \DIRECTORY_SEPARATOR . $safeName . '.php';

            if (!$this->filesystem->exists($dir)) {
                $this->logger->error(
                    \sprintf('Module not found: %s', $safeName),
                    ['path' => $safeName, 'type' => 'FileNotFoundException']
                );

                throw new FileNotFoundGenerativeException('Module not found: %s in %s', $safeName, $dir);
            }

            if (!$this->filesystem->exists($path)) {
                $this->logger->error(
                    \sprintf('File module not found: %s', $safeName),
                    ['path' => $safeName, 'type' => 'FileModuleNotFoundException']
                );

                throw new FileNotFoundGenerativeException('File module not found: %s in %s', $safeName, $path);
            }

            if ($force || !isset($this->loadedModules[$safeName])) {
                $instance = $this->factory->addInstanceFromFile($path, false);
                $this->loadedModules[$safeName] = $instance;
                self::$modules[$safeName] = $instance;

                $this->logger->debug(
                    \sprintf('Module loaded: %s', $safeName),
                    ['path' => $safeName, 'type' => 'ModuleLoaded']
                );
            }
        }
    }

    /**
     * @return array<string, object>
     */
    public static function getAll(): array
    {
        return self::$modules;
    }

    public function __get(string $args): object|false
    {
        $this->logger->debug(
            \sprintf('Get module: %s', $args),
            ['path' => $args, 'type' => 'GetModule']
        );

        return $this->loadedModules[$args] ?? false;
    }
}
