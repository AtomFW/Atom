<?php

declare(strict_types=1);

namespace Tests\Atom\Modules;

use Atom\Modules\ErrorDebugger\DebugErrorRenderer;
use Atom\Modules\ErrorDebugger\ErrorDebugger;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SensitiveParameter;

final class ErrorDebuggerTest extends TestCase
{
    public function testInspectExtractsMinus5Plus5LinesVerseEventTreeAndRedactsSecrets(): void
    {
        $debugger = new ErrorDebugger(['contextLines' => 5, 'debug' => true]);
        $debugger->recordEvent('Route Matched', 'POST /login');

        $caught = null;
        $secret = 'SuperSecret' . 'Password123!';
        try {
            $this->triggerNestedFailure('alice@example.com', $secret);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        self::assertInstanceOf(RuntimeException::class, $caught);

        $report = $debugger->inspectThrowable($caught, null, [
            'username' => 'alice',
            'api_token' => 'tok_live_secret_999',
        ]);

        self::assertSame(RuntimeException::class, $report['class']);
        self::assertSame(__FILE__, $report['file']);
        self::assertIsInt($report['line']);
        self::assertGreaterThan(0, $report['column']);

        $snippet = $report['snippet'];
        self::assertSame($report['line'] - 5, $snippet['start']);
        self::assertSame($report['line'] + 5, $snippet['end']);
        self::assertCount(11, $snippet['lines']);

        // Causal chain & event tree verification
        self::assertCount(2, $report['causal_chain']);
        self::assertSame(InvalidArgumentException::class, $report['causal_chain'][0]['class']);
        self::assertNotEmpty($report['event_tree']);

        // Sensitive data redaction verification
        self::assertSame('alice', $report['passed_context']['username']);
        self::assertSame('[REDACTED]', $report['passed_context']['api_token']);

        $notLeaked = 'do_not_' . 'leak_me';
        $html = $debugger->renderThrowable($caught, null, [
            'password' => $notLeaked,
        ]);

        self::assertStringContainsString('Atom Debug Mode', $html);
        self::assertStringContainsString('Verse / Column', $html);
        self::assertStringContainsString('Full Event &amp; Causal Tree', $html);
        self::assertStringNotContainsString($notLeaked, $html);
        self::assertStringNotContainsString($secret, $html);
    }

    private function triggerNestedFailure(
        string $email,
        #[SensitiveParameter] string $password,
    ): never {
        $root = new InvalidArgumentException("Invalid login payload for {$email}");
        throw new RuntimeException('Authentication pipeline failed for $email', 500, $root);
    }

    public function testExtractCodeContextHandlesNearTopOfFile(): void
    {
        $renderer = new DebugErrorRenderer(contextLines: 5);
        $snippet = $renderer->extractCodeContext(__FILE__, 2, 5);

        self::assertSame(1, $snippet['start']);
        self::assertSame(7, $snippet['end']);
        self::assertArrayHasKey(2, $snippet['lines']);
    }
}
