<?php

/*
    the atom all error
*/

namespace Atom;

use Atom\Report\Report;
use Atom\Report\Interface\ReportableInterface;

class AtomReport implements ReportableInterface
{
    private Report $report;

    public function __construct(Report $report, ?string $message = null, ?int $code = null)
    {
        $this->report = $report;

        if (!empty($message)) {
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
