<?php

declare(strict_types=1);

namespace Atom\Modules\PerformanceProfiler;

/**
 * Self-contained interactive HTML visualizer for `ProfileTrace`, rendering:
 * - Detailed CPU (User/Sys/Saturation), RAM (Used/Delta/Peak), Disk (I/O + Volume), and JIT/OPcache gauges
 * - Core vs Client Application execution split bar
 * - Ranked Bottleneck & Hotspot diagnostics with `file:line` and optimization guidance
 * - Interactive Waterfall / Flame-Timeline flow graph with click-to-inspect span drawer
 * - Embedded AI Agent JSON & Timonix Cloud Raw Telemetry tabs
 */
final readonly class PerformanceReportRenderer
{
    /**
     * @param list<ProfileTrace> $recentTraces
     */
    public function renderHtml(ProfileTrace $trace, array $recentTraces = [], string $projectKey = ''): string
    {
        $totalNs = \max(1, $trace->totalDurationNs);
        $totalMs = \number_format($trace->totalDurationMs(), 2);
        $cpuMs = \number_format($trace->cpuTotalMs(), 2);
        $cpuSat = \number_format($trace->cpuSaturationPercent(), 1);
        $peakMb = \number_format($trace->memoryPeakBytes / 1_048_576, 2);
        $deltaKb = \number_format($trace->memoryDeltaBytes / 1024, 1);
        $diskIoKb = \number_format(($trace->diskReadDeltaBytes + $trace->diskWriteDeltaBytes) / 1024, 1);
        $diskUsedPct = \number_format($trace->diskVolume['usedPercent'], 1);
        $diskFreeGb = \number_format($trace->diskVolume['freeBytes'] / 1_073_741_824, 2);

        $corePct = \clamp(\round(($trace->coreSelfDurationNs / $totalNs) * 100.0, 1), 0.0, 100.0);
        $appPct = \clamp(\round(($trace->clientAppSelfDurationNs / $totalNs) * 100.0, 1), 0.0, 100.0);
        $dbMs = \number_format($trace->databaseDurationNs / 1_000_000, 2);
        $viewMs = \number_format($trace->viewDurationNs / 1_000_000, 2);

        $methodEsc = $this->esc(\strtoupper($trace->method));
        $urlEsc = $this->esc($trace->url);
        $traceIdEsc = $this->esc($trace->traceId);
        $bottleneckCount = \count($trace->bottlenecks);

        $bottleneckSpanIds = [];
        foreach ($trace->bottlenecks as $b) {
            if (isset($b['spanId'])) {
                $bottleneckSpanIds[(string) $b['spanId']] = (string) ($b['severity'] ?? 'warning');
            }
        }

        $bottlenecksHtml = '';
        if ($trace->bottlenecks === []) {
            $bottlenecksHtml = '<div class="empty-ok">No bottlenecks exceeded thresholds — execution is fast and balanced.</div>';
        } else {
            foreach ($trace->bottlenecks as $b) {
                $sev = (string) ($b['severity'] ?? 'warning');
                $scope = (string) ($b['originScope'] ?? 'core');
                $loc = ($b['file'] ?? null) !== null
                    ? $this->esc((string) $b['file']) . ':' . (int) ($b['line'] ?? 0)
                    : 'runtime';

                $bottlenecksHtml .= \sprintf(
                    '<div class="bneck-card sev-%s">'
                    . '<div class="bneck-top">'
                    . '<span class="pill pill-%s">%s</span>'
                    . '<span class="pill pill-scope-%s">%s</span>'
                    . '<strong class="mono">%s</strong>'
                    . '<span class="bneck-metrics">%0.2f ms self (%0.1f%%) | CPU %0.2f ms | RAM %+0.1f KB</span>'
                    . '</div>'
                    . '<div class="bneck-diag">%s</div>'
                    . '<div class="bneck-loc mono">Location: %s</div>'
                    . '<div class="bneck-rec">Hint: %s</div>'
                    . '</div>',
                    $this->esc($sev),
                    $this->esc($sev),
                    \strtoupper($this->esc($sev)),
                    $this->esc($scope),
                    $scope === 'client_app' ? 'CLIENT APP' : 'ATOM CORE',
                    $this->esc((string) ($b['spanName'] ?? '')),
                    (float) ($b['selfDurationMs'] ?? 0.0),
                    (float) ($b['sharePercent'] ?? 0.0),
                    (float) ($b['cpuTotalMs'] ?? 0.0),
                    ((int) ($b['memoryDeltaBytes'] ?? 0)) / 1024,
                    $this->esc((string) ($b['diagnosis'] ?? '')),
                    $loc,
                    $this->esc((string) ($b['recommendation'] ?? '')),
                );
            }
        }

        $waterfallRowsHtml = '';
        foreach ($trace->spans as $idx => $span) {
            $leftPct = \clamp(($span->startOffsetNs / $totalNs) * 100.0, 0.0, 99.5);
            $widthPct = \clamp(($span->durationNs / $totalNs) * 100.0, 0.6, 100.0 - $leftPct);
            $indentPx = \clamp($span->depth, 0, 16) * 16;
            $sevFlag = $bottleneckSpanIds[$span->spanId] ?? '';
            $rowAlertClass = $sevFlag !== '' ? ' row-bneck-' . $this->esc($sevFlag) : '';
            $catClass = 'cat-' . $this->esc($span->category);
            $scopeBadge = $span->originScope === 'client_app' ? 'APP' : 'CORE';
            $locStr = $span->file !== null ? ($span->file . ':' . ($span->line ?? 1)) : 'internal';
            $detailJson = $this->esc((string) \json_encode($span->toArray($trace->totalDurationNs), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

            $waterfallRowsHtml .= \sprintf(
                '<div class="wf-row%s" onclick="inspectSpan(%d)" data-span-json="%s" id="wf-row-%d">'
                . '<div class="wf-label" style="padding-left:%dpx">'
                . '<span class="scope-mini scope-%s">%s</span>'
                . '<span class="wf-name mono" title="%s">%s</span>'
                . '</div>'
                . '<div class="wf-track">'
                . '<div class="wf-bar %s%s" style="left:%0.2f%%;width:%0.2f%%"></div>'
                . '</div>'
                . '<div class="wf-stats mono">%0.2f ms <span class="muted">(self %0.2f ms)</span></div>'
                . '</div>',
                $rowAlertClass,
                $idx,
                $detailJson,
                $idx,
                12 + $indentPx,
                $this->esc($span->originScope),
                $scopeBadge,
                $this->esc($locStr),
                $this->esc($span->name),
                $catClass,
                $sevFlag !== '' ? ' bar-hot' : '',
                $leftPct,
                $widthPct,
                $span->durationMs(),
                $span->selfDurationMs(),
            );
        }

        $historyHtml = '';
        foreach ($recentTraces as $recent) {
            $active = $recent->traceId === $trace->traceId ? ' active' : '';
            $historyHtml .= \sprintf(
                '<a class="hist-pill%s" href="?trace=%s"><span class="mono">%s %s</span> <strong>%0.2f ms</strong> (%d)</a>',
                $active,
                $this->esc($recent->traceId),
                $this->esc(\strtoupper($recent->method)),
                $this->esc($recent->url),
                $recent->totalDurationMs(),
                $recent->statusCode,
            );
        }

        $agentJson = $this->esc((string) \json_encode($trace->toAgentReport(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        $cloudJson = $this->esc((string) \json_encode($trace->toTimonixCloudTelemetry($projectKey), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        $jitBadge = $trace->engineStatus['jitEnabled'] ? 'JIT ON' : ($trace->engineStatus['opcacheEnabled'] ? 'OPcache ON' : 'Interpreter');

        return <<<HTML
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atom Performance Profiler — {$methodEsc} {$urlEsc}</title>
    <style>
        :root {
            --bg: #090d16;
            --surface: #111827;
            --surface-alt: #1b2436;
            --border: #293548;
            --text: #e2e8f0;
            --muted: #94a3b8;
            --core: #38bdf8;
            --app: #a855f7;
            --db: #f59e0b;
            --view: #10b981;
            --danger: #f43f5e;
            --mono: 'JetBrains Mono', 'Fira Code', Consolas, monospace;
            --sans: Inter, system-ui, -apple-system, sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: var(--bg); color: var(--text); font-family: var(--sans); padding: 22px; line-height: 1.5; }
        .mono { font-family: var(--mono); }
        .muted { color: var(--muted); }
        .wrap { max-width: 1480px; margin: 0 auto; display: flex; flex-direction: column; gap: 18px; }
        .hero { background: linear-gradient(135deg, #111b2e 0%, #1d1430 100%); border: 1px solid var(--border); border-radius: 12px; padding: 20px 24px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 14px; }
        .brand { background: var(--core); color: #082f49; font-weight: 800; font-size: 11px; padding: 4px 10px; border-radius: 999px; letter-spacing: 0.08em; text-transform: uppercase; }
        .hero-title { font-size: 20px; font-weight: 700; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
        .kpi { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 16px; }
        .kpi-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); margin-bottom: 6px; }
        .kpi-val { font-size: 24px; font-weight: 800; font-family: var(--mono); color: #fff; }
        .kpi-sub { font-size: 12px; color: var(--muted); margin-top: 4px; }
        .split-bar { height: 12px; background: var(--surface-alt); border-radius: 999px; overflow: hidden; display: flex; margin-top: 8px; border: 1px solid var(--border); }
        .split-core { background: var(--core); }
        .split-app { background: var(--app); }
        .tabs { display: flex; gap: 8px; flex-wrap: wrap; }
        .tab-btn { background: var(--surface); color: var(--text); border: 1px solid var(--border); padding: 8px 16px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 13px; }
        .tab-btn.active { background: var(--core); color: #082f49; border-color: var(--core); }
        .tab-panel { display: none; flex-direction: column; gap: 16px; }
        .tab-panel.active { display: flex; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
        .card-hd { background: var(--surface-alt); padding: 12px 18px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; }
        .bneck-list { padding: 16px; display: flex; flex-direction: column; gap: 12px; }
        .bneck-card { background: rgba(244, 63, 94, 0.07); border: 1px solid var(--border); border-left: 4px solid var(--db); border-radius: 8px; padding: 14px; display: flex; flex-direction: column; gap: 6px; }
        .bneck-card.sev-critical { border-left-color: var(--danger); background: rgba(244, 63, 94, 0.13); }
        .bneck-top { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; font-size: 13px; }
        .bneck-metrics { margin-left: auto; font-family: var(--mono); font-size: 12px; color: #cbd5e1; }
        .bneck-diag { font-size: 14px; color: #f8fafc; font-weight: 500; }
        .bneck-loc { font-size: 12px; color: var(--core); }
        .bneck-rec { font-size: 12px; color: #fde68a; }
        .pill { font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 999px; }
        .pill-critical { background: var(--danger); color: #fff; }
        .pill-warning { background: var(--db); color: #1e1b4b; }
        .pill-scope-core { background: rgba(56, 189, 248, 0.2); color: var(--core); border: 1px solid var(--core); }
        .pill-scope-client_app { background: rgba(168, 85, 247, 0.2); color: #d8b4fe; border: 1px solid var(--app); }
        .empty-ok { padding: 14px; color: var(--view); font-weight: 600; }
        .wf-grid { display: grid; grid-template-columns: 1fr 380px; gap: 16px; align-items: start; }
        @media (max-width: 1100px) { .wf-grid { grid-template-columns: 1fr; } }
        .wf-row { display: grid; grid-template-columns: 320px 1fr 170px; align-items: center; gap: 12px; padding: 8px 14px; border-bottom: 1px solid rgba(41,53,72,0.6); cursor: pointer; font-size: 12px; }
        .wf-row:hover, .wf-row.selected { background: rgba(56, 189, 248, 0.1); }
        .wf-row.row-bneck-critical { background: rgba(244, 63, 94, 0.12); }
        .wf-label { display: flex; align-items: center; gap: 6px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
        .scope-mini { font-size: 9px; font-weight: 800; padding: 1px 5px; border-radius: 4px; }
        .scope-core { background: rgba(56,189,248,0.2); color: var(--core); }
        .scope-client_app { background: rgba(168,85,247,0.25); color: #e9d5ff; }
        .wf-track { position: relative; height: 14px; background: #0b111e; border-radius: 4px; overflow: hidden; }
        .wf-bar { position: absolute; top: 1px; bottom: 1px; border-radius: 3px; background: var(--core); min-width: 3px; }
        .wf-bar.cat-client_app { background: var(--app); }
        .wf-bar.cat-database { background: var(--db); }
        .wf-bar.cat-view { background: var(--view); }
        .wf-bar.bar-hot { box-shadow: 0 0 8px var(--danger); outline: 1px solid var(--danger); }
        .wf-stats { text-align: right; font-size: 11px; }
        .pre-json { padding: 16px; font-family: var(--mono); font-size: 12px; color: #bae6fd; overflow-x: auto; max-height: 640px; }
        .hist-bar { display: flex; gap: 8px; overflow-x: auto; padding: 4px 0; }
        .hist-pill { background: var(--surface); border: 1px solid var(--border); color: var(--text); text-decoration: none; font-size: 12px; padding: 6px 12px; border-radius: 999px; white-space: nowrap; }
        .hist-pill.active { border-color: var(--core); color: var(--core); }
    </style>
</head>
<body>
<div class="wrap">
    <header class="hero">
        <div class="hero-title">
            <span class="brand">Atom Bottleneck Profiler</span>
            <span class="mono">{$methodEsc} {$urlEsc}</span>
            <span class="pill pill-scope-core">HTTP {$trace->statusCode}</span>
            <span class="muted mono" style="font-size:12px">Trace #{$traceIdEsc}</span>
        </div>
        <div class="tabs">
            <button class="tab-btn active" onclick="switchProfTab('visual', this)">Visual Flow &amp; Bottlenecks</button>
            <button class="tab-btn" onclick="switchProfTab('agent', this)">AI Agent Diagnostic Dump</button>
            <button class="tab-btn" onclick="switchProfTab('cloud', this)">Timonix Cloud Raw Telemetry</button>
        </div>
    </header>

    <div class="hist-bar">{$historyHtml}</div>

    <section class="kpis">
        <div class="kpi">
            <div class="kpi-label">Total Wall Time / Boot</div>
            <div class="kpi-val">{$totalMs} ms</div>
            <div class="kpi-sub">DB: {$dbMs} ms | View: {$viewMs} ms | Bottlenecks: {$bottleneckCount}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">CPU Load (User + Kernel)</div>
            <div class="kpi-val">{$cpuMs} ms</div>
            <div class="kpi-sub">CPU Saturation: {$cpuSat}% | Engine: {$jitBadge}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">RAM Memory Usage &amp; Peak</div>
            <div class="kpi-val">{$peakMb} MB</div>
            <div class="kpi-sub">Request Delta: {$deltaKb} KB | Files +{$trace->includedFilesDelta}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Disk I/O &amp; Storage Volume</div>
            <div class="kpi-val">{$diskIoKb} KB</div>
            <div class="kpi-sub">Volume Used: {$diskUsedPct}% ({$diskFreeGb} GB free)</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Atom Core vs Client App Split</div>
            <div class="kpi-val" style="font-size:16px">Core {$corePct}% / App {$appPct}%</div>
            <div class="split-bar">
                <div class="split-core" style="width:{$corePct}%"></div>
                <div class="split-app" style="width:{$appPct}%"></div>
            </div>
        </div>
    </section>

    <div id="prof-tab-visual" class="tab-panel active">
        <div class="card">
            <div class="card-hd"><span>Detected Bottlenecks &amp; Hotspots ({$bottleneckCount})</span><span>Sorted by Severity &amp; Self-Time</span></div>
            <div class="bneck-list">{$bottlenecksHtml}</div>
        </div>

        <div class="wf-grid">
            <div class="card">
                <div class="card-hd"><span>Execution Flow Waterfall (Click any span for granular CPU/RAM/Disk details)</span><span>Total: {$totalMs} ms</span></div>
                <div>{$waterfallRowsHtml}</div>
            </div>
            <div class="card">
                <div class="card-hd"><span>Selected Span Inspector</span><span class="mono">file:line &amp; deltas</span></div>
                <pre class="pre-json" id="span-detail-box">Click any span row in the waterfall to inspect its exact file, line, self-time, CPU microseconds, RAM bytes, and Disk I/O.</pre>
            </div>
        </div>
    </div>

    <div id="prof-tab-agent" class="tab-panel">
        <div class="card">
            <div class="card-hd"><span>Structured AI Agent Diagnostic Payload (`atom.profiler.agent_diagnostics.v1`)</span><span>Ready for LLM / MCP Analysis</span></div>
            <pre class="pre-json">{$agentJson}</pre>
        </div>
    </div>

    <div id="prof-tab-cloud" class="tab-panel">
        <div class="card">
            <div class="card-hd"><span>Timonix Cloud Raw Measurement Telemetry (`timonix.cloud.telemetry.v1`)</span><span>Compact Machine Vector</span></div>
            <pre class="pre-json">{$cloudJson}</pre>
        </div>
    </div>
</div>
<script>
function switchProfTab(tab, btn) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('prof-tab-' + tab).classList.add('active');
    btn.classList.add('active');
}
function inspectSpan(idx) {
    document.querySelectorAll('.wf-row').forEach(r => r.classList.remove('selected'));
    const row = document.getElementById('wf-row-' + idx);
    if (!row) return;
    row.classList.add('selected');
    const parsed = JSON.parse(row.getAttribute('data-span-json') || '{}');
    document.getElementById('span-detail-box').textContent = JSON.stringify(parsed, null, 2);
}
</script>
</body>
</html>
HTML;
    }

    private function esc(string $text): string
    {
        return \htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
