<?php

declare(strict_types=1);

namespace Atom\FileSytem;

/**
 * WebResourcesPath class
 *
 * This class is responsible for generating paths to resources and assets.
 *
 * It provides a way to access common paths used in web applications.
 *
 * @final
 */
final class WebResourcesPath
{
    private static string $pathSource;
    private static string $resources   = "/" . 'resources' . "/";
    private static string $assets      =
        "/" .
        'storage' .
        "/" .
        'assets' .
        "/";
    private static string $cssPath     = 'css' . "/";
    private static string $jsPath      = 'js' . "/";
    private static string $fontPath    = 'font' . "/";
    private static string $imagePath   = 'image' . "/";
    private static string $moviePath   = 'movie' . "/";
    private static string $soundPath   = 'sound' . "/";
    private static string $svgPath     = 'svg' . "/";

    private static string $resourcesCssDirPath;
    private static string $resourcesCssRootDirPath;
    private static string $resourcesJsDirPath;
    private static string $resourcesJsRootDirPath;

    private static string $assetsCssDirPath;
    private static string $assetsCssRootMainPath;
    private static string $assetsJsDirPath;
    private static string $assetsJsRootMainPath;

    private static string $webManifest = "manifest/manifest.json";

    /**
     * Construct a new WebResourcesPath object.
     *
     * @param string|null $path The path to the resources and assets.
     * @param array|null $configShring An array containing configuration options for shrinking the path.
     */
    public function __construct(private ?string $path = null, private ?array $configShring = null)
    {
        if ($this->path === null) {
            // use static path
            return;
        }

        self::$resources = $this->path . self::$resources;
        self::$assets = $this->path . self::$assets;

        self::$resourcesCssDirPath = self::$resources . self::$cssPath;
        self::$resourcesCssRootDirPath =
            $this->resourcesCssDirPath .
            $this->configShring["rootCssDirName"] .
            "/";
        self::$resourcesJsDirPath = self::$resources . self::$jsPath;
        self::$resourcesJsRootDirPath =
            $this->resourcesJsDirPath .
            $this->configShring["rootJsDirName"] .
            "/";

        self::$assetsCssDirPath = self::$assets . self::$cssPath;
        self::$assetsCssRootMainPath =
            $this->assetsCssDirPath .
            $this->configShring["rootCssDirName"] .
            "/";
        self::$assetsJsDirPath = self::$assets . self::$jsPath;
        self::$assetsJsRootMainPath =
            self::$assetsJsDirPath .
            $this->configShring["rootJsDirName"] .
            "/";

        self::$webManifest = self::$assets . self::$webManifest;

        if ($this->configShring["on"]) {
            if ($this->configShring["singleFile"]) {
                self::$cssPath  = self::$assetsCssRootMainPath . $this->configShring["cssSingleFileName"] . ".min.css";
                self::$jsPath   = self::$assetsJsRootMainPath . $this->configShring["jsSingleFileName"] . ".min.js";
            } else {
                self::$cssPath  = self::$assetsCssDirPath;
                self::$jsPath   = self::$assetsJsDirPath;
            }

            self::$fontPath    = self::$assets . self::$fontPath;
            self::$imagePath   = self::$assets . self::$imagePath;
            self::$moviePath   = self::$assets . self::$moviePath;
            self::$soundPath   = self::$assets . self::$soundPath;
            self::$svgPath     = self::$assets . self::$svgPath;

            self::$pathSource  = self::$assets;

            return;
        }

        self::$pathSource  = self::$resources;

        self::$cssPath     = self::$resourcesCssDirPath;
        self::$jsPath      = self::$resourcesJsDirPath;
        self::$fontPath    = self::$resources . self::$fontPath;
        self::$imagePath   = self::$resources . self::$imagePath;
        self::$moviePath   = self::$resources . self::$moviePath;
        self::$soundPath   = self::$resources . self::$soundPath;
        self::$svgPath     = self::$resources . self::$svgPath;
    }

    /**
     * Returns an associative array with all paths.
     *
     * This method returns an associative array containing all the paths
     * defined in this class.
     *
     * @return array An associative array with all paths.
     */
    public function all(): array
    {
        return [
            'resources'               => self::$resources,
            'resourcesCssDirPath'     => self::$resourcesCssDirPath,
            'resourcesCssRootDirPath' => self::$resourcesCssRootDirPath,
            'resourcesJsDirPath'      => self::$resourcesJsDirPath,
            'resourcesJsRootDirPath'  => self::$resourcesJsRootDirPath,

            'assets'                => self::$assets,
            'assetsCssDirPath'      => self::$assetsCssDirPath,
            'assetsCssRootMainPath' => self::$assetsCssRootMainPath,
            'assetsJsDirPath'       => self::$assetsJsDirPath,
            'assetsJsRootMainPath'  => self::$assetsJsRootMainPath,

            'css'   => self::$cssPath,
            'js'    => self::$jsPath,
            'font'  => self::$fontPath,
            'image' => self::$imagePath,
            'movie' => self::$moviePath,
            'sound' => self::$soundPath,
            'svg'   => self::$svgPath,

            'path'  => self::$pathSource,

            'webManifest' => self::$webManifest
        ];
    }

    /**
     * Returns a string representation of the object.
     *
     * This method returns a string representation of the object.
     *
     * @return string The string representation of the object.
     */
    public function __toString(): string
    {
        return self::$pathSource;
    }

    /**
     * Returns a string representation of the object.
     *
     * This method returns a string representation of the object.
     *
     * @param string $pathName The name of the path to return.
     * @return string The string representation of the object.
     */
    public function __get($pathName): string
    {
        return match ($pathName) {
            "path"      => self::$pathSource,
            "assets"    => self::$assets,
            "resources" => self::$resources,
            "css"       => self::$cssPath,
            "js"        => self::$jsPath,
            'font'      => self::$fontPath,
            'image'     => self::$imagePath,
            'movie'     => self::$moviePath,
            'sound'     => self::$soundPath,
            'svg'       => self::$svgPath,


            'resourcesCssDirPath'     => self::$resourcesCssDirPath,
            'resourcesCssRootDirPath' => self::$resourcesCssRootDirPath,
            'resourcesJsDirPath'      => self::$resourcesJsDirPath,
            'resourcesJsRootDirPath'  => self::$resourcesJsRootDirPath,

            'assetsCssDirPath'      => self::$assetsCssDirPath,
            'assetsCssRootMainPath' => self::$assetsCssRootMainPath,
            'assetsJsDirPath'       => self::$assetsJsDirPath,
            'assetsJsRootMainPath'  => self::$assetsJsRootMainPath,

            'webManifest' => self::$webManifest,
            default     => self::all()
        };
    }
}
