<?php

declare(strict_types=1);

namespace Atom\HttpFoundation;

use Atom\Log\T4LOG;
use BrowscapPHP\Browscap;
use BrowscapPHP\BrowscapUpdater;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;
use WhichBrowser\Parser as WhichBrowserParser;

/**
 * Combined User-Agent parser (WhichBrowser + optional Browscap) with bounded LRU-style cache
 * to prevent memory leaks in long-running Swoole / FrankenPHP workers.
 */
final class ConnectionInformation
{
    private const int MAX_CACHE_ENTRIES = 256;

    private string $userAgent;
    private bool $extends;

    /** @var array<string, mixed> */
    private array $parsed = [];

    /** @var array<string, array<string, mixed>> Bounded cache by UA */
    private static array $cache = [];

    private static bool $browscapAvailable = false;
    private static string $whichBrowserClass = WhichBrowserParser::class;
    private static ?string $browscapClass = null;
    private static ?Browscap $browscap = null;

    private LoggerInterface $logger;

    public function __construct(
        CacheInterface $cache,
        T4LOG $logger,
        ?string $userAgent = null,
        bool $extends = false,
    ) {
        $this->userAgent = $userAgent ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $this->extends = $extends;
        $this->logger = $logger;

        $key = $this->cacheKey();
        if (isset(self::$cache[$key])) {
            $this->parsed = self::$cache[$key];
            return;
        }

        if ($extends && self::$browscap === null) {
            $this->initBrowscap($cache, $logger);
        }

        $this->parsed = $this->parse();
        self::putCache($key, $this->parsed);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function putCache(string $key, array $entry): void
    {
        if (\count(self::$cache) >= self::MAX_CACHE_ENTRIES) {
            $oldestKey = \array_key_first(self::$cache);
            unset(self::$cache[$oldestKey]);
        }

        self::$cache[$key] = $entry;
    }

    private function initBrowscap(CacheInterface $cache, LoggerInterface $logger): void
    {
        if (self::$browscap !== null) {
            return;
        }

        self::$browscap = new Browscap($cache, $logger);
        self::$browscapAvailable = true;
        self::$browscapClass = Browscap::class;
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(): array
    {
        $wbParser = new WhichBrowserParser($this->userAgent);
        $wb = $this->normalizeWhichBrowser($wbParser);
        $combined = $wb;

        if ($this->extends && self::$browscap !== null) {
            try {
                $bc = $this->parseBrowscap();
                if ($bc !== []) {
                    $combined = $this->mergePreferFirst($combined, $this->normalizeWichBrowscap($bc));
                }
            } catch (Throwable $th) {
                $this->logger->warning(
                    'ConnectionInformation: Failed to parse user agent with Browscap during initialization. '
                    . 'Run ConnectionInformation::browscapUpdate() to refresh the database.',
                    ['ua' => $this->userAgent, 'exception' => $th]
                );
            }
        }

        return [
            'whichbrowser' => $wb,
            'combined' => $combined,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseBrowscap(): array
    {
        return (array) (self::$browscap?->getBrowser($this->userAgent) ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeWhichBrowser(WhichBrowserParser $parser): array
    {
        $browserVersion = $parser->browser->version->value ?? $parser->browser->version->toString();
        $osVersion = $parser->os->version->value ?? $parser->os->version->toString();

        return [
            'browserName' => $parser->browser->name !== '' ? $parser->browser->name : null,
            'browserType' => $parser->browser->type !== '' ? $parser->browser->type : null,
            'browserVersion' => $browserVersion !== '' ? $browserVersion : null,
            'platform' => $parser->os->name !== '' ? $parser->os->name : null,
            'osVersion' => $osVersion !== '' ? $osVersion : null,
            'engine' => $parser->engine->name !== '' ? $parser->engine->name : null,
            'deviceType' => $parser->device->type !== '' ? $parser->device->type : null,
            'deviceModel' => $parser->device->model !== '' ? $parser->device->model : null,
            'isBot' => $parser->isType('bot'),
            'isMobile' => $parser->isType('mobile'),
            'isTablet' => $parser->isType('tablet'),
            'isDesktop' => $parser->isType('desktop'),
            'all' => $parser,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeWichBrowscap(array $data): array
    {
        $deviceType = \strtolower((string) ($data['device_type'] ?? ''));

        return [
            'browserName' => $data['browser'] ?? null,
            'browserVersion' => $data['version'] ?? null,
            'platform' => $data['platform'] ?? null,
            'engine' => $data['renderingengine_name'] ?? null,
            'deviceType' => $data['device_type'] ?? null,
            'deviceModel' => $data['device_code_name'] ?? null,
            'isBot' => isset($data['comment']) && \str_contains(\strtolower((string) $data['comment']), 'bot'),
            'isMobile' => $deviceType === 'mobile',
            'isTablet' => $deviceType === 'tablet',
            'isDesktop' => $deviceType === 'desktop',
        ];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return array<string, mixed>
     */
    private function mergePreferFirst(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            if (!isset($a[$k]) || $a[$k] === '') {
                $a[$k] = $v;
            }
        }

        return $a;
    }

    private function cacheKey(): string
    {
        return \hash('xxh128', ($this->extends ? '1:' : '0:') . $this->userAgent);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->parsed;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function getBrowserName(): string
    {
        return (string) ($this->parsed['combined']['browserName'] ?? 'Unknown');
    }

    public function getBrowserVersion(): ?string
    {
        $v = $this->parsed['combined']['browserVersion'] ?? null;
        if ($v === null || $v === '') {
            return null;
        }

        return \is_object($v) && \method_exists($v, '__toString') ? (string) $v : (\is_scalar($v) ? (string) $v : null);
    }

    public function getPlatform(): string
    {
        return (string) ($this->parsed['combined']['platform'] ?? 'Unknown');
    }

    public function getEngine(): ?string
    {
        $engine = $this->parsed['combined']['engine'] ?? ($this->parsed['combined']['renderingengine_name'] ?? null);
        return \is_string($engine) && $engine !== '' ? $engine : null;
    }

    /**
     * @return array{type: mixed, model: mixed, brand: mixed}
     */
    public function getDevice(): array
    {
        return [
            'type' => $this->parsed['combined']['deviceType']
                ?? $this->parsed['combined']['device_name']
                ?? $this->parsed['combined']['device']
                ?? null,
            'model' => $this->parsed['whichbrowser']['deviceModel']
                ?? $this->parsed['browscap']['Device_Type']
                ?? null,
            'brand' => $this->parsed['whichbrowser']['device_brand_name']
                ?? $this->parsed['whichbrowser']['deviceBrand']
                ?? null,
        ];
    }

    public function isBot(): bool
    {
        return (bool) ($this->parsed['combined']['isBot'] ?? false);
    }

    public function isMobile(): bool
    {
        return (bool) ($this->parsed['combined']['isMobile'] ?? false);
    }

    public function isTablet(): bool
    {
        return (bool) ($this->parsed['combined']['isTablet'] ?? false);
    }

    public function isDesktop(): bool
    {
        return (bool) ($this->parsed['combined']['isDesktop'] ?? false);
    }

    public function isBrowserVersion(string $version, string $operator = '>='): bool
    {
        $cur = $this->getBrowserVersion();
        if ($cur === null || $cur === '') {
            return false;
        }

        return (bool) \version_compare($cur, $version, $operator);
    }

    public function isBrowserVersionAtLeast(string $version): bool
    {
        return $this->isBrowserVersion($version, '>=');
    }

    public function supportsJavascript(): bool
    {
        $bc = $this->parsed['browscap'] ?? null;
        if (\is_array($bc) && isset($bc['JavaScript'])) {
            return \filter_var($bc['JavaScript'], \FILTER_VALIDATE_BOOLEAN);
        }

        return $this->getEngine() !== null && !$this->isBot();
    }

    public function supportsCookies(): bool
    {
        $bc = $this->parsed['browscap'] ?? null;
        if (\is_array($bc) && isset($bc['Cookies'])) {
            return \filter_var($bc['Cookies'], \FILTER_VALIDATE_BOOLEAN);
        }

        return !$this->isBot();
    }

    public function supportsCssLevel(int $level = 3): bool
    {
        if ($level <= 1) {
            return true;
        }

        $bc = $this->parsed['browscap'] ?? null;
        if (\is_array($bc) && isset($bc['CssVersion'])) {
            return (int) $bc['CssVersion'] >= $level;
        }

        $engine = \strtolower((string) ($this->getEngine() ?? ''));
        if (\in_array($engine, ['webkit', 'blink', 'gecko', 'edgehtml'], true)) {
            return $level <= 3;
        }

        return $level <= 2;
    }

    public static function getWhichBrowserClass(): string
    {
        return self::$whichBrowserClass;
    }

    public static function getBrowscapClass(): ?string
    {
        return self::$browscapClass;
    }

    /**
     * @return array<string, mixed>
     */
    public function debugState(): array
    {
        return [
            'ua' => $this->userAgent,
            'whichbrowser_class' => self::$whichBrowserClass,
            'browscap_available' => self::$browscapAvailable,
            'browscap_class' => self::$browscapClass,
            'cached' => isset(self::$cache[$this->cacheKey()]),
            'parsed_summary' => [
                'browser' => $this->getBrowserName(),
                'version' => $this->getBrowserVersion(),
                'platform' => $this->getPlatform(),
                'isBot' => $this->isBot(),
                'isMobile' => $this->isMobile(),
            ],
        ];
    }

    public function refresh(bool $recreateBrowscap = false): void
    {
        if ($recreateBrowscap) {
            self::$browscapAvailable = false;
            self::$browscap = null;
        }

        $this->parsed = $this->parse();
        self::putCache($this->cacheKey(), $this->parsed);
    }

    public static function browscapUpdate(CacheInterface $cache, T4LOG $logger): void
    {
        try {
            $updater = new BrowscapUpdater($cache, $logger);
            $updater->update();
        } catch (Throwable $e) {
            $logger->warning('Browscap update failed: ' . $e->getMessage());
        }
    }
}
