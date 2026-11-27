<?php

declare(strict_types=1);

namespace Atom\Modules\ErrorDebugger;

use Atom\HttpFoundation\Request;
use ErrorException;
use SensitiveParameterValue;
use SplFileObject;
use Throwable;

use function array_reverse;
use function array_slice;
use function clamp;
use function count;
use function error_get_last;
use function explode;
use function get_debug_type;
use function gmdate;
use function htmlspecialchars;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_file;
use function is_object;
use function is_readable;
use function is_string;
use function json_encode;
use function ltrim;
use function max;
use function memory_get_peak_usage;
use function number_format;
use function preg_match;
use function round;
use function sprintf;
use function str_contains;
use function strlen;
use function strpos;
use function strtolower;
use function substr;

/**
 * Stateless inspector and graphical HTML renderer for PHP exceptions and errors in debug mode.
 *
 * Streams `-5` to `+5` lines around each error location (`O(1)` memory), computes line & column
 * (verse) coordinates, builds the full causal/lifecycle event tree, and sanitizes passed data.
 */
final readonly class DebugErrorRenderer
{
    /** @var list<string> */
    private const array SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'passwd',
        'pwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'authorization',
        'cookie',
        'session',
        'private_key',
        'card',
        'cvv',
        'dsn',
    ];

    private int $contextLines;
    private int $maxArgDepth;
    private int $maxStringLength;

    public function __construct(
        int $contextLines = 5,
        int $maxArgDepth = 3,
        int $maxStringLength = 512,
    ) {
        $this->contextLines = clamp($contextLines, 1, 25);
        $this->maxArgDepth = clamp($maxArgDepth, 1, 6);
        $this->maxStringLength = clamp($maxStringLength, 64, 4096);
    }

    /**
     * Builds a structured, sanitized diagnostic payload for a `Throwable`.
     *
     * @param list<array{stage: string, detail?: string, timestamp?: string}> $lifecycleEvents
     * @param array<string, mixed> $extraContext
     * @return array<string, mixed>
     */
    public function inspect(
        Throwable $throwable,
        ?Request $request = null,
        array $lifecycleEvents = [],
        array $extraContext = [],
    ): array {
        $file = $throwable->getFile();
        $line = max(1, $throwable->getLine());
        $snippet = $this->extractCodeContext($file, $line, $this->contextLines);
        $column = $this->detectColumn($throwable->getMessage(), $snippet['lines'][$line] ?? null);

        $frames = $this->buildStackFrames($throwable, $file, $line, $column, $snippet);
        $causalChain = $this->buildCausalChain($throwable);
        $eventTree = $this->buildEventTree($lifecycleEvents, $causalChain, $frames);
        $requestData = $this->extractRequestData($request);
        $sanitizedExtra = $this->sanitizeMap($extraContext, 0);

        return [
            'class' => $throwable::class,
            'severity' => $this->resolveSeverityLabel($throwable),
            'code' => $throwable->getCode(),
            'message' => $throwable->getMessage(),
            'file' => $file,
            'line' => $line,
            'column' => $column,
            'context_radius' => $this->contextLines,
            'snippet' => $snippet,
            'frames' => $frames,
            'causal_chain' => $causalChain,
            'event_tree' => $eventTree,
            'request' => $requestData,
            'passed_context' => $sanitizedExtra,
            'environment' => [
                'php_version' => \PHP_VERSION,
                'zts' => \PHP_ZTS === 1 ? 'ZTS (Thread-Safe)' : 'NTS (Non-Thread-Safe)',
                'sapi' => \PHP_SAPI,
                'os' => \PHP_OS_FAMILY,
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1_048_576, 2),
                'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
        ];
    }

    /**
     * Renders a self-contained, interactive HTML page displaying the error details,
     * `-5` / `+5` code lines, file:line:column, full event tree, and passed arguments.
     *
     * @param list<array{stage: string, detail?: string, timestamp?: string}> $lifecycleEvents
     * @param array<string, mixed> $extraContext
     */
    public function renderHtml(
        Throwable $throwable,
        ?Request $request = null,
        array $lifecycleEvents = [],
        array $extraContext = [],
    ): string {
        $data = $this->inspect($throwable, $request, $lifecycleEvents, $extraContext);

        $classEsc = $this->esc((string) $data['class']);
        $severityEsc = $this->esc((string) $data['severity']);
        $msgEsc = $this->esc((string) $data['message']);
        $fileEsc = $this->esc((string) $data['file']);
        $line = (int) $data['line'];
        $col = (int) $data['column'];
        $codeVal = $this->esc((string) $data['code']);

        /** @var list<array<string, mixed>> $frames */
        $frames = $data['frames'];
        /** @var list<array<string, mixed>> $eventTree */
        $eventTree = $data['event_tree'];
        /** @var array<string, mixed> $requestInfo */
        $requestInfo = $data['request'];
        /** @var array<string, mixed> $envInfo */
        $envInfo = $data['environment'];
        /** @var array<string, mixed> $passedContext */
        $passedContext = $data['passed_context'];

        $framesNavHtml = '';
        $framesDetailHtml = '';

        foreach ($frames as $idx => $frame) {
            $activeClass = $idx === 0 ? ' active' : '';
            $fCall = $this->esc((string) $frame['call']);
            $fFile = $this->esc((string) $frame['file']);
            $fLine = (int) $frame['line'];
            $fCol = (int) $frame['column'];

            $framesNavHtml .= sprintf(
                '<button type="button" class="frame-btn%s" data-frame="%d" onclick="selectAtomFrame(%d)">'
                . '<span class="frame-idx">#%d</span>'
                . '<span class="frame-call">%s</span>'
                . '<span class="frame-loc">%s:%d (col %d)</span>'
                . '</button>',
                $activeClass,
                $idx,
                $idx,
                $idx,
                $fCall,
                $fFile,
                $fLine,
                $fCol,
            );

            $codeHtml = $this->renderSnippetHtml($frame['snippet'], $fLine, $fCol);
            $argsJson = $this->esc((string) json_encode($frame['args'], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

            $framesDetailHtml .= sprintf(
                '<section class="frame-panel%s" id="atom-frame-%d">'
                . '<div class="panel-header">'
                . '<div class="path-badge" title="Full file path, line, and verse/column">'
                . '<span class="badge-label">Location</span>'
                . '<code>%s</code>'
                . '<span class="loc-pill">Line %d</span>'
                . '<span class="loc-pill verse-pill">Verse / Col %d</span>'
                . '</div>'
                . '</div>'
                . '%s'
                . '<div class="args-box">'
                . '<h4>Passed Arguments to <code>%s</code></h4>'
                . '<pre class="data-pre">%s</pre>'
                . '</div>'
                . '</section>',
                $activeClass,
                $idx,
                $fFile,
                $fLine,
                $fCol,
                $codeHtml,
                $fCall,
                $argsJson !== '' ? $argsJson : '[]',
            );
        }

        $treeHtml = '';
        foreach ($eventTree as $stepIdx => $node) {
            $nodeType = $this->esc((string) ($node['type'] ?? 'event'));
            $nodeTitle = $this->esc((string) ($node['title'] ?? ''));
            $nodeDetail = $this->esc((string) ($node['detail'] ?? ''));
            $nodeMeta = $this->esc((string) ($node['meta'] ?? ''));

            $treeHtml .= sprintf(
                '<li class="tree-node tree-%s">'
                . '<div class="tree-marker">%d</div>'
                . '<div class="tree-body">'
                . '<div class="tree-title"><span class="tree-tag">%s</span> %s</div>'
                . ($nodeDetail !== '' ? '<div class="tree-detail">' . $nodeDetail . '</div>' : '')
                . ($nodeMeta !== '' ? '<div class="tree-meta"><code>' . $nodeMeta . '</code></div>' : '')
                . '</div>'
                . '</li>',
                $nodeType,
                $stepIdx + 1,
                $nodeType,
                $nodeTitle,
            );
        }

        $reqJson = $this->esc((string) json_encode($requestInfo, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        $ctxJson = $this->esc((string) json_encode($passedContext, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        $envJson = $this->esc((string) json_encode($envInfo, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Atom Debug — {$classEsc}: {$msgEsc}</title>
    <style>
        :root {
            --bg: #0b0f19;
            --surface: #131926;
            --surface-alt: #1a2234;
            --border: #28354f;
            --text: #e2e8f0;
            --muted: #94a3b8;
            --accent: #38bdf8;
            --danger: #f43f5e;
            --danger-bg: rgba(244, 63, 94, 0.16);
            --warn: #f59e0b;
            --ok: #10b981;
            --mono: 'JetBrains Mono', 'Fira Code', Consolas, monospace;
            --sans: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: var(--bg); color: var(--text); font-family: var(--sans); line-height: 1.5; padding: 24px; }
        .container { max-width: 1440px; margin: 0 auto; display: flex; flex-direction: column; gap: 20px; }
        .hero { background: linear-gradient(135deg, #161e2e 0%, #1e152a 100%); border: 1px solid var(--danger); border-radius: 12px; padding: 24px; box-shadow: 0 12px 32px rgba(0,0,0,0.45); }
        .hero-top { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 12px; }
        .atom-brand { background: var(--accent); color: #082f49; font-weight: 800; font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; padding: 4px 10px; border-radius: 999px; }
        .severity-badge { background: var(--danger-bg); color: #fda4af; border: 1px solid var(--danger); font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 999px; }
        .class-badge { font-family: var(--mono); color: var(--accent); font-size: 14px; font-weight: 600; }
        .error-message { font-size: 22px; font-weight: 700; color: #fff; word-break: break-word; margin-bottom: 14px; }
        .hero-location { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; font-family: var(--mono); font-size: 13px; background: rgba(0,0,0,0.35); padding: 10px 14px; border-radius: 8px; border: 1px solid var(--border); }
        .loc-pill { background: var(--surface-alt); border: 1px solid var(--border); color: #f8fafc; padding: 2px 8px; border-radius: 6px; font-weight: 600; }
        .verse-pill { border-color: var(--warn); color: #fcd34d; }
        .workspace { display: grid; grid-template-columns: 360px 1fr; gap: 20px; align-items: start; }
        @media (max-width: 1024px) { .workspace { grid-template-columns: 1fr; } }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
        .card-title { padding: 14px 18px; background: var(--surface-alt); border-bottom: 1px solid var(--border); font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); display: flex; justify-content: space-between; align-items: center; }
        .frames-list { max-height: 680px; overflow-y: auto; display: flex; flex-direction: column; }
        .frame-btn { background: transparent; border: 0; border-bottom: 1px solid var(--border); color: var(--text); text-align: left; padding: 12px 16px; cursor: pointer; display: flex; flex-direction: column; gap: 4px; transition: background 0.15s; }
        .frame-btn:hover { background: rgba(56, 189, 248, 0.08); }
        .frame-btn.active { background: rgba(244, 63, 94, 0.14); border-left: 4px solid var(--danger); }
        .frame-idx { font-family: var(--mono); font-size: 11px; color: var(--accent); font-weight: 700; }
        .frame-call { font-family: var(--mono); font-size: 13px; font-weight: 600; word-break: break-all; }
        .frame-loc { font-family: var(--mono); font-size: 11px; color: var(--muted); word-break: break-all; }
        .frame-panel { display: none; padding: 18px; }
        .frame-panel.active { display: block; }
        .panel-header { margin-bottom: 14px; }
        .path-badge { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; font-family: var(--mono); font-size: 13px; background: var(--bg); padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); }
        .badge-label { color: var(--muted); text-transform: uppercase; font-size: 11px; font-weight: 700; }
        .code-window { background: #070a12; border: 1px solid var(--border); border-radius: 8px; overflow-x: auto; font-family: var(--mono); font-size: 13px; margin-bottom: 16px; }
        .code-row { display: flex; padding: 3px 12px; white-space: pre; border-left: 3px solid transparent; }
        .code-row.error-line { background: var(--danger-bg); border-left-color: var(--danger); color: #fff; font-weight: 600; }
        .code-caret-row { display: flex; padding: 0 12px 4px 12px; white-space: pre; background: rgba(244, 63, 94, 0.08); border-left: 3px solid var(--danger); color: var(--warn); font-weight: 700; }
        .line-num { width: 56px; flex-shrink: 0; color: #64748b; user-select: none; text-align: right; padding-right: 14px; }
        .error-line .line-num { color: #fda4af; font-weight: 800; }
        .line-code { flex: 1; }
        .args-box h4 { font-size: 13px; color: var(--muted); margin-bottom: 8px; }
        .data-pre { background: #070a12; border: 1px solid var(--border); border-radius: 8px; padding: 12px; font-family: var(--mono); font-size: 12px; color: #cbd5e1; overflow-x: auto; max-height: 300px; }
        .grid-bottom { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 1024px) { .grid-bottom { grid-template-columns: 1fr; } }
        .tree-list { list-style: none; padding: 18px; display: flex; flex-direction: column; gap: 12px; }
        .tree-node { display: flex; gap: 12px; align-items: flex-start; }
        .tree-marker { width: 26px; height: 26px; border-radius: 50%; background: var(--surface-alt); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; font-family: var(--mono); font-size: 11px; font-weight: 700; flex-shrink: 0; color: var(--accent); }
        .tree-exception .tree-marker { border-color: var(--danger); color: #fda4af; background: var(--danger-bg); }
        .tree-body { background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 10px 14px; flex: 1; }
        .tree-title { font-size: 13px; font-weight: 700; margin-bottom: 4px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .tree-tag { font-family: var(--mono); font-size: 10px; text-transform: uppercase; padding: 2px 6px; border-radius: 4px; background: var(--surface-alt); color: var(--accent); }
        .tree-exception .tree-tag { background: var(--danger-bg); color: #fda4af; }
        .tree-detail { font-size: 12px; color: #cbd5e1; word-break: break-word; }
        .tree-meta { margin-top: 4px; font-size: 11px; color: var(--muted); word-break: break-all; }
        .section-pad { padding: 16px; display: flex; flex-direction: column; gap: 12px; }
        .section-pad h4 { font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); }
    </style>
</head>
<body>
<div class="container">
    <header class="hero">
        <div class="hero-top">
            <span class="atom-brand">Atom Debug Mode</span>
            <span class="severity-badge">{$severityEsc} (Code: {$codeVal})</span>
            <span class="class-badge">{$classEsc}</span>
        </div>
        <h1 class="error-message">{$msgEsc}</h1>
        <div class="hero-location">
            <span>File: <strong>{$fileEsc}</strong></span>
            <span class="loc-pill">Line {$line}</span>
            <span class="loc-pill verse-pill">Verse / Column {$col}</span>
        </div>
    </header>

    <main class="workspace">
        <aside class="card">
            <div class="card-title">
                <span>Call Stack Frames</span>
                <span>Context: -{$this->contextLines} / +{$this->contextLines} lines</span>
            </div>
            <div class="frames-list">
                {$framesNavHtml}
            </div>
        </aside>

        <div class="card">
            <div class="card-title">
                <span>Source Code Preview &amp; Frame Arguments</span>
                <span>PHP {$envInfo['php_version']} ({$envInfo['zts']})</span>
            </div>
            {$framesDetailHtml}
        </div>
    </main>

    <div class="grid-bottom">
        <section class="card">
            <div class="card-title">
                <span>Full Event &amp; Causal Tree</span>
            </div>
            <ol class="tree-list">
                {$treeHtml}
            </ol>
        </section>

        <section class="card">
            <div class="card-title">
                <span>Passed Request, Context &amp; Runtime Data</span>
            </div>
            <div class="section-pad">
                <h4>HTTP / Input Data (Sanitized)</h4>
                <pre class="data-pre">{$reqJson}</pre>
                <h4>Additional Passed Context</h4>
                <pre class="data-pre">{$ctxJson}</pre>
                <h4>Runtime Environment</h4>
                <pre class="data-pre">{$envJson}</pre>
            </div>
        </section>
    </div>
</div>
<script>
function selectAtomFrame(index) {
    document.querySelectorAll('.frame-btn').forEach(function (btn) {
        btn.classList.toggle('active', Number(btn.getAttribute('data-frame')) === index);
    });
    document.querySelectorAll('.frame-panel').forEach(function (panel) {
        panel.classList.toggle('active', panel.id === 'atom-frame-' + index);
    });
}
</script>
</body>
</html>
HTML;
    }

    /**
     * Streams `-radius` to `+radius` lines around `$errorLine` without loading the entire file into RAM.
     *
     * @return array{start: int, end: int, target: int, lines: array<int, string>}
     */
    public function extractCodeContext(string $file, int $errorLine, int $radius = 5): array
    {
        $safeLine = max(1, $errorLine);
        $startLine = max(1, $safeLine - $radius);
        $endLine = $safeLine + $radius;

        if ($file === '' || !is_file($file)) {
            return [
                'start' => $startLine,
                'end' => $endLine,
                'target' => $safeLine,
                'lines' => [],
            ];
        }

        $lines = [];
        try {
            $fileObj = new SplFileObject($file, 'r');
        } catch (Throwable) {
            return [
                'start' => $startLine,
                'end' => $endLine,
                'target' => $safeLine,
                'lines' => [],
            ];
        }
        $fileObj->seek($startLine - 1);
        $currentLineNumber = $startLine;

        while (!$fileObj->eof() && $currentLineNumber <= $endLine) {
            $rawLine = $fileObj->current();
            $lines[$currentLineNumber] = is_string($rawLine)
                ? \rtrim($rawLine, "\r\n")
                : '';
            $fileObj->next();
            $currentLineNumber++;
        }

        return [
            'start' => $startLine,
            'end' => $currentLineNumber > $startLine ? $currentLineNumber - 1 : $startLine,
            'target' => $safeLine,
            'lines' => $lines,
        ];
    }

    /**
     * @param array{start: int, end: int, target: int, lines: array<int, string>} $snippet
     */
    private function renderSnippetHtml(array $snippet, int $errorLine, int $column): string
    {
        if ($snippet['lines'] === []) {
            return '<div class="code-window"><div class="code-row"><span class="line-num">-</span><span class="line-code">// Source file is virtual or unavailable on disk.</span></div></div>';
        }

        $rows = [];
        foreach ($snippet['lines'] as $num => $codeLine) {
            $isTarget = $num === $errorLine;
            $rowClass = $isTarget ? 'code-row error-line' : 'code-row';
            $rows[] = sprintf(
                '<div class="%s"><span class="line-num">%d</span><span class="line-code">%s</span></div>',
                $rowClass,
                $num,
                $this->esc($codeLine !== '' ? $codeLine : ' '),
            );

            if ($isTarget && $column > 0) {
                $padLen = clamp($column - 1, 0, 240);
                $caret = \str_repeat(' ', $padLen) . '^-- verse / col ' . $column;
                $rows[] = sprintf(
                    '<div class="code-caret-row"><span class="line-num"></span><span class="line-code">%s</span></div>',
                    $this->esc($caret),
                );
            }
        }

        return '<div class="code-window">' . implode('', $rows) . '</div>';
    }

    private function detectColumn(string $message, ?string $sourceLine): int
    {
        if (preg_match('/\b(?:column|col|offset)\s+(\d+)\b/i', $message, $m) === 1) {
            return max(1, (int) $m[1]);
        }

        if ($sourceLine === null || $sourceLine === '') {
            return 1;
        }

        if (preg_match('/\$([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)/', $message, $varMatch) === 1) {
            $pos = strpos($sourceLine, '$' . $varMatch[1]);
            if ($pos !== false) {
                return $pos + 1;
            }
        }

        if (preg_match('/([a-zA-Z0-9_]+)\(\)/', $message, $fnMatch) === 1) {
            $pos = strpos($sourceLine, $fnMatch[1]);
            if ($pos !== false) {
                return $pos + 1;
            }
        }

        $trimmed = ltrim($sourceLine, " \t");
        $indent = strlen($sourceLine) - strlen($trimmed);

        return $indent + 1;
    }

    /**
     * @param array{start: int, end: int, target: int, lines: array<int, string>} $rootSnippet
     * @return list<array<string, mixed>>
     */
    private function buildStackFrames(
        Throwable $throwable,
        string $rootFile,
        int $rootLine,
        int $rootColumn,
        array $rootSnippet,
    ): array {
        $rawTrace = $throwable->getTrace();
        $firstFrameArgs = isset($rawTrace[0]['args']) && is_array($rawTrace[0]['args'])
            ? $this->sanitizeList($rawTrace[0]['args'], 0)
            : [];

        $frames = [
            [
                'call' => $throwable::class . ' (throw site)',
                'file' => $rootFile,
                'line' => $rootLine,
                'column' => $rootColumn,
                'snippet' => $rootSnippet,
                'args' => $firstFrameArgs,
            ],
        ];

        foreach (array_slice($rawTrace, 0, 40) as $traceItem) {
            $fFile = (string) ($traceItem['file'] ?? '[internal]');
            $fLine = (int) ($traceItem['line'] ?? 1);
            $fClass = (string) ($traceItem['class'] ?? '');
            $fType = (string) ($traceItem['type'] ?? '');
            $fFunc = (string) ($traceItem['function'] ?? '{closure}');
            $callSig = $fClass !== '' ? "{$fClass}{$fType}{$fFunc}()" : "{$fFunc}()";

            $snippet = $fFile !== '[internal]'
                ? $this->extractCodeContext($fFile, $fLine, $this->contextLines)
                : ['start' => 1, 'end' => 1, 'target' => 1, 'lines' => []];

            $col = $this->detectColumn('', $snippet['lines'][$fLine] ?? null);
            $args = isset($traceItem['args']) && is_array($traceItem['args'])
                ? $this->sanitizeList($traceItem['args'], 0)
                : [];

            $frames[] = [
                'call' => $callSig,
                'file' => $fFile,
                'line' => $fLine,
                'column' => $col,
                'snippet' => $snippet,
                'args' => $args,
            ];
        }

        return $frames;
    }

    /**
     * @return list<array{class: string, message: string, file: string, line: int, code: int|string}>
     */
    private function buildCausalChain(Throwable $throwable): array
    {
        $chain = [];
        $cursor = $throwable;
        $guard = 0;

        while ($cursor !== null && $guard < 16) {
            $chain[] = [
                'class' => $cursor::class,
                'message' => $cursor->getMessage(),
                'file' => $cursor->getFile(),
                'line' => $cursor->getLine(),
                'code' => $cursor->getCode(),
            ];
            $cursor = $cursor->getPrevious();
            $guard++;
        }

        return array_reverse($chain);
    }

    /**
     * @param list<array{stage: string, detail?: string, timestamp?: string}> $lifecycleEvents
     * @param list<array{class: string, message: string, file: string, line: int, code: int|string}> $causalChain
     * @param list<array<string, mixed>> $frames
     * @return list<array{type: string, title: string, detail: string, meta: string}>
     */
    private function buildEventTree(array $lifecycleEvents, array $causalChain, array $frames): array
    {
        $tree = [
            [
                'type' => 'boot',
                'title' => 'Atom Runtime Initialized',
                'detail' => sprintf('PHP %s (%s) on %s', \PHP_VERSION, \PHP_SAPI, \PHP_OS_FAMILY),
                'meta' => '',
            ],
        ];

        foreach ($lifecycleEvents as $ev) {
            $tree[] = [
                'type' => 'lifecycle',
                'title' => $ev['stage'],
                'detail' => (string) ($ev['detail'] ?? ''),
                'meta' => (string) ($ev['timestamp'] ?? ''),
            ];
        }

        $callFlow = array_reverse(array_slice($frames, 1, 12));
        foreach ($callFlow as $frame) {
            $tree[] = [
                'type' => 'call',
                'title' => (string) $frame['call'],
                'detail' => count((array) $frame['args']) > 0
                    ? sprintf('%d argument(s) passed', count((array) $frame['args']))
                    : 'Invoked without captured arguments',
                'meta' => sprintf('%s:%d (col %d)', (string) $frame['file'], (int) $frame['line'], (int) $frame['column']),
            ];
        }

        foreach ($causalChain as $idx => $ex) {
            $tree[] = [
                'type' => 'exception',
                'title' => ($idx === 0 && count($causalChain) > 1 ? 'Root Cause: ' : 'Thrown: ') . $ex['class'],
                'detail' => $ex['message'],
                'meta' => sprintf('%s:%d (code %s)', $ex['file'], $ex['line'], (string) $ex['code']),
            ];
        }

        return $tree;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractRequestData(?Request $request): array
    {
        if ($request === null) {
            return [
                'mode' => \PHP_SAPI,
                'note' => 'No HTTP Request object attached to this error context.',
            ];
        }

        return [
            'method' => \strtoupper($request->getMethod()),
            'path' => $request->getPath(),
            'url' => $request->getUrl(),
            'body_and_query' => $this->sanitizeMap($request->getBody(), 0),
        ];
    }

    /**
     * @param array<int|string, mixed> $map
     * @return array<int|string, mixed>
     */
    private function sanitizeMap(array $map, int $depth): array
    {
        if ($depth >= $this->maxArgDepth) {
            return ['...' => sprintf('Array(%d) truncated at max depth', count($map))];
        }

        $clean = [];
        $count = 0;
        foreach ($map as $key => $value) {
            if ($count >= 50) {
                $clean['...'] = 'Remaining entries truncated';
                break;
            }
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $clean[$key] = '[REDACTED]';
            } else {
                $clean[$key] = $this->sanitizeValue($value, $depth + 1);
            }
            $count++;
        }

        return $clean;
    }

    /**
     * @param array<int|string, mixed> $list
     * @return list<mixed>
     */
    private function sanitizeList(array $list, int $depth): array
    {
        $out = [];
        $count = 0;
        foreach ($list as $item) {
            if ($count >= 25) {
                $out[] = '... truncated';
                break;
            }
            $out[] = $this->sanitizeValue($item, $depth + 1);
            $count++;
        }

        return $out;
    }

    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        if ($value instanceof SensitiveParameterValue) {
            return '[REDACTED SensitiveParameter]';
        }

        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return \is_finite($value) ? $value : (string) $value;
        }

        if (is_string($value)) {
            if (strlen($value) > $this->maxStringLength) {
                return substr($value, 0, $this->maxStringLength) . sprintf('... (%s bytes)', number_format(strlen($value)));
            }
            return $value;
        }

        if (is_array($value)) {
            return $this->sanitizeMap($value, $depth);
        }

        if (is_object($value)) {
            if ($value instanceof \UnitEnum) {
                return $value::class . '::' . $value->name;
            }
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d\TH:i:s.uP');
            }
            if ($value instanceof \Stringable) {
                $str = (string) $value;
                return $value::class . '(' . (strlen($str) > 120 ? substr($str, 0, 120) . '...' : $str) . ')';
            }

            return 'object(' . $value::class . ')';
        }

        return get_debug_type($value);
    }

    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function resolveSeverityLabel(Throwable $throwable): string
    {
        if ($throwable instanceof ErrorException) {
            return match ($throwable->getSeverity()) {
                \E_ERROR, \E_CORE_ERROR, \E_COMPILE_ERROR, \E_USER_ERROR => 'Fatal Error',
                \E_WARNING, \E_CORE_WARNING, \E_COMPILE_WARNING, \E_USER_WARNING => 'Warning',
                \E_PARSE => 'Parse Error',
                \E_NOTICE, \E_USER_NOTICE => 'Notice',
                \E_DEPRECATED, \E_USER_DEPRECATED => 'Deprecated',
                default => 'PHP Error',
            };
        }

        if ($throwable instanceof \ParseError) {
            return 'Parse Error';
        }

        if ($throwable instanceof \TypeError) {
            return 'Type Error';
        }

        if ($throwable instanceof \Error) {
            return 'Engine Error';
        }

        return 'Uncaught Exception';
    }

    private function esc(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
