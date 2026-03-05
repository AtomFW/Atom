<?php

declare(strict_types=1);

namespace Atom\HttpFoundation;

use InvalidArgumentException;

/**
 * BrowserDetector
 *
 * - Uses native get_browser() when available and configured (browscap).
 * - Falls back to a lightweight User-Agent parser when get_browser is unavailable.
 * - Safe: does not emit warnings/errors if browscap/get_browser is missing.
 *
 * Comments in English inside the file.
 */
final class BrowserDetector
{
    /** @var array<string, mixed> */
    private array $data = [];

    private string $userAgent;

    /**
     * @param string|null $userAgent if null, will use $_SERVER['HTTP_USER_AGENT'] or empty string
     * @param bool $preferGetBrowser try get_browser when available (default true)
     */
    public function __construct(?string $userAgent = null, bool $preferGetBrowser = true)
    {
        $this->userAgent = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($preferGetBrowser && self::isGetBrowserAvailable()) {
            $this->useGetBrowser();
        } else {
            $this->useFallbackParser();
        }
    }

    /**
     * Return raw user agent string used.
     */
    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    /**
     * Check if native get_browser exists and browscap is configured (non-empty).
     */
    public static function isGetBrowserAvailable(): bool
    {
        if (!function_exists('get_browser')) {
            return false;
        }
        $browscap = ini_get('browscap');
        return (\is_string($browscap) && $browscap !== '');
    }

    /**
     * Return the value of browscap ini setting (path) or null if not configured.
     */
    public static function getBrowscapPath(): ?string
    {
        $val = ini_get('browscap');
        if ($val === false || $val === '') {
            return null;
        }
        return $val;
    }

    /**
     * Attempt to populate $this->data using native get_browser().
     * Use @ to suppress warnings if browscap is misconfigured; validate result.
     */
    private function useGetBrowser(): void
    {
        try {
            // Ask for array result to simplify handling
            $result = @get_browser($this->userAgent, true);
            if (!is_array($result) || empty($result)) {
                // fallback if result is empty or invalid
                $this->useFallbackParser();
                return;
            }
            // normalize properties: convert values to appropriate types where possible
            $this->data = $this->normalizeGetBrowserArray($result);
            // ensure UA is present
            if (!isset($this->data['browser_name_pattern']) && !isset($this->data['browser'])) {
                $this->data['browser'] = $this->detectNameFromUA();
            }
        } catch (\Throwable $e) {
            // Something unexpected; fallback safely
            $this->useFallbackParser();
        }
    }

    /**
     * Normalize array returned by get_browser() (string/true/false conversions).
     *
     * @param array<string,mixed> $arr
     * @return array<string,mixed>
     */
    private function normalizeGetBrowserArray(array $arr): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            // Normalize boolean-like strings
            if ($v === '1' || $v === 1 || $v === true) {
                $out[$k] = true;
                continue;
            }
            if ($v === '0' || $v === 0 || $v === false) {
                $out[$k] = false;
                continue;
            }
            // leave numbers and other values as-is
            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * Lightweight fallback parser for common browsers and platforms.
     * This parser intentionally focuses on practical checks (name/version/platform/mobile/bot).
     */
    private function useFallbackParser(): void
    {
        $ua = $this->userAgent;
        $data = [
            'browser' => 'Unknown',
            'version' => null,
            'platform' => 'Unknown',
            'ismobiledevice' => false,
            'crawler' => false,
            // common capability defaults (unknown -> false)
            'javascript' => false,
            'cookies' => false,
            'frames' => false,
            'iframes' => false,
            'cssversion' => 0,
            // raw UA
            'user_agent' => $ua,
        ];

        // detect platform
        if (stripos($ua, 'windows phone') !== false) {
            $data['platform'] = 'Windows Phone';
            $data['ismobiledevice'] = true;
        } elseif (stripos($ua, 'windows') !== false) {
            $data['platform'] = 'Windows';
        } elseif (stripos($ua, 'android') !== false) {
            $data['platform'] = 'Android';
            $data['ismobiledevice'] = true;
        } elseif (
            stripos($ua, 'iphone') !== false ||
            stripos($ua, 'ipad') !== false ||
            stripos($ua, 'ipod') !== false
        ) {
            $data['platform'] = 'iOS';
            $data['ismobiledevice'] = true;
        } elseif (stripos($ua, 'mac os x') !== false || stripos($ua, 'macintosh') !== false) {
            $data['platform'] = 'Mac OS X';
        } elseif (stripos($ua, 'linux') !== false) {
            $data['platform'] = 'Linux';
        }

        // detect crawler / bot
        $botSignatures = [
            'bot',
            'crawler',
            'spider',
            'bingpreview',
            'slurp',
            'duckduckgo',
            'curl',
            'wget',
            'python-requests',
            'httpclient'
        ];
        foreach ($botSignatures as $sig) {
            if (stripos($ua, $sig) !== false) {
                $data['crawler'] = true;
                break;
            }
        }

        // detect common browsers and versions
        // Order matters: Edge/Edg first, then Chrome, then Safari, then Firefox, then Opera, then IE
        $patterns = [
            'Edge' => '/Edg\/([0-9\.]+)/i',
            'EdgeOld' => '/Edge\/([0-9\.]+)/i',
            'OPR' => '/OPR\/([0-9\.]+)/i', // Opera new
            'Opera' => '/Opera\/([0-9\.]+)/i',
            'Chrome' => '/Chrome\/([0-9\.]+)/i',
            'CriOS' => '/CriOS\/([0-9\.]+)/i', // Chrome on iOS
            'Firefox' => '/Firefox\/([0-9\.]+)/i',
            'SafariVersion' => '/Version\/([0-9\.]+).*Safari/i',
            'MSIE' => '/MSIE\s+([0-9\.]+)/i',
            'Trident' => '/Trident\/.*rv:([0-9\.]+)/i',
        ];

        foreach ($patterns as $name => $regex) {
            if (preg_match($regex, $ua, $m)) {
                $version = $m[1];
                switch ($name) {
                    case 'Edge':
                    case 'EdgeOld':
                        $data['browser'] = 'Edge';
                        $data['version'] = $version;
                        $data['javascript'] = true;
                        $data['cookies'] = true;
                        $data['frames'] = true;
                        break 2;
                    case 'OPR':
                    case 'Opera':
                        $data['browser'] = 'Opera';
                        $data['version'] = $version;
                        $data['javascript'] = true;
                        $data['cookies'] = true;
                        $data['frames'] = true;
                        break 2;
                    case 'CriOS':
                    case 'Chrome':
                        $data['browser'] = 'Chrome';
                        $data['version'] = $version;
                        $data['javascript'] = true;
                        $data['cookies'] = true;
                        $data['frames'] = true;
                        break 2;
                    case 'Firefox':
                        $data['browser'] = 'Firefox';
                        $data['version'] = $version;
                        $data['javascript'] = true;
                        $data['cookies'] = true;
                        $data['frames'] = true;
                        break 2;
                    case 'SafariVersion':
                        $data['browser'] = 'Safari';
                        $data['version'] = $version;
                        $data['javascript'] = true;
                        $data['cookies'] = true;
                        $data['frames'] = true;
                        break 2;
                    case 'MSIE':
                    case 'Trident':
                        $data['browser'] = 'Internet Explorer';
                        $data['version'] = $version;
                        // very old IE may have limited features
                        $data['javascript'] = true;
                        $data['cookies'] = true;
                        $data['frames'] = true;
                        break 2;
                }
            }
        }

        // heuristics for CSS version
        if (
            $data['browser'] === 'Chrome' ||
            $data['browser'] === 'Edge' ||
            $data['browser'] === 'Firefox' ||
            $data['browser'] === 'Opera' ||
            $data['browser'] === 'Safari'
        ) {
            $data['cssversion'] = 3;
            $data['iframes'] = true;
        }

        $data['user_agent'] = $ua;
        $this->data = $data;
    }

    /**
     * Get all detected properties as array.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Get named property or $default if not present.
     *
     * Common keys (when get_browser used):
     * browser, version, platform, javascript, cookies, cssversion, crawler, ismobiledevice
     *
     * @param mixed $default
     * @return mixed
     */
    public function get(string $name, mixed $default = null): mixed
    {
        return $this->data[$name] ?? $default;
    }

    /**
     * Check if browser name matches (case-insensitive).
     * Accepts partial matches: e.g. isBrowser('Chrome'), isBrowser('Chrom')
     */
    public function isBrowser(string $name): bool
    {
        $cur = (string) ($this->data['browser'] ?? ($this->data['browser_name_pattern'] ?? ''));
        if ($cur === '') {
            return false;
        }
        return stripos($cur, $name) !== false;
    }

    /**
     * Check platform equality/containment (case-insensitive)
     */
    public function isPlatform(string $platform): bool
    {
        $cur = (string) ($this->data['platform'] ?? '');
        if ($cur === '') {
            return false;
        }
        return stripos($cur, $platform) !== false;
    }

    /**
     * Check whether current browser version is >= (or other operator) given version.
     *
     * Example: isBrowserVersionAtLeast('Chrome', '80') -> bool
     *
     * @param string $browserName check only if name matches (partial, case-insensitive)
     * @param string $version version to compare against
     * @param string $operator any operator supported by version_compare (>, >=, <, <=, ==, !=)
     */
    public function isBrowserVersion(string $browserName, string $version, string $operator = '>='): bool
    {
        if (!$this->isBrowser($browserName)) {
            return false;
        }
        $curVer = (string) ($this->data['version'] ?? '');
        if ($curVer === '') {
            return false;
        }
        return version_compare($curVer, $version, $operator);
    }

    /**
     * Convenience: is version at least
     */
    public function isBrowserVersionAtLeast(string $browserName, string $version): bool
    {
        return $this->isBrowserVersion($browserName, $version, '>=');
    }

    /**
     * Check if device is mobile (heuristic)
     */
    public function isMobile(): bool
    {
        return (bool) ($this->data['ismobiledevice'] ?? false);
    }

    /**
     * Check if UA is known crawler/bot
     */
    public function isBot(): bool
    {
        return (bool) ($this->data['crawler'] ?? false) || (bool) ($this->data['is_robot'] ?? false);
    }

    /**
     * Detect browser name from User-Agent (fallback helper).
     */
    private function detectNameFromUA(): string
    {
        $ua = $this->userAgent;

        $map = [
            'Edg/' => 'Edge',
            'Edge/' => 'Edge',
            'OPR/' => 'Opera',
            'Opera' => 'Opera',
            'Chrome/' => 'Chrome',
            'CriOS/' => 'Chrome',
            'Firefox/' => 'Firefox',
            'Safari/' => 'Safari',
            'MSIE' => 'Internet Explorer',
            'Trident/' => 'Internet Explorer',
        ];

        foreach ($map as $needle => $browser) {
            if (stripos($ua, $needle) !== false) {
                return $browser;
            }
        }

        return 'Unknown';
    }


    /**
     * Generic capability check: see if header/property is present and truthy.
     * Example: supports('javascript'), supports('cookies'), supports('iframes')
     */
    public function supports(string $capability): bool
    {
        $k = strtolower($capability);
        // map some common aliases
        $aliases = [
            'js' => 'javascript',
            'iframes' => 'iframes',
            'iframe' => 'iframes',
            'cookies' => 'cookies',
            'css' => 'cssversion',
            'gzip' => 'supports_gzip',
        ];
        if (isset($aliases[$k])) {
            $k = $aliases[$k];
        }
        $val = $this->data[$k] ?? null;
        if ($val === null) {
            return false;
        }
        // boolean-ish
        if (\is_bool($val)) {
            return $val;
        }
        // numeric cssversion > 0 counts as support
        if (\is_numeric($val)) {
            return ((float)$val) > 0;
        }
        // string '1' or 'true'
        if (\is_string($val)) {
            $low = strtolower($val);
            return \in_array($low, ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    /**
     * Return a human readable summary.
     */
    public function summary(): string
    {
        $b = $this->data['browser'] ?? 'Unknown';
        $v = $this->data['version'] ?? '';
        $p = $this->data['platform'] ?? 'Unknown';
        $ua = $this->userAgent;
        return \sprintf('%s %s on %s (UA: %s)', $b, $v, $p, $ua);
    }

    /**
     * Force re-evaluation (e.g. after changing UA) - you can pass new UA.
     */
    public function refresh(?string $userAgent = null, bool $preferGetBrowser = true): void
    {
        if ($userAgent !== null) {
            $this->userAgent = $userAgent;
        }
        if ($preferGetBrowser && self::isGetBrowserAvailable()) {
            $this->useGetBrowser();
        } else {
            $this->useFallbackParser();
        }
    }

    /**
     * Simple debug print (array).
     */
    public function debugDump(): array
    {
        return $this->toArray();
    }

    public function __get($name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function __isset($name): bool
    {
        return isset($this->data[$name]);
    }

    public function __toString(): string
    {
        return $this->summary();
    }

    public function __destruct()
    {
        unset($this->data);
    }
}
