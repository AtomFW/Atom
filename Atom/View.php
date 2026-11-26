<?php

declare(strict_types=1);

namespace Atom;

use InvalidArgumentException;
use RuntimeException;

class View
{
    public string $title = '';

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
        $layoutContent = $this->includeIsolated($layoutFile, []);

        return \str_replace('{{content}}', $viewContent, $layoutContent);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function renderViewOnly(string $view, array $params = []): string
    {
        $safeView = $this->sanitizeViewName($view);
        $viewFile = \rtrim(Atom::$ROOT_DIR, '/\\') . '/resources/Views/' . $safeView . '.php';

        return $this->includeIsolated($viewFile, $params);
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
