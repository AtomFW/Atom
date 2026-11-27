<?php

declare(strict_types=1);

namespace Atom;

use Atom\Support\DataRegistry;
use Atom\Support\TemplateInterpolator;
use InvalidArgumentException;
use RuntimeException;

class View
{
    public string $title = '';

    private DataRegistry $viewData;
    private bool $autoInterpolate = true;
    private bool $escapeInterpolatedHtml = true;

    public function __construct(?DataRegistry $viewData = null, bool $autoInterpolate = true, bool $escapeInterpolatedHtml = true)
    {
        $this->viewData = $viewData ?? new DataRegistry();
        $this->autoInterpolate = $autoInterpolate;
        $this->escapeInterpolatedHtml = $escapeInterpolatedHtml;
    }

    /**
     * Returns the view-scoped dot-notation `DataRegistry`.
     */
    public function data(): DataRegistry
    {
        return $this->viewData;
    }

    /**
     * Assigns one dot-path (`'jakaśAplikacjia.core.version'`, `'1.0.0'`) or an array of dot-paths
     * so `{{jakaśAplikacjia.core.version}}` is automatically replaced in HTML templates.
     *
     * @param string|array<string, mixed> $keyOrMap
     */
    public function assign(string|array $keyOrMap, mixed $value = null): self
    {
        if (\is_array($keyOrMap)) {
            $this->viewData->merge($keyOrMap);
            return $this;
        }

        $this->viewData->set($keyOrMap, $value);
        return $this;
    }

    /**
     * Interpolates `{{dot.key}}` and `{!! dot.key !!}` placeholders inside an arbitrary HTML fragment.
     *
     * @param array<string, mixed> $params
     */
    public function interpolateHtml(string $html, array $params = [], ?bool $escapeHtml = null): string
    {
        $appRegistry = isset(Atom::$app) && isset(Atom::$app->data) ? Atom::$app->data : null;

        return TemplateInterpolator::interpolate(
            html: $html,
            viewParams: $params,
            primaryRegistry: $this->viewData,
            fallbackRegistry: $appRegistry,
            escapeByDefault: $escapeHtml ?? $this->escapeInterpolatedHtml,
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    public function renderView(string $view, array $params = []): string
    {
        $controller = Atom::$app->controller;
        $layoutName = $controller !== null ? $controller->layout : Atom::$app->layout;
        $safeLayout = $this->sanitizeViewName($layoutName);
        $viewContent = $this->renderViewOnly($view, $params);

        $layoutFile = \rtrim(Atom::$ROOT_DIR, '/\\') . '/resources/Views/Layouts/' . $safeLayout . '.php';
        $probe = isset(Atom::$app) ? Atom::$app->probe : null;
        $layoutSpan = '';

        if ($probe !== null) {
            $probe->recordTraversedFile($layoutFile, 'layout', 1);
            $layoutSpan = $probe->startSpan('view.layout:' . $safeLayout, 'view', $layoutFile, 1);
        }

        try {
            $layoutContent = $this->includeIsolated($layoutFile, []);
        } finally {
            if ($probe !== null && $layoutSpan !== '') {
                $probe->endSpan($layoutSpan);
            }
        }

        $assembled = \str_replace('{{content}}', $viewContent, $layoutContent);

        return $this->shouldInterpolate()
            ? $this->interpolateHtml($assembled, $params)
            : $assembled;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function renderViewOnly(string $view, array $params = []): string
    {
        $safeView = $this->sanitizeViewName($view);
        $viewFile = \rtrim(Atom::$ROOT_DIR, '/\\') . '/resources/Views/' . $safeView . '.php';
        $probe = isset(Atom::$app) ? Atom::$app->probe : null;
        $viewSpan = '';

        if ($probe !== null) {
            $probe->recordTraversedFile($viewFile, 'view', 1);
            $viewSpan = $probe->startSpan('view.template:' . $safeView, 'view', $viewFile, 1);
        }

        try {
            $rendered = $this->includeIsolated($viewFile, $params);
        } finally {
            if ($probe !== null && $viewSpan !== '') {
                $probe->endSpan($viewSpan);
            }
        }

        return $this->shouldInterpolate()
            ? $this->interpolateHtml($rendered, $params)
            : $rendered;
    }

    private function shouldInterpolate(): bool
    {
        if (!$this->autoInterpolate) {
            return false;
        }

        if (isset(Atom::$app) && isset(Atom::$app->config) && Config\Config::isLoaded('app')) {
            $appCfg = Atom::$app->config->get('app');
            if (\is_array($appCfg) && isset($appCfg['templateData']['on'])) {
                return (bool) $appCfg['templateData']['on'];
            }
        }

        return true;
    }

    /**
     * Prevents directory traversal (`..`, null bytes) in view/layout names.
     */
    private function sanitizeViewName(string $name): string
    {
        $normalized = \str_replace('\\', '/', \trim($name));
        $normalized = \trim($normalized, '/');

        if ($normalized === '' || \str_contains($normalized, '..') || \str_contains($normalized, "\0")) {
            throw new InvalidArgumentException("Invalid view name: {$name}");
        }

        return $normalized;
    }

    /**
     * Renders a PHP view file inside an isolated scope without `extract()` and guarantees buffer cleanup via `try/finally`.
     * Notice `include` (not `include_once`) is used so Swoole workers can render the same view across multiple requests.
     *
     * @param array<string, mixed> $__params
     */
    private function includeIsolated(string $__filePath, array $__params): string
    {
        if (!\is_file($__filePath)) {
            throw new RuntimeException("View file not found: {$__filePath}");
        }

        $renderer = (function (string $__viewFile, array $__vars): string {
            foreach ($__vars as $__key => $__val) {
                if (\is_string($__key) && \preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $__key) === 1) {
                    $$__key = $__val;
                }
            }

            \ob_start();
            try {
                include $__viewFile;
                $content = \ob_get_contents();
            } finally {
                \ob_end_clean();
            }

            return $content === false ? '' : $content;
        })->bindTo($this, static::class);

        return $renderer($__filePath, $__params);
    }
}
