<?php

declare(strict_types=1);

namespace Atom\Modules;

use Atom\Atom;
use Atom\Exception\IO\Generative\FileNotFoundGenerativeException;
use Atom\Factory\ClassFactory;
use Atom\FileSytem\FileSystem;
use Atom\Log\T4LOG;
use Atom\Modules\Contract\AtomModuleInterface;
use Atom\Modules\Exception\MissingRequiredModuleException;
use Atom\Terminal\Terminal;
use PDO;
use ReflectionClass;

use function array_keys;
use function array_unique;
use function array_values;
use function count;
use function defined;
use function in_array;
use function is_array;
use function is_bool;
use function is_dir;
use function is_int;
use function is_string;
use function preg_match;
use function preg_replace;
use function rtrim;
use function scandir;
use function sort;
use function sprintf;
use function str_ends_with;
use function trim;

/**
 * Dynamic module manager supporting configuration-driven activation, required-module
 * verification, dependency checks, Atom kernel boot hooks, CLI command registration,
 * and per-module SQL/PHP migrations (`up` / `down`).
 */
final class Modul
{
    private ClassFactory $factory;
    private string $modulesRootDir;

    /** @var array<string, bool> */
    private array $enabledMap = [];

    /** @var list<string> */
    private array $requiredModules = [];

    /** @var array<string, array<string, mixed>> */
    private array $moduleOptions = [];

    /** @var array<string, object> */
    private array $loadedModules = [];

    /**
     * @param array<int|string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly FileSystem $filesystem,
        private readonly T4LOG $logger,
        ?string $modulesRootDir = null,
    ) {
        $this->modulesRootDir = $modulesRootDir !== null
            ? rtrim($modulesRootDir, '/\\')
            : __DIR__;
        $this->factory = new ClassFactory();
        $this->reload();
    }

    /**
     * Reloads module configuration, verifies required modules, and instantiates enabled modules.
     */
    public function reload(bool $force = false): void
    {
        if ($force) {
            $this->factory = new ClassFactory();
            $this->loadedModules = [];
        }

        $this->parseConfiguration($this->config);
        $this->verifyRequiredModules();

        foreach ($this->enabledMap as $moduleName => $isEnabled) {
            if (!$isEnabled) {
                unset($this->loadedModules[$moduleName]);
                continue;
            }

            $safeName = $this->validateModuleName($moduleName);
            $dir = $this->modulesRootDir . \DIRECTORY_SEPARATOR . $safeName;

            if (!$this->filesystem->exists($dir)) {
                $this->logger->error(
                    sprintf('Module not found: %s', $safeName),
                    ['path' => $safeName, 'type' => 'FileNotFoundException']
                );

                throw new FileNotFoundGenerativeException('Module not found: %s in %s', $safeName, $dir);
            }

            $entryPath = $this->resolveModuleEntryFile($dir, $safeName);
            if ($entryPath === null || !$this->filesystem->exists($entryPath)) {
                $expectedPath = $dir . \DIRECTORY_SEPARATOR . $safeName . '.php';
                $this->logger->error(
                    sprintf('File module not found: %s', $safeName),
                    ['path' => $safeName, 'type' => 'FileModuleNotFoundException']
                );

                throw new FileNotFoundGenerativeException('File module not found: %s in %s', $safeName, $expectedPath);
            }

            if ($force || !isset($this->loadedModules[$safeName])) {
                $options = $this->moduleOptions[$safeName] ?? [];
                $instance = $this->instantiateModule($entryPath, $options);
                $this->loadedModules[$safeName] = $instance;

                $this->logger->debug(
                    sprintf('Module loaded: %s', $safeName),
                    ['path' => $safeName, 'type' => 'ModuleLoaded']
                );
            }
        }

        $this->verifyModuleDependencies();
    }

    /**
     * Verifies that all required modules are enabled; throws `MissingRequiredModuleException` in English otherwise.
     *
     * @param list<string> $additionalRequired
     */
    public function verifyRequiredModules(array $additionalRequired = []): void
    {
        $allRequired = array_values(array_unique([...$this->requiredModules, ...$additionalRequired]));

        foreach ($allRequired as $requiredName) {
            $cleanName = trim((string) $requiredName);
            if ($cleanName === '') {
                continue;
            }

            if (!$this->isEnabled($cleanName)) {
                $exception = MissingRequiredModuleException::forDisabledRequiredModule($cleanName);
                $this->logger->critical($exception->getMessage(), [
                    'module' => $cleanName,
                    'type' => 'MissingRequiredModuleException',
                ]);
                throw $exception;
            }
        }
    }

    /**
     * Boots all loaded modules implementing `AtomModuleInterface` into the `Atom` kernel.
     */
    public function boot(Atom $app): void
    {
        foreach ($this->loadedModules as $module) {
            if ($module instanceof AtomModuleInterface) {
                $module->boot($app);
            }
        }
    }

    /**
     * Registers CLI commands from all loaded modules onto the `Terminal` instance.
     */
    public function registerTerminalCommands(Terminal $terminal): Terminal
    {
        foreach ($this->loadedModules as $module) {
            if ($module instanceof AtomModuleInterface) {
                $terminal = $module->registerCommands($terminal);
            }
        }

        return $terminal;
    }

    /**
     * Creates a `ModuleMigrationRunner` bound to this manager's `Atom/Modules` directory.
     */
    public function migrations(PDO $pdo, string $tablePrefix = ''): ModuleMigrationRunner
    {
        return new ModuleMigrationRunner($pdo, $tablePrefix, $this->modulesRootDir);
    }

    public function has(string $moduleName): bool
    {
        return isset($this->loadedModules[$moduleName]);
    }

    public function isEnabled(string $moduleName): bool
    {
        return $this->enabledMap[$moduleName] ?? false;
    }

    public function isRequired(string $moduleName): bool
    {
        return in_array($moduleName, $this->requiredModules, true);
    }

    public function get(string $moduleName): ?object
    {
        return $this->loadedModules[$moduleName] ?? null;
    }

    /**
     * @return array<string, object>
     */
    public function getLoadedModules(): array
    {
        return $this->loadedModules;
    }

    /**
     * @return list<string>
     */
    public function getRequiredModules(): array
    {
        return $this->requiredModules;
    }

    /**
     * @return array<string, mixed>
     */
    public function getModuleOptions(string $moduleName): array
    {
        return $this->moduleOptions[$moduleName] ?? [];
    }

    /**
     * Discovers all module folder names available inside `Atom/Modules`.
     *
     * @return list<string>
     */
    public function discoverAvailableModules(): array
    {
        if (!is_dir($this->modulesRootDir)) {
            return [];
        }

        $entries = scandir($this->modulesRootDir);
        if ($entries === false) {
            return [];
        }

        $ignored = ['Contract', 'Exception'];
        $discovered = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, $ignored, true)) {
                continue;
            }
            $dir = $this->modulesRootDir . \DIRECTORY_SEPARATOR . $entry;
            if (is_dir($dir) && preg_match('/^[a-zA-Z0-9_\-]+$/', $entry) === 1 && $this->resolveModuleEntryFile($dir, $entry) !== null) {
                $discovered[] = $entry;
            }
        }

        sort($discovered, \SORT_NATURAL);
        return $discovered;
    }

    /**
     * Returns full diagnostic metadata for all configured and discovered modules.
     *
     * @return list<array{
     *     name: string,
     *     enabled: bool,
     *     loaded: bool,
     *     required: bool,
     *     version: string,
     *     hasMigrations: bool,
     *     hasTests: bool
     * }>
     */
    public function status(): array
    {
        $names = array_values(array_unique([
            ...$this->discoverAvailableModules(),
            ...array_keys($this->enabledMap),
            ...$this->requiredModules,
        ]));
        sort($names, \SORT_NATURAL);

        $report = [];
        foreach ($names as $name) {
            $instance = $this->loadedModules[$name] ?? null;
            $version = '1.0.0';
            if ($instance instanceof AtomModuleInterface) {
                $version = $instance->getVersion();
            } elseif ($instance !== null && defined($instance::class . '::VERSION')) {
                $version = (string) ($instance::VERSION);
            }

            $moduleDir = $this->modulesRootDir . \DIRECTORY_SEPARATOR . $name . \DIRECTORY_SEPARATOR;
            $hasMigrations = is_dir($moduleDir . 'migration') || is_dir($moduleDir . 'migrations');
            $hasTests = is_dir($moduleDir . 'tests') || is_dir($moduleDir . 'Tests');

            $report[] = [
                'name' => $name,
                'enabled' => $this->isEnabled($name),
                'loaded' => $instance !== null,
                'required' => $this->isRequired($name),
                'version' => $version,
                'hasMigrations' => $hasMigrations,
                'hasTests' => $hasTests,
            ];
        }

        return $report;
    }

    public function __get(string $args): object|false
    {
        $this->logger->debug(
            sprintf('Get module: %s', $args),
            ['path' => $args, 'type' => 'GetModule']
        );

        return $this->loadedModules[$args] ?? false;
    }

    /**
     * Supports both legacy flat arrays (`['String']`) and structured module config arrays
     * (`['enabled' => [...], 'required' => [...], 'options' => [...]]`).
     *
     * @param array<int|string, mixed> $rawConfig
     */
    private function parseConfiguration(array $rawConfig): void
    {
        $this->enabledMap = [];
        $this->requiredModules = [];
        $this->moduleOptions = [];

        if (isset($rawConfig['enabled']) || isset($rawConfig['required']) || isset($rawConfig['options'])) {
            $enabledRaw = isset($rawConfig['enabled']) && is_array($rawConfig['enabled']) ? $rawConfig['enabled'] : [];
            $requiredRaw = isset($rawConfig['required']) && is_array($rawConfig['required']) ? $rawConfig['required'] : [];
            $optionsRaw = isset($rawConfig['options']) && is_array($rawConfig['options']) ? $rawConfig['options'] : [];

            foreach ($optionsRaw as $modName => $opts) {
                if (is_string($modName) && is_array($opts)) {
                    $this->moduleOptions[$modName] = $opts;
                }
            }

            foreach ($enabledRaw as $key => $val) {
                if (is_int($key) && is_string($val)) {
                    $this->enabledMap[$val] = true;
                    continue;
                }

                if (!is_string($key)) {
                    continue;
                }

                if (is_bool($val)) {
                    $this->enabledMap[$key] = $val;
                    continue;
                }

                if (is_array($val)) {
                    $this->enabledMap[$key] = (bool) ($val['on'] ?? $val['enabled'] ?? true);
                    if (!empty($val['required'])) {
                        $this->requiredModules[] = $key;
                    }
                    $this->moduleOptions[$key] = [...($this->moduleOptions[$key] ?? []), ...$val];
                }
            }

            foreach ($requiredRaw as $req) {
                if (is_string($req) && trim($req) !== '') {
                    $this->requiredModules[] = trim($req);
                }
            }

            $this->requiredModules = array_values(array_unique($this->requiredModules));
            return;
        }

        foreach ($rawConfig as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $this->enabledMap[$value] = true;
            } elseif (is_string($key) && is_bool($value)) {
                $this->enabledMap[$key] = $value;
            } elseif (is_string($key) && is_array($value)) {
                $this->enabledMap[$key] = (bool) ($value['on'] ?? $value['enabled'] ?? true);
                if (!empty($value['required'])) {
                    $this->requiredModules[] = $key;
                }
                $this->moduleOptions[$key] = $value;
            }
        }

        $this->requiredModules = array_values(array_unique($this->requiredModules));
    }

    private function verifyModuleDependencies(): void
    {
        foreach ($this->loadedModules as $moduleName => $instance) {
            if (!$instance instanceof AtomModuleInterface) {
                continue;
            }

            foreach ($instance->dependencies() as $dep) {
                if (!$this->has($dep)) {
                    $exception = MissingRequiredModuleException::forMissingDependency($moduleName, $dep);
                    $this->logger->critical($exception->getMessage(), [
                        'module' => $moduleName,
                        'dependency' => $dep,
                        'type' => 'MissingRequiredModuleException',
                    ]);
                    throw $exception;
                }
            }
        }
    }

    private function validateModuleName(string $moduleName): string
    {
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $moduleName) ?? '';
        if ($safeName === '' || $safeName !== $moduleName) {
            throw new FileNotFoundGenerativeException('Invalid module name: %s in %s', $moduleName, $this->modulesRootDir);
        }

        return $safeName;
    }

    private function resolveModuleEntryFile(string $dir, string $safeName): ?string
    {
        $primary = $dir . \DIRECTORY_SEPARATOR . $safeName . '.php';
        if ($this->filesystem->exists($primary)) {
            return $primary;
        }

        $secondary = $dir . \DIRECTORY_SEPARATOR . 'Module.php';
        if ($this->filesystem->exists($secondary)) {
            return $secondary;
        }

        if (!is_dir($dir)) {
            return null;
        }

        $files = scandir($dir);
        if ($files === false) {
            return null;
        }

        $phpCandidates = [];
        foreach ($files as $f) {
            if ($f !== '.' && $f !== '..' && str_ends_with($f, '.php') && !str_ends_with($f, 'Test.php')) {
                $phpCandidates[] = $dir . \DIRECTORY_SEPARATOR . $f;
            }
        }

        return count($phpCandidates) === 1 ? $phpCandidates[0] : null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function instantiateModule(string $entryPath, array $options): object
    {
        if ($options === []) {
            return $this->factory->addInstanceFromFile($entryPath, false);
        }

        // Probe class constructor to pass `$options` when accepted
        $probe = $this->factory->addInstanceFromFile($entryPath, false);
        $ref = new ReflectionClass($probe);
        $ctor = $ref->getConstructor();

        if ($ctor !== null && $ctor->getNumberOfParameters() >= 1) {
            $firstParam = $ctor->getParameters()[0];
            $type = $firstParam->getType();
            if ($type === null || ($type instanceof \ReflectionNamedType && $type->getName() === 'array')) {
                return $ref->newInstance($options);
            }
        }

        return $probe;
    }
}
