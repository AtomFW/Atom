<?php

declare(strict_types=1);

namespace Tests\Atom\Detect;

use PHPUnit\Framework\TestCase;
use Atom\Detect\BotDetector;

// Assume the class to be tested is defined as follows (or similar structure):
// If your `crawlerDetectTried` class is in a namespace, adjust the `use` statement accordingly.
// If your `crawlerDetectTried` class is not autoloaded, you might need a `require_once` statement.


final class BotDetectorTest extends TestCase
{
    public function testIsBotWithKnownBotUserAgent(): void
    {
        $detector = new BotDetector();
        $requestData = ['user_agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'];
        $this->assertTrue($detector->isBot($requestData));
    }

    public function testIsBotWithRegularUserAgent(): void
    {
        $detector = new BotDetector();
        $requestData = ['user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.4896.127 Safari/537.36'];
        $this->assertFalse($detector->isBot($requestData));
    }

    public function testIsBotWithHoneypotFieldFilled(): void
    {
        $detector = new BotDetector();
        $requestData = ['hp_field' => 'some_value'];
        $this->assertTrue($detector->isBot($requestData));
    }

    public function testIsBotWithEmptyUserAgent(): void
    {
        $detector = new BotDetector();
        $requestData = ['user_agent' => ''];
        $this->assertFalse($detector->isBot($requestData));
    }

    public function testIsBotWithMissingHoneypotField(): void
    {
        $detector = new BotDetector();
        $requestData = [];
        $this->assertFalse($detector->isBot($requestData));
    }
}
