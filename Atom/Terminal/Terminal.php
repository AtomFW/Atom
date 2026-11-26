<?php

declare(strict_types=1);

namespace Atom\Terminal;

use InvalidArgumentException;

/**
 * Stateless CLI command dispatcher for Atom console commands.
 */
final readonly class Terminal
{
    public const string VERSION = '1.0.0';

    /**
     * @param array<string, callable(list<string>): int> $commands
     */
    public function __construct(
        private array $commands = [],
    ) {}

    /**
     * @param callable(list<string>): int $handler
     */
    public function withCommand(string $name, callable $handler): self
    {
        $updated = $this->commands;
        $updated[$name] = $handler;

        return new self($updated);
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? '';
        if ($command === '' || !isset($this->commands[$command])) {
            throw new InvalidArgumentException("Unknown terminal command: '{$command}'.");
        }

        /** @var list<string> $args */
        $args = \array_slice($argv, 2);
        return ($this->commands[$command])($args);
    }
}
