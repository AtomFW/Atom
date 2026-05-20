<?php

declare(strict_types=1);

/*
    the all log | constructor
    by Timonix
*/

namespace Atom\Log;

use Atom\Exception\Exception;
use Countable;
use ErrorException;
use TypeError;
use Psr\Log\LoggerInterface;

final class T4LOG implements LoggerInterface
{
    /**
     * @readonly
     * @default LOG_EMERG
     * @var 0
     * @return 0
     */
    public const PRIORITY_EMERG = 0;
    /**
     * @readonly
     * @var 1
     * @return 1
     */
    public const PRIORITY_ALERT = LOG_ALERT;
    /**
     * @readonly
     * @default LOG_CRIT
     * @var 2
     * @return 2
     */
    public const PRIORITY_CRIT = 2;
    /**
     * @readonly
     * @var 4
     * @return 4
     */
    public const PRIORITY_ERR = LOG_ERR;
    /**
     * @readonly
     * @var 5
     * @return 5
     */
    public const PRIORITY_WARNING = LOG_WARNING;
    /**
     * @readonly
     * @var 6
     * @return 6
     */
    public const PRIORITY_NOTICE = LOG_NOTICE;
    /**
     * @readonly
     * @var 44
     * @return 44
     */
    public const PRIORITY_DEV = 44;
    /**
     * @readonly
     * @var 1
     * @return 1
     */
    public const PRIORITY_DEFAULT = LOG_ALERT;
    /**
     * @readonly
     * @var 6
     * @return 6
     */
    public const PRIORITY_NORMAL = LOG_NOTICE;
    /**
     * @readonly
     * @var 3
     * @return 3
     */
    public const PRIORITY_INFO = 3;
    /**
     * @readonly
     * @var 40
     * @return 40
     */
    public const PRIORITY_DEBUG = 40;
    // int(1) int(1) int(1) int(4) int(5) int(6) int(44) int(1) int(6) int(6) int(6)
    // int(0) int(1) int(2) int(4) int(5) int(6) int(44) int(1) int(6) int(3) int(40)

    private string $datetime = DATE_ATOM;
    private string $datetimeFile = "d_m_Y";
    private int $priority = self::PRIORITY_DEFAULT;
    public static string $LOG_ROOT_DIR =
        __DIR__ .
        "..{{DIRECTORY_SEPARATOR}}..{{DIRECTORY_SEPARATOR}}runtime{{DIRECTORY_SEPARATOR}}log{{DIRECTORY_SEPARATOR}}";
    public static string $LOG_ROOT_DEV_DIR = "dev{{DIRECTORY_SEPARATOR}}";
    protected string $after = "";
    protected string $before = "";
    protected string $message = "APP:LOG >>";
    protected string $sepparator = "-";
    protected string $logScheme =
        "{{datetime}} {{sepparator}} [{{prefix}}] {{before}} {{message}} {{after}} {{sepparator}} {{file}}::{{line}}" .
        " |{{ip}}| {{sepparator}} {{php_version}} {{sepparator}} {{system_user}}/{{os}}/{{os_family}}";
    protected string $logFileScheme = "{{date_file}}_{{php_version}}.log";
    protected string $logFileDevScheme = "{{date_file}}_{{php_version}}.t4log";
    protected string $startScheme =
        "{{datetime}} {{sepparator}} [{{prefix}}][START:APP] {{before}} {{message}} {{after}} {{sepparator}} " .
        "{{php_version}}::{{php_version_id}} {{sepparator}} {{system_user}}/{{os}}/{{os_family}} {{sepparator}} " .
        "{{php_config_file_path}} {{pear_install_dir}}";
    protected string $stopScheme =
        "{{datetime}} {{sepparator}} [{{prefix}}][STOP:APP] {{before}} {{message}} {{after}} {{sepparator}} " .
        "{{php_version}}::{{php_version_id}} {{sepparator}} {{system_user}}/{{os}}/{{os_family}} {{sepparator}} " .
        "{{php_config_file_path}} {{pear_install_dir}}";
    private array $params = [];
    private string $nameLogFile;
    private string $nameLogFileT4LOG;
    private bool|string $systemLog = false;
    private $systemLogSocket;

    public function __construct(array $params = [])
    {
        if (\array_key_exists("systemLog", $params)) {
            $this->systemLog = $params["systemLog"];
            $this->systemLogSocket = openlog($this->message, LOG_ODELAY | LOG_CONS | LOG_PID, LOG_USER);
        }

        if (\array_key_exists("priority", $params)) {
            $this->priority = $params["priority"];
        }

        if (\array_key_exists("logRootDir", $params)) {
            self::$LOG_ROOT_DIR = $params["logRootDir"];
        }

        if (\array_key_exists("logRootDevDir", $params)) {
            self::$LOG_ROOT_DEV_DIR = $params["logRootDevDir"];
        }

        if (\array_key_exists("message", $params)) {
            $this->message = $params["message"];
        }

        // $datetimeFile normalize
        $this->datetimeFile = self::santanizeFileName(date($this->datetimeFile));

        // crate a new log file name
        if (\array_key_exists("logFileScheme", $params)) {
            $this->nameLogFile = $params["logFileScheme"];
        } else {
            $this->nameLogFile = $this->paramParse($this->logFileScheme);
        }

        if (\array_key_exists("logFilesDevCheme", $params)) {
            $this->nameLogFileT4LOG = $params["logFilesDevCheme"];
        } else {
            $this->nameLogFileT4LOG = $this->paramParse($this->logFileDevScheme);
        }

        self::$LOG_ROOT_DEV_DIR =
            self::$LOG_ROOT_DIR .
            $this->paramParse(self::$LOG_ROOT_DEV_DIR) .
        self::filterFillName(
            $this->nameLogFileT4LOG
        );
        self::$LOG_ROOT_DIR =
            self::$LOG_ROOT_DIR .
        self::filterFillName(
            $this->nameLogFile
        );

        if (isset($params["autoLogStartStopConnection"]) && $params["autoLogStartStopConnection"] === true) {
            // call a T4LOG looger;
            $this->t4LOGLooger("START");
        }

        // run startScheme
        $this->saveLog(self::PRIORITY_INFO, "", [], $this->startScheme);
    }

    /**
     * @param int $priority
     * @param string $message
     * @return T4LOG
     */
    public function logT(int $priority, string $message)
    {
        $this->on($priority, $message);
        return $this;
    }
    /**
     * @param int $priority
     * @param string $message
     * @param mixed[] $context
     */
    public function on($priority, string $message, array $context = []): void
    {
        $this->saveLog($priority, $message, $context);
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param int $level
     * @param string $message
     * @param mixed[] $context
     *
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->on(self::PRIORITY_NORMAL, $message, $context);
    }
    /**
     * Logs with an arbitrary level.
     *
     * @param string $message
     * @param mixed[] $context
     *
     */
    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->on(self::PRIORITY_INFO, $message, $context);
    }
    /**
     * Logs with an arbitrary level.
     *
     * @param string $message
     * @param mixed[] $context
     *
     */
    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->on(self::PRIORITY_ERR, $message, $context);
    }
    /**
     * Logs with an arbitrary level.
     *
     * @param int $facility
     * @param string $message
     * @param mixed[] $context
     *
     * The `facility` argument is used to specify what type of program is logging the message.
     * This lets the configuration file specify that messages from different facilities will be handled differently.
     * Must be one of the following constants:
     * - `LOG_AUTH`
     * - `LOG_AUTHPRIV`
     * - `LOG_CRON`
     * - `LOG_DAEMON`
     * - `LOG_KERN`
     * - `LOG_LOCAL[0-7]`
     * - `LOG_LPR`
     * - `LOG_MAIL`
     * - `LOG_NEWS`
     * - `LOG_SYSLOG`
     * - `LOG_USER`
     * - `LOG_UUCP` Note : This parameter is ignored on Windows.
     *
     */
    public function sys(int $facility, string|\Stringable $message, array $context = []): void
    {

        if (!$this->systemLog && \is_bool($this->systemLog)) {
            $this->systemLogSocket = openlog($this->message, LOG_ODELAY | LOG_CONS | LOG_PID, $facility);
        }

        $this->saveLog($facility, $message, $context, nativeSystemLog: true);
    }
    /**
     * Logs with an arbitrary level.
     *
     * @param string $message
     * @param mixed[] $context
     *
     */
    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->on(self::PRIORITY_DEBUG, $message, $context);
    }
    /**
     * Logs with an arbitrary level.
     *
     * @param string $message
     * @param mixed[] $context
     *
     */
    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->on(self::PRIORITY_EMERG, $message, $context);
    }
    /**
     * Logs with an arbitrary level.
     *
     * @param string $message
     * @param mixed[] $context
     *
     */
    public function emerg(string|\Stringable $message, array $context = []): void
    {
        $this->emergency($message, $context);
    }
    /**
     * Logs with an arbitrary level.
     *
     * @param string $message
     * @param mixed[] $context
     *
     */
    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->on(self::PRIORITY_ALERT, $message, $context);
    }
    /**
     * Logs with an arbitrary level.
     *
     * @param string $message
     * @param mixed[] $context
     *
     */
    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->critical($message, $context);
    }
    /**
     * Logs with an arbitrary level.
     *
     * @param string $message
     * @param mixed[] $context
     *
     */
    public function crit(string|\Stringable $message, array $context = []): void
    {
        $this->critical($message, $context);
    }
    /**
     * Logs with an arbitrary level.
     *
     * @param string $message
     * @param mixed[] $context
     *
     */
    public function err(string|\Stringable $message, array $context = []): void
    {
        $this->error($message, $context);
    }
    /**
     * Logs with an arbitrary level.
     *
     * @param string $message
     * @param mixed[] $context
     *
     */
    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->on(self::PRIORITY_WARNING, $message, $context);
    }
    /**
     * Logs with an arbitrary level.
     *
     * @param string $message
     * @param mixed[] $context
     *
     */
    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->on(self::PRIORITY_NOTICE, $message, $context);
    }
    /**
     * Logs with an arbitrary level.
     *
     * @param string $message
     * @param mixed[] $context
     *
     */
    public function dev(string|\Stringable $message, array $context = []): void
    {
        $this->on(self::PRIORITY_DEV, $message, $context);
    }

    protected function saveLog(
        int $priority,
        string $message,
        array $context = [],
        ?string $scheme = null,
        ?bool $stering = null,
        bool $nativeSystemLog = false
    ): bool {

        try {
            $schemes = $scheme === null ? $this->logScheme : $scheme;

            $scheme = $this->paramParse($schemes, $priority, $message);

            if ($scheme === false) {
                throw new ErrorException("paramParse method return false", 1);
            }

            $scheme = self::contextParse($scheme, $context);

            $path = !\is_bool($stering) ? self::$LOG_ROOT_DIR : self::$LOG_ROOT_DEV_DIR;
            $path = $this->filterFillName($path);

            if ($nativeSystemLog && \is_string($this->systemLog)) {
                if (!self::validatePriority($priority)) {
                    throw new TypeError("Priority type is not valid", 1);
                }

                \syslog($priority, "$scheme");

                return true;
            }

            // TODO dodać zapisaywanie logów na raz w register_shutdown_function
            $save = file_put_contents($path, $scheme . PHP_EOL, FILE_APPEND);

            if ($save === false) {
                throw new ErrorException("file_put_contents method return false", 1);
            }

            return true;
        } catch (\Throwable $th) {
            return false;
        }
    }

    protected function paramParse($log, int|null $priority = null, string|null $message = null): string|bool
    {
        try {
            $debugTrace = \count(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)) > 2 ?
                        debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2] :
                        debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[1];
            $paramTry = str_replace(
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
                    '{{php_datadir}}',
                    '{{php_localstatedir}}',
                    '{{php_config_file_path}}',
                    '{{pid}}',
                    '{{gid}}',
                    '{{uid}}',
                    '{{node}}',
                    '{{server}}',
                    "{{getallheaders}}",
                    "{{ip}}",
                    "{{date}}",
                    "{{timezone}}",
                    "{{DIRECTORY_SEPARATOR}}",
                    "{{date_file}}",
                ],
                [
                    date($this->datetime),
                    $this->sepparator,
                    $this->after,
                    $this->before,
                    $message === null ? $this->message : $message,
                    $debugTrace["function"],
                    \array_key_exists("line", $debugTrace) ? $debugTrace["line"] : "null",
                    \array_key_exists("file", $debugTrace) ? $debugTrace["file"] : "null",
                    \array_key_exists("class", $debugTrace) ? $debugTrace["class"] : "null",
                    phpversion(),
                    get_current_user(),
                    self::validatePriority($priority) ? $this->priorityParse($priority) : "unknown",
                    PHP_OS,
                    PHP_OS_FAMILY,
                    PHP_SAPI,
                    PHP_BINARY,
                    PHP_PREFIX,
                    PHP_DATADIR,
                    PHP_EXTENSION_DIR,
                    PHP_EXTRA_VERSION,
                    PHP_SESSION_ACTIVE,
                    PHP_RELEASE_VERSION,
                    PHP_MAJOR_VERSION,
                    PHP_MINOR_VERSION,
                    PHP_VERSION_ID,
                    DEFAULT_INCLUDE_PATH,
                    PEAR_INSTALL_DIR,
                    PEAR_EXTENSION_DIR,
                    PHP_LIBDIR,
                    PHP_SYSCONFDIR,
                    PHP_DATADIR,
                    PHP_LOCALSTATEDIR,
                    PHP_CONFIG_FILE_PATH,
                    getmypid(),
                    getmygid(),
                    getmyuid(),
                    getmyinode(),
                    json_encode($_SERVER),
                    json_encode(getallheaders()),
                    $_SERVER['REMOTE_ADDR'],
                    date("d_m_Y"),
                    date_default_timezone_get(),
                    DIRECTORY_SEPARATOR,
                    $this->datetimeFile
                ],
                $log
            );

            return $paramTry;
        } catch (\Throwable $th) {
            return false;
        }
    }

    protected function contextParse(string $scheme, array|object $context): string
    {
        if (empty($context)) {
            return $scheme;
        }

        $contextJsonEncode = json_encode($context);

        if (\is_object($context)) {
            foreach ($context as $key => $value) {
                $scheme = str_replace("{{{$key}}}", $value, $scheme);
            }
        }

        return \sprintf('%s %s %s', $scheme, $this->sepparator, $contextJsonEncode);
    }

    protected function priorityParse(?int $priority = null): string
    {

        if ($priority === null) {
            $priority = $this->priority;
        }

        $returnPriorityTag = match ($priority) {
            self::PRIORITY_EMERG => "EMERG",
            self::PRIORITY_ALERT => "ALERT",
            self::PRIORITY_CRIT => "CRIT",
            self::PRIORITY_ERR => "ERR",
            self::PRIORITY_WARNING => "WARNING",
            self::PRIORITY_NOTICE => "NOTICE",
            self::PRIORITY_INFO => "INFO",
            self::PRIORITY_DEBUG => "DEBUG",
            self::PRIORITY_DEV => "DEV",
            default => throw new TypeError($priority . " is not found"),
        };

        return $returnPriorityTag;
    }

    protected function validatePriority(?int $priority = null): bool
    {

        if ($priority === null) {
            $priority = $this->priority;
        }

        return \in_array($priority, [
            self::PRIORITY_EMERG,
            self::PRIORITY_ALERT,
            self::PRIORITY_CRIT,
            self::PRIORITY_ERR,
            self::PRIORITY_WARNING,
            self::PRIORITY_NOTICE,
            self::PRIORITY_INFO,
            self::PRIORITY_DEBUG,
            self::PRIORITY_DEV,
        ]);
    }

    private function santanizeFileName(string $raw): string
    {
        return str_replace(
            [
                "\x22", // "
                "\x2A", // *
                "\x2F", // /
                "\x3A", // :
                "\x3C", // <
                "\x3E", // >
                "\x3F", // ?
                "\x5C", // \
                "\x7C"  // |
            ],
            '-',
            $raw
        );
    }

    /**
     * Filters special characters from a file name or path.
     *
     * This function removes or replaces characters that are not safe for use in file systems.
     * It's designed to prevent potential issues with file creation or access due to invalid characters.
     *
     * @param string $raw The input string (file name or path) to be filtered.
     * @return string The filtered string, safe for use as a file name or path component.
     */
    private function filterFillName(string $raw): string
    {
        // Remove control characters (ASCII 0-31)
        $raw = str_replace(
            [
            "\x00", "\x01", "\x02", "\x03", "\x04", "\x05", "\x06", "\x07", "\x08", "\x0B", "\x0C", "\x0E", "\x0F",
            "\x10", "\x11", "\x12", "\x13", "\x14", "\x15", "\x16", "\x17", "\x18", "\x19", "\x1A", "\x1B", "\x1C",
                "\x1D", "\x1E", "\x1F"
            ],
            '',
            $raw
        );

        // Escape special characters that might be problematic in filesystems (if any remain)
        return addslashes($raw);
    }

    /**
     * @param array $params
     * @return T4LOG
     */
    public function setParams(array $params)
    {
        $this->params[] = $params;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getDatetime()
    {
        return $this->datetime;
    }

    /**
     * @param mixed $datetime
     * @return T4LOG
     */
    public function setDatetime($datetime): self
    {
        $this->datetime = $datetime;
        return $this;
    }
    /**
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @param string $message
     * @return T4LOG
     */
    public function setMessage(string $message): self
    {
        $this->message = $message;
        return $this;
    }
    /**
     * @param string $logScheme
     * @return T4LOG
     */
    public function setLogScheme(string $logScheme): self
    {
        $this->logScheme = $logScheme;
        return $this;
    }
    /**
     * @return string
     */
    public function getLogScheme(): string
    {
        return $this->logScheme;
    }
    /**
     * @param string $stopScheme
     * @return T4LOG
     */
    public function setStopScheme(string $stopScheme): self
    {
        $this->stopScheme = $stopScheme;
        return $this;
    }
    /**
     * @param string $startScheme
     * @return T4LOG
     */
    public function setStartScheme(string $startScheme): self
    {
        $this->startScheme = $startScheme;
        return $this;
    }

    /**
     * Logs application start/stop events to the specified log file.
     *
     * @param string $EGV The event type, e.g., "START" or "STOP".
     * @return bool True on success, false on failure.
     */
    private function t4LOGLooger(string $EGV): bool
    {
        return $this->saveLog(
            self::PRIORITY_INFO,
            date(DATE_ATOM) . " - APLICATION is [$EGV]",
            [],
            "{{message}} | {{php_version}}|{{os}}|{{os_family}}|::|{{system_user}}|{{pid}}|{{gid}}|{{uid}}|{{node}}",
            true
        );
    }

    public function __destruct()
    {
        // TODO poprawić zapisywanie logów na dysk przy użyciu register_shutdown_function i dodać opługę
        // register_shutdown_function w klasie statycznej aby wszysstkie calbacki do niego się wykonywały
        // przychowywać w tablicy i wykonywać przy wywołaniu register_shutdown_function
        // run startScheme
        $this->saveLog(self::PRIORITY_INFO, "", [], $this->stopScheme);

        // call a T4LOG looger;
        $this->t4LOGLooger("STOP");

        if ($this->systemLog !== false) {
            unset($this->systemLogSocket);
            closelog();
        }
    }

    /**
     * @return array
     */
    public function getParams(): array
    {
        return $this->params;
    }
}
