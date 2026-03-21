<?php

declare(strict_types=1);

namespace Atom\Shrink;

use Atom\Log\T4LOG;
use MatthiasMullie\Minify;
use MatthiasMullie\Minify\CSS;
use MatthiasMullie\Minify\JS;
use Atom\Exception\IO\FileNotFoundException;

/**
 * Shrink class
 *
 * This class is responsible for shrinking CSS and JS files.
 *
 * It provides methods to add CSS and JS files, and to shrink them.
 *
 * @final
 */
final class Shrink
{
    private static CSS $CSS;
    private static JS $JS;

    private array $cssPaths = [];
    private array $jsPaths = [];

    /**
     * Shrink constructor.
     *
     * @param T4LOG $logger logger instance
     * @param array $option options for shrink
     */
    public function __construct(private T4LOG $logger, private array $option)
    {
        static::$CSS = new Minify\CSS();
        static::$JS = new Minify\JS();
    }

    /**
     * Adds a CSS file to be shrunk.
     *
     * @param string $path The path to the CSS file.
     *
     * @throws FileNotFoundException If the file does not exist.
     */
    public function addCss(string $path): void
    {
        if (!\file_exists(($path))) {
            $this->logger->error(
                \sprintf("File css not found: %s", $path),
                ['path' => $path, 'type' => 'FileNotFoundException']
            );
            throw new FileNotFoundException();
        }

        $this->cssPaths[] = $path;
    }

    /**
     * Returns the minified CSS content from the given file path.
     *
     * @param string $path The path to the CSS file.
     *
     * @return string The minified CSS content.
     *
     * @throws FileNotFoundException If the file does not exist.
     */
    public function css(string $path): string
    {
        if (!\file_exists(($path))) {
            $this->logger->error(
                \sprintf("File css single not found: %s", $path),
                ['path' => $path, 'type' => 'FileNotFoundException']
            );
            throw new FileNotFoundException();
        }

        $css = new Minify\CSS();
        $css->addFile($path);
        return $css->minify();
    }

    /**
     * Returns the minified CSS content from the given file path and saves it to a target path.
     *
     * @param string $path The path to the CSS file.
     * @param string $targetPath The path where the minified CSS content will be saved.
     *
     * @return string The minified CSS content.
     *
     * @throws FileNotFoundException If the file does not exist.
     */
    public function cssWithSave(string $path, string $targetPath): string
    {
        if (!\file_exists(($path))) {
            $this->logger->error(
                \sprintf("File css single with save not found: %s", $path),
                ['path' => $path, 'type' => 'FileNotFoundException']
            );
            throw new FileNotFoundException();
        }

        if (!\file_exists($targetPath)) {
            $this->logger->error(
                \sprintf("File css single with save not found for save: %s", $targetPath),
                ['path' => $targetPath, 'type' => 'FileNotFoundException']
            );
            throw new FileNotFoundException();
        }

        $css = new Minify\CSS();
        $css->addFile($path);
        return $css->minify($targetPath);
    }

    /**
     * Automatically scans a directory for CSS files and adds them to be shrunk.
     *
     * @param string $dirPath The path to the directory to be scanned.
     *
     * @throws FileNotFoundException If the directory does not exist.
     */
    public function autoScanCssDir(string $dirPath): void
    {
        if (!\file_exists($dirPath)) {
            $this->logger->error(
                \sprintf("File css dir not found: %s", $dirPath),
                ['path' => $dirPath, 'type' => 'FileNotFoundException']
            );
            throw new FileNotFoundException();
        }

        if ($this->option['onlyRootDir']) {
            $directory = new \RecursiveDirectoryIterator($dirPath . $this->option['rootCssDir']);
            $iterator = new \RecursiveIteratorIterator($directory);
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'css') {
                    $this->cssPaths[] = \str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $file->getPathname());
                }
            }
            return;
        }

        $directory = new \RecursiveDirectoryIterator($dirPath);
        $iterator = new \RecursiveIteratorIterator($directory);
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'css') {
                $this->cssPaths[] = \str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $file->getPathname());
            }
        }
    }

    /**
     * Adds a JavaScript file to be shrunk.
     *
     * @param string $path The path to the JavaScript file.
     *
     * @throws FileNotFoundException If the file does not exist.
     */
    public function addJs(string $path): void
    {
        if (!\file_exists(($path))) {
            $this->logger->error(
                \sprintf("File js not found: %s", $path),
                ['path' => $path, 'type' => 'FileNotFoundException']
            );
            throw new FileNotFoundException();
        }

        $this->jsPaths[] = $path;
    }

    /**
     * Shrink a JavaScript file.
     *
     * This method shrinks a given JavaScript file.
     *
     * @param string $path The path to the JavaScript file.
     *
     * @return string The shrunk JavaScript code.
     *
     * @throws FileNotFoundException If the file does not exist.
     */
    public function js(string $path): string
    {
        if (!\file_exists(($path))) {
            $this->logger->error(
                \sprintf("File js single not found: %s", $path),
                ['path' => $path, 'type' => 'FileNotFoundException']
            );
            throw new FileNotFoundException();
        }

        $js = new Minify\JS();
        $js->addFile($path);
        return $js->minify();
    }

    /**
     * Shrink a JavaScript file with save.
     *
     * This method shrinks a given JavaScript file and saves the result to a target path.
     *
     * @param string $path The path to the JavaScript file.
     * @param string $targetPath The path where the shrunk JavaScript content will be saved.
     *
     * @return string The shrunk JavaScript code.
     *
     * @throws FileNotFoundException If the file does not exist.
     */
    public function jsWithSave(string $path, string $targetPath): string
    {
        if (!\file_exists(($path))) {
            $this->logger->error(
                \sprintf("File js single with save not found: %s", $path),
                ['path' => $path, 'type' => 'FileNotFoundException']
            );
            throw new FileNotFoundException();
        }

        if (!\file_exists($targetPath)) {
            $this->logger->error(
                \sprintf("File js single with save not found for save: %s", $targetPath),
                ['path' => $targetPath, 'type' => 'FileNotFoundException']
            );
            throw new FileNotFoundException();
        }

        $js = new Minify\JS();
        $js->addFile($path);
        return $js->minify($targetPath);
    }

    /**
     * Automatically scans a directory for JavaScript files and adds them to be shrunk.
     *
     * This method scans a given directory for JavaScript files and adds them to be shrunk.
     *
     * @param string $dirPath The path to the directory to be scanned.
     *
     * @throws FileNotFoundException If the directory does not exist.
     */
    public function autoScanJsDir(string $dirPath): void
    {
        if (!\file_exists($dirPath)) {
            $this->logger->error(
                \sprintf("File js dir not found: %s", $dirPath),
                ['path' => $dirPath, 'type' => 'FileNotFoundException']
            );
            throw new FileNotFoundException();
        }

        if ($this->option['onlyRootDir']) {
            $directory = new \RecursiveDirectoryIterator($dirPath . $this->option['rootDir']);
            $iterator = new \RecursiveIteratorIterator($directory);
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'js') {
                    $this->jsPaths[] = \str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $file->getPathname());
                }
            }
            return;
        }

        $directory = new \RecursiveDirectoryIterator($dirPath);
        $iterator = new \RecursiveIteratorIterator($directory);
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'js') {
                $this->jsPaths[] = \str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $file->getPathname());
            }
        }
    }

    /**
     * Saves the shrunk CSS and JavaScript files.
     *
     * This method saves the shrunk CSS and JavaScript files to the designated paths.
     */
    public function save(): void
    {
        if (!\file_exists($this->option['assetsCssDirPath'])) {
            $this->logger->error(
                \sprintf("Directory not found: %s", $this->option['assetsCssDirPath']),
                ['path' => $this->option['assetsCssDirPath'], 'type' => 'FileNotFoundException']
            );
            throw new FileNotFoundException();
        }

        if (!\file_exists($this->option['assetsJsDirPath'])) {
            $this->logger->error(
                \sprintf("Directory not found: %s", $this->option['assetsJsDirPath']),
                ['path' => $this->option['assetsJsDirPath'], 'type' => 'FileNotFoundException']
            );
            throw new FileNotFoundException();
        }

        if ($this->option['singleFile']) {
            foreach ($this->cssPaths as $path) {
                if (\is_file($path)) {
                    static::$CSS->add($path);
                    continue;
                }
                static::$CSS->addFile($path);
            }

            $path = $this->option['assetsCssRootMainPath'] . $this->option['cssSingleFileName'] . ".min.css";
            if (!\file_exists($path)) {
                \mkdir(\dirname($path), 0755, true);
            }

            static::$CSS->minify($path);

            foreach ($this->jsPaths as $path) {
                if (\is_file($path)) {
                    static::$JS->add($path);
                    continue;
                }
                static::$JS->addFile($path);
            }

            $path = $this->option['assetsJsRootMainPath'] . $this->option['jsSingleFileName'] . ".min.js";
            if (!\file_exists($path)) {
                \mkdir(\dirname($path), 0755, true);
            }
            static::$JS->minify($path);

            $this->logger->info('Save single shrink complete');
            return;
        }

        foreach ($this->cssPaths as $path) {
            $css = new Minify\CSS();
            $css->addFile($path);

            $path = \str_replace(
                [$this->option['resourcesCssDirPath'], ".css"],
                [$this->option['assetsCssDirPath'], ""],
                $path
            );
            $path .= ".min.css";

            if (!\file_exists($path)) {
                \mkdir(\dirname($path), 0755, true);
            }
            $css->minify($path);
            unset($css);
        }

        foreach ($this->jsPaths as $path) {
            $js = new Minify\JS();
            $js->addFile($path);

            $path = \str_replace(
                [$this->option['resourcesJsDirPath'], ".js"],
                [$this->option['assetsJsDirPath'], ""],
                $path
            );
            $path .= ".min.js";

            if (!\file_exists($path)) {
                \mkdir(\dirname($path), 0755, true);
            }
            $js->minify($path);
            unset($js);
        }

        $this->logger->info('Save shrink complete');
    }

    /**
     * Destructs the object and removes all the CSS and JavaScript paths from memory.
     *
     * This method is called when the object is destroyed. It will remove all the CSS and
     * JavaScript paths from memory.
     */
    public function __destruct()
    {
        unset($this->cssPaths);
        unset($this->jsPaths);
    }
}
