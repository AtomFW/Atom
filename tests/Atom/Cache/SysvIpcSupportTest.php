<?php

declare(strict_types=1);

namespace Tests\Atom\Cache;

use PHPUnit\Framework\TestCase;
use Atom\Cache\SysvIpcSupport;
use Atom\Cache\SysvIpcException;

class SysvIpcSupportTest extends TestCase
{
    public function testRequireFunctionWithValidExtensionAndFunction(): void
    {
        // Test with a function that should exist
        $this->expectNotToPerformAssertions();
        
        try {
            SysvIpcSupport::requireFunction('time', 'Core');
        } catch (SysvIpcException $e) {
            $this->fail('Should not throw exception for valid function');
        }
    }

    public function testRequireFunctionWithMissingExtension(): void
    {
        $this->expectException(SysvIpcException::class);
        $this->expectExceptionMessage('Brak wymaganego rozszerzenia PHP:');
        
        // This would require mocking or a non-existent extension
        // For testing purposes, we'll just ensure it doesn't crash with invalid params
        SysvIpcSupport::requireFunction('non_existent_function', 'non_existent_extension');
    }

    public function testRequireFunctionWithMissingFunction(): void
    {
        $this->expectException(SysvIpcException::class);
        $this->expectExceptionMessage('Brak wymaganej funkcji PHP:');
        
        // This would require a valid extension but missing function
        // Testing with a valid extension but non-existent function
        SysvIpcSupport::requireFunction('non_existent_function', 'Core');
    }

    public function testDeriveKey(): void
    {
        $baseKey = 12345;
        $namespace = 'test_namespace';
        $suffix = 'test_suffix';
        
        $result = SysvIpcSupport::deriveKey($baseKey, $namespace, $suffix);
        
        $this->assertIsInt($result);
        $this->assertGreaterThan(0, $result);
    }

    public function testDeriveKeyConsistency(): void
    {
        $baseKey = 12345;
        $namespace = 'test_namespace';
        $suffix = 'test_suffix';
        
        $result1 = SysvIpcSupport::deriveKey($baseKey, $namespace, $suffix);
        $result2 = SysvIpcSupport::deriveKey($baseKey, $namespace, $suffix);
        
        $this->assertEquals($result1, $result2);
    }

    public function testIsExpiredWithNull(): void
    {
        $result = SysvIpcSupport::isExpired(null);
        $this->assertFalse($result);
    }

    public function testIsExpiredWithEmptyString(): void
    {
        $result = SysvIpcSupport::isExpired('');
        $this->assertFalse($result);
    }

    public function testIsExpiredWithZero(): void
    {
        $result = SysvIpcSupport::isExpired(0);
        $this->assertFalse($result);
    }

    public function testIsExpiredWithFutureTime(): void
    {
        $futureTime = time() + 3600; // 1 hour in future
        $result = SysvIpcSupport::isExpired($futureTime);
        $this->assertFalse($result);
    }

    public function testIsExpiredWithPastTime(): void
    {
        $pastTime = time() - 3600; // 1 hour ago
        $result = SysvIpcSupport::isExpired($pastTime);
        $this->assertTrue($result);
    }

    public function testIsExpiredWithCurrentTime(): void
    {
        $currentTime = time();
        $result = SysvIpcSupport::isExpired($currentTime);
        $this->assertTrue($result); // Current time is considered expired (equal to or less than now)
    }

    public function testComputeExpiresAtWithNullTtl(): void
    {
        $result = SysvIpcSupport::computeExpiresAt(null, 3600);
        $this->assertNull($result);
    }

    public function testComputeExpiresAtWithZeroTtl(): void
    {
        $result = SysvIpcSupport::computeExpiresAt(0, 3600);
        $this->assertNull($result);
    }

    public function testComputeExpiresAtWithNegativeTtl(): void
    {
        $result = SysvIpcSupport::computeExpiresAt(-3600, 3600);
        $this->assertNull($result);
    }

    public function testComputeExpiresAtWithValidTtl(): void
    {
        $ttl = 3600; // 1 hour
        $defaultTtl = 7200; // 2 hours
        $result = SysvIpcSupport::computeExpiresAt($ttl, $defaultTtl);
        
        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(time() + $ttl, $result);
    }

    public function testComputeExpiresAtWithDefaultTtl(): void
    {
        $defaultTtl = 3600; // 1 hour
        $result = SysvIpcSupport::computeExpiresAt(null, $defaultTtl);
        
        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(time() + $defaultTtl, $result);
    }
}
