<?php
/* 
        the error raport use Atom
    */

namespace Atom\core\Report;

use Atom\core\exception\ForbiddenException;
use Atom\core\exception\NotFoundException;
use Atom\core\Log\T4LOG;

class Report extends \Exception implements \Stringable, \Throwable
{

    const E_USER_INFO = 4;
    const E_USER_DEBUG = 5;
    const E_USER_DEPRECATED = E_USER_DEPRECATED;
    const E_USER_ERROR = E_USER_ERROR;
    const E_USER_NOTICE = E_USER_NOTICE;
    const E_USER_WARNING = E_USER_WARNING;

    // protected Exception $exception;
    public array $errorCache;
    // bool>true return error exception 
    private bool $execute;

    protected $message;
    protected $code;
    // bool>true view all info debug error
    private bool $debug = false;
    // save in the T4LOG
    private bool $reportLogger = true;

    private T4LOG $looger;

    public function __construct(bool $execute = true, bool $debug = false, bool $reportLogger = false, array $config = [], array $strucutre = [], T4LOG $looger)
    {
        $this->execute = $execute;
        $this->debug = $debug;
        $this->reportLogger = $reportLogger;

        $this->looger = $looger;
    }

    public function add(string $message, int $code, ?bool $execute)
    {
        try {
            $this->debug = true;
            $execute = (is_null($execute) ? $this->execute : $execute);

            if ($this->reportLogger === true) {
                $this->looger->on($this->normalize($code), $message);
            }

            if ($execute && !$this->debug) {
                if ($code !== static::E_USER_DEPRECATED || static::E_USER_ERROR || static::E_USER_NOTICE || static::E_USER_WARNING) {
                    $code = \E_USER_WARNING;
                }

                return trigger_error($message, $code);
            } else if ($this->debug) {
                throw new \Exception($message, $code);
            } else if (is_array($message)) {
                return (array) $message;
            } else {
                echo $message;
            }
            return $this;
        } catch (\Throwable $th) {
            $this->looger->on($this->looger::PRIORITY_CRIT, $th);
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
}
