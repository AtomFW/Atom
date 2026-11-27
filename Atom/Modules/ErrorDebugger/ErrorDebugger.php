<?php

declare(strict_types=1);

namespace Atom\Modules\ErrorDebugger;

use Atom\Atom;
use Atom\HttpFoundation\Request;
use Atom\Modules\Contract\AtomModuleInterface;
use Atom\Terminal\Terminal;
use ErrorException;
use Override;
use RuntimeException;
use Throwable;

use function error_reporting;
use function gmdate;
use function set_error_handler;

/**
 * Official Atom module providing rich graphical PHP exception and error diagnostics
 * (`-5` / `+5` source context, file:line:verse, full event tree, sanitized arguments)
 * strictly when `app.debug === true`.
 */
final class ErrorDebugger implements AtomModuleInterface
{
    public const string VERSION = '1.0.0';

    private readonly DebugErrorRenderer $renderer;
    private readonly bool $convertPhpErrors;
    private ?bool $forcedDebugFlag;

    /**
     * Bounded per-instance lifecycle trace buffer (cleared on `EVENT_AFTER_REQUEST`).
     *
     * @var list<array{stage: string, detail?: string, timestamp?: string}>
     */
    private array $lifecycleEvents = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $contextLines = isset($options['contextLines']) ? (int) $options['contextLines'] : 5;
        $maxArgDepth = isset($options['maxArgDepth']) ? (int) $options['maxArgDepth'] : 3;
        $maxStringLength = isset($options['maxStringLength']) ? (int) $options['maxStringLength'] : 512;

        $this->renderer = new DebugErrorRenderer($contextLines, $maxArgDepth, $maxStringLength);
        $this->convertPhpErrors = (bool) ($options['convertPhpErrors'] ?? true);
        $this->forcedDebugFlag = isset($options['debug']) ? (bool) $options['debug'] : null;
    }

    #[Override]
    public function getName(): string
    {
        return 'ErrorDebugger';
    }

    #[Override]
    public function getVersion(): string
    {
        return self::VERSION;
    }

    #[Override]
    public function dependencies(): array
    {
        return [];
    }

    public function renderer(): DebugErrorRenderer
    {
        return $this->renderer;
    }

    public function isDebugEnabled(?Atom $app = null): bool
    {
        if ($this->forcedDebugFlag !== null) {
            return $this->forcedDebugFlag;
        }

        if ($app !== null) {
            $appConfig = $app->config->get('app');
            return \is_array($appConfig) && !empty($appConfig['debug']);
        }

        return false;
    }

    #[Override]
    public function boot(Atom $app): void
    {
        if (!$this->isDebugEnabled($app)) {
            return;
        }

        $this->recordEvent('ErrorDebugger Booted', 'Debug mode active; graphical error handler attached');

        if ($this->convertPhpErrors) {
            $this->registerPhpErrorPromotion();
        }

        $app->on(Atom::EVENT_BEFORE_REQUEST, function () use ($app): void {
            $this->lifecycleEvents = [];
            $this->recordEvent(
                'HTTP Request Received',
                \strtoupper($app->request->getMethod()) . ' ' . $app->request->getPath(),
            );
        });

        $app->on(Atom::EVENT_AFTER_REQUEST, function (): void {
            $this->lifecycleEvents = [];
        });
    }

    #[Override]
    public function registerCommands(Terminal $terminal): Terminal
    {
        return $terminal->withCommand(
            'debug:preview',
            function (array $args): int {
                $message = $args[0] ?? 'Sample Atom debug diagnostic exception';
                $sample = new RuntimeException($message, 500);
                $info = $this->renderer->inspect($sample, null, [
                    ['stage' => 'Terminal Command', 'detail' => 'debug:preview invoked'],
                ]);

                $terminal->writeln($terminal->style('ErrorDebugger Diagnostic Check', 'bold', 'cyan'));
                $terminal->writeln('  Exception : ' . (string) $info['class']);
                $terminal->writeln('  Message   : ' . (string) $info['message']);
                $terminal->writeln('  Location  : ' . (string) $info['file'] . ':' . (int) $info['line'] . ' (col ' . (int) $info['column'] . ')');
                return 0;
            },
            'Simulate and inspect a sample exception through ErrorDebugger',
        );
    }

    /**
     * Records a custom application/request event into the current request's debug timeline.
     */
    public function recordEvent(string $stage, string $detail = ''): void
    {
        if (\count($this->lifecycleEvents) >= 64) {
            \array_shift($this->lifecycleEvents);
        }

        $this->lifecycleEvents[] = [
            'stage' => $stage,
            'detail' => $detail,
            'timestamp' => gmdate('H:i:s'),
        ];
    }

    /**
     * Renders a `Throwable` to a graphical HTML diagnostic page (only intended for debug mode).
     *
     * @param array<string, mixed> $extraContext
     */
    public function renderThrowable(
        Throwable $throwable,
        ?Request $request = null,
        array $extraContext = [],
    ): string {
        return $this->renderer->renderHtml($throwable, $request, $this->lifecycleEvents, $extraContext);
    }

    /**
     * Inspects a `Throwable` and returns structured diagnostic arrays.
     *
     * @param array<string, mixed> $extraContext
     * @return array<string, mixed>
     */
    public function inspectThrowable(
        Throwable $throwable,
        ?Request $request = null,
        array $extraContext = [],
    ): array {
        return $this->renderer->inspect($throwable, $request, $this->lifecycleEvents, $extraContext);
    }

    /**
     * Converts reportable PHP warnings/notices/errors into `ErrorException` instances during debug execution.
     */
    public function registerPhpErrorPromotion(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }
}
