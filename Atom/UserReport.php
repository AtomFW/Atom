<?php
/* 
    the user error / not validation form
*/

namespace Atom;

use Atom\Report\Report;

class UserReport
{

    private Report $report;

    function __construct(Report $report, ?string $message = null, ?int $code = null)
    {
        $this->report = $report;

        if (!is_null($message)) {
            $this->on($message, $code, false);
        }
    }

    public function error(string $message): void
    {
        $this->report->add($message, $this->report::E_USER_ERROR, false);
    }

    public function notice(string $message): void
    {
        $this->report->add($message, $this->report::E_USER_NOTICE, false);
    }

    public function info(string $message): void
    {
        $this->report->add($message, $this->report::E_USER_INFO, false);
    }

    public function warning(string $message): void
    {
        $this->report->add($message, $this->report::E_USER_WARNING, false);
    }

    public function debug(string $message): void
    {
        $this->report->add($message, $this->report::E_USER_DEBUG, false);
    }

    public function on(string $message, int $code, bool $execute = false)
    {
        return $this->report->add($message, $code, $execute);
    }
}
