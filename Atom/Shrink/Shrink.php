<?php

declare(strict_types=1);

namespace Atom\Shrink;

use Atom\Exception\IO\FileNotFoundException;
use Atom\Log\T4LOG;
use FilesystemIterator;
use MatthiasMullie\Minify;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Stateless CSS & JS asset minifier without shared static minifier state (Swoole-safe)
 * and SMB/Samba-safe stream I/O.
 */
final class Shrink
{
    /** @var list<string> */
    private array $cssPaths = [];

    /** @var list<string> */
    private array $jsPaths = [];

    /**
     * @param array<string, mixed> $option
     */
    public function __construct(
        private readonly T4LOG $logger,
        private readonly array $option,
    ) {}

    public function addCss(string $path): void
    {
        if (!\is_file($path)) {
            $this->logger->error(
                \sprintf('File css not found: %s', $path),
                ['path' => $path, 'type' => 'FileNotFoundException'],
            );
            throw new FileNotFoundException();
        }

        $this->cssPaths[] = $path;
    }

    public function css(string $path): string
    {
        if (!\is_file($path)) {
            $this->logger->error(
                \sprintf('File css single not found: %s', $path),
                ['path' => $path, 'type' => 'FileNotFoundException'],
            );
            throw new FileNotFoundException();
        }

        $css = new Minify\CSS();
        $css->add($this->readAsset($path));
        return $css->minify();
    }

    public function cssWithSave(string $path, string $targetPath): string
    {
        if (!\is_file($path)) {
            $this->logger->error(
                \sprintf('File css single with save not found: %s', $path),
                ['path' => $path, 'type' => 'FileNotFoundException'],
            );
            throw new FileNotFoundException();
        }

        if (!\file_exists($targetPath)) {
            $this->logger->error(
                \sprintf('File css single with save not found for save: %s', $targetPath),
                ['path' => $targetPath, 'type' => 'FileNotFoundException'],
            );
            throw new FileNotFoundException();
        }

        $css = new Minify\CSS();
        $css->add($this->readAsset($path));
        $minified = $css->minify();
        $this->writeAsset($targetPath, $minified);

        return $minified;
    }

    public function autoScanCssDir(string $dirPath): void
    {
        if (!\file_exists($dirPath)) {
            $this->logger->error(
                \sprintf('File css dir not found: %s', $dirPath),
                ['path' => $dirPath, 'type' => 'FileNotFoundException'],
            );
            throw new FileNotFoundException();
        }

        $scanDir = !empty($this->option['onlyRootDir'])
            ? $dirPath . (string) ($this->option['rootCssDir'] ?? $this->option['rootCssDirName'] ?? '')
            : $dirPath;

        $this->scanByExtension($scanDir, 'css', $this->cssPaths);
    }

    public function addJs(string $path): void
    {
        if (!\is_file($path)) {
            $this->logger->error(
                \sprintf('File js not found: %s', $path),
                ['path' => $path, 'type' => 'FileNotFoundException'],
            );
            throw new FileNotFoundException();
        }

        $this->jsPaths[] = $path;
    }

    public function js(string $path): string
    {
        if (!\is_file($path)) {
            $this->logger->error(
                \sprintf('File js single not found: %s', $path),
                ['path' => $path, 'type' => 'FileNotFoundException'],
            );
            throw new FileNotFoundException();
        }

        $js = new Minify\JS();
        $js->add($this->readAsset($path));
        return $js->minify();
    }

    public function jsWithSave(string $path, string $targetPath): string
    {
        if (!\is_file($path)) {
            $this->logger->error(
                \sprintf('File js single with save not found: %s', $path),
                ['path' => $path, 'type' => 'FileNotFoundException'],
            );
            throw new FileNotFoundException();
        }

        if (!\file_exists($targetPath)) {
            $this->logger->error(
                \sprintf('File js single with save not found for save: %s', $targetPath),
                ['path' => $targetPath, 'type' => 'FileNotFoundException'],
            );
            throw new FileNotFoundException();
        }

        $js = new Minify\JS();
        $js->add($this->readAsset($path));
        $minified = $js->minify();
        $this->writeAsset($targetPath, $minified);

        return $minified;
    }

    public function autoScanJsDir(string $dirPath): void
    {
        if (!\file_exists($dirPath)) {
            $this->logger->error(
                \sprintf('File js dir not found: %s', $dirPath),
                ['path' => $dirPath, 'type' => 'FileNotFoundException'],
            );
            throw new FileNotFoundException();
        }

        $scanDir = !empty($this->option['onlyRootDir'])
            ? $dirPath . (string) ($this->option['rootDir'] ?? $this->option['rootJsDirName'] ?? '')
            : $dirPath;

        $this->scanByExtension($scanDir, 'js', $this->jsPaths);
    }

    /**
     * @param list<string> $collector
     */
    private function scanByExtension(string $scanDir, string $extension, array &$collector): void
    {
        $directory = new RecursiveDirectoryIterator($scanDir, FilesystemIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($directory);

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile() && \strtolower($file->getExtension()) === $extension) {
                $collector[] = \str_replace(['\\', '/'], \DIRECTORY_SEPARATOR, $file->getPathname());
            }
        }
    }

    public function save(): void
    {
        $assetsCssDir = (string) ($this->option['assetsCssDirPath'] ?? '');
        $assetsJsDir = (string) ($this->option['assetsJsDirPath'] ?? '');

        if ($assetsCssDir === '' || !\file_exists($assetsCssDir)) {
            $this->logger->error(
                \sprintf('Directory not found: %s', $assetsCssDir),
                ['path' => $assetsCssDir, 'type' => 'FileNotFoundException'],
            );
            throw new FileNotFoundException();
        }

        if ($assetsJsDir === '' || !\file_exists($assetsJsDir)) {
            $this->logger->error(
                \sprintf('Directory not found: %s', $assetsJsDir),
                ['path' => $assetsJsDir, 'type' => 'FileNotFoundException'],
            );
            throw new FileNotFoundException();
        }

        if (!empty($this->option['singleFile'])) {
            $cssMinifier = new Minify\CSS();
            foreach ($this->cssPaths as $path) {
                $cssMinifier->add($this->readAsset($path));
            }

            $cssOut = (string) $this->option['assetsCssRootMainPath'] . (string) $this->option['cssSingleFileName'] . '.min.css';
            $this->writeAsset($cssOut, $cssMinifier->minify());

            $jsMinifier = new Minify\JS();
            foreach ($this->jsPaths as $path) {
                $jsMinifier->add($this->readAsset($path));
            }

            $jsOut = (string) $this->option['assetsJsRootMainPath'] . (string) $this->option['jsSingleFileName'] . '.min.js';
            $this->writeAsset($jsOut, $jsMinifier->minify());

            $this->logger->info('Save single shrink complete');
            return;
        }

        foreach ($this->cssPaths as $path) {
            $css = new Minify\CSS();
            $css->add($this->readAsset($path));

            $outPath = \str_replace(
                [(string) $this->option['resourcesCssDirPath'], '.css'],
                [$assetsCssDir, ''],
                $path,
            ) . '.min.css';

            $this->writeAsset($outPath, $css->minify());
        }

        foreach ($this->jsPaths as $path) {
            $js = new Minify\JS();
            $js->add($this->readAsset($path));

            $outPath = \str_replace(
                [(string) $this->option['resourcesJsDirPath'], '.js'],
                [$assetsJsDir, ''],
                $path,
            ) . '.min.js';

            $this->writeAsset($outPath, $js->minify());
        }

        $this->logger->info('Save shrink complete');
    }

    private function readAsset(string $path): string
    {
        \set_error_handler(static fn(): bool => true);
        try {
            $content = \file_get_contents($path);
        } finally {
            \restore_error_handler();
        }

        if ($content === false) {
            throw new FileNotFoundException();
        }

        return $content;
    }

    private function writeAsset(string $targetPath, string $content): void
    {
        $dir = \dirname($targetPath);
        if (!\is_dir($dir) && !\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            throw new FileNotFoundException();
        }

        if (\file_put_contents($targetPath, $content) === false) {
            throw new FileNotFoundException();
        }
    }

    public function __destruct()
    {
        $this->cssPaths = [];
        $this->jsPaths = [];
    }
}
