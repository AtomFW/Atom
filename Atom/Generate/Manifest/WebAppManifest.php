<?php
declare(strict_types=1);

namespace Atom\Generate\Manifest;

use InvalidArgumentException;
use RuntimeException;

/**
 * WebAppManifest
 *
 * Professional helper for building and managing a web app manifest.json (PWA).
 *
 * Usage:
 *   $m = new WebAppManifest([...]);
 *   $m->setName('My App')->addIcon(...)->saveToFile('manifest.json');
 */
final class WebAppManifest
{
    /** @var array<string,mixed> internal manifest data */
    private array $data = [];

    /** @var array<string,mixed> default manifest skeleton */
    private const DEFAULT = [
        'name' => null,
        'short_name' => null,
        'start_url' => '/',
        'scope' => '/',
        'display' => 'standalone',
        'orientation' => null,
        'background_color' => null,
        'theme_color' => null,
        'description' => null,
        'categories' => [],
        'lang' => null,
        'dir' => null,
        'icons' => [],
        'shortcuts' => [],
        'related_applications' => [],
        'prefer_related_applications' => false,
        'screenshots' => [],
        'share_target' => null,
    ];

    /**
     * Constructor accepts initial manifest array (keys as in spec).
     *
     * Unknown keys are preserved (useful for custom fields).
     *
     * @param array<string,mixed> $initial
     */
    public function __construct(array $initial = [])
    {
        // seed with defaults, then merge provided
        $this->data = self::DEFAULT;
        $this->merge($initial);
    }

    /**
     * Factory: create from array
     */
    public static function fromArray(array $arr): self
    {
        return new self($arr);
    }

    /**
     * Merge given values into manifest (shallow merge for top-level keys).
     * For arrays like icons/shortcuts you probably want to use addIcon/addShortcut.
     *
     * @param array<string,mixed> $values
     * @return $this
     */
    public function merge(array $values): static
    {
        foreach ($values as $k => $v) {
            if (is_array($v) && isset($this->data[$k]) && is_array($this->data[$k]) && in_array($k, ['icons','shortcuts','related_applications','categories','screenshots'], true)) {
                // merge arrays preserving existing elements
                $this->data[$k] = array_values(array_merge($this->data[$k], $v));
            } else {
                $this->data[$k] = $v;
            }
        }
        return $this;
    }

    // -------------------- Basic setters/getters --------------------

    public function setName(string $name): static { $this->data['name'] = $name; return $this; }
    public function getName(): ?string { return $this->data['name'] ?? null; }
    public function setShortName(string $short): static { $this->data['short_name'] = $short; return $this; }
    public function getShortName(): ?string { return $this->data['short_name'] ?? null; }
    public function setStartUrl(string $url): static { $this->data['start_url'] = $url; return $this; }
    public function getStartUrl(): string { return $this->data['start_url'] ?? '/'; }
    public function setScope(string $scope): static { $this->data['scope'] = $scope; return $this; }
    public function getScope(): ?string { return $this->data['scope'] ?? null; }
    public function setDisplay(string $display): static { $this->data['display'] = $display; return $this; }
    public function getDisplay(): ?string { return $this->data['display'] ?? null; }
    public function setThemeColor(string $color): static { $this->data['theme_color'] = $color; return $this; }
    public function getThemeColor(): ?string { return $this->data['theme_color'] ?? null; }
    public function setBackgroundColor(string $color): static { $this->data['background_color'] = $color; return $this; }
    public function getBackgroundColor(): ?string { return $this->data['background_color'] ?? null; }
    public function setDescription(string $description): static { $this->data['description'] = $description; return $this; }
    public function getDescription(): ?string { return $this->data['description'] ?? null; }
    public function setLang(string $lang): static { $this->data['lang'] = $lang; return $this; }
    public function getLang(): ?string { return $this->data['lang'] ?? null; }
    public function setDir(string $dir): static { $this->data['dir'] = $dir; return $this; }
    public function getDir(): ?string { return $this->data['dir'] ?? null; }

    // -------------------- Categories --------------------

    /**
     * Replace categories list
     *
     * @param string[] $categories
     */
    public function setCategories(array $categories): static
    {
        $this->data['categories'] = array_values(array_map('strval', $categories));
        return $this;
    }

    public function addCategory(string $category): static
    {
        if (!in_array($category, $this->data['categories'], true)) {
            $this->data['categories'][] = $category;
        }
        return $this;
    }

    public function getCategories(): array
    {
        return $this->data['categories'] ?? [];
    }

    // -------------------- Icons --------------------

    /**
     * Add icon entry.
     * Example:
     *   addIcon('/icons/icon-192x192.png', '192x192', 'image/png', ['purpose'=>'any'])
     *
     * @param string $src
     * @param string|null $sizes
     * @param string|null $type
     * @param array<string,string> $attrs additional fields (e.g. purpose)
     * @return $this
     */
    public function addIcon(string $src, ?string $sizes = null, ?string $type = null, array $attrs = []): static
    {
        $icon = array_filter([
            'src' => $src,
            'sizes' => $sizes,
            'type' => $type,
        ], fn($v) => $v !== null && $v !== '');

        // merge additional attributes
        foreach ($attrs as $k => $v) {
            if ($v === null) {
                continue;
            }
            $icon[$k] = $v;
        }

        // keep unique by src
        foreach ($this->data['icons'] as $existing) {
            if (isset($existing['src']) && $existing['src'] === $src) {
                // replace existing
                $existing = array_merge($existing, $icon);
                $this->data['icons'] = array_map(fn($i) => ($i['src'] === $src ? $existing : $i), $this->data['icons']);
                return $this;
            }
        }

        $this->data['icons'][] = $icon;
        return $this;
    }

    /**
     * Remove icon entries by src (exact match) or by predicate.
     *
     * @param string|callable|null $srcOrCallable exact src or predicate fn(array):bool
     */
    public function removeIcon(string|callable|null $srcOrCallable = null): static
    {
        if ($srcOrCallable === null) {
            $this->data['icons'] = [];
            return $this;
        }

        if (is_string($srcOrCallable)) {
            $this->data['icons'] = array_values(array_filter($this->data['icons'], fn($i) => !isset($i['src']) || $i['src'] !== $srcOrCallable));
            return $this;
        }

        $pred = $srcOrCallable;
        $this->data['icons'] = array_values(array_filter($this->data['icons'], fn($i) => !$pred($i)));
        return $this;
    }

    public function getIcons(): array
    {
        return $this->data['icons'] ?? [];
    }

    // -------------------- Shortcuts --------------------

    /**
     * Add shortcut definition (PWA shortcuts).
     *
     * Shortcut example:
     *  [
     *    'name' => 'Open search',
     *    'short_name' => 'Search',
     *    'url' => '/search',
     *    'icons' => [ [ 'src'=>..., 'sizes'=>..., 'type'=>... ] ]
     *  ]
     *
     * @param array<string,mixed> $shortcut
     */
    public function addShortcut(array $shortcut): static
    {
        $this->data['shortcuts'][] = $shortcut;
        return $this;
    }

    public function getShortcuts(): array
    {
        return $this->data['shortcuts'] ?? [];
    }

    // -------------------- Related applications --------------------

    /**
     * Add related application
     *
     * @param string $platform e.g. "play", "itunes"
     * @param string $id id or url
     * @param array<string,mixed> $attrs
     */
    public function addRelatedApplication(string $platform, string $id, array $attrs = []): static
    {
        $entry = array_merge(['platform' => $platform, 'id' => $id], $attrs);
        $this->data['related_applications'][] = $entry;
        return $this;
    }

    public function setPreferRelatedApplications(bool $flag): static
    {
        $this->data['prefer_related_applications'] = (bool)$flag;
        return $this;
    }

    public function getRelatedApplications(): array
    {
        return $this->data['related_applications'] ?? [];
    }

    // -------------------- Screenshots --------------------

    public function addScreenshot(string $src, ?string $sizes = null, ?string $type = null): static
    {
        $s = array_filter(['src' => $src, 'sizes' => $sizes, 'type' => $type], fn($v) => $v !== null && $v !== '');
        $this->data['screenshots'][] = $s;
        return $this;
    }

    public function getScreenshots(): array
    {
        return $this->data['screenshots'] ?? [];
    }

    // -------------------- Share target --------------------

    /**
     * Set share_target as per spec.
     *
     * Example:
     *  setShareTarget([
     *    'action' => '/share',
     *    'method' => 'GET',
     *    'params' => ['title'=>'title','text'=>'text','url'=>'url']
     *  ])
     *
     * @param array<string,mixed>|null $config
     */
    public function setShareTarget(?array $config): static
    {
        $this->data['share_target'] = $config;
        return $this;
    }

    public function getShareTarget(): ?array
    {
        return $this->data['share_target'] ?? null;
    }

    // -------------------- Low-level get / toArray / toJson --------------------

    /**
     * Return manifest as array (removes null values).
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        // deep filter null values for top-level entries only (spec allows custom fields)
        $out = [];
        foreach ($this->data as $k => $v) {
            if ($v === null) continue;
            if (is_array($v)) {
                // remove empty arrays
                if ($v === [] || $v === ['']) continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * Serialize manifest to JSON.
     *
     * @param bool $pretty
     * @return string
     */
    public function toJson(bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) $flags |= JSON_PRETTY_PRINT;
        $json = json_encode($this->toArray(), $flags);
        if ($json === false) {
            throw new RuntimeException('Failed to json_encode manifest: ' . json_last_error_msg());
        }
        return $json;
    }

    /**
     * Save manifest to file (atomically write to temporary file then rename).
     *
     * @param string $path
     * @param bool $pretty
     * @throws RuntimeException
     */
    public function saveToFile(string $path, bool $pretty = true): void
    {
        $json = $this->toJson($pretty);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new RuntimeException('Unable to create directory: ' . $dir);
            }
        }

        $tmp = tempnam($dir, 'manifest_');
        if ($tmp === false) {
            throw new RuntimeException('Unable to create temp file in: ' . $dir);
        }

        $written = file_put_contents($tmp, $json);
        if ($written === false) {
            @unlink($tmp);
            throw new RuntimeException('Unable to write manifest to temp file: ' . $tmp);
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to move temp manifest to destination: ' . $path);
        }
    }

    // -------------------- Validation --------------------

    /**
     * Validate manifest against minimal rules.
     * Returns array of errors (empty if valid).
     *
     * @return string[] errors
     */
    public function validate(): array
    {
        $errors = [];

        // name recommended
        if (empty($this->data['name']) && empty($this->data['short_name'])) {
            $errors[] = 'Either "name" or "short_name" should be provided.';
        }

        // start_url should be set
        if (empty($this->data['start_url'])) {
            $errors[] = '"start_url" is required.';
        }

        // icons: ensure each icon has src and valid sizes (optional)
        foreach ($this->data['icons'] as $i => $icon) {
            if (!is_array($icon) || !isset($icon['src'])) {
                $errors[] = "Icon at index {$i} must contain 'src'.";
                continue;
            }
            // sizes basic format: 'WxH' or 'any'
            if (isset($icon['sizes']) && $icon['sizes'] !== 'any') {
                if (!preg_match('/^\d+x\d+$/', (string)$icon['sizes'])) {
                    $errors[] = "Icon sizes for {$icon['src']} must be 'WxH' or 'any'.";
                }
            }
        }

        // display allowed values
        if (isset($this->data['display'])) {
            $allowed = ['fullscreen','standalone','minimal-ui','browser'];
            if (!in_array($this->data['display'], $allowed, true)) {
                $errors[] = '"display" must be one of: ' . implode(', ', $allowed) . '.';
            }
        }

        return $errors;
    }

    /**
     * Validate and throw on error.
     *
     * @throws InvalidArgumentException
     */
    public function validateOrFail(): void
    {
        $errors = $this->validate();
        if (!empty($errors)) {
            throw new InvalidArgumentException('Manifest validation failed: ' . implode('; ', $errors));
        }
    }

    // -------------------- Helpful utilities --------------------

    /**
     * Quick iterator-friendly representation (array)
     */
    public function jsonSerializable(): array
    {
        return $this->toArray();
    }

    /**
     * Convenience: set multiple common fields via fluent API
     *
     * @param array<string,mixed> $data
     * @return $this
     */
    public function setCommon(array $data): static
    {
        $allowed = ['name','short_name','start_url','scope','display','theme_color','background_color','description','lang','dir'];
        foreach ($data as $k => $v) {
            if (in_array($k, $allowed, true)) {
                $this->data[$k] = $v;
            } else {
                $this->data[$k] = $v; // keep other custom keys as well
            }
        }
        return $this;
    }

    /**
     * Best-effort icon generator using Imagick (optional).
     * If Imagick extension not loaded or generation failed -> throws RuntimeException.
     *
     * Function will generate resized images from a source file into destination directory and add icons entries.
     *
     * @param string $sourcePath absolute path to source image (should be square and large enough)
     * @param string $destDir destination directory (web-accessible) - will be created if missing
     * @param array<int,int[]> $sizes array of sizes to generate, e.g. [192, 512]
     * @param string $format png|webp
     * @return $this
     * @throws RuntimeException
     */
    public function generateIconsFromSource(string $sourcePath, string $destDir, array $sizes = [192,512], string $format = 'png'): static
    {
        if (!extension_loaded('imagick')) {
            throw new RuntimeException('Imagick extension is required for icon generation.');
        }
        if (!is_readable($sourcePath)) {
            throw new RuntimeException('Source image not readable: ' . $sourcePath);
        }
        if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
            throw new RuntimeException('Unable to create destination directory: ' . $destDir);
        }

        $im = new \Imagick($sourcePath);
        foreach ($sizes as $size) {
            $size = (int)$size;
            $clone = clone $im;
            $clone->setImageBackgroundColor('transparent');
            $clone->thumbnailImage($size, $size, true, true);
            $filename = sprintf('icon-%dx%d.%s', $size, $size, $format);
            $outPath = rtrim($destDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
            if ($format === 'png') {
                $clone->setImageFormat('png');
            } elseif ($format === 'webp') {
                $clone->setImageFormat('webp');
            } else {
                $clone->setImageFormat($format);
            }
            if (!$clone->writeImage($outPath)) {
                throw new RuntimeException('Failed to write icon: ' . $outPath);
            }
            $this->addIcon($outPath, "{$size}x{$size}", $this->mimeTypeForExtension($format));
            $clone->clear();
            $clone->destroy();
        }
        $im->clear();
        $im->destroy();
        return $this;
    }

    /**
     * Utility: guess mime type for an extension
     */
    private function mimeTypeForExtension(string $ext): string
    {
        $ext = strtolower(ltrim($ext, '.'));
        return match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }

    // -------------------- Magic / utils --------------------

    /**
     * Return JSON string on cast
     */
    public function __toString(): string
    {
        try {
            return $this->toJson(true);
        } catch (\Throwable) {
            return '';
        }
    }
}