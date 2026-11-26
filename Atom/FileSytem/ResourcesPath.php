<?php

declare(strict_types=1);

namespace Atom\FileSytem;

use Stringable;

/**
 * Generates filesystem paths to resources and compiled assets with instance isolation.
 */
final class ResourcesPath implements Stringable
{
    private static string $pathSource = '';
    private static string $resources = \DIRECTORY_SEPARATOR . 'resources' . \DIRECTORY_SEPARATOR;
    private static string $assets = \DIRECTORY_SEPARATOR . 'storage' . \DIRECTORY_SEPARATOR . 'assets' . \DIRECTORY_SEPARATOR;
    private static string $cssPath = 'css' . \DIRECTORY_SEPARATOR;
    private static string $jsPath = 'js' . \DIRECTORY_SEPARATOR;
    private static string $fontPath = 'font' . \DIRECTORY_SEPARATOR;
    private static string $imagePath = 'image' . \DIRECTORY_SEPARATOR;
    private static string $moviePath = 'movie' . \DIRECTORY_SEPARATOR;
    private static string $soundPath = 'sound' . \DIRECTORY_SEPARATOR;
    private static string $svgPath = 'svg' . \DIRECTORY_SEPARATOR;

    private static string $resourcesCssDirPath = '';
    private static string $resourcesCssRootDirPath = '';
    private static string $resourcesJsDirPath = '';
    private static string $resourcesJsRootDirPath = '';

    private static string $assetsCssDirPath = '';
    private static string $assetsCssRootMainPath = '';
    private static string $assetsJsDirPath = '';
    private static string $assetsJsRootMainPath = '';
    private static string $webManifest = 'manifest' . \DIRECTORY_SEPARATOR . 'manifest.json';

    /** @var array<string, string> */
    private array $resolved = [];

    /**
     * @param array<string, mixed>|null $configShring
     */
    public function __construct(private readonly ?string $path = null, private readonly ?array $configShring = null)
    {
        if ($this->path === null) {
            return;
        }

        $cfg = $this->configShring ?? [];
        $ds = \DIRECTORY_SEPARATOR;
        $rootCssDir = (string) ($cfg['rootCssDirName'] ?? $cfg['rootCssDir'] ?? '');
        $rootJsDir = (string) ($cfg['rootJsDirName'] ?? $cfg['rootDir'] ?? '');

        self::$resources = $this->path . self::$resources;
        self::$assets = $this->path . self::$assets;

        self::$resourcesCssDirPath = self::$resources . self::$cssPath;
        self::$resourcesCssRootDirPath = self::$resourcesCssDirPath . $rootCssDir . $ds;
        self::$resourcesJsDirPath = self::$resources . self::$jsPath;
        self::$resourcesJsRootDirPath = self::$resourcesJsDirPath . $rootJsDir . $ds;

        self::$assetsCssDirPath = self::$assets . self::$cssPath;
        self::$assetsCssRootMainPath = self::$assetsCssDirPath . $rootCssDir . $ds;
        self::$assetsJsDirPath = self::$assets . self::$jsPath;
        self::$assetsJsRootMainPath = self::$assetsJsDirPath . $rootJsDir . $ds;

        self::$webManifest = self::$assets . self::$webManifest;

        if (!empty($cfg['on'])) {
            if (!empty($cfg['singleFile'])) {
                self::$cssPath = self::$assetsCssRootMainPath . (string) ($cfg['cssSingleFileName'] ?? 'app') . '.min.css';
                self::$jsPath = self::$assetsJsRootMainPath . (string) ($cfg['jsSingleFileName'] ?? 'app') . '.min.js';
            } else {
                self::$cssPath = self::$assetsCssDirPath;
                self::$jsPath = self::$assetsJsDirPath;
            }

            self::$fontPath = self::$assets . self::$fontPath;
            self::$imagePath = self::$assets . self::$imagePath;
            self::$moviePath = self::$assets . self::$moviePath;
            self::$soundPath = self::$assets . self::$soundPath;
            self::$svgPath = self::$assets . self::$svgPath;
            self::$pathSource = self::$assets;
        } else {
            self::$pathSource = self::$resources;
            self::$cssPath = self::$resourcesCssDirPath;
            self::$jsPath = self::$resourcesJsDirPath;
            self::$fontPath = self::$resources . self::$fontPath;
            self::$imagePath = self::$resources . self::$imagePath;
            self::$moviePath = self::$resources . self::$moviePath;
            self::$soundPath = self::$resources . self::$soundPath;
            self::$svgPath = self::$resources . self::$svgPath;
        }

        $this->resolved = self::snapshotStatic();
    }

    /**
     * @return array<string, string>
     */
    private static function snapshotStatic(): array
    {
        return [
            'resources' => self::$resources,
            'resourcesCssDirPath' => self::$resourcesCssDirPath,
            'resourcesCssRootDirPath' => self::$resourcesCssRootDirPath,
            'resourcesJsDirPath' => self::$resourcesJsDirPath,
            'resourcesJsRootDirPath' => self::$resourcesJsRootDirPath,
            'assets' => self::$assets,
            'assetsCssDirPath' => self::$assetsCssDirPath,
            'assetsCssRootMainPath' => self::$assetsCssRootMainPath,
            'assetsJsDirPath' => self::$assetsJsDirPath,
            'assetsJsRootMainPath' => self::$assetsJsRootMainPath,
            'css' => self::$cssPath,
            'js' => self::$jsPath,
            'font' => self::$fontPath,
            'image' => self::$imagePath,
            'movie' => self::$moviePath,
            'sound' => self::$soundPath,
            'svg' => self::$svgPath,
            'path' => self::$pathSource,
            'webManifest' => self::$webManifest,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->resolved !== [] ? $this->resolved : self::snapshotStatic();
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->resolved['path'] ?? self::$pathSource;
    }

    public function __get(string $pathName): string
    {
        $map = $this->all();
        if (\array_key_exists($pathName, $map)) {
            return $map[$pathName];
        }

        throw new \TypeError(\sprintf('Unknown ResourcesPath property "%s".', $pathName));
    }
}
