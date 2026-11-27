<?php

declare(strict_types=1);

namespace Atom\Terminal;

use Atom\Async\Async;
use Atom\Cache\MemoryCacheManager;
use Atom\Config\Config;
use Atom\DataBase\Database;
use Atom\DataBase\Migrations;
use Atom\DateTime\DateTime;
use Atom\FileSytem\FileSystem;
use Atom\FileSytem\ResourcesPath;
use Atom\Filter\TypeCaster;
use Atom\Log\T4LOG;
use Atom\Modules\Modul;
use Atom\Modules\ModuleMigrationRunner;
use Atom\Shrink\Shrink;
use InvalidArgumentException;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Throwable;

use function array_filter;
use function array_keys;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function class_exists;
use function clamp;
use function count;
use function dirname;
use function explode;
use function extension_loaded;
use function fgets;
use function file_exists;
use function function_exists;
use function fwrite;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_dir;
use function is_file;
use function is_float;
use function is_int;
use function is_numeric;
use function is_object;
use function is_resource;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;
use function json_validate;
use function ksort;
use function levenshtein;
use function ltrim;
use function max;
use function preg_match;
use function preg_replace;
use function realpath;
use function rtrim;
use function scandir;
use function sort;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_pad;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use function unlink;

/**
 * Full-featured Atom CLI Terminal featuring:
 * - Startup ASCII Atom logo, framework version, TSR/ZTS status, author, and runtime summary
 * - ANSI colors, formatted tables, and `ProgressBar` integration
 * - Tab-completion (`complete()`, readline integration, Bash/Zsh/PowerShell completion scripts) & fuzzy typo suggestions
 * - Built-in commands (`version`, `about`, `minify`, `migrate`, `module:*`, `cache:*`, `config:*`, `classes`, `types:list`, `call`)
 * - Safe dynamic invocation of any `Atom\*` class and method returning structured data
 */
final class Terminal
{
    public const string VERSION = '2.1.0';

    /** @var array<string, string> */
    private const array ANSI_CODES = [
        'reset' => '0',
        'bold' => '1',
        'dim' => '2',
        'underline' => '4',
        'red' => '31',
        'green' => '32',
        'yellow' => '33',
        'blue' => '34',
        'magenta' => '35',
        'cyan' => '36',
        'white' => '37',
        'gray' => '90',
        'bg_red' => '41',
        'bg_green' => '42',
        'bg_yellow' => '43',
        'bg_blue' => '44',
        'bg_cyan' => '46',
    ];

    /** @var array<string, callable(list<string>, self): int> */
    private array $commands = [];

    /** @var array<string, string> */
    private array $descriptions = [];

    private string $projectRoot;
    private bool $colorsEnabled;

    /** @var resource|null */
    private mixed $outputStream;

    private string $outputBuffer = '';
    private mixed $lastReturnValue = null;
    private ?PDO $customPdo = null;

    /**
     * @param array<string, callable> $commands Custom command map (`fn(list<string> $args): int`)
     * @param resource|false|null $outputStream Stream for CLI output (`null` defaults to STDOUT in CLI, `false` buffers silently)
     */
    public function __construct(
        array $commands = [],
        ?string $projectRoot = null,
        ?bool $colorsEnabled = null,
        mixed $outputStream = false,
        bool $registerBuiltins = true,
        ?PDO $pdo = null,
    ) {
        $resolvedRoot = $projectRoot ?? dirname(__DIR__, 2);
        $real = realpath($resolvedRoot);
        $this->projectRoot = $real !== false ? $real : rtrim($resolvedRoot, '/\\');
        $this->colorsEnabled = $colorsEnabled ?? true;
        $this->customPdo = $pdo;

        if ($outputStream === null) {
            $this->outputStream = \defined('STDOUT') && is_resource(\STDOUT) ? \STDOUT : null;
        } elseif (is_resource($outputStream)) {
            $this->outputStream = $outputStream;
        } else {
            $this->outputStream = null;
        }

        if ($registerBuiltins) {
            $this->registerBuiltinCommands();
        }

        foreach ($commands as $name => $handler) {
            $this->bindCommand((string) $name, $handler, 'Custom command');
        }
    }

    /**
     * Creates a CLI-ready Terminal bound to `STDOUT` and enriched with any active module commands.
     */
    public static function createConsole(?string $projectRoot = null, ?bool $colorsEnabled = null): self
    {
        $terminal = new self(
            commands: [],
            projectRoot: $projectRoot,
            colorsEnabled: $colorsEnabled ?? self::detectAnsiSupport(),
            outputStream: null,
            registerBuiltins: true,
        );

        try {
            $modul = $terminal->createModulInstance();
            $terminal = $modul->registerTerminalCommands($terminal);
        } catch (Throwable) {
            // Allow terminal commands (such as `help` or `version`) to run even before `.env` / modules are configured
        }

        return $terminal;
    }

    /**
     * Returns a new `Terminal` instance with the given command registered.
     */
    public function withCommand(string $name, callable $handler, string $description = 'Custom command'): self
    {
        $clone = clone $this;
        $clone->bindCommand($name, $handler, $description);

        return $clone;
    }

    /**
     * Returns a new `Terminal` configured with a specific PDO connection (used for module/core migrations).
     */
    public function withPdo(PDO $pdo): self
    {
        $clone = clone $this;
        $clone->customPdo = $pdo;

        return $clone;
    }

    public function withColors(bool $enabled): self
    {
        $clone = clone $this;
        $clone->colorsEnabled = $enabled;

        return $clone;
    }

    public function __clone(): void
    {
        $rebound = [];
        foreach ($this->commands as $name => $handler) {
            if ($handler instanceof \Closure) {
                $rebound[$name] = $handler;
            }
        }
        $this->registerBuiltinCommands();
        foreach ($rebound as $name => $handler) {
            if (!isset($this->commands[$name])) {
                $this->commands[$name] = $handler;
            }
        }
    }

    /**
     * Executes a CLI `$argv` vector (e.g. `['atom', 'version']`).
     *
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $filteredArgs = [];
        $quiet = false;

        foreach (array_slice($argv, 1) as $token) {
            if ($token === '--no-color') {
                $this->colorsEnabled = false;
                continue;
            }
            if ($token === '--quiet' || $token === '-q') {
                $quiet = true;
                continue;
            }
            $filteredArgs[] = $token;
        }

        $command = $filteredArgs[0] ?? '';

        if ($command === '--version' || $command === '-v') {
            $command = 'version';
        } elseif ($command === '--help' || $command === '-h') {
            $command = 'help';
        }

        if ($command === '' || !isset($this->commands[$command])) {
            $suggestions = $command !== '' ? $this->suggestCommands($command) : [];
            $hint = $suggestions !== []
                ? ' Did you mean: ' . implode(', ', $suggestions) . '?'
                : " Run 'help' to list available commands.";

            throw new InvalidArgumentException("Unknown terminal command: '{$command}'.{$hint}");
        }

        if (!$quiet && in_array($command, ['help', 'list', 'about'], true)) {
            $this->write($this->renderBanner());
        }

        /** @var list<string> $cmdArgs */
        $cmdArgs = array_slice($filteredArgs, 1);

        return ($this->commands[$command])($cmdArgs, $this);
    }

    /**
     * Starts an interactive REPL terminal session with banner, colors, and readline tab-completion.
     *
     * @param resource|null $inputStream
     */
    public function interactive(mixed $inputStream = null): int
    {
        $this->write($this->renderBanner());
        $this->writeln($this->style("Type 'help' for commands, 'call <Class::method>' to invoke Atom classes, or 'exit' to quit.", 'dim'));
        $this->writeln('');

        $useReadline = $inputStream === null && extension_loaded('readline') && function_exists('readline');
        if ($useReadline && function_exists('readline_completion_function')) {
            \readline_completion_function(fn(string $input, int $index): array => $this->complete($input));
        }

        $stream = is_resource($inputStream) ? $inputStream : (\defined('STDIN') && is_resource(\STDIN) ? \STDIN : null);

        while (true) {
            if ($useReadline) {
                $line = \readline($this->colorsEnabled ? "\033[1;36matom>\033[0m " : 'atom> ');
                if ($line === false) {
                    break;
                }
                if (trim($line) !== '' && function_exists('readline_add_history')) {
                    \readline_add_history($line);
                }
            } else {
                if ($stream === null) {
                    break;
                }
                $this->write($this->style('atom> ', 'bold', 'cyan'));
                $line = fgets($stream);
                if ($line === false) {
                    break;
                }
            }

            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if ($trimmed === 'exit' || $trimmed === 'quit') {
                $this->info('Goodbye from Atom Terminal.');
                return 0;
            }

            $tokens = $this->tokenizeCommandLine($trimmed);
            try {
                $this->run(['atom', ...$tokens]);
            } catch (Throwable $e) {
                $this->error($e->getMessage());
            }
        }

        return 0;
    }

    /**
     * Renders the startup Atom ASCII logo, version, TSR/ZTS status, author, and concise runtime info.
     */
    public function renderBanner(): string
    {
        $about = $this->loadAboutMetadata();
        $atomVersion = (string) ($about['version'] ?? self::VERSION);
        $author = (string) ($about['author'] ?? 'Timonix');
        $description = (string) ($about['description'] ?? 'Atom high-performance stateless PHP 8.6+ framework');
        $tsrStatus = \PHP_ZTS === 1 ? 'ZTS Enabled (TSR Thread-Safe)' : 'NTS (Single-Thread SAPI)';
        $jitStatus = function_exists('opcache_get_status') && is_array($st = \opcache_get_status(false)) && !empty($st['jit']['enabled'])
            ? 'JIT Active'
            : 'OPcache Standard';
        $ramDrivers = implode('/', MemoryCacheManager::availableDrivers());

        $logoLines = [
            '    ___   ______ ____   __  ___ ',
            '   /   | /_  __// __ \ /  |/  /   ⚛  ATOM TERMINAL',
            '  / /| |  / /  / / / // /|_/ /    ' . $description,
            ' / ___ | / /  / /_/ // /  / /     ',
            '/_/  |_|/_/   \____//_/  /_/      ',
        ];

        $styledLogo = implode(
            \PHP_EOL,
            array_map(fn(string $l): string => $this->style($l, 'bold', 'cyan'), $logoLines),
        );

        $metaLine1 = sprintf(
            '  %s %s   %s %s   %s %s',
            $this->style('Version:', 'bold', 'yellow'),
            $this->style('v' . $atomVersion, 'green'),
            $this->style('TSR / ZTS:', 'bold', 'yellow'),
            $this->style($tsrStatus, 'cyan'),
            $this->style('Author:', 'bold', 'yellow'),
            $this->style($author, 'magenta'),
        );

        $metaLine2 = sprintf(
            '  %s %s (%s)   %s %s   %s [%s]',
            $this->style('Runtime:', 'bold', 'yellow'),
            'PHP ' . \PHP_VERSION,
            $jitStatus,
            $this->style('OS:', 'bold', 'yellow'),
            \PHP_OS_FAMILY,
            $this->style('RAM Cache:', 'bold', 'yellow'),
            $ramDrivers,
        );

        $divider = $this->style('  ' . str_repeat('─', 74), 'gray');

        return $styledLogo . \PHP_EOL . $divider . \PHP_EOL . $metaLine1 . \PHP_EOL . $metaLine2 . \PHP_EOL . $divider . \PHP_EOL;
    }

    /**
     * Context-aware autocomplete returning valid completions for a partial input string.
     *
     * @return list<string>
     */
    public function complete(string $partialLine): array
    {
        $trimmedLeft = ltrim($partialLine);

        if (!str_contains($trimmedLeft, ' ')) {
            $candidates = [
                ...array_keys($this->commands),
                '--help',
                '--version',
                '--no-color',
                '--quiet',
            ];
            sort($candidates, \SORT_NATURAL);

            if ($trimmedLeft === '') {
                return array_values(array_unique($candidates));
            }

            return array_values(array_filter(
                array_unique($candidates),
                static fn(string $cmd): bool => str_starts_with($cmd, $trimmedLeft),
            ));
        }

        [$cmd, $rest] = explode(' ', $trimmedLeft, 2);
        $argPrefix = trim($rest);

        if ($cmd === 'call' || $cmd === 'classes') {
            return $this->completeAtomClassesAndMethods($argPrefix);
        }

        if (in_array($cmd, ['module:migrate', 'module:downgrade', 'module:rollback', 'module:status'], true)) {
            $modules = $this->discoverModuleDirectoryNames();
            return array_values(array_filter(
                $modules,
                static fn(string $m): bool => $argPrefix === '' || str_starts_with(strtolower($m), strtolower($argPrefix)),
            ));
        }

        if ($cmd === 'config:show') {
            $configs = ['app', 'async', 'auth', 'cache', 'cluster', 'database', 'exchange', 'filesystem', 'inspector', 'logger', 'mail', 'mcp', 'modul', 'oauth', 'profiler', 'session'];
            return array_values(array_filter(
                $configs,
                static fn(string $c): bool => $argPrefix === '' || str_starts_with($c, $argPrefix),
            ));
        }

        if ($cmd === 'completion') {
            $shells = ['bash', 'zsh', 'pwsh'];
            return array_values(array_filter(
                $shells,
                static fn(string $s): bool => $argPrefix === '' || str_starts_with($s, $argPrefix),
            ));
        }

        return [];
    }

    /**
     * Suggests close command names for mistyped CLI commands.
     *
     * @return list<string>
     */
    public function suggestCommands(string $unknownCommand): array
    {
        $needle = strtolower(trim($unknownCommand));
        if ($needle === '') {
            return [];
        }

        $scored = [];
        foreach (array_keys($this->commands) as $cmd) {
            $lowerCmd = strtolower($cmd);
            if (str_contains($lowerCmd, $needle) || str_contains($needle, $lowerCmd)) {
                $scored[$cmd] = 0;
                continue;
            }

            $dist = levenshtein($needle, $lowerCmd);
            $threshold = max(2, (int) (\strlen($needle) / 2));
            if ($dist <= $threshold) {
                $scored[$cmd] = $dist;
            }
        }

        \asort($scored);
        return array_slice(array_keys($scored), 0, 3);
    }

    /**
     * Dynamically invokes any `Atom\*` class and public method (static or instance) and returns its result.
     *
     * @param list<mixed> $args
     * @param list<mixed> $constructorArgs
     */
    public function invokeAtomClass(
        string $classOrTarget,
        ?string $method = null,
        array $args = [],
        array $constructorArgs = [],
    ): mixed {
        if ($method === null && str_contains($classOrTarget, '::')) {
            [$classPart, $methodPart] = explode('::', $classOrTarget, 2);
            $classOrTarget = $classPart;
            $method = $methodPart;
        }

        $fqcn = $this->resolveAtomClassName($classOrTarget);
        if (!class_exists($fqcn)) {
            throw new InvalidArgumentException("Atom class '{$fqcn}' was not found.");
        }

        $reflection = new ReflectionClass($fqcn);

        if ($method === null || trim($method) === '') {
            $methods = array_map(
                static fn(ReflectionMethod $m): string => $m->getName(),
                array_filter(
                    $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
                    static fn(ReflectionMethod $m): bool => !$m->isConstructor() && !$m->isDestructor(),
                ),
            );

            $info = [
                'class' => $fqcn,
                'public_methods' => array_values($methods),
            ];
            $this->lastReturnValue = $info;
            return $info;
        }

        if (!$reflection->hasMethod($method)) {
            throw new InvalidArgumentException("Method '{$fqcn}::{$method}()' does not exist.");
        }

        $refMethod = $reflection->getMethod($method);
        if (!$refMethod->isPublic()) {
            throw new InvalidArgumentException("Method '{$fqcn}::{$method}()' is not public.");
        }

        $coercedArgs = $this->coerceArgumentsForMethod($refMethod, $args);

        if ($refMethod->isStatic()) {
            $result = $refMethod->invokeArgs(null, $coercedArgs);
        } else {
            if ($reflection->isAbstract() || $reflection->isInterface()) {
                throw new InvalidArgumentException("Cannot instantiate non-concrete class '{$fqcn}'.");
            }

            $ctor = $reflection->getConstructor();
            if ($ctor === null || $ctor->getNumberOfRequiredParameters() === 0 && $constructorArgs === []) {
                $instance = $reflection->newInstance();
            } else {
                $coercedCtor = $this->coerceArgumentsForMethod($ctor, $constructorArgs);
                $instance = $reflection->newInstanceArgs($coercedCtor);
            }

            $result = $refMethod->invokeArgs($instance, $coercedArgs);
        }

        if ($result instanceof \Generator) {
            $result = \iterator_to_array($result, preserve_keys: false);
        }

        $this->lastReturnValue = $result;
        return $result;
    }

    public function getLastReturnValue(): mixed
    {
        return $this->lastReturnValue;
    }

    /**
     * Creates a `ProgressBar` bound to this terminal's output stream and color mode.
     */
    public function createProgressBar(int $total, int $width = 28): ProgressBar
    {
        return new ProgressBar(
            total: $total,
            width: $width,
            stream: $this->outputStream,
            colors: $this->colorsEnabled,
        );
    }

    /**
     * Styles text with one or more ANSI style/color names when colors are enabled.
     */
    public function style(string $text, string ...$styles): string
    {
        if (!$this->colorsEnabled || $styles === []) {
            return $text;
        }

        $codes = [];
        foreach ($styles as $s) {
            if (isset(self::ANSI_CODES[$s])) {
                $codes[] = self::ANSI_CODES[$s];
            }
        }

        if ($codes === []) {
            return $text;
        }

        return "\033[" . implode(';', $codes) . 'm' . $text . "\033[0m";
    }

    public function write(string $text): void
    {
        $this->outputBuffer .= $text;
        if ($this->outputStream !== null) {
            fwrite($this->outputStream, $text);
        }
    }

    public function writeln(string $line = ''): void
    {
        $this->write($line . \PHP_EOL);
    }

    public function info(string $message): void
    {
        $this->writeln($this->style(' [INFO] ', 'bold', 'bg_cyan') . ' ' . $message);
    }

    public function success(string $message): void
    {
        $this->writeln($this->style(' [OK] ', 'bold', 'bg_green') . ' ' . $this->style($message, 'green'));
    }

    public function warn(string $message): void
    {
        $this->writeln($this->style(' [WARN] ', 'bold', 'bg_yellow') . ' ' . $this->style($message, 'yellow'));
    }

    public function error(string $message): void
    {
        $this->writeln($this->style(' [ERROR] ', 'bold', 'bg_red') . ' ' . $this->style($message, 'red'));
    }

    /**
     * Renders a formatted ASCII/Unicode table to the terminal output.
     *
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $widths = [];
        foreach ($headers as $i => $h) {
            $widths[$i] = strlen($h);
        }

        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, strlen($cell));
            }
        }

        $borderParts = [];
        $headerParts = [];
        foreach ($headers as $i => $h) {
            $w = $widths[$i] ?? strlen($h);
            $borderParts[] = str_repeat('─', $w + 2);
            $headerParts[] = ' ' . $this->style(str_pad($h, $w), 'bold', 'cyan') . ' ';
        }

        $top = '┌' . implode('┬', $borderParts) . '┐';
        $mid = '├' . implode('┼', $borderParts) . '┤';
        $bot = '└' . implode('┴', $borderParts) . '┘';

        $this->writeln($this->style($top, 'gray'));
        $this->writeln('│' . implode('│', $headerParts) . '│');
        $this->writeln($this->style($mid, 'gray'));

        foreach ($rows as $row) {
            $cells = [];
            foreach ($headers as $i => $_) {
                $cellVal = $row[$i] ?? '';
                $cells[] = ' ' . str_pad($cellVal, $widths[$i]) . ' ';
            }
            $this->writeln('│' . implode('│', $cells) . '│');
        }

        $this->writeln($this->style($bot, 'gray'));
    }

    public function getOutputBuffer(): string
    {
        return $this->outputBuffer;
    }

    public function clearOutputBuffer(): void
    {
        $this->outputBuffer = '';
    }

    /**
     * @return list<string>
     */
    public function getRegisteredCommands(): array
    {
        $cmds = array_keys($this->commands);
        sort($cmds, \SORT_NATURAL);
        return $cmds;
    }

    /**
     * Generates a shell tab-completion script for `bash`, `zsh`, or `pwsh`.
     */
    public function generateCompletionScript(string $shell = 'bash'): string
    {
        $cmds = implode(' ', $this->getRegisteredCommands());

        return match (strtolower(trim($shell))) {
            'pwsh', 'powershell' => <<<PWSH
Register-ArgumentCompleter -Native -CommandName atom,php -ScriptBlock {
    param(\$wordToComplete, \$commandAst, \$cursorPosition)
    \$commands = @('{$this->joinForPwsh($this->getRegisteredCommands())}')
    \$commands | Where-Object { \$_ -like "\$wordToComplete*" } | ForEach-Object {
        [System.Management.Automation.CompletionResult]::new(\$_, \$_, 'ParameterValue', \$_)
    }
}
PWSH,
            'zsh' => <<<ZSH
#compdef atom
_atom_complete() {
    local -a commands
    commands=({$cmds})
    _describe 'atom commands' commands
}
compdef _atom_complete atom
ZSH,
            default => <<<BASH
_atom_complete() {
    local cur="\${COMP_WORDS[COMP_CWORD]}"
    COMPREPLY=( \$(compgen -W "{$cmds}" -- "\${cur}") )
}
complete -F _atom_complete atom
BASH,
        };
    }

    private function bindCommand(string $name, callable $handler, string $description): void
    {
        $ref = new \ReflectionFunction(\Closure::fromCallable($handler));
        $paramCount = $ref->getNumberOfParameters();

        $this->commands[$name] = static function (array $args, self $term) use ($handler, $paramCount): int {
            $res = $paramCount >= 2 ? $handler($args, $term) : $handler($args);
            return is_int($res) ? $res : 0;
        };
        $this->descriptions[$name] = $description;
    }

    private function registerBuiltinCommands(): void
    {
        $this->bindCommand('help', function (array $args): int {
            $this->writeln($this->style('Available Commands:', 'bold', 'yellow'));
            $rows = [];
            $cmds = $this->getRegisteredCommands();
            foreach ($cmds as $name) {
                $rows[] = [$name, $this->descriptions[$name] ?? ''];
            }
            $this->table(['Command', 'Description'], $rows);
            return 0;
        }, 'Display help banner and list all available Atom commands');

        $this->bindCommand('list', fn(array $args): int => ($this->commands['help'])($args, $this), 'Alias for help');

        $this->bindCommand('version', function (): int {
            $this->write($this->renderBanner());
            return 0;
        }, 'Show Atom logo, version, TSR/ZTS status, author, and PHP runtime info');

        $this->bindCommand('about', function (): int {
            $about = $this->loadAboutMetadata();
            $cache = new MemoryCacheManager();
            $rows = [
                ['Framework', (string) ($about['name'] ?? 'Atom') . ' v' . (string) ($about['version'] ?? self::VERSION)],
                ['Author', (string) ($about['author'] ?? 'Timonix')],
                ['PHP Engine', \PHP_VERSION . ' (' . (\PHP_ZTS === 1 ? 'ZTS / TSR Active' : 'NTS') . ')'],
                ['Operating System', \PHP_OS_FAMILY . ' (' . \php_uname('s') . ' ' . \php_uname('r') . ')'],
                ['Active RAM Cache', $cache->getDriverName() . ' (available: ' . implode(', ', MemoryCacheManager::availableDrivers()) . ')'],
                ['AutoMapped Types', (string) count(TypeCaster::supportedTypes()) . ' built-in types'],
                ['Discovered Modules', implode(', ', $this->discoverModuleDirectoryNames()) ?: 'None'],
                ['Project Root', $this->projectRoot],
            ];
            $this->table(['Property', 'Value'], $rows);
            return 0;
        }, 'Display comprehensive Atom environment and component diagnostics');

        $this->bindCommand('minify', fn(array $args): int => $this->handleMinifyCommand($args), 'Automatically scan and minify CSS and JS assets with progress bar');
        $this->bindCommand('shrink', fn(array $args): int => $this->handleMinifyCommand($args), 'Alias for minify');

        $this->bindCommand('async:status', function (): int {
            $async = new Async();
            $diag = $async->diagnostics();
            $this->table(
                ['Property', 'Value'],
                [
                    ['Effective Driver', (string) $diag['effectiveDriver']],
                    ['Swoole / OpenSwoole', $diag['swooleAvailable'] ? 'Available (Native C Coroutines)' : 'Not Installed (Using PHP 8.6 Fibers + Io\\Poll)'],
                    ['Io\\Poll Backend', (string) $diag['ioPollBackend']],
                    ['Default Concurrency', (string) $diag['defaultConcurrency']],
                    ['Stream Chunk Size', (string) $diag['streamChunkBytes'] . ' bytes'],
                ],
            );
            $this->lastReturnValue = $diag;
            return 0;
        }, 'Display Atom Async Fiber, Io\\Poll, and Swoole coroutine runtime diagnostics');

        $this->bindCommand('call', fn(array $args): int => $this->handleCallCommand($args), 'Invoke any Atom\* class method (`call Class::method [arg1] [arg2] [--ctor=json]`)');
        $this->bindCommand('classes', fn(array $args): int => $this->handleClassesCommand($args), 'List discoverable Atom\* classes or inspect public methods of a class');

        $this->bindCommand('types:list', function (): int {
            $types = TypeCaster::supportedTypes();
            $this->info('Supported built-in AutoMapped / TypeCaster types (' . count($types) . '):');
            $this->writeln('  ' . implode(', ', $types));
            $this->lastReturnValue = $types;
            return 0;
        }, 'List all built-in AutoMapped / TypeCaster data types');

        $this->bindCommand('cache:status', function (): int {
            $manager = new MemoryCacheManager();
            $stats = $manager->stats();
            $rows = [];
            foreach ($stats as $k => $v) {
                $rows[] = [$k, is_array($v) ? implode(', ', $v) : (string) $v];
            }
            $this->table(['Metric', 'Value'], $rows);
            $this->lastReturnValue = $stats;
            return 0;
        }, 'Show adaptive RAM cache driver diagnostics');

        $this->bindCommand('cache:clear', fn(): int => $this->handleCacheClearCommand(), 'Clear application and config cache in runtime/cache');

        $this->bindCommand('config:show', fn(array $args): int => $this->handleConfigShowCommand($args), 'Inspect resolved configuration (`config:show [app|cache|modul|...]`)');
        $this->bindCommand('config:cache', function (): int {
            Config::autoLoad($this->projectRoot, false);
            Config::writeConfigCache($this->projectRoot);
            $this->success('Configuration cached successfully in runtime/cache/config/.');
            return 0;
        }, 'Compile configuration files into runtime/cache/config');

        $this->bindCommand('config:clear', function (): int {
            $dir = $this->projectRoot . \DIRECTORY_SEPARATOR . 'runtime' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR . 'config';
            $removed = $this->clearDirectoryFiles($dir);
            $this->success("Cleared {$removed} cached configuration file(s).");
            return 0;
        }, 'Remove cached configuration files from runtime/cache/config');

        $this->bindCommand('migrate', function (array $args): int {
            $includeCore = !in_array('--app-only', $args, true);
            $includeApp = !in_array('--core-only', $args, true);

            if ($this->customPdo !== null) {
                $dt = new DateTime();
                $db = Database::fromExistingPdo($this->customPdo);
                $db->pdo = $this->customPdo;
                \Atom\Atom::$ROOT_DIR = $this->projectRoot . \DIRECTORY_SEPARATOR;
                $migrations = new Migrations($db, $dt);
                $applied = $migrations->applyMigrations($includeCore, $includeApp);
                $this->lastReturnValue = $applied;
                $this->success(sprintf('Applied %d SQL migration(s).', count($applied)));
                return 0;
            }

            Config::autoLoad($this->projectRoot, false);
            $appCfg = Config::get('app');
            $dbCfg = Config::get('database');

            if (\Atom\Atom::resolveMicroserviceMode($appCfg) || \Atom\Atom::resolveFlag($appCfg['noDB'] ?? false) || \Atom\Atom::resolveFlag($dbCfg['noDB'] ?? false)) {
                $this->warn('Database SQL migrations skipped because Atom is configured in noDB / microservice mode.');
                $this->lastReturnValue = [];
                return 0;
            }

            $dt = new DateTime();
            $db = new Database($dt, $dbCfg['driver']);
            \Atom\Atom::$ROOT_DIR = $this->projectRoot . \DIRECTORY_SEPARATOR;
            $migrations = new Migrations($db, $dt);
            $applied = $migrations->applyMigrations($includeCore, $includeApp);
            $this->lastReturnValue = $applied;
            $this->success(sprintf('Applied %d core/application SQL migration(s).', count($applied)));
            return 0;
        }, 'Apply pending SQL migrations from Atom/DataBase/migrations and /migrations');

        $this->bindCommand('migrate:down', function (array $args): int {
            $steps = 1;
            foreach ($args as $arg) {
                if (str_starts_with($arg, '--steps=')) {
                    $steps = max(1, (int) substr($arg, 8));
                }
            }

            if ($this->customPdo !== null) {
                $dt = new DateTime();
                $db = Database::fromExistingPdo($this->customPdo);
                $db->pdo = $this->customPdo;
            } else {
                Config::autoLoad($this->projectRoot, false);
                $dbCfg = Config::get('database');
                $dt = new DateTime();
                $db = new Database($dt, $dbCfg['driver']);
            }

            \Atom\Atom::$ROOT_DIR = $this->projectRoot . \DIRECTORY_SEPARATOR;
            $migrations = new Migrations($db, $dt);
            $rolledBack = $migrations->rollbackMigrations($steps);
            $this->lastReturnValue = $rolledBack;
            $this->success(sprintf('Rolled back %d SQL migration(s).', count($rolledBack)));
            return 0;
        }, 'Rollback applied SQL migrations from Atom/DataBase/migrations and /migrations');
        $this->bindCommand('migrate:rollback', fn(array $args): int => ($this->commands['migrate:down'])($args, $this), 'Alias for migrate:down');

        $this->bindCommand('module:list', function (): int {
            $modul = $this->createModulInstance();
            $status = $modul->status();
            $rows = [];
            foreach ($status as $m) {
                $rows[] = [
                    $m['name'],
                    $m['enabled'] ? 'ENABLED' : 'DISABLED',
                    $m['required'] ? 'YES' : 'no',
                    $m['version'],
                    $m['hasMigrations'] ? 'yes' : '-',
                    $m['hasTests'] ? 'yes' : '-',
                ];
            }
            $this->table(['Module', 'State', 'Required', 'Version', 'Migrations', 'Tests'], $rows);
            $this->lastReturnValue = $status;
            return 0;
        }, 'List all Atom modules, their activation state, migrations, and tests');

        $this->bindCommand('module:verify', function (): int {
            $modul = $this->createModulInstance();
            $modul->verifyRequiredModules();
            $this->success('All required Atom modules and module dependencies are enabled and verified.');
            return 0;
        }, 'Verify that all required modules and dependencies are enabled');

        $this->bindCommand('module:migrate', fn(array $args): int => $this->handleModuleMigrateCommand($args, down: false), 'Apply SQL/PHP UP migrations for modules (`module:migrate [Module] [--steps=N]`)');
        $this->bindCommand('module:downgrade', fn(array $args): int => $this->handleModuleMigrateCommand($args, down: true), 'Rollback SQL/PHP DOWN migrations for modules (`module:downgrade [Module] [--steps=1]`)');
        $this->bindCommand('module:rollback', fn(array $args): int => $this->handleModuleMigrateCommand($args, down: true), 'Alias for module:downgrade');

        $this->bindCommand('module:status', function (array $args): int {
            $moduleFilter = isset($args[0]) && !str_starts_with($args[0], '--') ? $args[0] : null;
            $runner = $this->createModuleMigrationRunner();
            $status = $runner->status($moduleFilter);
            if ($status === []) {
                $this->info('No module migrations found.');
                $this->lastReturnValue = [];
                return 0;
            }
            $rows = [];
            foreach ($status as $row) {
                $rows[] = [
                    $row['module'],
                    $row['migration'],
                    $row['applied'] ? 'APPLIED' : 'PENDING',
                    $row['applied_at'] ?? '-',
                ];
            }
            $this->table(['Module', 'Migration', 'Status', 'Applied At'], $rows);
            $this->lastReturnValue = $status;
            return 0;
        }, 'Show migration status for one or all modules');

        $this->bindCommand('completion', function (array $args): int {
            $shell = $args[0] ?? 'bash';
            $script = $this->generateCompletionScript($shell);
            $this->writeln($script);
            $this->lastReturnValue = $script;
            return 0;
        }, 'Generate shell autocomplete script (`completion [bash|zsh|pwsh]`)');
    }

    /**
     * @param list<string> $args
     */
    private function handleMinifyCommand(array $args): int
    {
        $cssDir = null;
        $jsDir = null;
        $outCssDir = null;
        $outJsDir = null;
        $singleFile = false;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--css=')) {
                $cssDir = substr($arg, 6);
            } elseif (str_starts_with($arg, '--js=')) {
                $jsDir = substr($arg, 5);
            } elseif (str_starts_with($arg, '--out-css=')) {
                $outCssDir = substr($arg, 10);
            } elseif (str_starts_with($arg, '--out-js=')) {
                $outJsDir = substr($arg, 9);
            } elseif ($arg === '--single') {
                $singleFile = true;
            }
        }

        $defaultShrinkConfig = [
            'singleFile' => $singleFile,
            'onlyRootDir' => false,
            'rootCssDirName' => 'global',
            'rootJsDirName' => 'global',
            'cssSingleFileName' => 'main',
            'jsSingleFileName' => 'main',
        ];

        $resPaths = (new ResourcesPath($this->projectRoot . \DIRECTORY_SEPARATOR, $defaultShrinkConfig))->all();
        $sourceCssDir = $cssDir ?? $resPaths['resourcesCssDirPath'];
        $sourceJsDir = $jsDir ?? $resPaths['resourcesJsDirPath'];
        $targetCssDir = rtrim($outCssDir ?? $resPaths['assetsCssDirPath'], '/\\');
        $targetJsDir = rtrim($outJsDir ?? $resPaths['assetsJsDirPath'], '/\\');

        $cssFiles = $this->collectFilesByExtension($sourceCssDir, '.css');
        $jsFiles = $this->collectFilesByExtension($sourceJsDir, '.js');
        $totalFiles = count($cssFiles) + count($jsFiles);

        if ($totalFiles === 0) {
            $this->warn('No CSS or JS source files found to minify.');
            $this->lastReturnValue = ['css' => [], 'js' => []];
            return 0;
        }

        $logDir = $this->projectRoot . \DIRECTORY_SEPARATOR . 'runtime' . \DIRECTORY_SEPARATOR . 'log' . \DIRECTORY_SEPARATOR;
        if (!is_dir($logDir)) {
            \mkdir($logDir, 0775, true);
        }
        $logger = new T4LOG(['logRootDir' => $logDir]);
        $shrink = new Shrink($logger, [...$resPaths, ...$defaultShrinkConfig]);

        if (!is_dir($targetCssDir) && $cssFiles !== []) {
            \mkdir($targetCssDir, 0775, true);
        }
        if (!is_dir($targetJsDir) && $jsFiles !== []) {
            \mkdir($targetJsDir, 0775, true);
        }

        $bar = $this->createProgressBar($totalFiles);
        $bar->start('Starting asset minification');

        $minifiedCss = [];
        $minifiedJs = [];

        foreach ($cssFiles as $file) {
            $base = \basename($file, '.css');
            $outName = str_ends_with($base, '.min') ? $base . '.css' : $base . '.min.css';
            $dest = $targetCssDir . \DIRECTORY_SEPARATOR . $outName;
            if (!file_exists($dest)) {
                \file_put_contents($dest, '');
            }
            $shrink->cssWithSave($file, $dest);
            $minifiedCss[] = $dest;
            $bar->advance(1, 'CSS: ' . \basename($file));
        }

        foreach ($jsFiles as $file) {
            $base = \basename($file, '.js');
            $outName = str_ends_with($base, '.min') ? $base . '.js' : $base . '.min.js';
            $dest = $targetJsDir . \DIRECTORY_SEPARATOR . $outName;
            if (!file_exists($dest)) {
                \file_put_contents($dest, '');
            }
            $shrink->jsWithSave($file, $dest);
            $minifiedJs[] = $dest;
            $bar->advance(1, 'JS: ' . \basename($file));
        }

        $bar->finish('Minified ' . $totalFiles . ' file(s)');
        $this->success(sprintf('Minified %d CSS and %d JS file(s).', count($minifiedCss), count($minifiedJs)));

        $summary = ['css' => $minifiedCss, 'js' => $minifiedJs];
        $this->lastReturnValue = $summary;
        return 0;
    }

    /**
     * @param list<string> $args
     */
    private function handleCallCommand(array $args): int
    {
        if ($args === []) {
            throw new InvalidArgumentException("Usage: call <Atom\\Class[::method]> [method] [args...] [--ctor='[...]']");
        }

        $ctorArgs = [];
        $positional = [];

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--ctor=')) {
                $rawCtor = substr($arg, 7);
                $decoded = json_decode($rawCtor, true);
                if (!is_array($decoded)) {
                    throw new InvalidArgumentException('Invalid JSON array passed to --ctor.');
                }
                $ctorArgs = array_values($decoded);
                continue;
            }
            $positional[] = $arg;
        }

        $target = $positional[0];
        $method = null;
        $rawCallArgs = [];

        if (str_contains($target, '::')) {
            $rawCallArgs = array_slice($positional, 1);
        } else {
            $method = $positional[1] ?? null;
            $rawCallArgs = array_slice($positional, 2);
        }

        $parsedArgs = array_map($this->parseCliValue(...), $rawCallArgs);
        $result = $this->invokeAtomClass($target, $method, $parsedArgs, $ctorArgs);

        $this->info('Result from ' . $this->resolveAtomClassName(explode('::', $target)[0]) . ($method !== null ? "::{$method}()" : '') . ':');
        if (is_scalar($result) || $result === null) {
            $this->writeln('  ' . (string) json_encode($result, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        } else {
            $this->writeln((string) json_encode($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        }

        return 0;
    }

    /**
     * @param list<string> $args
     */
    private function handleClassesCommand(array $args): int
    {
        $filter = $args[0] ?? '';

        if ($filter !== '') {
            $candidateFqcn = $this->resolveAtomClassName($filter);
            if (class_exists($candidateFqcn)) {
                $info = $this->invokeAtomClass($candidateFqcn, null);
                $this->info("Public methods on {$candidateFqcn}:");
                foreach ($info['public_methods'] as $m) {
                    $this->writeln('  - ' . $m . '()');
                }
                return 0;
            }
        }

        $all = $this->discoverAtomClasses();
        if ($filter !== '') {
            $low = strtolower($filter);
            $all = array_values(array_filter(
                $all,
                static fn(string $c): bool => str_contains(strtolower($c), $low),
            ));
        }

        $this->info('Discovered Atom classes (' . count($all) . '):');
        foreach ($all as $cls) {
            $this->writeln('  ' . $cls);
        }

        $this->lastReturnValue = $all;
        return 0;
    }

    private function handleCacheClearCommand(): int
    {
        $cacheRoot = $this->projectRoot . \DIRECTORY_SEPARATOR . 'runtime' . \DIRECTORY_SEPARATOR . 'cache';
        $removed = $this->clearDirectoryFiles($cacheRoot);
        $this->success("Cleared {$removed} cached file(s) from runtime/cache.");
        $this->lastReturnValue = $removed;
        return 0;
    }

    /**
     * @param list<string> $args
     */
    private function handleConfigShowCommand(array $args): int
    {
        $section = $args[0] ?? 'app';
        $cfgFile = $this->projectRoot . \DIRECTORY_SEPARATOR . 'config' . \DIRECTORY_SEPARATOR . $section . '.php';
        if (!is_file($cfgFile)) {
            throw new InvalidArgumentException("Configuration section '{$section}' not found at {$cfgFile}.");
        }

        $data = require $cfgFile;
        $this->info("Configuration [{$section}]:");
        $this->writeln((string) json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        $this->lastReturnValue = $data;
        return 0;
    }

    /**
     * @param list<string> $args
     */
    private function handleModuleMigrateCommand(array $args, bool $down): int
    {
        $moduleName = null;
        $steps = $down ? 1 : null;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--steps=')) {
                $steps = max(1, (int) substr($arg, 8));
            } elseif (!str_starts_with($arg, '--') && $moduleName === null) {
                $moduleName = $arg;
            }
        }

        $runner = $this->createModuleMigrationRunner();
        $bar = null;

        $onProgress = function (int $current, int $total, string $label) use (&$bar, $down): void {
            if ($bar === null) {
                $bar = $this->createProgressBar($total);
                $bar->start($down ? 'Rolling back module migrations' : 'Applying module migrations');
            }
            $bar->setProgress($current, $label);
            if ($current >= $total) {
                $bar->finish($down ? 'Downgrade finished' : 'Migrations applied');
            }
        };

        $executed = $down
            ? $runner->migrateDown($moduleName, $steps ?? 1, $onProgress)
            : $runner->migrateUp($moduleName, $steps, $onProgress);

        if ($executed === []) {
            $this->info($down ? 'No applied module migrations to downgrade.' : 'No pending module migrations to apply.');
        } else {
            $verb = $down ? 'Downgraded' : 'Applied';
            $this->success(sprintf('%s %d module migration(s): %s', $verb, count($executed), implode(', ', $executed)));
        }

        $this->lastReturnValue = $executed;
        return 0;
    }

    private function createModulInstance(): Modul
    {
        $modulFile = $this->projectRoot . \DIRECTORY_SEPARATOR . 'config' . \DIRECTORY_SEPARATOR . 'modul.php';
        $modulConfig = is_file($modulFile) ? (array) (require $modulFile) : [];
        $logDir = $this->projectRoot . \DIRECTORY_SEPARATOR . 'runtime' . \DIRECTORY_SEPARATOR . 'log' . \DIRECTORY_SEPARATOR;
        if (!is_dir($logDir)) {
            \mkdir($logDir, 0775, true);
        }

        $modulesDir = $this->projectRoot . \DIRECTORY_SEPARATOR . 'Atom' . \DIRECTORY_SEPARATOR . 'Modules';
        return new Modul($modulConfig, new FileSystem(), new T4LOG(['logRootDir' => $logDir]), $modulesDir);
    }

    private function createModuleMigrationRunner(): ModuleMigrationRunner
    {
        $modulesDir = $this->projectRoot . \DIRECTORY_SEPARATOR . 'Atom' . \DIRECTORY_SEPARATOR . 'Modules';

        if ($this->customPdo !== null) {
            return new ModuleMigrationRunner($this->customPdo, '', $modulesDir);
        }

        Config::autoLoad($this->projectRoot, false);
        $dbConfig = Config::get('database');
        $dt = new DateTime();
        $db = new Database($dt, $dbConfig['driver']);
        $prefix = (string) ($dbConfig['driver']['prefix'] ?? '');

        return new ModuleMigrationRunner($db->pdo, $prefix, $modulesDir);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadAboutMetadata(): array
    {
        $aboutPath = dirname(__DIR__) . \DIRECTORY_SEPARATOR . 'AboutAtom.php';
        if (is_file($aboutPath)) {
            $data = require $aboutPath;
            if (is_array($data)) {
                return $data;
            }
        }

        return [
            'name' => 'Atom',
            'version' => self::VERSION,
            'author' => 'Timonix',
        ];
    }

    private function resolveAtomClassName(string $rawClass): string
    {
        $normalized = str_replace('/', '\\', ltrim(trim($rawClass), '\\'));
        if (!str_starts_with($normalized, 'Atom\\')) {
            $normalized = 'Atom\\' . $normalized;
        }

        if (preg_match('/^Atom\\\\[A-Za-z0-9_\\\\]+$/', $normalized) !== 1) {
            throw new InvalidArgumentException("Invalid Atom class identifier: '{$rawClass}'. Only classes inside 'Atom\\' are permitted.");
        }

        return $normalized;
    }

    private function parseCliValue(string $raw): mixed
    {
        $trimmed = trim($raw);
        $lower = strtolower($trimmed);

        if ($lower === 'null') {
            return null;
        }
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if ($trimmed !== '' && (\ctype_digit($trimmed) || (str_starts_with($trimmed, '-') && \ctype_digit(substr($trimmed, 1))))) {
            return (int) $trimmed;
        }
        if (is_numeric($trimmed)) {
            return (float) $trimmed;
        }
        if ((str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) && json_validate($trimmed)) {
            return json_decode($trimmed, true);
        }

        return $raw;
    }

    /**
     * @param list<mixed> $args
     * @return list<mixed>
     */
    private function coerceArgumentsForMethod(ReflectionMethod $method, array $args): array
    {
        $params = $method->getParameters();
        $coerced = [];

        foreach ($params as $idx => $param) {
            if ($param->isVariadic()) {
                for ($v = $idx; $v < count($args); $v++) {
                    $coerced[] = $args[$v];
                }
                break;
            }

            if (!\array_key_exists($idx, $args)) {
                if ($param->isDefaultValueAvailable()) {
                    $coerced[] = $param->getDefaultValue();
                    continue;
                }
                if ($param->allowsNull()) {
                    $coerced[] = null;
                    continue;
                }
                throw new InvalidArgumentException(sprintf(
                    "Missing required argument #%d (\$%s) for %s::%s().",
                    $idx + 1,
                    $param->getName(),
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                ));
            }

            $val = $args[$idx];
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && $type->isBuiltin() && $val !== null) {
                $val = match ($type->getName()) {
                    'int' => (int) $val,
                    'float' => (float) $val,
                    'bool' => is_bool($val) ? $val : in_array(strtolower((string) $val), ['1', 'true', 'yes', 'on'], true),
                    'string' => is_scalar($val) ? (string) $val : (string) json_encode($val),
                    'array' => is_array($val) ? $val : [$val],
                    default => $val,
                };
            }

            $coerced[] = $val;
        }

        return $coerced;
    }

    /**
     * @return list<string>
     */
    private function completeAtomClassesAndMethods(string $prefix): array
    {
        $classes = $this->discoverAtomClasses();

        if (str_contains($prefix, '::')) {
            [$clsPart, $methodPrefix] = explode('::', $prefix, 2);
            try {
                $fqcn = $this->resolveAtomClassName($clsPart);
            } catch (Throwable) {
                return [];
            }

            if (!class_exists($fqcn)) {
                return [];
            }

            $ref = new ReflectionClass($fqcn);
            $matches = [];
            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
                if ($m->isConstructor() || $m->isDestructor()) {
                    continue;
                }
                if ($methodPrefix === '' || str_starts_with($m->getName(), $methodPrefix)) {
                    $matches[] = $clsPart . '::' . $m->getName();
                }
            }
            sort($matches, \SORT_NATURAL);
            return $matches;
        }

        $normalizedPrefix = str_replace('/', '\\', ltrim($prefix, '\\'));
        $results = [];

        foreach ($classes as $fqcn) {
            $short = substr($fqcn, 5); // without 'Atom\'
            if ($normalizedPrefix === '' || str_starts_with($fqcn, $normalizedPrefix) || str_starts_with($short, $normalizedPrefix)) {
                $results[] = str_starts_with($normalizedPrefix, 'Atom\\') || $normalizedPrefix === '' ? $fqcn : $short;
            }
        }

        sort($results, \SORT_NATURAL);
        return $results;
    }

    /**
     * @return list<string>
     */
    private function discoverAtomClasses(): array
    {
        $atomDir = dirname(__DIR__);
        if (!is_dir($atomDir)) {
            return [];
        }

        $classes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($atomDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                continue;
            }

            $fullPath = $fileInfo->getPathname();
            $relative = substr($fullPath, strlen($atomDir) + 1);
            if (
                str_starts_with($relative, 'Lib' . \DIRECTORY_SEPARATOR)
                || str_contains($relative, \DIRECTORY_SEPARATOR . 'tests' . \DIRECTORY_SEPARATOR)
                || str_contains($relative, \DIRECTORY_SEPARATOR . 'migration' . \DIRECTORY_SEPARATOR)
                || str_contains($relative, \DIRECTORY_SEPARATOR . 'migrations' . \DIRECTORY_SEPARATOR)
                || $relative === 'AboutAtom.php'
                || str_ends_with($relative, 'Function.php')
                || str_ends_with($relative, '_function.php')
            ) {
                continue;
            }

            $fqcn = 'Atom\\' . str_replace(['/', '\\'], '\\', substr($relative, 0, -4));
            $classes[] = $fqcn;
        }

        sort($classes, \SORT_NATURAL);
        return $classes;
    }

    /**
     * @return list<string>
     */
    private function discoverModuleDirectoryNames(): array
    {
        $dir = $this->projectRoot . \DIRECTORY_SEPARATOR . 'Atom' . \DIRECTORY_SEPARATOR . 'Modules';
        if (!is_dir($dir)) {
            return [];
        }

        $entries = scandir($dir) ?: [];
        $names = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, ['Contract', 'Exception'], true)) {
                continue;
            }
            if (is_dir($dir . \DIRECTORY_SEPARATOR . $entry)) {
                $names[] = $entry;
            }
        }

        sort($names, \SORT_NATURAL);
        return $names;
    }

    /**
     * @return list<string>
     */
    private function collectFilesByExtension(string $dir, string $extension): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $found = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if ($item->isFile() && str_ends_with(strtolower($item->getFilename()), $extension)) {
                $found[] = $item->getPathname();
            }
        }

        sort($found, \SORT_NATURAL);
        return $found;
    }

    private function clearDirectoryFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $removed = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isFile() && $item->getFilename() !== '.gitignore') {
                if (unlink($path)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * @return list<string>
     */
    private function tokenizeCommandLine(string $line): array
    {
        \preg_match_all('/"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"|\'([^\']*)\'|(\S+)/', $line, $matches, \PREG_SET_ORDER);
        $tokens = [];
        foreach ($matches as $m) {
            if (isset($m[1]) && $m[1] !== '') {
                $tokens[] = \stripslashes($m[1]);
            } elseif (isset($m[2]) && $m[2] !== '') {
                $tokens[] = $m[2];
            } elseif (isset($m[3])) {
                $tokens[] = $m[3];
            }
        }

        return $tokens;
    }

    /**
     * @param list<string> $items
     */
    private function joinForPwsh(array $items): string
    {
        return implode("','", $items);
    }

    private static function detectAnsiSupport(): bool
    {
        if (\getenv('NO_COLOR') !== false) {
            return false;
        }

        if (\defined('STDOUT') && is_resource(\STDOUT) && function_exists('stream_isatty')) {
            return \stream_isatty(\STDOUT);
        }

        return true;
    }
}
