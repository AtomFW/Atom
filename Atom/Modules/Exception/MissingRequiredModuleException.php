<?php

declare(strict_types=1);

namespace Atom\Modules\Exception;

use RuntimeException;

/**
 * Thrown when a module required by the application or another module is disabled or missing.
 */
final class MissingRequiredModuleException extends RuntimeException
{
    public static function forDisabledRequiredModule(string $moduleName): self
    {
        return new self(\sprintf(
            "Required Atom module '%s' is disabled or not enabled in configuration. Enable '%s' in config/modul.php to run the application.",
            $moduleName,
            $moduleName,
        ));
    }

    public static function forMissingDependency(string $moduleName, string $dependencyName): self
    {
        return new self(\sprintf(
            "Module '%s' requires module '%s', which is currently disabled or not enabled in configuration.",
            $moduleName,
            $dependencyName,
        ));
    }
}
