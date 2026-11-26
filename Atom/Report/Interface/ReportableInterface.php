<?php

declare(strict_types=1);

namespace Atom\Report\Interface;

interface ReportableInterface
{
    public function error(string $message, bool $execute = true): void;
    public function notice(string $message, bool $execute = true): void;
    public function info(string $message, bool $execute = true): void;
    public function warning(string $message, bool $execute = true): void;
    public function debug(string $message, bool $execute = true): void;
}
