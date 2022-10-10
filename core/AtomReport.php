<?php
/* 
    the atom all error
*/

namespace Atom\core;

use Atom\core\Report\Report;

class AtomReport
{
    private Report $report;

    function __construct(?string $message = null, ?int $code = null, Report $report)
    {
        $this->report = $report;

        if (!is_null($message)) {
            $this->on($message, $code, false);
        }
    }

    public function error(string $message, bool $execute = true): void
    {
        $this->report->add($message, $this->report::E_USER_ERROR, $execute);
    }

    public function notice(string $message, bool $execute = true): void
    {
        $this->report->add($message, $this->report::E_USER_NOTICE, $execute);
    }

    public function info(string $message, bool $execute = true): void
    {
        $this->report->add($message, $this->report::E_USER_INFO, $execute);
    }

    public function warning(string $message, bool $execute = true): void
    {
        $this->report->add($message, $this->report::E_USER_WARNING, $execute);
    }

    public function debug(string $message, bool $execute = true): void
    {
        $this->report->add($message, $this->report::E_USER_DEBUG, $execute);
    }

    public function deprecated(string $message, bool $execute = true): void
    {
        $this->report->add($message, $this->report::E_USER_DEPRECATED, $execute);
    }

    public function on(string $message, int $code, bool $execute = true): void
    {
        $this->report->add($message, $code, $execute);
    }
}
