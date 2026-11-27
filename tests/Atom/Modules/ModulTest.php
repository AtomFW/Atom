<?php

declare(strict_types=1);

namespace Tests\Atom\Modules;

use Atom\FileSytem\FileSystem;
use Atom\Log\T4LOG;
use Atom\Modules\ErrorDebugger\ErrorDebugger;
use Atom\Modules\Exception\MissingRequiredModuleException;
use Atom\Modules\Modul;
use Atom\Terminal\Terminal;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ModulTest extends TestCase
{
    private string $tempDir;
    private T4LOG $logger;
    private FileSystem $filesystem;

    protected function setUp(): void
    {
        $this->tempDir = \dirname(__DIR__, 3) . '/runtime/temp/modul_test_' . \uniqid();
        $logDir = $this->tempDir . '/logs/';
        \mkdir($logDir, 0777, true);

        $this->logger = new T4LOG(['logRootDir' => $logDir]);
        $this->filesystem = new FileSystem();
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->tempDir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) {
                $p = $file->getPathname();
                $file->isDir() ? \rmdir($p) : \unlink($p);
            }
            \rmdir($this->tempDir);
        }
    }

    public function testLoadsConfiguredBuiltInModulesWithOptions(): void
    {
        $config = [
            'enabled' => [
                'String' => ['on' => true],
                'NameGenerator' => ['on' => true],
                'ErrorDebugger' => ['on' => true],
            ],
            'required' => ['String'],
            'options' => [
                'String' => ['prefix' => 'atom_'],
            ],
        ];

        $modul = new Modul($config, $this->filesystem, $this->logger);

        self::assertTrue($modul->has('String'));
        self::assertTrue($modul->has('NameGenerator'));
        self::assertTrue($modul->has('ErrorDebugger'));
        self::assertInstanceOf(ErrorDebugger::class, $modul->get('ErrorDebugger'));

        $stringModule = $modul->String;
        self::assertIsObject($stringModule);
        self::assertSame('atom_cba', $stringModule->run('abc'));

        $terminal = $modul->registerTerminalCommands(new Terminal());
        self::assertContains('debug:preview', $terminal->getRegisteredCommands());
    }

    public function testThrowsEnglishMessageWhenRequiredModuleIsDisabled(): void
    {
        $config = [
            'enabled' => [
                'String' => ['on' => false],
                'ErrorDebugger' => ['on' => true],
            ],
            'required' => ['String'],
        ];

        $this->expectException(MissingRequiredModuleException::class);
        $this->expectExceptionMessage("Required Atom module 'String' is disabled or not enabled in configuration.");

        new Modul($config, $this->filesystem, $this->logger);
    }

    public function testThrowsEnglishMessageWhenModuleDependencyIsMissing(): void
    {
        $modsDir = $this->tempDir . '/Modules';
        \mkdir($modsDir . '/ChildMod', 0777, true);

        $childCode = <<<'PHP'
<?php
declare(strict_types=1);
namespace Tests\TempModules;
use Atom\Atom;
use Atom\Modules\Contract\AtomModuleInterface;
use Atom\Terminal\Terminal;

final class ChildMod implements AtomModuleInterface {
    public function getName(): string { return 'ChildMod'; }
    public function getVersion(): string { return '1.0.0'; }
    public function dependencies(): array { return ['ParentMod']; }
    public function boot(Atom $app): void {}
    public function registerCommands(Terminal $terminal): Terminal { return $terminal; }
}
PHP;
        \file_put_contents($modsDir . '/ChildMod/ChildMod.php', $childCode);

        $this->expectException(MissingRequiredModuleException::class);
        $this->expectExceptionMessage("Module 'ChildMod' requires module 'ParentMod', which is currently disabled or not enabled in configuration.");

        new Modul(
            ['enabled' => ['ChildMod' => ['on' => true]]],
            $this->filesystem,
            $this->logger,
            $modsDir,
        );
    }
}
