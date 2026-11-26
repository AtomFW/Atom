<?php

declare(strict_types=1);

namespace Tests\Atom\Config;

use PHPUnit\Framework\TestCase;
use Atom\Config\EnvironmentVariables;

/**
 * Test suite for the EnvFunction component.
 */
class EnvFunctionTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear $_ENV before each test
        $_ENV = [];
    }

    /**
     * Test env function exists.
     */
    public function test_env_FunctionExists()
    {
        $this->assertTrue(function_exists('env'));
    }

    /**
     * Test env with valid key and default value returns value.
     */
    public function test_env_WithValidKeyAndDefaultValue_ReturnsValue()
    {
        $_ENV['TEST_KEY'] = 'test_value';
        
        $result = env('TEST_KEY', 'default');
        $this->assertEquals('test_value', $result);
    }

    /**
     * Test env with missing key and default value returns default value.
     */
    public function test_env_WithMissingKeyAndDefaultValue_ReturnsDefaultValue()
    {
        $result = env('NONEXISTENT_KEY', 'default_value');
        $this->assertEquals('default_value', $result);
    }

    /**
     * Test env with missing key and no default value returns null.
     */
    public function test_env_WithMissingKeyAndNoDefaultValue_ReturnsNull()
    {
        $result = env('NONEXISTENT_KEY');
        $this->assertNull($result);
    }

    /**
     * Test env with empty string key returns value.
     */
    public function test_env_WithEmptyStringKey_ReturnsValue()
    {
        $_ENV[''] = 'empty_key_value';
        $result = env('', 'default');
        $this->assertEquals('empty_key_value', $result);
    }

    /**
     * Test env with numeric key returns value.
     */
    public function test_env_WithNumericKey_ReturnsValue()
    {
        $_ENV[123] = 'numeric_key_value';
        $result = env('123', 'default');
        $this->assertEquals('numeric_key_value', $result);
    }

    /**
     * Test env with zero value returns zero.
     */
    public function test_env_WithZeroValue_ReturnsZero()
    {
        $_ENV['TEST'] = '0';
        $result = env('TEST', 'default');
        $this->assertEquals('0', $result);
    }

    /**
     * Test env with false value returns false.
     */
    public function test_env_WithFalseValue_ReturnsFalse()
    {
        $_ENV['TEST'] = 'false';
        $result = env('TEST', 'default');
        $this->assertFalse($result);
    }

    /**
     * Test env with null value returns null.
     */
    public function test_env_WithNullValue_ReturnsNull()
    {
        $_ENV['TEST'] = 'null';
        $result = env('TEST', 'default');
        $this->assertNull($result);
    }
}
