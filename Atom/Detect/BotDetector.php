<?php

declare(strict_types=1);

namespace Atom\Detect;

use ReflectionFunction;
use Throwable;
use Jaybizzle\CrawlerDetect\CrawlerDetect;
use Jaybizzle\CrawlerDetect\CrawlerDetectInterface;
use Atom\Log\T4LOG;

/**
 * BotDetector
 *
 * - Loads an optional is_bot.php (require_once) into a static slot (only once).
 * - Optionally (when $extends === true) attempts to load CrawlerDetect via Composer
 *   and keep an instance in a static property (only once).
 * - Primary detection order:
 *     1) is_bot() (function from is_bot.php) -> if returns truthy, treat as bot
 *     2) if extends enabled and CrawlerDetect available -> use it
 *     3) fallback UA heuristics (simple)
 *
 * Comments in English inside code.
 */
final class BotDetector
{
    /** @var string Current user agent string used by instance */
    private string $userAgent;

    /** @var bool Whether this instance will attempt to use CrawlerDetect (if available) */
    private bool $extends;

    /** @var bool Whether CrawlerDetect load was attempted */
    private static bool $crawlerDetectTried = false;

    /** @var object|null Static instance of CrawlerDetect if available (actual type depends on package) */
    private static ?object $crawlerDetectInstance = null;

    /** @var bool Whether is_bot file load was attempted */
    private static bool $isBotFileTried = false;

    /** @var string|null Path of loaded is_bot.php (if loaded) */
    private static ?string $isBotFilePath = null;

    /** @var callable|null Callable wrapper to is_bot function if present (accepts UA or none) */
    private static $isBotCallable = null;

    /** @var T4LOG Logger */
    private static T4LOG $logger;

    /**
     * Constructor.
     *
     * @param string|null $userAgent User-Agent string; if null, uses $_SERVER['HTTP_USER_AGENT'] or empty string
     * @param bool $extends If true, attempt to load and use CrawlerDetect (composer) when required
     * @param string|null $isBotFile Path to is_bot.php (optional). If null,
     * no file is auto-loaded here (but can be loaded later via static loadIsBotFile()).
     */
    public function __construct(
        T4LOG $logger,
        ?string $userAgent = null,
        bool $extends = false,
        ?string $isBotFile = "Lib/is_bot.php"
    ) {
        static::$logger = $logger;

        $this->userAgent = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $this->extends = $extends;

        // Load is_bot file now if provided and not loaded yet
        if ($isBotFile !== null) {
            self::loadIsBotFile($isBotFile);
        }

        // If extends requested, attempt to load CrawlerDetect (only once)
        if ($this->extends) {
            self::loadCrawlerDetectIfNeeded();
        }
    }

    // -------------------------
    // Static loaders / inspectors
    // -------------------------

    /**
     * Safely require_once an is_bot.php file and detect a callable function.
     * Returns true if a usable is_bot function was found after loading.
     *
     * The function expected name is "is_bot" by default. If the file defines a different function
     * you can optionally pass the function name via $functionName.
     *
     * @param string $path
     * @param string|null $functionName
     * @return bool
     */
    private static function loadIsBotFile(string $path, ?string $functionName = 'is_bot'): bool
    {
        // If already tried and path matches, return current status
        if (static::$isBotFileTried && static::$isBotFilePath === $path && static::$isBotCallable !== null) {
            return true;
        }
        // Mark tried so we don't repeatedly attempt expensive includes
        static::$isBotFileTried = true;

        // Basic sanity: check filesystem first to avoid warnings
        if (!is_file($path) || !is_readable($path)) {
            // do not throw; silent failure but mark path
            static::$isBotFilePath = null;
            static::$isBotCallable = null;
            static::$logger->warning("BotDetector: is_bot file not found or not readable at path: {$path}");
            return false;
        }

        // attempt to include the file once, suppressing warnings from include if any
        try {
            /** @noinspection PhpIncludeInspection */
            @require_once $path;
        } catch (Throwable $th) {
            // include failure, treat as unavailable
            static::$isBotFilePath = null;
            static::$isBotCallable = null;
            static::$logger->warning(
                "BotDetector: Failed to include is_bot file at path: {$path}",
                ["exception" => $th]
            );
            return false;
        }

        // verify function existence
        if ($functionName !== null && \is_string($functionName) && function_exists($functionName)) {
            // wrap with callable that inspects signature (UA param optional)
            $rf = new ReflectionFunction($functionName);
            $params = $rf->getNumberOfParameters();
            if ($params >= 1) {
                static::$isBotCallable = function (?string $ua = null) use ($functionName) {
                    // call with UA (or null)
                    return (bool) $functionName($ua);
                };
            } else {
                static::$isBotCallable = function (?string $ua = null) use ($functionName) {
                    // call without args
                    return (bool) $functionName();
                };
            }
            static::$isBotFilePath = $path;
            return true;
        }

        // If function not found as expected, leave as null
        static::$isBotFilePath = null;
        static::$isBotCallable = null;
        return false;
    }

    /**
     * Returns true if is_bot() callable is available (loaded).
     */
    public static function isIsBotFileLoaded(): bool
    {
        return static::$isBotCallable !== null;
    }

    /**
     * Attempt to autoload Composer (searches typical vendor/autoload.php locations) and instantiate CrawlerDetect.
     * Returns true if instance is available.
     */
    private static function loadCrawlerDetectIfNeeded(): bool
    {
        if (static::$crawlerDetectTried) {
            return static::$crawlerDetectInstance !== null;
        }

        static::$crawlerDetectTried = true;

        try {
            static::$crawlerDetectInstance = new CrawlerDetect();
            return true;
        } catch (Throwable $th) {
            static::$crawlerDetectInstance = null;
            static::$logger->warning(
                "BotDetector: CrawlerDetect class not available." .
                "Bot detection will be limited to is_bot file and heuristics.",
                ["exception" => $th]
            );
            return false;
        }
    }

    /**
     * Returns whether CrawlerDetect instance is available.
     */
    public static function isCrawlerDetectAvailable(): bool
    {
        return static::$crawlerDetectInstance !== null;
    }

    /**
     * Return the path of the loaded is_bot file, or null.
     */
    public static function getIsBotFilePath(): ?string
    {
        return static::$isBotFilePath;
    }

    // -------------------------
    // Instance detection methods
    // -------------------------

    /**
     * Primary method: is this UA a bot?
     *
     * Logic:
     *  - If is_bot callable available, call it. If it returns truthy -> true.
     *  - If it returns falsy, and extends === true and CrawlerDetect available -> use CrawlerDetect->isCrawler(UA)
     *  - If neither available, fallback to light heuristic (checks for common bot keywords).
     *
     * @return bool
     */
    public function isBot(): bool
    {
        $ua = $this->userAgent;

        // 1) is_bot() from file
        if (static::$isBotCallable !== null) {
            try {
                $res = (bool) \call_user_func(static::$isBotCallable, $ua);
                if ($res === true) {
                    return true;
                }
                // if false, continue to other detectors only if allowed
            } catch (Throwable $th) {
                // if the callable throws, log and continue to other detectors
                static::$logger->warning(
                    "BotDetector: is_bot callable threw an exception. Continuing to other detection methods.",
                    ["exception" => $th]
                );
            }
        }

        // 2) CrawlerDetect (if allowed for this instance)
        if ($this->extends && static::$crawlerDetectInstance !== null) {
            try {
                // Many versions of CrawlerDetect provide isCrawler($ua) or isCrawler() with UA considered
                $inst = static::$crawlerDetectInstance;
                if (method_exists($inst, 'isCrawler')) {
                    // some implementations accept UA as first arg (newer versions) or use server globals
                    $ref = new \ReflectionMethod($inst, 'isCrawler');
                    if ($ref->getNumberOfParameters() >= 1) {
                        $res = (bool) $inst->isCrawler($ua);
                    } else {
                        $res = (bool) $inst->isCrawler();
                    }
                    if ($res === true) {
                        return true;
                    }
                }
            } catch (Throwable $th) {
                // if CrawlerDetect throws, log and continue to heuristic
                static::$logger->warning(
                    "BotDetector: CrawlerDetect threw an exception. Continuing to heuristic detection.",
                    ["exception" => $th]
                );
            }
        }

        // 3) fallback heuristic: check for bot keywords in UA
        return $this->heuristicIsBot($ua);
    }

    /**
     * Convenience alias: not bot => human
     */
    public function isHuman(): bool
    {
        return !$this->isBot();
    }

    /**
     * Try to detect the bot's canonical name (if any).
     *
     * - First tries CrawlerDetect (if available) for a match or name (best-effort).
     * - Then attempts to call is_bot callable which might also identify names (not standardized).
     * - Finally uses UA pattern matching for common bots.
     *
     * @return string|null Bot name if detected, null otherwise
     */
    public function detectBotName(): ?string
    {
        $ua = $this->userAgent;

        // 1) try CrawlerDetect (it may provide getMatches/getInfo depending on version)
        if ($this->extends && static::$crawlerDetectInstance !== null) {
            $inst = static::$crawlerDetectInstance;
            // some versions expose getMatches() which returns array of matched patterns
            if (method_exists($inst, 'getMatches')) {
                try {
                    $matches = $inst->getMatches($ua);
                    if (!empty($matches)) {
                        // return first key or combined representation
                        if (\is_array($matches)) {
                            $first = reset($matches);
                            if (\is_string($first) && $first !== '') {
                                return $first;
                            }
                        }
                    }
                } catch (Throwable $th) {
                    // ignore and continue
                    static::$logger->warning(
                        "BotDetector: CrawlerDetect getMatches threw an exception." .
                        "Continuing to other detection methods.",
                        ["exception" => $th]
                    );
                }
            }
            // some variants have getResult or getProvider - not standard; skip if not present
        }

        // 2) attempt simple mapping via known bot substrings
        $known = [
            'Googlebot' => ['Googlebot', 'Google-Structured-Data-Testing-Tool'],
            'Bingbot'   => ['bingbot', 'msnbot'],
            'Slurp'     => ['Slurp'],
            'DuckDuckBot' => ['DuckDuckBot'],
            'Baiduspider' => ['Baiduspider'],
            'YandexBot' => ['YandexBot'],
            'FacebookExternalHit' => ['facebookexternalhit', 'Facebot'],
            'Twitterbot' => ['Twitterbot'],
            'curl' => ['curl/'],
            'Wget' => ['Wget/'],
        ];

        foreach ($known as $name => $signatures) {
            foreach ($signatures as $sig) {
                if (stripos($ua, $sig) !== false) {
                    return $name;
                }
            }
        }

        // 3) Not detected
        return null;
    }

    /**
     * Heuristic check for bots: search for common bot tokens.
     *
     * @param string $ua
     * @return bool
     */
    private function heuristicIsBot(string $ua): bool
    {
        if ($ua === '') {
            return false;
        }
        $tokens = [
            'bot', 'crawler', 'spider', 'archiver', 'transcoder', 'validator',
            'fetcher', 'python-requests', 'httpclient', 'java/', 'curl', 'wget',
            'facebookexternalhit', 'bingpreview', 'slurp', 'mediapartners-google'
        ];
        $uaLow = strtolower($ua);
        foreach ($tokens as $t) {
            if (strpos($uaLow, $t) !== false) {
                return true;
            }
        }
        return false;
    }

    // -------------------------
    // Utility methods
    // -------------------------

    /**
     * Change user agent for this detector and optionally refresh loaders.
     */
    public function setUserAgent(string $ua, bool $refreshLoaders = false): void
    {
        $this->userAgent = $ua;
        if ($refreshLoaders && $this->extends) {
            self::loadCrawlerDetectIfNeeded();
        }
    }

    /**
     * Get the user agent string for the current request
     *
     * @return string The user agent string from the HTTP headers
     */
    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    /**
     * Force attempt to (re)load CrawlerDetect and/or is_bot file.
     * Useful for testing or if autoloader was registered later.
     *
     * @param string|null $isBotFilePath optional path to is_bot.php to (re)load
     * @param bool $forceReload whether to force reload even if tried before
     * @return void
     */
    public function refreshLoads(?string $isBotFilePath = null, bool $forceReload = false): void
    {
        if ($forceReload) {
            static::$crawlerDetectTried = false;
            static::$isBotFileTried = false;
            static::$isBotCallable = null;
            static::$isBotFilePath = null;
            static::$crawlerDetectInstance = null;
        }

        if ($isBotFilePath !== null) {
            self::loadIsBotFile($isBotFilePath);
        }

        if ($this->extends) {
            self::loadCrawlerDetectIfNeeded();
        }
    }

    /**
     * Return a debug array showing current internal state (safe for logging).
     *
     * Be careful not to log sensitive UA in production logs unless necessary.
     *
     * @return array<string,mixed>
     */
    public function debugState(): array
    {
        return [
            'ua' => $this->userAgent,
            'is_bot_file_loaded' => self::isIsBotFileLoaded(),
            'is_bot_file_path' => static::getIsBotFilePath(),
            'crawler_detect_available' => self::isCrawlerDetectAvailable(),
            'extends_flag' => $this->extends,
            'crawler_detect_class' => static::$crawlerDetectInstance ? get_class(static::$crawlerDetectInstance) : null,
        ];
    }

    /**
     * Return which detector would be used first (string)
     */
    public function preferredDetector(): string
    {
        if (self::isIsBotFileLoaded()) {
            return 'is_bot_file';
        }
        if ($this->extends && self::isCrawlerDetectAvailable()) {
            return 'crawler_detect';
        }
        return 'heuristic';
    }
}
