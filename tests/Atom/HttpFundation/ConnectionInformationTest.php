<?php

declare(strict_types=1);

namespace Tests\Atom\HttpFoundation;

use Atom\HttpFoundation\ConnectionInformation;
use Atom\Log\T4LOG;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class ConnectionInformationTest extends TestCase
{
    private function makeLogger(): T4LOG
    {
        // T4LOG requires a path; use runtime dir to avoid permissions issues
        return new T4LOG(__DIR__ . '/../../../../runtime');
    }

    private function makeCache(): CacheInterface
    {
        // Use a simple in-memory cache stub implementing PSR-16 for tests
        return new class implements CacheInterface {
            private array $store = [];
            public function get($key, $default = null) { return $this->store[$key] ?? $default; }
            public function set($key, $value, $ttl = null) { $this->store[$key] = $value; return true; }
            public function delete($key) { unset($this->store[$key]); return true; }
            public function clear() { $this->store = []; return true; }
            public function getMultiple($keys, $default = null) { $out=[]; foreach ($keys as $k){$out[$k]=$this->get($k,$default);} return $out; }
            public function setMultiple($values, $ttl = null) { foreach ($values as $k=>$v){$this->set($k,$v,$ttl);} return true; }
            public function deleteMultiple($keys) { foreach ($keys as $k){$this->delete($k);} return true; }
            public function has($key) { return array_key_exists($key, $this->store); }
        };
    }

    public function testToArrayStructureWithoutBrowscap(): void
    {
        $ua = 'TestAgent/1.0';
        $ci = new ConnectionInformation($this->makeCache(), $this->makeLogger(), $ua, false);
        $arr = $ci->toArray();
        $this->assertArrayHasKey('whichbrowser', $arr);
        $this->assertArrayHasKey('combined', $arr);
        $this->assertSame($ua, $ci->getUserAgent());
        $this->assertIsArray($arr['whichbrowser']);
        $this->assertIsArray($arr['combined']);
    }

    public function testGettersHaveSafeDefaults(): void
    {
        $ci = new ConnectionInformation($this->makeCache(), $this->makeLogger(), 'UA/2.0', false);
        $this->assertIsString($ci->getBrowserName());
        $this->assertTrue(is_string($ci->getPlatform()));
        $this->assertTrue(is_bool($ci->isBot()));
        $this->assertTrue(is_bool($ci->isMobile()));
        $this->assertTrue(is_bool($ci->isTablet()));
        $this->assertTrue(is_bool($ci->isDesktop()));
    }

    public function testVersionComparisonHandlesNullVersion(): void
    {
        $ci = new ConnectionInformation($this->makeCache(), $this->makeLogger(), 'UA/3.0', false);
        // When version cannot be determined, isBrowserVersion should be false
        $this->assertFalse($ci->isBrowserVersion('100'));
        $this->assertFalse($ci->isBrowserVersionAtLeast('100'));
    }

    public function testSupportsHeuristics(): void
    {
        $ci = new ConnectionInformation($this->makeCache(), $this->makeLogger(), 'UA/4.0', false);
        // With no browscap data, JS and cookies fall back to heuristics
        $this->assertTrue(is_bool($ci->supportsJavascript()));
        $this->assertTrue(is_bool($ci->supportsCookies()));
        $this->assertTrue($ci->supportsCssLevel(1));
        $this->assertTrue(is_bool($ci->supportsCssLevel(3)));
    }

    public function testCachingByUserAgent(): void
    {
        $cache = $this->makeCache();
        $logger = $this->makeLogger();
        $ua = 'CacheTest/1.0';
        $ci1 = new ConnectionInformation($cache, $logger, $ua, false);
        $first = $ci1->toArray();
        $ci2 = new ConnectionInformation($cache, $logger, $ua, false);
        $second = $ci2->toArray();
        $this->assertEquals($first, $second, 'Same UA should hit static cache and yield same parse result');
    }
}
