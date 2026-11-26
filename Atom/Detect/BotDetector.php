<?php

declare(strict_types=1);

namespace Atom\Detect;

use Atom\Log\T4LOG;
use Jaybizzle\CrawlerDetect\CrawlerDetect;
use ReflectionFunction;
use ReflectionMethod;
use Throwable;

/**
 * Stateless User-Agent bot & crawler detector using optional `is_bot.php`, `CrawlerDetect`,
 * and fast PHP 8.6 heuristics (`array_any`, `array_first`).
 */
final class BotDetector
{
    private const array HEURISTIC_TOKENS = [
        'bot',
        'crawler',
        'spider',
        'archiver',
        'transcoder',
        'validator',
        'fetcher',
        'python-requests',
        'httpclient',
        'java/',
        'curl',
        'wget',
        'facebookexternalhit',
        'bingpreview',
        'slurp',
        'mediapartners-google',
    ];

    private const array KNOWN_BOTS = [
        'Googlebot' => ['Googlebot', 'Google-Structured-Data-Testing-Tool'],
        'Bingbot' => ['bingbot', 'msnbot'],
        'Slurp' => ['Slurp'],
        'DuckDuckBot' => ['DuckDuckBot'],
        'Baiduspider' => ['Baiduspider'],
        'YandexBot' => ['YandexBot'],
        'FacebookExternalHit' => ['facebookexternalhit', 'Facebot'],
        'Twitterbot' => ['Twitterbot'],
        'curl' => ['curl/'],
        'Wget' => ['Wget/'],
    ];

    private string $userAgent;
    private bool $extends;
    private T4LOG $logger;

    private static bool $crawlerDetectTried = false;
    private static ?object $crawlerDetectInstance = null;
    private static bool $isBotFileTried = false;
    private static ?string $isBotFilePath = null;

    /** @var callable(?string): bool|null */
    private static $isBotCallable = null;

    public function __construct(
        T4LOG $logger,
        ?string $userAgent = null,
        bool $extends = false,
        ?string $isBotFile = 'Lib/is_bot.php',
    ) {
        $this->logger = $logger;
        $this->userAgent = $userAgent ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $this->extends = $extends;

        if ($isBotFile !== null) {
            $this->loadIsBotFile($isBotFile);
        }

        if ($this->extends) {
            $this->loadCrawlerDetectIfNeeded();
        }
    }

    private function loadIsBotFile(string $path, ?string $functionName = 'is_bot'): bool
    {
        if (self::$isBotFileTried && self::$isBotFilePath === $path && self::$isBotCallable !== null) {
            return true;
        }

        self::$isBotFileTried = true;

        if (!\is_file($path) || !\is_readable($path)) {
            self::$isBotFilePath = null;
            self::$isBotCallable = null;
            $this->logger->warning("BotDetector: is_bot file not found or not readable at path: {$path}");
            return false;
        }

        try {
            require_once $path;
        } catch (Throwable $th) {
            self::$isBotFilePath = null;
            self::$isBotCallable = null;
            $this->logger->warning(
                "BotDetector: Failed to include is_bot file at path: {$path}",
                ['exception' => $th]
            );
            return false;
        }

        if ($functionName !== null && $functionName !== '' && \function_exists($functionName)) {
            $rf = new ReflectionFunction($functionName);
            $params = $rf->getNumberOfParameters();
            self::$isBotCallable = $params >= 1
                ? static fn(?string $ua = null): bool => (bool) $functionName($ua)
                : static fn(?string $ua = null): bool => (bool) $functionName();
            self::$isBotFilePath = $path;
            return true;
        }

        self::$isBotFilePath = null;
        self::$isBotCallable = null;
        return false;
    }

    public static function isIsBotFileLoaded(): bool
    {
        return self::$isBotCallable !== null;
    }

    private function loadCrawlerDetectIfNeeded(): bool
    {
        if (self::$crawlerDetectTried) {
            return self::$crawlerDetectInstance !== null;
        }

        self::$crawlerDetectTried = true;

        try {
            self::$crawlerDetectInstance = new CrawlerDetect();
            return true;
        } catch (Throwable $th) {
            self::$crawlerDetectInstance = null;
            $this->logger->warning(
                'BotDetector: CrawlerDetect class not available. Bot detection limited to is_bot file and heuristics.',
                ['exception' => $th]
            );
            return false;
        }
    }

    public static function isCrawlerDetectAvailable(): bool
    {
        return self::$crawlerDetectInstance !== null;
    }

    public static function getIsBotFilePath(): ?string
    {
        return self::$isBotFilePath;
    }

    public function isBot(): bool
    {
        $ua = $this->userAgent;

        if (self::$isBotCallable !== null) {
            try {
                if ((self::$isBotCallable)($ua) === true) {
                    return true;
                }
            } catch (Throwable $th) {
                $this->logger->warning(
                    'BotDetector: is_bot callable threw an exception. Continuing to other detection methods.',
                    ['exception' => $th]
                );
            }
        }

        if ($this->extends && self::$crawlerDetectInstance !== null) {
            try {
                $inst = self::$crawlerDetectInstance;
                if (\method_exists($inst, 'isCrawler')) {
                    $ref = new ReflectionMethod($inst, 'isCrawler');
                    $res = $ref->getNumberOfParameters() >= 1
                        ? (bool) $inst->isCrawler($ua)
                        : (bool) $inst->isCrawler();
                    if ($res === true) {
                        return true;
                    }
                }
            } catch (Throwable $th) {
                $this->logger->warning(
                    'BotDetector: CrawlerDetect threw an exception. Continuing to heuristic detection.',
                    ['exception' => $th]
                );
            }
        }

        return $this->heuristicIsBot($ua);
    }

    public function isHuman(): bool
    {
        return !$this->isBot();
    }

    public function detectBotName(): ?string
    {
        $ua = $this->userAgent;

        if ($this->extends && self::$crawlerDetectInstance !== null) {
            $inst = self::$crawlerDetectInstance;
            if (\method_exists($inst, 'getMatches')) {
                try {
                    $matches = $inst->getMatches($ua);
                    if (\is_array($matches) && $matches !== []) {
                        $first = \array_first($matches);
                        if (\is_string($first) && $first !== '') {
                            return $first;
                        }
                    } elseif (\is_string($matches) && $matches !== '') {
                        return $matches;
                    }
                } catch (Throwable $th) {
                    $this->logger->warning(
                        'BotDetector: CrawlerDetect getMatches threw an exception.',
                        ['exception' => $th]
                    );
                }
            }
        }

        foreach (self::KNOWN_BOTS as $name => $signatures) {
            $matched = \array_any(
                $signatures,
                static fn(string $sig): bool => \stripos($ua, $sig) !== false
            );
            if ($matched) {
                return $name;
            }
        }

        return null;
    }

    private function heuristicIsBot(string $ua): bool
    {
        if ($ua === '') {
            return false;
        }

        $uaLow = \strtolower($ua);
        return \array_any(
            self::HEURISTIC_TOKENS,
            static fn(string $token): bool => \str_contains($uaLow, $token)
        );
    }

    public function setUserAgent(string $ua, bool $refreshLoaders = false): void
    {
        $this->userAgent = $ua;
        if ($refreshLoaders && $this->extends) {
            $this->loadCrawlerDetectIfNeeded();
        }
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function refreshLoads(?string $isBotFilePath = null, bool $forceReload = false): void
    {
        if ($forceReload) {
            self::$crawlerDetectTried = false;
            self::$isBotFileTried = false;
            self::$isBotCallable = null;
            self::$isBotFilePath = null;
            self::$crawlerDetectInstance = null;
        }

        if ($isBotFilePath !== null) {
            $this->loadIsBotFile($isBotFilePath);
        }

        if ($this->extends) {
            $this->loadCrawlerDetectIfNeeded();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function debugState(): array
    {
        return [
            'ua' => $this->userAgent,
            'is_bot_file_loaded' => self::isIsBotFileLoaded(),
            'is_bot_file_path' => self::getIsBotFilePath(),
            'crawler_detect_available' => self::isCrawlerDetectAvailable(),
            'extends_flag' => $this->extends,
            'crawler_detect_class' => self::$crawlerDetectInstance !== null ? self::$crawlerDetectInstance::class : null,
        ];
    }

    /**
     * @return array{isBot: bool, isHuman: bool, ua: string, detectBotName: string|null}
     */
    public function toArray(): array
    {
        return [
            'isBot' => $this->isBot(),
            'isHuman' => $this->isHuman(),
            'ua' => $this->userAgent,
            'detectBotName' => $this->detectBotName(),
        ];
    }

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
