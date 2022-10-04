<?php
/* 
    the all log | constructor
    by Timonix
*/

namespace Atom\core\Log;

use Atom\core\Exception\Exception;
use Countable;
use ErrorException;
use TypeError;

final class T4LOG
{
    const PRIORITY_EMERG = LOG_EMERG;
    const PRIORITY_ALERT = LOG_ALERT;
    const PRIORITY_CRIT = LOG_CRIT;
    const PRIORITY_ERR = LOG_ERR;
    const PRIORITY_WARNING = LOG_WARNING;
    const PRIORITY_NOTICE = LOG_NOTICE;
    const PRIORITY_DEV = 44;
    const PRIORITY_DEFAULT = LOG_ALERT;
    const PRIORITY_NORMAL = LOG_NOTICE;
    const PRIORITY_INFO = LOG_INFO;
    const PRIORITY_DEBUG = LOG_DEBUG;

    private string $datetime = DATE_ATOM;
    private int $priority = self::PRIORITY_DEFAULT;
    public static string $LOG_ROOT_DIR = __DIR__;
    protected string $after = "";
    protected string $before = "";
    protected string $message = "APP:LOG >>";
    protected string $sepparator = "-";
    protected string $logScheme = "{{datetime}} {{sepparator}} [{{prefix}}] {{before}} {{message}} {{after}} {{sepparator}} {{file}}::{{line}} |{{ip}}| {{sepparator}} {{php_version}} {{sepparator}} {{system_user}}/{{os}}/{{os_family}}";
    protected string $logFileScheme = "{{date}}_{{os}}_{{php_version}}.log";
    protected string $startScheme = "{{datetime}} {{sepparator}} [{{prefix}}][START:APP] {{before}} {{message}} {{after}} {{sepparator}} {{php_version}}::{{php_version_id}} {{sepparator}} {{system_user}}/{{os}}/{{os_family}} {{sepparator}} {{php_config_file_path}} {{pear_install_dir}}";
    protected string $stopScheme = "{{datetime}} {{sepparator}} [{{prefix}}][STOP:APP] {{before}} {{message}} {{after}} {{sepparator}} {{php_version}}::{{php_version_id}} {{sepparator}} {{system_user}}/{{os}}/{{os_family}} {{sepparator}} {{php_config_file_path}} {{pear_install_dir}}";
    private array $params = [];
    private string $nameLogFile = "log.log";
    private string $nameLogFileT4LOG;
    private bool|string $systemLog = false;
    private $systemLogSocket;

    public function __construct(array|Countable $params = null)
    {
        $this->params[] = $params;

        if (!is_null($params) && array_key_exists("systemLog", $params)) {
            $this->systemLog = (bool) $params["systemLog"];
        }
        if (!is_null($params) && array_key_exists("priority", $params)) {
            $this->priority = $params["priority"];
        }

        if (!is_null($params) && array_key_exists("logRootDir", $params)) {
            self::$LOG_ROOT_DIR = $params["logRootDir"];
        }
        if (!is_null($params) && array_key_exists("message", $params)) {
            $this->message = $params["message"];
        }

        // crate a new log file name
        if (!is_null($params) && array_key_exists("logfilescheme", $params)) {
            $this->nameLogFile = $this->paramParse($this->logFileScheme);
        } else {
            $this->nameLogFile = date("Y_m_d") . ".log";
        }

        if ($this->systemLog === true) {
            $this->systemLogSocket = openlog($this->message, LOG_ODELAY | LOG_CONS | LOG_PID, LOG_USER);
        }

        $this->nameLogFileT4LOG = __DIR__ . DIRECTORY_SEPARATOR . "log" . DIRECTORY_SEPARATOR . date("Y_m_d") . ".t4log";
        // call a T4LOG looger;
        $this->T4LOGLooger("START");

        // run startScheme
        $this->saveLog(self::PRIORITY_INFO, null, $this->startScheme);
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
     * @return T4LOG
     */
    public function on(int $priority, string $message)
    {
        $this->saveLog($priority, $message);
        return $this;
    }
    /**
     * @param string $message 
     * @return T4LOG
     */
    public function log(string $message)
    {
        $this->saveLog(self::PRIORITY_NORMAL, $message);
        return $this;
    }
    /**
     * @param string $message 
     * @return T4LOG
     */
    public function info(string $message)
    {
        $this->saveLog(self::PRIORITY_INFO, $message);
        return $this;
    }
    /**
     * @param string $message 
     * @return T4LOG
     */
    public function error(string $message)
    {
        $this->saveLog(self::PRIORITY_ERR, $message);
        return $this;
    }
    /**
     * @param int $priority 
     * @param string $message 
     * @return T4LOG
     */
    public function sys(string $message, int $priority = null)
    {

        if (!$this->systemLog && $this->systemLog !== true && is_bool($this->systemLog)) {
            $this->systemLogSocket = openlog($this->message, LOG_ODELAY | LOG_CONS | LOG_PID, LOG_USER);
        }

        $this->saveLog($priority, $message);
        return $this;
    }
    /**
     * @param string $message 
     * @return T4LOG
     */
    public function debug(string $message)
    {
        $this->saveLog(self::PRIORITY_DEBUG, $message);
        return $this;
    }
    /**
     * @param string $message 
     * @return T4LOG
     */
    public function emerg(string $message)
    {
        $this->saveLog(self::PRIORITY_EMERG, $message);
        return $this;
    }
    /**
     * @param string $message 
     * @return T4LOG
     */
    public function alert(string $message)
    {
        $this->saveLog(self::PRIORITY_ALERT, $message);
        return $this;
    }
    /**
     * @param string $message 
     * @return T4LOG
     */
    public function crit(string $message)
    {
        $this->saveLog(self::PRIORITY_CRIT, $message);
        return $this;
    }
    /**
     * @param string $message 
     * @return T4LOG
     */
    public function err(string $message)
    {
        $this->saveLog(self::PRIORITY_ERR, $message);
        return $this;
    }
    /**
     * @param string $message 
     * @return T4LOG
     */
    public function warning(string $message)
    {
        $this->saveLog(self::PRIORITY_WARNING, $message);
        return $this;
    }
    /**
     * @param string $message 
     * @return T4LOG
     */
    public function notice(string $message)
    {
        $this->saveLog(self::PRIORITY_NOTICE, $message);
        return $this;
    }
    /**
     * @param string $message 
     * @return T4LOG
     */
    public function dev(string $message)
    {
        $this->saveLog(self::PRIORITY_DEV, $message);
        return $this;
    }

    protected function saveLog(int|null $priority = null, string|null $message = null, string|null $scheme = null, bool|null $stering = null)
    {

        try {
            $schemes = (is_null($scheme) ? $this->logScheme : $scheme);
            $messages  = is_null($message) ? null : $message;

            $scheme = $this->paramParse($schemes, $priority, $messages);
            if ($scheme === false) {
                throw new ErrorException("paramParse method return false", 1);
            }

            $path = (!is_bool($stering) ? $this->nameLogFile : $this->nameLogFileT4LOG);
            $path = $this->filterFillName($path);
            $path = (!is_bool($stering) ? self::$LOG_ROOT_DIR . $this->nameLogFile : $this->nameLogFileT4LOG);

            if ($path === false) {
                throw new ErrorException("filterFillName method return false", 1);
            }

            if ($this->systemLog !== false || is_string($this->systemLog)) {
                $checkPriority = (is_null($priority) ? $this->priorityParse() : $this->priorityParse($priority));
                $checkPriority = match ($checkPriority) {
                    "DEV" => self::PRIORITY_DEBUG,
                    "ERR" => self::PRIORITY_CRIT,
                    "WARNING" => self::PRIORITY_ALERT,
                    default => $priority
                };

                $isSave = syslog($checkPriority, "$scheme");

                if ($isSave === false) {
                    throw new ErrorException("syslog method return false", 1);
                }

                return true;
            }

            $save = file_put_contents($path, "$scheme" . PHP_EOL, FILE_APPEND);

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
            $debugTrace = count(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)) > 2 ? debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2] : debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[1];
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
                    "{{date}}"
                ],
                [
                    date($this->datetime),
                    $this->sepparator,
                    $this->after,
                    $this->before,
                    (is_null($message) ? $this->message : $message),
                    array_key_exists("function", $debugTrace) ? $debugTrace["function"] : "null",
                    array_key_exists("line", $debugTrace) ? $debugTrace["line"] : "null",
                    array_key_exists("file", $debugTrace) ? $debugTrace["file"] : "null",
                    array_key_exists("class", $debugTrace) ? $debugTrace["class"] : "null",
                    phpversion(),
                    get_current_user(),
                    (is_null($priority) ? $this->priorityParse() : $this->priorityParse($priority)),
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
                    date("Y_m_d")
                ],
                $log
            );

            return $paramTry;
        } catch (\Throwable $th) {
            return false;
        }
    }

    protected function priorityParse(int|null $priority = null): string
    {

        if (is_null($priority)) {
            $priority = $this->priority;
        }

        $returnPriorityTag = match ($priority) {
            self::PRIORITY_EMERG => "EMERG",
            self::PRIORITY_ALERT, self::PRIORITY_DEFAULT => "ALERT",
            self::PRIORITY_CRIT => "CRIT",
            self::PRIORITY_ERR => "ERR",
            self::PRIORITY_WARNING => "WARNING",
            self::PRIORITY_NOTICE, self::PRIORITY_NORMAL => "NOTICE",
            self::PRIORITY_INFO => "INFO",
            self::PRIORITY_DEBUG => "DEBUG",
            self::PRIORITY_DEV => "DEV",
            default => throw new TypeError($priority . " is not found"),
        };

        return $returnPriorityTag;
    }

    private function filterFillName(string $raw): string
    {
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
    function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @param string $message 
     * @return T4LOG
     */
    function setMessage(string $message): self
    {
        $this->message = $message;
        return $this;
    }
    /**
     * @param string $logScheme 
     * @return T4LOG
     */
    function setLogScheme(string $logScheme): self
    {
        $this->logScheme = $logScheme;
        return $this;
    }
    /**
     * @return string
     */
    function getLogScheme(): string
    {
        return $this->logScheme;
    }
    /**
     * @param string $stopScheme 
     * @return T4LOG
     */
    function setStopScheme(string $stopScheme): self
    {
        $this->stopScheme = $stopScheme;
        return $this;
    }
    /**
     * @param string $startScheme 
     * @return T4LOG
     */
    function setStartScheme(string $startScheme): self
    {
        $this->startScheme = $startScheme;
        return $this;
    }

    private function T4LOGLooger(string $EGV)
    {


        return $this->saveLog(self::PRIORITY_INFO, date(DATE_ATOM) . " - APLICATION is [$EGV]", "{{message}} | {{php_version}}|{{os}}|{{os_family}}|::|{{system_user}}|{{pid}}|{{gid}}|{{uid}}|{{node}}", true);
    }

    public function __destruct()
    {
        // run startScheme
        $this->saveLog(self::PRIORITY_INFO, null, $this->stopScheme);

        // call a T4LOG looger;
        $this->T4LOGLooger("STOP");

        if ($this->systemLog !== false) {
            unset($this->systemLogSocket);
            closelog();
        }
    }
}
