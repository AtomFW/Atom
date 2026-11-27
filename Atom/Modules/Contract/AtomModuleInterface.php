<?php

declare(strict_types=1);

namespace Atom\Modules\Contract;

use Atom\Atom;
use Atom\Terminal\Terminal;

/**
 * Contract for first-class Atom modules capable of extending the kernel and CLI terminal.
 */
interface AtomModuleInterface
{
    /**
     * Canonical module identifier matching its folder under `Atom/Modules/<Name>`.
     */
    public function getName(): string;

    /**
     * Semantic version of the module.
     */
    public function getVersion(): string;

    /**
     * Names of other Atom modules required for this module to operate.
     *
     * @return list<string>
     */
    public function dependencies(): array;

    /**
     * Boots the module into the active Atom application instance (routes, events, services).
     */
    public function boot(Atom $app): void;

    /**
     * Registers module-specific CLI commands on the Atom Terminal.
     */
    public function registerCommands(Terminal $terminal): Terminal;
}
