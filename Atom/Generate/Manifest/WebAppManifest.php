<?php

declare(strict_types=1);

namespace Atom\Generate\Manifest;

use InvalidArgumentException;
use JsonSerializable;
use Override;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * Builder and atomic serializer for W3C Progressive Web App `manifest.json`.
 */
final class WebAppManifest implements JsonSerializable, Stringable
{
    private const array DEFAULT = [
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

    private const array LIST_KEYS = [
        'icons',
        'shortcuts',
        'related_applications',
        'categories',
        'screenshots',
    ];

    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param array<string, mixed> $initial
     */
    public function __construct(array $initial = [])
    {
        $this->data = self::DEFAULT;
        $this->merge($initial);
    }

    /**
     * @param array<string, mixed> $arr
     */
    public static function fromArray(array $arr): self
    {
        return new self($arr);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function merge(array $values): static
    {
        foreach ($values as $k => $v) {
            if (\is_array($v) && isset($this->data[$k]) && \is_array($this->data[$k]) && \in_array($k, self::LIST_KEYS, true)) {
                $this->data[$k] = \array_values([...$this->data[$k], ...$v]);
            } else {
                $this->data[$k] = $v;
            }
        }

        return $this;
    }

    public function setName(string $name): static { $this->data['name'] = $name; return $this; }
    public function getName(): ?string { return isset($this->data['name']) ? (string) $this->data['name'] : null; }
    public function setShortName(string $short): static { $this->data['short_name'] = $short; return $this; }
    public function getShortName(): ?string { return isset($this->data['short_name']) ? (string) $this->data['short_name'] : null; }
    public function setStartUrl(string $url): static { $this->data['start_url'] = $url; return $this; }
    public function getStartUrl(): string { return (string) ($this->data['start_url'] ?? '/'); }
    public function setScope(string $scope): static { $this->data['scope'] = $scope; return $this; }
    public function getScope(): ?string { return isset($this->data['scope']) ? (string) $this->data['scope'] : null; }
    public function setDisplay(string $display): static { $this->data['display'] = $display; return $this; }
    public function getDisplay(): ?string { return isset($this->data['display']) ? (string) $this->data['display'] : null; }
    public function setThemeColor(string $color): static { $this->data['theme_color'] = $color; return $this; }
    public function getThemeColor(): ?string { return isset($this->data['theme_color']) ? (string) $this->data['theme_color'] : null; }
    public function setBackgroundColor(string $color): static { $this->data['background_color'] = $color; return $this; }
    public function getBackgroundColor(): ?string { return isset($this->data['background_color']) ? (string) $this->data['background_color'] : null; }
    public function setDescription(string $description): static { $this->data['description'] = $description; return $this; }
    public function getDescription(): ?string { return isset($this->data['description']) ? (string) $this->data['description'] : null; }
    public function setLang(string $lang): static { $this->data['lang'] = $lang; return $this; }
    public function getLang(): ?string { return isset($this->data['lang']) ? (string) $this->data['lang'] : null; }
    public function setDir(string $dir): static { $this->data['dir'] = $dir; return $this; }
    public function getDir(): ?string { return isset($this->data['dir']) ? (string) $this->data['dir'] : null; }

    /**
     * @param array<array-key, string|\Stringable> $categories
     */
    public function setCategories(array $categories): static
    {
        $this->data['categories'] = \array_values(\array_map(\strval(...), $categories));
        return $this;
    }

    public function addCategory(string $category): static
    {
        if (!\in_array($category, $this->data['categories'], true)) {
            $this->data['categories'][] = $category;
        }

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getCategories(): array
    {
        return $this->data['categories'] ?? [];
    }

    /**
     * @param array<string, string> $attrs
     */
    public function addIcon(string $src, ?string $sizes = null, ?string $type = null, array $attrs = []): static
    {
        $icon = \array_filter(
            ['src' => $src, 'sizes' => $sizes, 'type' => $type],
            static fn(?string $v): bool => $v !== null && $v !== ''
        );

        foreach ($attrs as $k => $v) {
            if ($v !== '') {
                $icon[$k] = $v;
            }
        }

        foreach ($this->data['icons'] as $existing) {
            if (\is_array($existing) && ($existing['src'] ?? null) === $src) {
                $merged = [...$existing, ...$icon];
                $this->data['icons'] = \array_map(
                    static fn(array $i): array => ($i['src'] ?? null) === $src ? $merged : $i,
                    $this->data['icons']
                );
                return $this;
            }
        }

        $this->data['icons'][] = $icon;
        return $this;
    }

    public function removeIcon(string|callable|null $srcOrCallable = null): static
    {
        if ($srcOrCallable === null) {
            $this->data['icons'] = [];
            return $this;
        }

        if (\is_string($srcOrCallable)) {
            $this->data['icons'] = \array_values(\array_filter(
                $this->data['icons'],
                static fn(array $i): bool => ($i['src'] ?? null) !== $srcOrCallable
            ));
            return $this;
        }

        $this->data['icons'] = \array_values(\array_filter(
            $this->data['icons'],
            static fn(array $i): bool => !$srcOrCallable($i)
        ));
        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getIcons(): array
    {
        return $this->data['icons'] ?? [];
    }

    /**
     * @param array<string, mixed> $shortcut
     */
    public function addShortcut(array $shortcut): static
    {
        $this->data['shortcuts'][] = $shortcut;
        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getShortcuts(): array
    {
        return $this->data['shortcuts'] ?? [];
    }

    /**
     * @param array<string, mixed> $attrs
     */
    public function addRelatedApplication(string $platform, string $id, array $attrs = []): static
    {
        $this->data['related_applications'][] = ['platform' => $platform, 'id' => $id, ...$attrs];
        return $this;
    }

    public function setPreferRelatedApplications(bool $flag): static
    {
        $this->data['prefer_related_applications'] = $flag;
        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRelatedApplications(): array
    {
        return $this->data['related_applications'] ?? [];
    }

    public function addScreenshot(string $src, ?string $sizes = null, ?string $type = null): static
    {
        $this->data['screenshots'][] = \array_filter(
            ['src' => $src, 'sizes' => $sizes, 'type' => $type],
            static fn(?string $v): bool => $v !== null && $v !== ''
        );
        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getScreenshots(): array
    {
        return $this->data['screenshots'] ?? [];
    }

    /**
     * @param array<string, mixed>|null $config
     */
    public function setShareTarget(?array $config): static
    {
        $this->data['share_target'] = $config;
        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getShareTarget(): ?array
    {
        return $this->data['share_target'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->data as $k => $v) {
            if ($v === null) {
                continue;
            }
            if (\is_array($v) && ($v === [] || $v === [''])) {
                continue;
            }
            $out[$k] = $v;
        }

        return $out;
    }

    public function toJson(bool $pretty = false): string
    {
        $flags = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | ($pretty ? \JSON_PRETTY_PRINT : 0);
        $json = \json_encode($this->toArray(), $flags);
        if ($json === false) {
            throw new RuntimeException('Failed to json_encode manifest: ' . \json_last_error_msg());
        }

        return $json;
    }

    public function saveToFile(string $path, bool $pretty = true): void
    {
        $json = $this->toJson($pretty);
        $dir = \dirname($path);
        if (!\is_dir($dir) && !\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            throw new RuntimeException('Unable to create directory: ' . $dir);
        }

        $tmp = \tempnam($dir, 'manifest_');
        if ($tmp === false) {
            throw new RuntimeException('Unable to create temp file in: ' . $dir);
        }

        if (\file_put_contents($tmp, $json, \LOCK_EX) === false) {
            if (\is_file($tmp)) {
                \unlink($tmp);
            }
            throw new RuntimeException('Unable to write manifest to temp file: ' . $tmp);
        }

        if (!\rename($tmp, $path)) {
            if (\is_file($tmp)) {
                \unlink($tmp);
            }
            throw new RuntimeException('Unable to move temp manifest to destination: ' . $path);
        }
    }

    /**
     * @return list<string>
     */
    public function validate(): array
    {
        $errors = [];

        if (empty($this->data['name']) && empty($this->data['short_name'])) {
            $errors[] = 'Either "name" or "short_name" should be provided.';
        }

        if (empty($this->data['start_url'])) {
            $errors[] = '"start_url" is required.';
        }

        foreach ($this->data['icons'] as $i => $icon) {
            if (!\is_array($icon) || !isset($icon['src'])) {
                $errors[] = "Icon at index {$i} must contain 'src'.";
                continue;
            }
            if (isset($icon['sizes']) && $icon['sizes'] !== 'any' && \preg_match('/^\d+x\d+$/', (string) $icon['sizes']) !== 1) {
                $errors[] = "Icon sizes for {$icon['src']} must be 'WxH' or 'any'.";
            }
        }

        if (isset($this->data['display'])) {
            $allowed = ['fullscreen', 'standalone', 'minimal-ui', 'browser'];
            if (!\in_array($this->data['display'], $allowed, true)) {
                $errors[] = '"display" must be one of: ' . \implode(', ', $allowed) . '.';
            }
        }

        return $errors;
    }

    public function validateOrFail(): void
    {
        $errors = $this->validate();
        if ($errors !== []) {
            throw new InvalidArgumentException('Manifest validation failed: ' . \implode('; ', $errors));
        }
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerializable(): array
    {
        return $this->toArray();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setCommon(array $data): static
    {
        foreach ($data as $k => $v) {
            $this->data[$k] = $v;
        }

        return $this;
    }

    /**
     * @param list<int> $sizes
     */
    public function generateIconsFromSource(
        string $sourcePath,
        string $destDir,
        array $sizes = [192, 512],
        string $format = 'png',
    ): static {
        if (!\extension_loaded('imagick')) {
            throw new RuntimeException('Imagick extension is required for icon generation.');
        }
        if (!\is_readable($sourcePath)) {
            throw new RuntimeException('Source image not readable: ' . $sourcePath);
        }
        if (!\is_dir($destDir) && !\mkdir($destDir, 0755, true) && !\is_dir($destDir)) {
            throw new RuntimeException('Unable to create destination directory: ' . $destDir);
        }

        $im = new \Imagick($sourcePath);
        try {
            foreach ($sizes as $size) {
                $safeSize = \clamp((int) $size, 16, 2048);
                $clone = clone $im;
                try {
                    $clone->setImageBackgroundColor('transparent');
                    $clone->thumbnailImage($safeSize, $safeSize, true, true);
                    $filename = \sprintf('icon-%dx%d.%s', $safeSize, $safeSize, $format);
                    $outPath = \rtrim($destDir, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . $filename;
                    $clone->setImageFormat($format);
                    if (!$clone->writeImage($outPath)) {
                        throw new RuntimeException('Failed to write icon: ' . $outPath);
                    }
                    $this->addIcon($outPath, "{$safeSize}x{$safeSize}", $this->mimeTypeForExtension($format));
                } finally {
                    $clone->clear();
                    $clone->destroy();
                }
            }
        } finally {
            $im->clear();
            $im->destroy();
        }

        return $this;
    }

    private function mimeTypeForExtension(string $ext): string
    {
        return match (\strtolower(\ltrim($ext, '.'))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }

    #[Override]
    public function __toString(): string
    {
        try {
            return $this->toJson(true);
        } catch (Throwable) {
            return '';
        }
    }
}
