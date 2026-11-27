<?php

declare(strict_types=1);

namespace Atom\Modules\ErrorDebugger\Tests;

use Atom\Modules\ErrorDebugger\ErrorDebugger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * In-module test suite discovered automatically via `phpunit.xml` (`Modules Test Suite`).
 */
final class ErrorDebuggerModuleTest extends TestCase
{
    public function testModuleMetadataAndDebugFlagControl(): void
    {
        $disabledDebugger = new ErrorDebugger(['debug' => false]);
        $enabledDebugger = new ErrorDebugger(['debug' => true, 'contextLines' => 5]);

        self::assertSame('ErrorDebugger', $enabledDebugger->getName());
        self::assertSame(ErrorDebugger::VERSION, $enabledDebugger->getVersion());
        self::assertSame([], $enabledDebugger->dependencies());
        self::assertFalse($disabledDebugger->isDebugEnabled());
        self::assertTrue($enabledDebugger->isDebugEnabled());

        $html = $enabledDebugger->renderThrowable(new RuntimeException('Module self-test error', 422));
        self::assertStringContainsString('Module self-test error', $html);
        self::assertStringContainsString('Line ', $html);
    }
}
