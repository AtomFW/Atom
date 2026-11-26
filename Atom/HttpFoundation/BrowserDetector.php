<?php

declare(strict_types=1);

namespace Atom\HttpFoundation;

use Stringable;
use Throwable;

/**
 * Stateless User-Agent browser & platform detector using native `get_browser()` when configured
 * or a fast PHP 8.6 fallback parser (`array_any`, `match`).
 */
final class BrowserDetector implements Stringable
{
    private const array BOT_SIGNATURES = [
        'bot',
        'crawler',
        'spider',
        'bingpreview',
        'slurp',
        'duckduckgo',
        'curl',
        'wget',
        'python-requests',
        'httpclient',
    ];

    private const array BROWSER_PATTERNS = [
        'Edge' => '/Edg\/([0-9\.]+)/i',
        'EdgeOld' => '/Edge\/([0-9\.]+)/i',
        'OPR' => '/OPR\/([0-9\.]+)/i',
        'Opera' => '/Opera\/([0-9\.]+)/i',
        'Chrome' => '/Chrome\/([0-9\.]+)/i',
        'CriOS' => '/CriOS\/([0-9\.]+)/i',
        'Firefox' => '/Firefox\/([0-9\.]+)/i',
        'SafariVersion' => '/Version\/([0-9\.]+).*Safari/i',
        'MSIE' => '/MSIE\s+([0-9\.]+)/i',
        'Trident' => '/Trident\/.*rv:([0-9\.]+)/i',
    ];

    /** @var array<string, mixed> */
    private array $data = [];

    private string $userAgent;

    public function __construct(?string $userAgent = null, bool $preferGetBrowser = true)
    {
        $this->userAgent = $userAgent ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($preferGetBrowser && self::isGetBrowserAvailable()) {
            $this->useGetBrowser();
        } else {
            $this->useFallbackParser();
        }
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public static function isGetBrowserAvailable(): bool
    {
        if (!\function_exists('get_browser')) {
            return false;
        }

        $browscap = \ini_get('browscap');
        return \is_string($browscap) && $browscap !== '';
    }

    public static function getBrowscapPath(): ?string
    {
        $val = \ini_get('browscap');
        return ($val === false || $val === '') ? null : $val;
    }

    private function useGetBrowser(): void
    {
        $result = false;
        \set_error_handler(static fn(): bool => true);
        try {
            $result = \get_browser($this->userAgent, true);
        } catch (Throwable) {
            $result = false;
        } finally {
            \restore_error_handler();
        }

        if (!\is_array($result) || $result === []) {
            $this->useFallbackParser();
            return;
        }

        $this->data = $this->normalizeGetBrowserArray($result);
        if (!isset($this->data['browser_name_pattern']) && !isset($this->data['browser'])) {
            $this->data['browser'] = $this->detectNameFromUA();
        }
    }

    /**
     * @param array<string, mixed> $arr
     * @return array<string, mixed>
     */
    private function normalizeGetBrowserArray(array $arr): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            $out[$k] = match ($v) {
                '1', 1, true => true,
                '0', 0, false => false,
                default => $v,
            };
        }

        return $out;
    }

    private function useFallbackParser(): void
    {
        $ua = $this->userAgent;
        $data = [
            'browser' => 'Unknown',
            'version' => null,
            'platform' => 'Unknown',
            'ismobiledevice' => false,
            'crawler' => false,
            'javascript' => false,
            'cookies' => false,
            'frames' => false,
            'iframes' => false,
            'cssversion' => 0,
            'user_agent' => $ua,
        ];

        if (\stripos($ua, 'windows phone') !== false) {
            $data['platform'] = 'Windows Phone';
            $data['ismobiledevice'] = true;
        } elseif (\stripos($ua, 'windows') !== false) {
            $data['platform'] = 'Windows';
        } elseif (\stripos($ua, 'android') !== false) {
            $data['platform'] = 'Android';
            $data['ismobiledevice'] = true;
        } elseif (\stripos($ua, 'iphone') !== false || \stripos($ua, 'ipad') !== false || \stripos($ua, 'ipod') !== false) {
            $data['platform'] = 'iOS';
            $data['ismobiledevice'] = true;
        } elseif (\stripos($ua, 'mac os x') !== false || \stripos($ua, 'macintosh') !== false) {
            $data['platform'] = 'Mac OS X';
        } elseif (\stripos($ua, 'linux') !== false) {
            $data['platform'] = 'Linux';
        }

        $data['crawler'] = \array_any(
            self::BOT_SIGNATURES,
            static fn(string $sig): bool => \stripos($ua, $sig) !== false
        );

        foreach (self::BROWSER_PATTERNS as $name => $regex) {
            if (\preg_match($regex, $ua, $m) === 1) {
                $data['browser'] = match ($name) {
                    'Edge', 'EdgeOld' => 'Edge',
                    'OPR', 'Opera' => 'Opera',
                    'CriOS', 'Chrome' => 'Chrome',
                    'Firefox' => 'Firefox',
                    'SafariVersion' => 'Safari',
                    'MSIE', 'Trident' => 'Internet Explorer',
                };
                $data['version'] = $m[1];
                $data['javascript'] = true;
                $data['cookies'] = true;
                $data['frames'] = true;
                break;
            }
        }

        if (\in_array($data['browser'], ['Chrome', 'Edge', 'Firefox', 'Opera', 'Safari'], true)) {
            $data['cssversion'] = 3;
            $data['iframes'] = true;
        }

        $this->data = $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->data[$name] ?? $default;
    }

    public function isBrowser(string $name): bool
    {
        $cur = (string) ($this->data['browser'] ?? ($this->data['browser_name_pattern'] ?? ''));
        return $cur !== '' && \stripos($cur, $name) !== false;
    }

    public function isPlatform(string $platform): bool
    {
        $cur = (string) ($this->data['platform'] ?? '');
        return $cur !== '' && \stripos($cur, $platform) !== false;
    }

    public function isBrowserVersion(string $browserName, string $version, string $operator = '>='): bool
    {
        if (!$this->isBrowser($browserName)) {
            return false;
        }

        $curVer = (string) ($this->data['version'] ?? '');
        return $curVer !== '' && (bool) \version_compare($curVer, $version, $operator);
    }

    public function isBrowserVersionAtLeast(string $browserName, string $version): bool
    {
        return $this->isBrowserVersion($browserName, $version, '>=');
    }

    public function isMobile(): bool
    {
        return (bool) ($this->data['ismobiledevice'] ?? false);
    }

    public function isBot(): bool
    {
        return (bool) ($this->data['crawler'] ?? false) || (bool) ($this->data['is_robot'] ?? false);
    }

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
            if (\stripos($ua, $needle) !== false) {
                return $browser;
            }
        }

        return 'Unknown';
    }

    public function supports(string $capability): bool
    {
        $key = match (\strtolower($capability)) {
            'js' => 'javascript',
            'iframe', 'iframes' => 'iframes',
            'cookies' => 'cookies',
            'css' => 'cssversion',
            'gzip' => 'supports_gzip',
            default => \strtolower($capability),
        };

        $val = $this->data[$key] ?? null;
        if ($val === null) {
            return false;
        }
        if (\is_bool($val)) {
            return $val;
        }
        if (\is_numeric($val)) {
            return ((float) $val) > 0;
        }
        if (\is_string($val)) {
            return \in_array(\strtolower($val), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    public function summary(): string
    {
        $b = (string) ($this->data['browser'] ?? 'Unknown');
        $v = (string) ($this->data['version'] ?? '');
        $p = (string) ($this->data['platform'] ?? 'Unknown');

        return \sprintf('%s %s on %s (UA: %s)', $b, $v, $p, $this->userAgent);
    }

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
     * @return array<string, mixed>
     */
    public function debugDump(): array
    {
        return $this->toArray();
    }

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }

    public function __toString(): string
    {
        return $this->summary();
    }
}
