<?php

/*
    the error raport use Atom
*/

namespace Atom\Report;

use Atom\Exception\ForbiddenException;
use Atom\Exception\NotFoundException;
use Atom\Log\T4LOG;

class Report extends \Exception implements \Stringable, \Throwable
{
    /**
     * E_USER_INFO - Runtime notice. Indicate that the script encountered something odd that should typically not happen
     * , but which is not serious enough to stop the script. Examples include using
     *  a deprecated function or obsolete argument.
     * @var int
     */
    public const E_USER_INFO = 4;

    /**
     * E_USER_DEBUG - Runtime notice. Indicates that the script encountered something potentially wrong,
     *  but not serious enough to stop the script.
     * @var int
     */
    public const E_USER_DEBUG = 5;

    /**
     * E_USER_DEPRECATED - Runtime notice. Indicate that the called code will generate
     *  a warning of level E_DEPRECATED in the future, it indicates that the code will stop working at some point.
     * @var int
     */
    public const E_USER_DEPRECATED = E_USER_DEPRECATED;

    /**
     * E_USER_ERROR - Fatal error. The execution of the script must be halted.
     * @var int
     */
    public const E_USER_ERROR = E_USER_ERROR;

    /**
     * E_USER_NOTICE - Runtime notice. Indicate that the script encountered something that might be of interest
     * (e.g. using a deprecated function).
     * @var int
     */
    public const E_USER_NOTICE = E_USER_NOTICE;

    /**
     * E_USER_WARNING - Runtime warning. Indicate that the script encountered something that -
     * might be of interest (e.g. using a deprecated function).
     * @var int
     */
    public const E_USER_WARNING = E_USER_WARNING;

    /**
     * @var Exception The exception that triggered the report.
     */
    // protected Exception $exception;

    /**
     * @var array Cache of error messages.
     */
    public array $errorCache;

    /**
     * bool>true return error exception
     * @var bool Whether to execute the report on errors.
     */
    private bool $execute;

    protected $message;
    protected $code;
    // bool>true view all info debug error
    private bool $debug;
    // save in the T4LOG
    private bool $reportLogger = true;

    private T4LOG $looger;

    /* , array $config = [], array $strucutre = [] */
    public function __construct(T4LOG $looger, bool $execute = true, bool $debug = false, bool $reportLogger = false)
    {
        $this->execute = $execute;
        $this->debug = $debug;
        $this->reportLogger = $reportLogger;

        $this->looger = $looger;
    }

    public function add(string|array $message, int $code, ?bool $execute): array|bool|Report
    {
        try {
            $execute = (is_null($execute) ? $this->execute : $execute);

            if ($this->reportLogger === true) {
                $this->looger->on($this->normalize($code), $message);
            }

            if ($execute && !$this->debug) {
                if (
                    $code !==   static::E_USER_DEPRECATED ||
                                static::E_USER_ERROR ||
                                static::E_USER_NOTICE ||
                                static::E_USER_WARNING
                ) {
                    $code = \E_USER_WARNING;
                }

                return trigger_error($message, $code);
            } elseif ($this->debug) {
                throw new \Exception($message, $code);
            } elseif (is_array($message)) {
                return (array) $message;
            } else {
                echo $message;
            }
            return $this;
        } catch (\Throwable $th) {
            $this->looger->on($this->looger::PRIORITY_CRIT, $th);
            return false;
        }
    }

    protected function normalize(int $code): int
    {
        $codeCheck = match ($code) {
            static::E_USER_INFO => $this->looger::PRIORITY_INFO,
            static::E_USER_DEBUG => $this->looger::PRIORITY_DEBUG,
            static::E_USER_DEPRECATED => $this->looger::PRIORITY_DEV,
            static::E_USER_ERROR => $this->looger::PRIORITY_ALERT,
            static::E_USER_NOTICE => $this->looger::PRIORITY_NOTICE,
            static::E_USER_WARNING => $this->looger::PRIORITY_WARNING,
            default => $this->looger::PRIORITY_INFO
        };

        return (int) $codeCheck;
    }

    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    public function getDebug(): bool
    {
        return $this->debug;
    }
}
