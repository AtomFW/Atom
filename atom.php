#!/usr/bin/env php
<?php

declare(strict_types=1);

use Atom\Terminal\Terminal;

require_once __DIR__ . '/vendor/autoload.php';

$terminal = Terminal::createConsole(__DIR__);
/** @var list<string> $argvInput */
$argvInput = isset($_SERVER['argv']) && \is_array($_SERVER['argv'])
    ? \array_values(\array_map(strval(...), $_SERVER['argv']))
    : ['atom.php'];

if (\count($argvInput) <= 1) {
    $exitCode = $terminal->interactive();
} else {
    try {
        $exitCode = $terminal->run($argvInput);
    } catch (\Throwable $e) {
        $terminal->error($e->getMessage());
        $exitCode = 1;
    }
}
