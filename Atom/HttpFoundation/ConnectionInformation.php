<?php

declare(strict_types=1);

namespace Atom\HttpFoundation;

use Atom\Log\T4LOG;
use Throwable;
use WhichBrowser\Parser as WhichBrowserParser;
use BrowscapPHP\Browscap;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use BrowscapPHP\BrowscapUpdater;

/**
 * CombinedBrowserDetector
 *
 * - Primary parser: WhichBrowser\Parser (if available)
 * - Optional extension: Browscap (if $extends === true and browscap lib available)
 * - Results: WhichBrowser result augmented with Browscap (when present)
 *
 * Safe: does not attempt to require composer autoload - assumes application bootstraped composer.
 */
final class ConnectionInformation
{
    /** @var string the user agent used by this instance */
    private string $userAgent;

    /** @var bool whether to attempt to use Browscap to extend WhichBrowser data */
    private bool $extends;

    /** @var array<string,mixed> instance-level parsed result */
    private array $parsed = [];

    /** @var array<string,array<string,mixed>> static cache by UA */
    private static array $cache = [];

    /** @var bool whether Browscap class/library is available */
    private static bool $browscapAvailable = false;

    /** @var object|null static Browscap instance (if available) */
    private static ?object $browscapInstance = null;

    /** @var string|null last WhichBrowser class discovered (for debug) */
    private static ?string $whichBrowserClass = null;

    /** @var string|null last Browscap class discovered (for debug) */
    private static ?string $browscapClass = null;

    /** static instances */
    private static ?Browscap $browscap = null;

    /** @var T4LOG static logger instance for Browscap (if used) */
    private static T4LOG $logger;

    /**
     * Construct detector.
     *
     * @param string|null $userAgent UA string; if null, uses $_SERVER['HTTP_USER_AGENT'] or empty string
     * @param bool $extends If true, attempt to augment WhichBrowser with Browscap where possible
     */
    public function __construct(CacheInterface $cache, T4LOG $logger, ?string $userAgent = null, bool $extends = false)
    {
        $this->userAgent = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $this->extends = $extends;

        static::$logger = $logger;

        $key = $this->cacheKey();

        if (isset(self::$cache[$key])) {
            $this->parsed = self::$cache[$key];
            return;
        }
        if ($extends && self::$browscap === null) {
            $this->initBrowscap($cache, $logger);
        }
            // var_dump("CI constructor: UA=", $this->userAgent, " extends=", $extends);

        $this->parsed = $this->parse();
        self::$cache[$key] = $this->parsed;
    }

    // -------------------------
    // Static detection helpers
    // -------------------------

    private function initBrowscap(CacheInterface $cache, LoggerInterface $logger): void
    {
        if (self::$browscap !== null) {
            return;
        }

        self::$browscap = new Browscap($cache, $logger);
    }

    // -------------------------
    // Parsing & combining
    // -------------------------

    private function parse(): array
    {
        $wbParser = new WhichBrowserParser($this->userAgent);

        $wb = $this->normalizeWhichBrowser($wbParser);

        $combined = $wb;

        if ($this->extends && self::$browscap !== null) {
            try {
                $bc = $this->parseBrowscap();

                if ($bc !== null) {
                    $combined = $this->mergePreferFirst($combined, self::normalizeWichBrowscap($bc));
                }
            } catch (\Throwable $th) {
                static::$logger->warning(
                    "ConnectionInformation: Failed to parse user agent with Browscap during initialization." .
                    "you probably need to update the Browscap database (ConnectionInformation::browscapUpdate())",
                    ['ua' => $this->userAgent, 'exception' => $th]
                );
            }
        }

        return [
            'whichbrowser' => $wb,
            'combined' => $combined,
        ];
    }

    private function parseBrowscap(): array
    {
        // try {
        return (array) self::$browscap?->getBrowser($this->userAgent);
            // var_dump ("CI parseBrowscap: data=", (array)$data );
        // return \is_array($data) ? $data : null;
        // } catch (Throwable) {
        //     var_dump ("CI parseBrowscap: failed to parse with browscap");
        //     return null;
        // }
    }

    private function normalizeWhichBrowser(WhichBrowserParser $parser): array
    {
        return [
            'browserName' => $parser->browser->name,

            'browserType' => $parser->browser->type,

            'browserVersion' =>
                $parser->browser->version->value
                ?? $parser->browser->version,


            'platform' => $parser->os->name,
            'osVersion' => $parser->os->version->value,

            'engine' => $parser->engine->name,

            'deviceType' => $parser->device->type,
            'deviceModel' => $parser->device->model,

            'isBot' => $parser->isType('bot'),
            'isMobile' => $parser->isType('mobile'),
            'isTablet' => $parser->isType('tablet'),
            'isDesktop' => $parser->isType('desktop'),

            'all' => $parser,
        ];
    }

    private function normalizeWichBrowscap(array $data): array
    {
        $deviceType = strtolower($data['device_type']);
        return [
            'browserName' => $data['browser'],
            'browserVersion' => $data['version'],

            'platform' => $data['platform'],

            'engine' => $data['renderingengine_name'],

            'deviceType' => $data['device_type'],
            'deviceModel' => $data['device_code_name'],

            'isBot' => isset($data['comment']) && str_contains(strtolower($data['comment']), 'bot'), // crawer
            'isMobile' => $deviceType === 'mobile', // ismobiledevice
            'isTablet' => $deviceType === 'tablet',
            'isDesktop' => $deviceType === 'desktop',
        ];
    }

    /**
     * Parse with WhichBrowser (if available) then augment with Browscap (if requested/available).
     *
     * @return array<string,mixed>
     */
    private function parseAndCombine(): array
    {
        $ua = $this->userAgent;
        $result = [
            'userAgent' => $ua,
            'whichbrowser' => null,
            'browscap' => null,
            'combined' => [],
        ];

        // 1) WhichBrowser parse
        if (static::$whichBrowserClass !== null) {
            try {
                $parserClass = self::$whichBrowserClass;
                $parser = new $parserClass($ua);
                // normalize whichbrowser result to array (best-effort)
                $wb = $this->normalizeWhichBrowserResult($parser);
                $result['whichbrowser'] = $wb;
                $result['combined'] = $wb; // start combined from WhichBrowser
            } catch (Throwable $e) {
                $result['whichbrowser'] = null;
                static::$logger->warning(
                    "ConnectionInformation: Failed to parse user agent with WhichBrowser." .
                    "WhichBrowser-based features will be unavailable for this UA.",
                    ['ua' => $ua, 'exception' => $e]
                );
            }
        }

        // 2) Browscap augmentation (only if extends requested)
        if ($this->extends && self::$browscapAvailable) {
            try {
                $bc = $this->queryBrowscap($ua);
                $result['browscap'] = $bc;
                // merge: existing combined gets fields from browscap only if missing in combined
                if (\is_array($bc)) {
                    $result['combined'] = $this->mergePreferFirst($result['combined'], $bc);
                }
            } catch (Throwable $e) {
                $result['browscap'] = null;
                static::$logger->warning(
                    "ConnectionInformation: Failed to parse user agent with Browscap." .
                    "Browscap-based features will be unavailable for this UA.",
                    ['ua' => $ua, 'exception' => $e]
                );
            }
        }

        // 3) Ensure minimal keys exist
        $combined = $result['combined'];
        $combined['userAgent'] = $ua;
        $combined['browserName'] = $combined['browserName'] ?? ($combined['name'] ?? 'Unknown');
        $combined['browserVersion'] = $combined['browserVersion'] ?? ($combined['version'] ?? null);
        $combined['platform'] = $combined['platform'] ?? ($combined['os'] ?? ($combined['osName'] ?? 'Unknown'));
        $combined['isBot'] = $combined['isBot'] ?? (bool) ($combined['bot'] ?? false);
        $combined['isMobile'] = $combined['isMobile'] ?? (bool) ($combined['mobile'] ?? false);
        $combined['engine'] = $combined['engine'] ?? ($combined['renderedBy'] ?? ($combined['engine'] ?? null));
        $combined['device'] = $combined['device'] ?? ($combined['deviceType'] ?? null);

        $result['combined'] = $combined;
        return $result;
    }

    /**
     * Safe normalization of WhichBrowser Parser object to associative array.
     *
     * Because WhichBrowser Parser returns nested objects, and different versions vary, this method
     * extracts the most important fields in a stable shape.
     *
     * @param object $parser
     * @return array<string,mixed>
     */
    private function normalizeWhichBrowserResult(object $parser): array
    {
        $out = [];

        // browser name & version
        try {
            if (isset($parser->browser) && \is_object($parser->browser)) {
                $out['name'] = $parser->browser->name ?? null;
                // version might be object or string
                if (isset($parser->browser->version)) {
                    $out['version'] = \is_object($parser->browser->version)
                        ? ($parser->browser->version->value ?? null)
                        : $parser->browser->version;
                }
            }
        } catch (Throwable $e) {
            static::$logger->warning(
                "ConnectionInformation: Failed to extract browser info from WhichBrowser parser result.",
                ['exception' => $e]
            );
        }

        // os / platform
        try {
            if (isset($parser->os) && \is_object($parser->os)) {
                $out['os'] = $parser->os->name ?? null;
                if (isset($parser->os->version)) {
                    $out['osVersion'] = \is_object($parser->os->version)
                        ? ($parser->os->version->value ?? null)
                        : $parser->os->version;
                }
            }
        } catch (Throwable $e) {
            static::$logger->warning(
                "ConnectionInformation: Failed to extract OS info from WhichBrowser parser result.",
                ['exception' => $e]
            );
        }

        // device
        try {
            if (isset($parser->device) && \is_object($parser->device)) {
                $out['deviceType'] = $parser->device->type ?? null;
                $out['deviceModel'] = $parser->device->model ?? null;
                $out['deviceBrand'] = $parser->device->brand ?? null;
            }
        } catch (Throwable $e) {
            static::$logger->warning(
                "ConnectionInformation: Failed to extract device info from WhichBrowser parser result.",
                ['exception' => $e]
            );
        }

        // engine
        try {
            if (isset($parser->engine) && \is_object($parser->engine)) {
                $out['engine'] = $parser->engine->name ?? null;
                if (isset($parser->engine->version)) {
                    $out['engineVersion'] = \is_object($parser->engine->version)
                        ? ($parser->engine->version->value ?? null)
                        : $parser->engine->version;
                }
            }
        } catch (Throwable $e) {
            static::$logger->warning(
                "ConnectionInformation: Failed to extract engine info from WhichBrowser parser result.",
                ['exception' => $e]
            );
        }

        // bot detection
        try {
            $out['bot'] =
                (bool) ($parser->isType('bot') ??
                (method_exists($parser, 'isBot') ?
                $parser->isBot() :
                false));
        } catch (Throwable $e) {
            // fallback: check parser->type
            $out['bot'] = false;
            static::$logger->warning(
                "ConnectionInformation: Failed to determine bot status from WhichBrowser parser result.",
                ['exception' => $e]
            );
        }

        // higher-level booleans (mobile/desktop/tablet)
        try {
            $out['mobile'] = (bool) ($parser->isType('mobile') ?? false);
            $out['tablet'] = (bool) ($parser->isType('tablet') ?? false);
            $out['desktop'] = (bool) ($parser->isType('desktop') ?? false);
        } catch (Throwable $e) {
            static::$logger->warning(
                "ConnectionInformation: Failed to determine device type from WhichBrowser parser result.",
                ['exception' => $e]
            );
        }

        // raw parser object for advanced users (do not expose in combined by default)
        $out['_raw'] = $parser;

        return $out;
    }

    /**
     * Query browscap data for UA in a tolerant manner.
     *
     * Supports:
     *  - BrowscapPHP\Browscap->getBrowser($ua, true) returning array
     *  - Browscap\Browscap->getBrowser()
     *  - native get_browser($ua, true) as fallback if browscapClass == 'native_get_browser'
     *
     * @param string $ua
     * @return array<string,mixed>|null
     */
    private function queryBrowscap(string $ua): ?array
    {
        if (!self::$browscapAvailable) {
            return null;
        }

        // If we have a browscap instance object, try to use it
        if (self::$browscapInstance !== null) {
            $inst = self::$browscapInstance;
            try {
                if (method_exists($inst, 'getBrowser')) {
                    // some libs: getBrowser($ua, $format = true) => array
                    $res = $inst->getBrowser($ua, true);
                    if (\is_array($res)) {
                        return $res;
                    }
                    // sometimes it returns object
                    if (\is_object($res)) {
                        return (array) $res;
                    }
                }
                // some libs expose getProperties or getInfo
                if (method_exists($inst, 'getProperties')) {
                    $res = $inst->getProperties($ua);
                    if (\is_array($res)) {
                        return $res;
                    }
                    if (\is_object($res)) {
                        return (array) $res;
                    }
                }
            } catch (Throwable $e) {
                static::$logger->warning(
                    "ConnectionInformation: Failed to query Browscap instance for user agent.",
                    ['ua' => $ua, 'exception' => $e]
                );
            }
        }

        // If browscapClass indicates native support via ini browscap -> try get_browser()
        if (self::$browscapClass === 'native_get_browser') {
            try {
                $res = @get_browser($ua, true);
                if (\is_array($res)) {
                    return $res;
                }
            } catch (Throwable $e) {
                static::$logger->warning(
                    "ConnectionInformation: Failed to query native get_browser() for user agent.",
                    ['ua' => $ua, 'exception' => $e]
                );
            }
        }

        return null;
    }

    // -------------------------
    // Utility merging helpers
    // -------------------------

    /**
     * Merge two associative arrays preferring keys present in $a (first argument).
     * If $a lacks a key but $b has it, take from $b.
     *
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array<string,mixed>
     */
    private function mergePreferFirst(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            if (!isset($a[$k]) || empty($a[$k])) {
                $a[$k] = $v;
            }
        }
        return $a;
    }

    private function cacheKey(): string
    {
        return md5(($this->extends ? '1:' : '0:') . $this->userAgent);
    }


    /**
     * Create uid key for cache
     */
    private function uaCacheKey(string $ua, bool $extends): string
    {
        return md5(($extends ? 'ext1:' : 'ext0:') . $ua);
    }

    // -------------------------
    // Public getters / helpers
    // -------------------------

    /**
     * Return raw combined parse array:
     * [
     *   'userAgent' => string,
     *   'whichbrowser' => array|null,
     *   'browscap' => array|null,
     *   'combined' => array
     * ]
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
        return $v === '' ? null : ($v ?? null);
    }

    public function getPlatform(): string
    {
        return (string) ($this->parsed['combined']['platform'] ?? 'Unknown');
    }

    public function getEngine(): ?string
    {
        return $this->parsed['combined']['renderingengine_name'] ?? null;
    }

    public function getDevice(): ?array
    {
        return [
            'type' =>
                $this->parsed['combined']['device_name'] ??
                $this->parsed['combined']['device'] ??
                null,
            'model' =>
                $this->parsed['whichbrowser']['deviceModel'] ??
                $this->parsed['browscap']['Device_Type'] ??
                null,
            'brand' =>
                $this->parsed['whichbrowser']['device_brand_name'] ??
                $this->parsed['whichbrowser']['deviceBrand'] ??
                null,
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
        return !$this->isMobile() && !$this->isDesktop();
    }

    public function isDesktop(): bool
    {
        // inverse of mobile/tablet where possible
        return !$this->isMobile() && !$this->isTablet();
    }

    /**
     * Compare browser version using version_compare.
     *
     * @param string $operator one of >, >=, <, <=, ==, !=
     */
    public function isBrowserVersion(string $version, string $operator = '>='): bool
    {
        $cur = $this->getBrowserVersion();
        if ($cur === null || $cur === '') {
            return false;
        }
        return version_compare($cur, $version, $operator);
    }

    public function isBrowserVersionAtLeast(string $version): bool
    {
        return $this->isBrowserVersion($version, '>=');
    }

    // Capability helpers (best-effort)
    public function supportsJavascript(): bool
    {
        // Browscap historically exposes 'JavaScript' or 'javascript' keys; WhichBrowser implies support via engine
        $bc = $this->parsed['browscap'] ?? null;
        if (\is_array($bc) && isset($bc['JavaScript'])) {
            return filter_var($bc['JavaScript'], FILTER_VALIDATE_BOOLEAN);
        }
        // WhichBrowser: if engine exists and not bot, assume JS
        if ($this->getEngine() !== null && !$this->isBot()) {
            return true;
        }
        return false;
    }

    public function supportsCookies(): bool
    {
        $bc = $this->parsed['browscap'] ?? null;
        if (\is_array($bc) && isset($bc['Cookies'])) {
            return filter_var($bc['Cookies'], FILTER_VALIDATE_BOOLEAN);
        }
        // fallback: typical modern browsers support cookies; treat bots as false
        return !$this->isBot();
    }

    /**
     * Check CSS level support heuristically.
     * @param int $level 1..3
     */
    public function supportsCssLevel(int $level = 3): bool
    {
        if ($level <= 1) {
            return true;
        }
        // Browscap may contain CSS version in 'CssVersion' key
        $bc = $this->parsed['browscap'] ?? null;
        if (\is_array($bc) && isset($bc['CssVersion'])) {
            return (int) $bc['CssVersion'] >= $level;
        }
        // WhichBrowser: assume modern engines => CSS3
        $engine = strtolower((string) ($this->getEngine() ?? ''));
        if (\in_array($engine, ['webkit', 'blink', 'gecko', 'edgehtml'])) {
            return $level <= 3;
        }
        return $level <= 2;
    }

    // -------------------------
    // Introspection / debug
    // -------------------------

    public static function getWhichBrowserClass(): ?string
    {
        return self::$whichBrowserClass;
    }

    public static function getBrowscapClass(): ?string
    {
        return self::$browscapClass;
    }

    /**
     * Return internal debug state
     */
    public function debugState(): array
    {
        return [
            'ua' => $this->userAgent,
            'whichbrowser_class' => self::$whichBrowserClass,
            'browscap_available' => self::$browscapAvailable,
            'browscap_class' => self::$browscapClass,
            'cached' => isset(self::$cache[$this->uaCacheKey($this->userAgent, $this->extends)]),
            'parsed_summary' => [
                'browser' => $this->getBrowserName(),
                'version' => $this->getBrowserVersion(),
                'platform' => $this->getPlatform(),
                'isBot' => $this->isBot(),
                'isMobile' => $this->isMobile(),
            ],
        ];
    }

    /**
     * Force refresh parsing (clears instance parsed result and updates cache entry).
     *
     * @param bool $recreateBrowscap If true, attempt to re-instantiate browscap
     */
    public function refresh(bool $recreateBrowscap = false): void
    {
        if ($recreateBrowscap) {
            self::$browscapAvailable = false;
            self::$browscapInstance = null;
        }
        // reparse and update instance + cache
        $this->parsed = $this->parseAndCombine();
        $key = $this->uaCacheKey($this->userAgent, $this->extends);
        self::$cache[$key] = $this->parsed;
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
