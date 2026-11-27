<?php

declare(strict_types=1);

namespace Tests\Atom\Terminal;

use Atom\Terminal\ProgressBar;
use PHPUnit\Framework\TestCase;
use Time\Duration;

final class ProgressBarTest extends TestCase
{
    public function testProgressBarRendersPercentageStepsAndDuration(): void
    {
        $stream = \fopen('php://memory', 'r+b');
        self::assertIsResource($stream);

        try {
            $bar = new ProgressBar(total: 4, width: 20, stream: $stream, colors: false);
            $startLine = $bar->start('Init');
            self::assertStringContainsString('0% (0/4)', $startLine);
            self::assertStringContainsString('Init', $startLine);

            $midLine = $bar->advance(2, 'Halfway');
            self::assertStringContainsString('50% (2/4)', $midLine);
            self::assertSame(2, $bar->getCurrent());

            $doneLine = $bar->finish('Complete');
            self::assertStringContainsString('100% (4/4)', $doneLine);
            self::assertStringContainsString('Complete', $doneLine);

            self::assertInstanceOf(Duration::class, $bar->elapsedDuration());

            \rewind($stream);
            $written = (string) \stream_get_contents($stream);
            self::assertStringContainsString('100%', $written);
        } finally {
            \fclose($stream);
        }
    }
}
