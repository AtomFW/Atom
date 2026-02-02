<?php

/*
    Reportable interface
    the Reportable structure
*/

namespace Atom\Report\Interface;

interface ReportableInterface
{
    // public int $code;
    public function error(string $message, bool $execute = true): void;
    public function notice(string $message, bool $execute = true): void;
    public function info(string $message, bool $execute = true): void;
    public function warning(string $message, bool $execute = true): void;
    public function debug(string $message, bool $execute = true): void;
}
