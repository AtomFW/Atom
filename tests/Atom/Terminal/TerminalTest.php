<?php

declare(strict_types=1);

namespace Tests\Atom\Terminal;

use Atom\Modules\NameGenerator\Name;
use Atom\Terminal\Terminal;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class TerminalTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \dirname(__DIR__, 3) . '/runtime/temp/terminal_test_' . \uniqid();
        \mkdir($this->tempDir, 0777, true);
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

    public function testBannerContainsLogoVersionTsrStatusAndAuthor(): void
    {
        $terminal = new Terminal(colorsEnabled: false);
        $exitCode = $terminal->run(['atom.php', 'version']);

        self::assertSame(0, $exitCode);
        $output = $terminal->getOutputBuffer();
        self::assertStringContainsString('ATOM TERMINAL', $output);
        self::assertStringContainsString('Version:', $output);
        self::assertStringContainsString('TSR / ZTS:', $output);
        self::assertStringContainsString('Author:', $output);
        self::assertStringContainsString('Timonix', $output);
    }

    public function testAnsiStylingAndCustomCommandCompatibility(): void
    {
        $colored = new Terminal(colorsEnabled: true);
        self::assertStringContainsString("\033[", $colored->style('Hello', 'bold', 'cyan'));

        $plain = $colored->withColors(false)->withCommand(
            'ping',
            static fn(array $args): int => $args === ['ok'] ? 0 : 2,
            'Ping check'
        );

        self::assertSame('Hello', $plain->style('Hello', 'bold', 'cyan'));
        self::assertSame(0, $plain->run(['atom.php', 'ping', 'ok']));
    }

    public function testAutocompleteAndFuzzyCommandSuggestions(): void
    {
        $terminal = new Terminal(colorsEnabled: false);

        $modCompletions = $terminal->complete('module:');
        self::assertContains('module:list', $modCompletions);
        self::assertContains('module:migrate', $modCompletions);
        self::assertContains('module:downgrade', $modCompletions);

        $classCompletions = $terminal->complete('call Atom\\Filter\\TypeCaster::');
        self::assertContains('Atom\\Filter\\TypeCaster::supportedTypes', $classCompletions);

        $pwshScript = $terminal->generateCompletionScript('pwsh');
        self::assertStringContainsString('Register-ArgumentCompleter', $pwshScript);

        $suggestions = $terminal->suggestCommands('minfy');
        self::assertContains('minify', $suggestions);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown terminal command: 'minfy'. Did you mean:");
        $terminal->run(['atom.php', 'minfy']);
    }

    public function testCallCommandInvokesStaticAndInstanceAtomClassesAndReturnsData(): void
    {
        $terminal = new Terminal(colorsEnabled: false);

        $codeStatic = $terminal->run(['atom.php', 'call', 'Filter\\TypeCaster::supportedTypes']);
        self::assertSame(0, $codeStatic);
        $types = $terminal->getLastReturnValue();
        self::assertIsArray($types);
        self::assertContains('money', $types);

        $codeInstance = $terminal->run(['atom.php', 'call', Name::class . '::generate', '12', 'tok_']);
        self::assertSame(0, $codeInstance);
        $token = $terminal->getLastReturnValue();
        self::assertIsString($token);
        self::assertStringStartsWith('tok_', $token);
        self::assertSame(16, \strlen($token));
    }

    public function testCallCommandRejectsClassesOutsideAtomNamespace(): void
    {
        $terminal = new Terminal(colorsEnabled: false);

        $this->expectException(InvalidArgumentException::class);
        $terminal->invokeAtomClass('\\DateTimeImmutable', 'getLastErrors');
    }

    public function testMinifyCommandMinifiesCssAndJsWithProgressBar(): void
    {
        $srcCss = $this->tempDir . '/src_css';
        $srcJs = $this->tempDir . '/src_js';
        $outCss = $this->tempDir . '/out_css';
        $outJs = $this->tempDir . '/out_js';
        \mkdir($srcCss, 0777, true);
        \mkdir($srcJs, 0777, true);

        \file_put_contents($srcCss . '/theme.css', "body {\n    color: #ffffff;\n}\n");
        \file_put_contents($srcJs . '/app.js', "function greet() {\n    return 42;\n}\n");

        $terminal = new Terminal(colorsEnabled: false);
        $code = $terminal->run([
            'atom.php',
            'minify',
            "--css={$srcCss}",
            "--js={$srcJs}",
            "--out-css={$outCss}",
            "--out-js={$outJs}",
        ]);

        self::assertSame(0, $code);
        self::assertFileExists($outCss . '/theme.min.css');
        self::assertFileExists($outJs . '/app.min.js');
        self::assertStringContainsString('Minified 1 CSS and 1 JS file(s).', $terminal->getOutputBuffer());
    }

    public function testModuleMigrateAndDowngradeCommandsExecuteViaTerminal(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $terminal = (new Terminal(colorsEnabled: false))->withPdo($pdo);

        self::assertSame(0, $terminal->run(['atom.php', 'module:migrate', 'ErrorDebugger']));
        self::assertSame(['ErrorDebugger:ed0001_create_error_debugger_snapshots'], $terminal->getLastReturnValue());

        self::assertSame(0, $terminal->run(['atom.php', 'module:downgrade', 'ErrorDebugger', '--steps=1']));
        self::assertSame(['ErrorDebugger:ed0001_create_error_debugger_snapshots'], $terminal->getLastReturnValue());
    }
}
