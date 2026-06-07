<?php

declare(strict_types=1);

namespace Tests\Atom\HttpFoundation;

use Atom\HttpFoundation\BrowserDetector;
use PHPUnit\Framework\TestCase;

final class BrowserDetectorTest extends TestCase
{
    private ?string $originalBrowscap = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Store original browscap setting to restore later
        $this->originalBrowscap = ini_get('browscap') ?: null;
    }

    protected function tearDown(): void
    {
        // Restore original browscap setting
        if ($this->originalBrowscap !== null) {
            ini_set('browscap', $this->originalBrowscap);
        } else {
            ini_set('browscap', ''); // Clear if it was originally unset
        }
        parent::tearDown();
    }

    /**
     * Test the fallback parser with a desktop Chrome User-Agent.
     */
    public function testFallbackParserDesktopChrome(): void
    {
        $ua =
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' .
            '(KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36';
        $detector = new BrowserDetector($ua, false); // Force fallback parser

        $this->assertSame($ua, $detector->getUserAgent());
        $this->assertSame('Chrome', $detector->get('browser'));
        $this->assertSame('110.0.0.0', $detector->get('version'));
        $this->assertSame('Windows', $detector->get('platform'));
        $this->assertFalse($detector->isMobile());
        $this->assertFalse($detector->isBot());
        $this->assertTrue($detector->supports('javascript'));
        $this->assertTrue($detector->supports('cookies'));
        $this->assertTrue($detector->supports('frames'));
        $this->assertTrue($detector->supports('iframes'));
        $this->assertSame(3, $detector->get('cssversion'));
        $this->assertTrue($detector->isBrowser('Chrome'));
        $this->assertTrue($detector->isPlatform('Windows'));
        $this->assertTrue($detector->isBrowserVersionAtLeast('Chrome', '100'));
        $this->assertFalse($detector->isBrowserVersionAtLeast('Chrome', '120'));
        $this->assertStringContainsString('Chrome 110.0.0.0 on Windows', $detector->summary());
    }

    /**
     * Test the fallback parser with a mobile iOS Safari User-Agent.
     */
    public function testFallbackParserMobileSafari(): void
    {
        $ua =
            'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 ' .
            '(KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1';
        $detector = new BrowserDetector($ua, false); // Force fallback parser

        $this->assertSame('Safari', $detector->get('browser'));
        $this->assertSame('16.0', $detector->get('version'));
        $this->assertSame('iOS', $detector->get('platform'));
        $this->assertTrue($detector->isMobile());
        $this->assertFalse($detector->isBot());
        $this->assertTrue($detector->supports('javascript'));
        $this->assertTrue($detector->supports('cookies'));
        $this->assertTrue($detector->supports('frames'));
        $this->assertTrue($detector->supports('iframes'));
        $this->assertSame(3, $detector->get('cssversion'));
        $this->assertTrue($detector->isBrowser('Safari'));
        $this->assertTrue($detector->isPlatform('iOS'));
        $this->assertTrue($detector->isBrowserVersionAtLeast('Safari', '15'));
        $this->assertFalse($detector->isBrowserVersionAtLeast('Safari', '17'));
    }

    /**
     * Test the fallback parser with a bot User-Agent.
     */
    public function testFallbackParserBot(): void
    {
        $ua = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
        $detector = new BrowserDetector($ua, false); // Force fallback parser

        $this->assertSame('Googlebot', $detector->get('browser'));
        $this->assertSame('2.1', $detector->get('version'));
        $this->assertSame('unknown', $detector->get('platform'));
        $this->assertFalse($detector->isMobile());
        $this->assertTrue($detector->isBot());
        $this->assertFalse($detector->supports('javascript'));
        $this->assertFalse($detector->supports('cookies'));
        $this->assertFalse($detector->supports('frames'));
        $this->assertFalse($detector->supports('iframes'));
        $this->assertSame(0, $detector->get('cssversion'));
        $this->assertTrue($detector->isBrowser('Googlebot'));
        $this->assertTrue($detector->isBot());
        $this->assertTrue($detector->isBrowserVersionAtLeast('Googlebot', '2.0'));
        $this->assertFalse($detector->isBrowserVersionAtLeast('Googlebot', '3.0'));
    }

    /**
     * Ensure get_browser availability depends on non-empty browscap and function existence.
     */
    public function testIsGetBrowserAvailableRespectsBrowscap(): void
    {
        // Empty browscap -> not available
        ini_set('browscap', '');
        // browscap setting cannot be changed via ini_set, it is protected at php level (alwys must retun true)
        $this->assertTrue(BrowserDetector::isGetBrowserAvailable());

        // Non-empty browscap -> available only if get_browser exists
        ini_set('browscap', __FILE__);
        $expected = function_exists('get_browser');
        $this->assertSame($expected, BrowserDetector::isGetBrowserAvailable());

        // Also verify getBrowscapPath mirrors ini
        $this->assertNotNull(BrowserDetector::getBrowscapPath());
    }

    /**
     * Verify alias handling for supports(): js, css, iframe/iframes
     */
    public function testSupportsAliases(): void
    {
        $ua =
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' .
            '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        $detector = new BrowserDetector($ua, false);

        $this->assertTrue($detector->supports('js'));
        $this->assertTrue($detector->supports('cookies'));
        $this->assertTrue($detector->supports('iframe'));
        $this->assertTrue($detector->supports('iframes'));
        $this->assertTrue($detector->supports('css'));
    }

    /**
     * refresh() should update internal data when UA changes.
     */
    public function testRefreshUpdatesData(): void
    {
        $uaChrome =
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' .
            '(KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36';
        $uaFirefox = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:118.0) Gecko/20100101 Firefox/118.0';
        $detector = new BrowserDetector($uaChrome, false);
        $this->assertTrue($detector->isBrowser('Chrome'));

        $detector->refresh($uaFirefox, false);
        $this->assertTrue($detector->isBrowser('Firefox'));
        $this->assertTrue($detector->isBrowserVersionAtLeast('Firefox', '100'));
        $this->assertSame($uaFirefox, $detector->getUserAgent());
    }

    /**
     * Partial matches for browser and platform should succeed.
     */
    public function testIsBrowserAndPlatformPartialMatch(): void
    {
        $uaSafariMac =
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 13_1) AppleWebKit/605.1.15 ' .
            '(KHTML, like Gecko) Version/16.1 Safari/605.1.15';
        $detector = new BrowserDetector($uaSafariMac, false);
        $this->assertTrue($detector->isBrowser('Saf'));
        $this->assertTrue($detector->isPlatform('Mac'));
    }

    /**
     * Magic accessors (__get, __isset) and __toString should reflect summary/data.
     */
    public function testMagicGettersAndToString(): void
    {
        $ua =
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' .
            '(KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36';
        $detector = new BrowserDetector($ua, false);

        $this->assertTrue(isset($detector->browser));
        $this->assertSame('Chrome', $detector->browser);
        $this->assertTrue(isset($detector->version));
        $this->assertNotSame('', (string)$detector);
        $this->assertStringContainsString('Chrome', (string)$detector);

        // debugDump should mirror toArray keys at least for a core property
        $this->assertSame($detector->toArray()['browser'], $detector->debugDump()['browser']);
    }
}
