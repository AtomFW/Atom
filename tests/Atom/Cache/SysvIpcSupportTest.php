<?php

declare(strict_types=1);

namespace Tests\Atom\Cache;

use PHPUnit\Framework\TestCase;
use Atom\Cache\SysvIpcSupport;
use Atom\Cache\SysvIpcException;

/**
 * Test suite for the SysvIpcSupport component.
 */
class SysvIpcSupportTest extends TestCase
{
    /**
     * Test require function with valid extension and function.
     */
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

    /**
     * Test require function with missing extension.
     */
    public function testRequireFunctionWithMissingExtension(): void
    {
        $this->expectException(SysvIpcException::class);
        $this->expectExceptionMessage('No PHP extension required:');
        
        // This would require mocking or a non-existent extension
        // For testing purposes, we'll just ensure it doesn't crash with invalid params
        SysvIpcSupport::requireFunction('non_existent_function', 'non_existent_extension');
    }

    /**
     * Test require function with missing function.
     */
    public function testRequireFunctionWithMissingFunction(): void
    {
        $this->expectException(SysvIpcException::class);
        $this->expectExceptionMessage('Missing required PHP function:');
        
        // This would require a valid extension but missing function
        // Testing with a valid extension but non-existent function
        SysvIpcSupport::requireFunction('non_existent_function', 'Core');
    }

    /**
     * Test derive key.
     */
    public function testDeriveKey(): void
    {
        $baseKey = 12345;
        $namespace = 'test_namespace';
        $suffix = 'test_suffix';
        
        $result = SysvIpcSupport::deriveKey($baseKey, $namespace, $suffix);
        
        $this->assertIsInt($result);
        $this->assertGreaterThan(0, $result);
    }

    /**
     * Test derive key consistency.
     */
    public function testDeriveKeyConsistency(): void
    {
        $baseKey = 12345;
        $namespace = 'test_namespace';
        $suffix = 'test_suffix';
        
        $result1 = SysvIpcSupport::deriveKey($baseKey, $namespace, $suffix);
        $result2 = SysvIpcSupport::deriveKey($baseKey, $namespace, $suffix);
        
        $this->assertEquals($result1, $result2);
    }

    /**
     * Test is expired with null.
     */
    public function testIsExpiredWithNull(): void
    {
        $result = SysvIpcSupport::isExpired(null);
        $this->assertFalse($result);
    }

    /**
     * Test is expired with empty string.
     */
    public function testIsExpiredWithEmptyString(): void
    {
        $result = SysvIpcSupport::isExpired('');
        $this->assertFalse($result);
    }

    /**
     * Test is expired with zero.
     */
    public function testIsExpiredWithZero(): void
    {
        $result = SysvIpcSupport::isExpired(0);
        $this->assertFalse($result);
    }

    /**
     * Test is expired with future time.
     */
    public function testIsExpiredWithFutureTime(): void
    {
        $futureTime = time() + 3600; // 1 hour in future
        $result = SysvIpcSupport::isExpired($futureTime);
        $this->assertFalse($result);
    }

    /**
     * Test is expired with past time.
     */
    public function testIsExpiredWithPastTime(): void
    {
        $pastTime = time() - 3600; // 1 hour ago
        $result = SysvIpcSupport::isExpired($pastTime);
        $this->assertTrue($result);
    }

    /**
     * Test is expired with current time.
     */
    public function testIsExpiredWithCurrentTime(): void
    {
        $currentTime = time();
        $result = SysvIpcSupport::isExpired($currentTime);
        $this->assertTrue($result); // Current time is considered expired (equal to or less than now)
    }

    /**
     * Test compute expires at with null ttl.
     */
    public function testComputeExpiresAtWithNullTtl(): void
    {
        $before = time();
        $result = SysvIpcSupport::computeExpiresAt(null, 3600);
        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual($before + 3600, $result);
    }

    /**
     * Test compute expires at with zero ttl.
     */
    public function testComputeExpiresAtWithZeroTtl(): void
    {
        $result = SysvIpcSupport::computeExpiresAt(0, 3600);
        $this->assertNull($result);
    }

    /**
     * Test compute expires at with negative ttl.
     */
    public function testComputeExpiresAtWithNegativeTtl(): void
    {
        $result = SysvIpcSupport::computeExpiresAt(-3600, 3600);
        $this->assertNull($result);
    }

    /**
     * Test compute expires at with valid ttl.
     */
    public function testComputeExpiresAtWithValidTtl(): void
    {
        $ttl = 3600; // 1 hour
        $defaultTtl = 7200; // 2 hours
        $result = SysvIpcSupport::computeExpiresAt($ttl, $defaultTtl);
        
        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(time() + $ttl, $result);
    }

    /**
     * Test compute expires at with default ttl.
     */
    public function testComputeExpiresAtWithDefaultTtl(): void
    {
        $defaultTtl = 3600; // 1 hour
        $result = SysvIpcSupport::computeExpiresAt(null, $defaultTtl);
        
        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(time() + $defaultTtl, $result);
    }
}
