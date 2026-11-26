<?php

declare(strict_types=1);

namespace Atom\Log;

use ErrorException;
use Override;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;
use TypeError;

/**
 * PSR-3 logger writing structured application & developer logs into `runtime/log`
 * with lazy placeholder interpolation and automatic secret redaction.
 */
class T4LOG implements LoggerInterface
{
    public const int PRIORITY_EMERG = 0;
    public const int PRIORITY_ALERT = \LOG_ALERT;
    public const int PRIORITY_CRIT = 2;
    public const int PRIORITY_ERR = \LOG_ERR;
    public const int PRIORITY_WARNING = \LOG_WARNING;
    public const int PRIORITY_NOTICE = \LOG_NOTICE;
    public const int PRIORITY_DEV = 44;
    public const int PRIORITY_DEFAULT = \LOG_ALERT;
    public const int PRIORITY_NORMAL = \LOG_NOTICE;
    public const int PRIORITY_INFO = 3;
    public const int PRIORITY_DEBUG = 40;

    private const array SENSITIVE_TOKENS = [
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'auth',
        'cookie',
    ];

    private string $datetime = \DATE_ATOM;
    private string $datetimeFile = 'd_m_Y';
    private int $priority = self::PRIORITY_DEFAULT;

    public static string $LOG_ROOT_DIR = __DIR__ . \DIRECTORY_SEPARATOR . '..' . \DIRECTORY_SEPARATOR . '..' . \DIRECTORY_SEPARATOR . 'runtime' . \DIRECTORY_SEPARATOR . 'log' . \DIRECTORY_SEPARATOR;
    public static string $LOG_ROOT_DEV_DIR = 'dev' . \DIRECTORY_SEPARATOR;

    protected string $after = '';
    protected string $before = '';
    protected string $message = 'APP:LOG >>';
    protected string $sepparator = '-';
    protected string $logScheme =
        '{{datetime}} {{sepparator}} [{{prefix}}] {{before}} {{message}} {{after}} {{sepparator}} {{file}}::{{line}}'
        . ' |{{ip}}| {{sepparator}} {{php_version}} {{sepparator}} {{system_user}}/{{os}}/{{os_family}}';
    protected string $logFileScheme = '{{date_file}}_{{php_version}}.log';
    protected string $logFileDevScheme = '{{date_file}}_{{php_version}}.t4log';
    protected string $startScheme =
        '{{datetime}} {{sepparator}} [{{prefix}}][START:APP] {{before}} {{message}} {{after}} {{sepparator}} '
        . '{{php_version}}::{{php_version_id}} {{sepparator}} {{system_user}}/{{os}}/{{os_family}} {{sepparator}} '
        . '{{php_config_file_path}} {{pear_install_dir}}';
    protected string $stopScheme =
        '{{datetime}} {{sepparator}} [{{prefix}}][STOP:APP] {{before}} {{message}} {{after}} {{sepparator}} '
        . '{{php_version}}::{{php_version_id}} {{sepparator}} {{system_user}}/{{os}}/{{os_family}} {{sepparator}} '
        . '{{php_config_file_path}} {{pear_install_dir}}';

    /** @var list<array<string, mixed>> */
    private array $params = [];
    private string $nameLogFile;
    private string $nameLogFileT4LOG;
    private bool|string $systemLog = false;
    private bool $systemLogOpened = false;

    private string $currentLogPath;
    private string $currentDevLogPath;

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(array $params = [])
    {
        if (\array_key_exists('systemLog', $params)) {
            $this->systemLog = \is_string($params['systemLog']) ? $params['systemLog'] : (bool) $params['systemLog'];
            if ($this->systemLog !== false) {
                $this->systemLogOpened = \openlog($this->message, \LOG_ODELAY | \LOG_CONS | \LOG_PID, \LOG_USER);
            }
        }

        if (\array_key_exists('priority', $params)) {
            $this->priority = (int) $params['priority'];
        }

        if (\array_key_exists('logRootDir', $params)) {
            self::$LOG_ROOT_DIR = (string) $params['logRootDir'];
        }

        if (\array_key_exists('logRootDevDir', $params)) {
            self::$LOG_ROOT_DEV_DIR = (string) $params['logRootDevDir'];
        }

        if (\array_key_exists('message', $params)) {
            $this->message = (string) $params['message'];
        }

        $this->datetimeFile = $this->santanizeFileName(\date($this->datetimeFile));

        $this->nameLogFile = \array_key_exists('logFileScheme', $params)
            ? (string) $params['logFileScheme']
            : (string) $this->paramParse($this->logFileScheme);

        $this->nameLogFileT4LOG = \array_key_exists('logFilesDevCheme', $params)
            ? (string) $params['logFilesDevCheme']
            : (string) $this->paramParse($this->logFileDevScheme);

        $resolvedRootDir = \rtrim((string) $this->paramParse(self::$LOG_ROOT_DIR), '/\\') . \DIRECTORY_SEPARATOR;
        $resolvedDevSubDir = \rtrim((string) $this->paramParse(self::$LOG_ROOT_DEV_DIR), '/\\') . \DIRECTORY_SEPARATOR;

        $this->currentDevLogPath = $resolvedRootDir . $resolvedDevSubDir . $this->filterFillName($this->nameLogFileT4LOG);
        $this->currentLogPath = $resolvedRootDir . $this->filterFillName($this->nameLogFile);

        if (($params['autoLogStartStopConnection'] ?? false) === true) {
            $this->t4LOGLooger('START');
        }

        $this->saveLog(self::PRIORITY_INFO, '', [], $this->startScheme);
    }

    public function logT(int $priority, string $message): self
    {
        $this->on($priority, $message);
        return $this;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function on(int $priority, string|Stringable $message, array $context = []): void
    {
        $this->saveLog($priority, (string) $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $mappedPriority = \is_int($level) && $this->validatePriority($level)
            ? $level
            : match (\strtolower((string) $level)) {
                'emergency', 'emerg' => self::PRIORITY_EMERG,
                'alert' => self::PRIORITY_ALERT,
                'critical', 'crit' => self::PRIORITY_CRIT,
                'error', 'err' => self::PRIORITY_ERR,
                'warning', 'warn' => self::PRIORITY_WARNING,
                'info' => self::PRIORITY_INFO,
                'debug' => self::PRIORITY_DEBUG,
                default => self::PRIORITY_NORMAL,
            };

        $this->on($mappedPriority, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    #[Override]
    public function info(string|Stringable $message, array $context = []): void
    {
        $this->on(self::PRIORITY_INFO, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    #[Override]
    public function error(string|Stringable $message, array $context = []): void
    {
        $this->on(self::PRIORITY_ERR, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function sys(int $facility, string|Stringable $message, array $context = []): void
    {
        if ($this->systemLog === false) {
            $this->systemLogOpened = \openlog($this->message, \LOG_ODELAY | \LOG_CONS | \LOG_PID, $facility);
        }

        $this->saveLog($facility, (string) $message, $context, nativeSystemLog: true);
    }

    /**
     * @param array<string, mixed> $context
     */
    #[Override]
    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->on(self::PRIORITY_DEBUG, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    #[Override]
    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->on(self::PRIORITY_EMERG, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function emerg(string|Stringable $message, array $context = []): void
    {
        $this->emergency($message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    #[Override]
    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->on(self::PRIORITY_ALERT, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    #[Override]
    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->on(self::PRIORITY_CRIT, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function crit(string|Stringable $message, array $context = []): void
    {
        $this->critical($message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function err(string|Stringable $message, array $context = []): void
    {
        $this->error($message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    #[Override]
    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->on(self::PRIORITY_WARNING, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    #[Override]
    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->on(self::PRIORITY_NOTICE, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function dev(string|Stringable $message, array $context = []): void
    {
        $this->on(self::PRIORITY_DEV, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function saveLog(
        int $priority,
        string $message,
        array $context = [],
        ?string $scheme = null,
        ?bool $stering = null,
        bool $nativeSystemLog = false,
    ): bool {
        try {
            $template = $scheme ?? $this->logScheme;
            $rendered = $this->paramParse($template, $priority, $message);

            if (!\is_string($rendered)) {
                throw new ErrorException('paramParse method return false', 1);
            }

            $rendered = $this->contextParse($rendered, $context);
            $path = !\is_bool($stering) ? $this->currentLogPath : $this->currentDevLogPath;
            $path = $this->filterFillName($path);

            if ($nativeSystemLog && \is_string($this->systemLog)) {
                if (!$this->validatePriority($priority)) {
                    throw new TypeError('Priority type is not valid', 1);
                }

                \syslog($priority, $rendered);
                return true;
            }

            $dir = \dirname($path);
            if (!\is_dir($dir) && !\mkdir($dir, 0750, true) && !\is_dir($dir)) {
                return false;
            }

            $written = \file_put_contents($path, $rendered . \PHP_EOL, \FILE_APPEND | \LOCK_EX);
            if ($written === false) {
                throw new ErrorException('file_put_contents method return false', 1);
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    protected function paramParse(string $log, ?int $priority = null, ?string $message = null): string|bool
    {
        try {
            $needsTrace = \str_contains($log, '{{function}}')
                || \str_contains($log, '{{line}}')
                || \str_contains($log, '{{file}}')
                || \str_contains($log, '{{class}}');

            $debugTrace = ['function' => 'main', 'line' => 'null', 'file' => 'null', 'class' => 'null'];
            if ($needsTrace) {
                $frames = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 4);
                $frame = $frames[2] ?? ($frames[1] ?? []);
                $debugTrace = [
                    'function' => (string) ($frame['function'] ?? 'main'),
                    'line' => (string) ($frame['line'] ?? 'null'),
                    'file' => (string) ($frame['file'] ?? 'null'),
                    'class' => (string) ($frame['class'] ?? 'null'),
                ];
            }

            $serverJson = \str_contains($log, '{{server}}')
                ? (\json_encode(self::redactMap($_SERVER), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '[]')
                : '[]';

            $headersJson = (\str_contains($log, '{{getallheaders}}') && \PHP_SAPI !== 'cli' && \function_exists('getallheaders'))
                ? (\json_encode(self::redactMap((array) \getallheaders()), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '[]')
                : '[]';

            return \str_replace(
                [
                    '{{datetime}}',
                    '{{sepparator}}',
                    '{{after}}',
                    '{{before}}',
                    '{{message}}',
                    '{{function}}',
                    '{{line}}',
                    '{{file}}',
                    '{{class}}',
                    '{{php_version}}',
                    '{{system_user}}',
                    '{{prefix}}',
                    '{{os}}',
                    '{{os_family}}',
                    '{{php_sapi}}',
                    '{{php_binary}}',
                    '{{php_prefix}}',
                    '{{php_datadir}}',
                    '{{php_extension_dir}}',
                    '{{php_extra_version}}',
                    '{{php_session_active}}',
                    '{{php_release_version}}',
                    '{{php_major_version}}',
                    '{{php_minor_version}}',
                    '{{php_version_id}}',
                    '{{default_include_path}}',
                    '{{pear_install_dir}}',
                    '{{pear_extension_dir}}',
                    '{{php_libdir}}',
                    '{{php_sysconfdir}}',
                    '{{php_localstatedir}}',
                    '{{php_config_file_path}}',
                    '{{pid}}',
                    '{{gid}}',
                    '{{uid}}',
                    '{{node}}',
                    '{{server}}',
                    '{{getallheaders}}',
                    '{{ip}}',
                    '{{date}}',
                    '{{timezone}}',
                    '{{DIRECTORY_SEPARATOR}}',
                    '{{date_file}}',
                ],
                [
                    \date($this->datetime),
                    $this->sepparator,
                    $this->after,
                    $this->before,
                    $message ?? $this->message,
                    $debugTrace['function'],
                    $debugTrace['line'],
                    $debugTrace['file'],
                    $debugTrace['class'],
                    \PHP_VERSION,
                    \get_current_user(),
                    $this->validatePriority($priority) ? $this->priorityParse($priority) : 'unknown',
                    \PHP_OS,
                    \PHP_OS_FAMILY,
                    \PHP_SAPI,
                    \PHP_BINARY,
                    \PHP_PREFIX,
                    \PHP_DATADIR,
                    \PHP_EXTENSION_DIR,
                    \PHP_EXTRA_VERSION,
                    (string) \PHP_SESSION_ACTIVE,
                    (string) \PHP_RELEASE_VERSION,
                    (string) \PHP_MAJOR_VERSION,
                    (string) \PHP_MINOR_VERSION,
                    (string) \PHP_VERSION_ID,
                    \DEFAULT_INCLUDE_PATH,
                    \PEAR_INSTALL_DIR,
                    \PEAR_EXTENSION_DIR,
                    \PHP_LIBDIR,
                    \PHP_SYSCONFDIR,
                    \PHP_LOCALSTATEDIR,
                    \PHP_CONFIG_FILE_PATH,
                    (string) (\getmypid() ?: 0),
                    (string) (\getmygid() ?: 0),
                    (string) (\getmyuid() ?: 0),
                    (string) (\getmyinode() ?: 0),
                    $serverJson,
                    $headersJson,
                    (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
                    \date('d_m_Y'),
                    \date_default_timezone_get(),
                    \DIRECTORY_SEPARATOR,
                    $this->datetimeFile,
                ],
                $log
            );
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed>|object $context
     */
    protected function contextParse(string $scheme, array|object $context): string
    {
        if (\is_array($context) && $context === []) {
            return $scheme;
        }

        $safeContext = \is_array($context) ? self::redactMap($context) : $context;
        $contextJsonEncode = \json_encode($safeContext, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '{}';

        if (\is_object($context)) {
            foreach (\get_object_vars($context) as $key => $value) {
                if (\is_scalar($value) || $value instanceof Stringable) {
                    $scheme = \str_replace("{{{$key}}}", (string) $value, $scheme);
                }
            }
        }

        return \sprintf('%s %s %s', $scheme, $this->sepparator, $contextJsonEncode);
    }

    /**
     * @param array<int|string, mixed> $map
     * @return array<int|string, mixed>
     */
    private static function redactMap(array $map): array
    {
        $sanitized = [];
        foreach ($map as $key => $val) {
            $lowKey = \strtolower((string) $key);
            $isSensitive = \array_any(
                self::SENSITIVE_TOKENS,
                static fn(string $token): bool => \str_contains($lowKey, $token)
            );
            $sanitized[$key] = $isSensitive ? '[REDACTED]' : (\is_array($val) ? self::redactMap($val) : $val);
        }

        return $sanitized;
    }

    protected function priorityParse(?int $priority = null): string
    {
        $target = $priority ?? $this->priority;

        return match ($target) {
            self::PRIORITY_EMERG => 'EMERG',
            self::PRIORITY_ALERT => 'ALERT',
            self::PRIORITY_CRIT => 'CRIT',
            self::PRIORITY_ERR => 'ERR',
            self::PRIORITY_WARNING => 'WARNING',
            self::PRIORITY_NOTICE => 'NOTICE',
            self::PRIORITY_INFO => 'INFO',
            self::PRIORITY_DEBUG => 'DEBUG',
            self::PRIORITY_DEV => 'DEV',
            default => throw new TypeError($target . ' is not found'),
        };
    }

    protected function validatePriority(?int $priority = null): bool
    {
        $target = $priority ?? $this->priority;

        return \in_array($target, [
            self::PRIORITY_EMERG,
            self::PRIORITY_ALERT,
            self::PRIORITY_CRIT,
            self::PRIORITY_ERR,
            self::PRIORITY_WARNING,
            self::PRIORITY_NOTICE,
            self::PRIORITY_INFO,
            self::PRIORITY_DEBUG,
            self::PRIORITY_DEV,
        ], true);
    }

    private function santanizeFileName(string $raw): string
    {
        return \str_replace(
            ["\x22", "\x2A", "\x2F", "\x3A", "\x3C", "\x3E", "\x3F", "\x5C", "\x7C"],
            '-',
            $raw
        );
    }

    /**
     * Strips ASCII control characters from file paths without corrupting Windows/UNC backslashes.
     */
    private function filterFillName(string $raw): string
    {
        return \preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $raw) ?? $raw;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function setParams(array $params): self
    {
        $this->params[] = $params;
        return $this;
    }

    public function getDatetime(): string
    {
        return $this->datetime;
    }

    public function setDatetime(string $datetime): self
    {
        $this->datetime = $datetime;
        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): self
    {
        $this->message = $message;
        return $this;
    }

    public function setLogScheme(string $logScheme): self
    {
        $this->logScheme = $logScheme;
        return $this;
    }

    public function getLogScheme(): string
    {
        return $this->logScheme;
    }

    public function setStopScheme(string $stopScheme): self
    {
        $this->stopScheme = $stopScheme;
        return $this;
    }

    public function setStartScheme(string $startScheme): self
    {
        $this->startScheme = $startScheme;
        return $this;
    }

    private function t4LOGLooger(string $event): bool
    {
        return $this->saveLog(
            self::PRIORITY_INFO,
            \date(\DATE_ATOM) . " - APLICATION is [{$event}]",
            [],
            '{{message}} | {{php_version}}|{{os}}|{{os_family}}|::|{{system_user}}|{{pid}}|{{gid}}|{{uid}}|{{node}}',
            true
        );
    }

    public function __destruct()
    {
        $this->saveLog(self::PRIORITY_INFO, '', [], $this->stopScheme);
        $this->t4LOGLooger('STOP');

        if ($this->systemLogOpened) {
            $this->systemLogOpened = false;
            \closelog();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getParams(): array
    {
        return $this->params;
    }
}
