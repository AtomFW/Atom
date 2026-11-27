<?php

declare(strict_types=1);

namespace Atom\Modules\TrafficInspector;

/**
 * Self-contained visual HTML renderer for `TrafficInspector`, displaying:
 * 1. SKĄD I PO CO: Client IP, Proxy chain, Referer/Origin, User-Agent, User/Session, and inferred Request Purpose
 * 2. CO NIOSŁO: Sanitized Headers, Query (`GET`), Form/JSON Body, Cookies, Route Params, and Uploaded Files
 * 3. TRASA I PLIKI: Step-by-step execution pipeline + exact traversed PHP/View/Layout files (`file:line`)
 * 4. ODPOWIEDŹ (CO, DLACZEGO, SKĄD): HTTP status, exact causal reason, `file:line` origin, outgoing headers & body preview
 */
final readonly class TrafficInspectorRenderer
{
    /**
     * @param list<TrafficTrace> $recentTraces
     */
    public function renderHtml(TrafficTrace $trace, array $recentTraces = []): string
    {
        $in = $trace->inbound;
        $route = $trace->routeAndFiles;
        $out = $trace->outbound;

        $method = $this->esc(\strtoupper((string) ($in['method'] ?? 'GET')));
        $url = $this->esc((string) ($in['url'] ?? '/'));
        $status = (int) ($out['statusCode'] ?? 200);
        $statusPhrase = $this->esc((string) ($out['statusPhrase'] ?? 'OK'));
        $statusColorClass = $status >= 500 ? 'st-5xx' : ($status >= 400 ? 'st-4xx' : ($status >= 300 ? 'st-3xx' : 'st-2xx'));

        $clientIp = $this->esc((string) ($in['clientIp'] ?? '127.0.0.1'));
        $clientType = $this->esc((string) ($in['clientType'] ?? 'Browser'));
        $intentCode = $this->esc((string) ($in['intentCode'] ?? 'http_request'));
        $intentDesc = $this->esc((string) ($in['intentDescription'] ?? ''));
        $referer = $this->esc((string) ($in['referer'] ?? '(direct / brak Referer)'));
        $origin = $this->esc((string) ($in['origin'] ?? '(same-origin / brak Origin)'));
        $userAgent = $this->esc((string) ($in['userAgent'] ?? '(brak User-Agent)'));
        $authActor = $this->esc((string) ($in['actor'] ?? 'Guest'));

        $matchedHandler = $this->esc((string) ($route['handlerSummary'] ?? 'Unmatched'));
        $matchedPattern = $this->esc((string) ($route['matchedPattern'] ?? '(none)'));

        $whyText = $this->esc((string) ($out['whyExplanation'] ?? ''));
        $originWhere = $this->esc((string) ($out['originSummary'] ?? 'Unknown'));
        $contentType = $this->esc((string) ($out['contentType'] ?? 'text/html'));
        $bodyBytes = (int) ($out['bodySizeBytes'] ?? 0);
        $durMs = \number_format($trace->durationMs, 2);

        $traversedHtml = '';
        $traversedList = \is_array($route['traversedFiles'] ?? null) ? $route['traversedFiles'] : [];
        foreach ($traversedList as $idx => $item) {
            if (!\is_array($item)) {
                continue;
            }
            $fRole = $this->esc((string) ($item['role'] ?? 'code'));
            $fPath = $this->esc((string) ($item['file'] ?? ''));
            $fLine = isset($item['line']) ? ':' . (int) $item['line'] : '';

            $traversedHtml .= \sprintf(
                '<li class="trail-item"><span class="step-num">%d</span><span class="role-badge role-%s">%s</span><code class="mono">%s%s</code></li>',
                $idx + 1,
                $fRole,
                \strtoupper($fRole),
                $fPath,
                $fLine,
            );
        }

        $stagesHtml = '';
        $stagesList = \is_array($route['stages'] ?? null) ? $route['stages'] : [];
        foreach ($stagesList as $st) {
            if (!\is_array($st)) {
                continue;
            }
            $sName = $this->esc((string) ($st['name'] ?? ''));
            $sCat = $this->esc((string) ($st['category'] ?? 'core'));
            $sDur = \number_format((float) ($st['durationMs'] ?? 0.0), 3);
            $sLoc = ($st['file'] ?? null) !== null ? $this->esc((string) $st['file']) . ':' . (int) ($st['line'] ?? 1) : 'internal';
            $sErr = ($st['error'] ?? null) !== null ? '<span class="err-tag">' . $this->esc((string) $st['error']) . '</span>' : '';

            $stagesHtml .= \sprintf(
                '<div class="stage-row"><span class="role-badge role-%s">%s</span><strong class="mono">%s</strong>%s<span class="muted mono" style="margin-left:auto">%s (%s ms)</span></div>',
                $sCat,
                \strtoupper($sCat),
                $sName,
                $sErr,
                $sLoc,
                $sDur,
            );
        }

        $includedHtml = '';
        $incList = \is_array($route['newlyIncludedFiles'] ?? null) ? $route['newlyIncludedFiles'] : [];
        foreach ($incList as $inc) {
            if (!\is_array($inc)) {
                continue;
            }
            $includedHtml .= \sprintf(
                '<div class="inc-row"><span class="role-badge">%s</span><code class="mono">%s</code></div>',
                $this->esc((string) ($inc['group'] ?? 'PHP')),
                $this->esc((string) ($inc['file'] ?? '')),
            );
        }
        if ($includedHtml === '') {
            $includedHtml = '<div class="muted" style="padding:12px">All required PHP classes were already warm in OPcache / memory for this request.</div>';
        }

        $historyHtml = '';
        foreach ($recentTraces as $recent) {
            $active = $recent->traceId === $trace->traceId ? ' active' : '';
            $rMethod = $this->esc(\strtoupper((string) ($recent->inbound['method'] ?? 'GET')));
            $rUrl = $this->esc((string) ($recent->inbound['url'] ?? '/'));
            $rCode = (int) ($recent->outbound['statusCode'] ?? 200);
            $historyHtml .= \sprintf(
                '<a class="hist-pill%s" href="?trace=%s"><span class="mono">%s %s</span> <strong>%d</strong> (%0.1f ms)</a>',
                $active,
                $this->esc($recent->traceId),
                $rMethod,
                $rUrl,
                $rCode,
                $recent->durationMs,
            );
        }

        $inHeadersJson = $this->prettyJson($in['headers'] ?? []);
        $inParamsJson = $this->prettyJson([
            'routeParams' => $in['routeParams'] ?? [],
            'queryParams' => $in['queryParams'] ?? [],
            'bodyParams' => $in['bodyParams'] ?? [],
            'cookies' => $in['cookies'] ?? [],
            'uploadedFiles' => $in['uploadedFiles'] ?? [],
        ]);
        $inRawPreview = $this->esc((string) ($in['rawBodyPreview'] ?? ''));
        $outHeadersJson = $this->prettyJson($out['headers'] ?? []);
        $outStatusTimelineJson = $this->prettyJson($out['statusTimeline'] ?? []);
        $outBodyPreview = $this->esc((string) ($out['bodyPreview'] ?? ''));
        $fullTraceJson = $this->prettyJson($trace->toArray());

        return <<<HTML
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atom Traffic Inspector — {$method} {$url} -> {$status}</title>
    <style>
        :root {
            --bg: #080c14;
            --surface: #111827;
            --surface-alt: #1a2438;
            --border: #2a3950;
            --text: #e2e8f0;
            --muted: #94a3b8;
            --cyan: #38bdf8;
            --violet: #a855f7;
            --emerald: #10b981;
            --amber: #f59e0b;
            --rose: #f43f5e;
            --mono: 'JetBrains Mono', 'Fira Code', Consolas, monospace;
            --sans: Inter, system-ui, -apple-system, sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: var(--bg); color: var(--text); font-family: var(--sans); padding: 22px; line-height: 1.5; }
        .mono { font-family: var(--mono); }
        .muted { color: var(--muted); }
        .wrap { max-width: 1480px; margin: 0 auto; display: flex; flex-direction: column; gap: 18px; }
        .hero { background: linear-gradient(135deg, #0f1b2d 0%, #17253b 100%); border: 1px solid var(--border); border-radius: 12px; padding: 20px 24px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; }
        .brand { background: var(--emerald); color: #052e16; font-weight: 800; font-size: 11px; padding: 4px 10px; border-radius: 999px; text-transform: uppercase; letter-spacing: 0.08em; }
        .st-badge { font-family: var(--mono); font-weight: 800; padding: 4px 12px; border-radius: 999px; font-size: 13px; }
        .st-2xx { background: rgba(16, 185, 129, 0.2); color: #34d399; border: 1px solid var(--emerald); }
        .st-3xx { background: rgba(56, 189, 248, 0.2); color: var(--cyan); border: 1px solid var(--cyan); }
        .st-4xx { background: rgba(245, 158, 11, 0.2); color: #fbbf24; border: 1px solid var(--amber); }
        .st-5xx { background: rgba(244, 63, 94, 0.2); color: #fb7185; border: 1px solid var(--rose); }
        .flow-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
        @media (max-width: 1150px) { .flow-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 680px) { .flow-grid { grid-template-columns: 1fr; } }
        .flow-card { background: var(--surface); border: 1px solid var(--border); border-top: 3px solid var(--cyan); border-radius: 10px; padding: 16px; display: flex; flex-direction: column; gap: 6px; }
        .flow-card.fc-in { border-top-color: var(--cyan); }
        .flow-card.fc-why { border-top-color: var(--violet); }
        .flow-card.fc-route { border-top-color: var(--amber); }
        .flow-card.fc-out { border-top-color: var(--emerald); }
        .fc-step { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); }
        .fc-main { font-size: 15px; font-weight: 700; color: #fff; word-break: break-word; }
        .fc-meta { font-size: 12px; color: #cbd5e1; word-break: break-word; }
        .cols-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 1024px) { .cols-2 { grid-template-columns: 1fr; } }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
        .card-hd { background: var(--surface-alt); padding: 12px 18px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; }
        .kv-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .kv-table td { padding: 8px 16px; border-bottom: 1px solid rgba(42,57,80,0.5); vertical-align: top; }
        .kv-table td:first-child { width: 170px; color: var(--muted); font-weight: 600; }
        .pre-box { padding: 14px 16px; font-family: var(--mono); font-size: 12px; color: #bae6fd; overflow-x: auto; max-height: 420px; white-space: pre-wrap; word-break: break-word; }
        .trail-list { list-style: none; padding: 14px 16px; display: flex; flex-direction: column; gap: 8px; }
        .trail-item, .stage-row, .inc-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; background: rgba(26,36,56,0.55); padding: 8px 12px; border-radius: 8px; border: 1px solid rgba(42,57,80,0.7); font-size: 12px; }
        .step-num { background: var(--cyan); color: #082f49; font-weight: 800; font-size: 11px; width: 22px; height: 22px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; }
        .role-badge { font-size: 10px; font-weight: 800; padding: 2px 7px; border-radius: 5px; background: rgba(148,163,184,0.2); color: #e2e8f0; }
        .role-controller, .role-client_app { background: rgba(168,85,247,0.25); color: #e9d5ff; }
        .role-view, .role-layout { background: rgba(16,185,129,0.25); color: #6ee7b7; }
        .role-middleware { background: rgba(245,158,11,0.25); color: #fde68a; }
        .role-exception { background: rgba(244,63,94,0.25); color: #fda4af; }
        .err-tag { background: var(--rose); color: #fff; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; }
        .hist-bar { display: flex; gap: 8px; overflow-x: auto; padding: 2px 0; }
        .hist-pill { background: var(--surface); border: 1px solid var(--border); color: var(--text); text-decoration: none; font-size: 12px; padding: 6px 12px; border-radius: 999px; white-space: nowrap; }
        .hist-pill.active { border-color: var(--emerald); color: #34d399; }
    </style>
</head>
<body>
<div class="wrap">
    <header class="hero">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <span class="brand">Atom Traffic Inspector</span>
            <strong class="mono" style="font-size:18px">{$method} {$url}</strong>
            <span class="st-badge {$statusColorClass}">HTTP {$status} {$statusPhrase}</span>
            <span class="muted mono" style="font-size:12px">{$durMs} ms | Trace #{$this->esc($trace->traceId)}</span>
        </div>
    </header>

    <div class="hist-bar">{$historyHtml}</div>

    <section class="flow-grid">
        <div class="flow-card fc-in">
            <span class="fc-step">1. Skąd przyszło zapytanie</span>
            <div class="fc-main mono">{$clientIp} ({$clientType})</div>
            <div class="fc-meta">Actor: <strong>{$authActor}</strong><br>Referer: {$referer}<br>Origin: {$origin}</div>
        </div>
        <div class="flow-card fc-why">
            <span class="fc-step">2. Po co przyszło (Intencja)</span>
            <div class="fc-main mono">{$intentCode}</div>
            <div class="fc-meta">{$intentDesc}</div>
        </div>
        <div class="flow-card fc-route">
            <span class="fc-step">3. Którędy przeszło (Trasa)</span>
            <div class="fc-main mono">{$matchedHandler}</div>
            <div class="fc-meta">Pattern: <code class="mono">{$matchedPattern}</code><br>Traversed Files: <strong>{$this->esc((string) \count($traversedList))}</strong></div>
        </div>
        <div class="flow-card fc-out">
            <span class="fc-step">4. Odpowiedź: Dlaczego i Skąd</span>
            <div class="fc-main">HTTP {$status} ({$contentType}, {$bodyBytes} B)</div>
            <div class="fc-meta"><strong>Dlaczego:</strong> {$whyText}<br><strong>Skąd:</strong> <code class="mono">{$originWhere}</code></div>
        </div>
    </section>

    <div class="cols-2">
        <div class="card">
            <div class="card-hd"><span>Wejście: Pochodzenie, Klient i Nagłówki HTTP</span><span>Sanitized</span></div>
            <table class="kv-table">
                <tr><td>Client IP / Proxy</td><td class="mono">{$clientIp}</td></tr>
                <tr><td>User-Agent</td><td class="mono">{$userAgent}</td></tr>
                <tr><td>Referer / Origin</td><td class="mono">{$referer} | {$origin}</td></tr>
                <tr><td>Po co (Cel)</td><td>{$intentDesc}</td></tr>
            </table>
            <div class="card-hd"><span>Nagłówki Przychodzące (Headers)</span></div>
            <pre class="pre-box">{$inHeadersJson}</pre>
            <div class="card-hd"><span>Parametry (Route, Query GET, POST/JSON, Cookies, Files)</span></div>
            <pre class="pre-box">{$inParamsJson}</pre>
            <div class="card-hd"><span>Surowe Body Wejściowe (Podgląd)</span></div>
            <pre class="pre-box">{$inRawPreview}</pre>
        </div>

        <div class="card">
            <div class="card-hd"><span>Wyjście: Jaka odpowiedź poszła, Dlaczego i Skąd</span><span>HTTP {$status}</span></div>
            <table class="kv-table">
                <tr><td>Status HTTP</td><td class="mono"><strong>{$status} {$statusPhrase}</strong></td></tr>
                <tr><td>Dlaczego (Powód)</td><td>{$whyText}</td></tr>
                <tr><td>Skąd (Plik i Linia)</td><td class="mono">{$originWhere}</td></tr>
                <tr><td>Content-Type / Rozmiar</td><td class="mono">{$contentType} ({$bodyBytes} bytes)</td></tr>
            </table>
            <div class="card-hd"><span>Historia Decyzji Statusu Odpowiedzi</span></div>
            <pre class="pre-box">{$outStatusTimelineJson}</pre>
            <div class="card-hd"><span>Nagłówki Wychodzące (Response Headers)</span></div>
            <pre class="pre-box">{$outHeadersJson}</pre>
            <div class="card-hd"><span>Podgląd Treści Odpowiedzi (Body Preview)</span></div>
            <pre class="pre-box">{$outBodyPreview}</pre>
        </div>
    </div>

    <div class="cols-2">
        <div class="card">
            <div class="card-hd"><span>Dokładna Trasa Plików Wykonywania (Traversed File Trail)</span><span>Kolejność Wywołań</span></div>
            <ul class="trail-list">{$traversedHtml}</ul>
            <div class="card-hd"><span>Etapy Potoku Wykonawczego (Pipeline Stages)</span></div>
            <div class="trail-list">{$stagesHtml}</div>
        </div>
        <div class="card">
            <div class="card-hd"><span>Pliki PHP Doładowane w Trakcie Zapytania</span></div>
            <div class="trail-list">{$includedHtml}</div>
            <div class="card-hd"><span>Pełny Zrzut JSON (Dla Agenta / Narzędzi)</span></div>
            <pre class="pre-box">{$fullTraceJson}</pre>
        </div>
    </div>
</div>
</body>
</html>
HTML;
    }

    private function prettyJson(mixed $data): string
    {
        $raw = \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        return $this->esc(\is_string($raw) ? $raw : '{}');
    }

    private function esc(string $text): string
    {
        return \htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
