<?php

declare(strict_types=1);

namespace Tests\Atom\Config;

use PHPUnit\Framework\TestCase;
use Atom\Config\EnvironmentVariables;

/**
 * Test suite for the EnvironmentVariables component.
 */
class EnvironmentVariablesTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear $_ENV before each test
        $_ENV = [];
    }

    /**
     * Test get environment variable with valid key and default value returns value.
     */
    public function test_getEnvironmentVariable_WithValidKeyAndDefaultValue_returnsValue()
    {
        $_ENV['TEST_KEY'] = 'test_value';
        
        $result = EnvironmentVariables::getEnvironmentVariable('TEST_KEY', 'default');
        $this->assertEquals('test_value', $result);
    }

    /**
     * Test get environment variable with missing key and default value returns default value.
     */
    public function test_getEnvironmentVariable_WithMissingKeyAndDefaultValue_returnsDefaultValue()
    {
        $result = EnvironmentVariables::getEnvironmentVariable('NONEXISTENT_KEY', 'default_value');
        $this->assertEquals('default_value', $result);
    }

    /**
     * Test get environment variable with missing key and no default value returns null.
     */
    public function test_getEnvironmentVariable_WithMissingKeyAndNoDefaultValue_returnsNull()
    {
        $result = EnvironmentVariables::getEnvironmentVariable('NONEXISTENT_KEY');
        $this->assertNull($result);
    }

    /**
     * Test normalize env value true string returns true.
     */
    public function test_normalizeEnvValue_TrueStringReturnsTrue()
    {
        $result = $this->invokeMethod('normalizeEnvValue', ['true']);
        $this->assertTrue($result);
    }

    /**
     * Test normalize env value parentheses true returns true.
     */
    public function test_normalizeEnvValue_ParenthesesTrueReturnsTrue()
    {
        $result = $this->invokeMethod('normalizeEnvValue', ['(true)']);
        $this->assertTrue($result);
    }

    /**
     * Test normalize env value false string returns false.
     */
    public function test_normalizeEnvValue_FalseStringReturnsFalse()
    {
        $result = $this->invokeMethod('normalizeEnvValue', ['false']);
        $this->assertFalse($result);
    }

    /**
     * Test normalize env value parentheses false returns false.
     */
    public function test_normalizeEnvValue_ParenthesesFalseReturnsFalse()
    {
        $result = $this->invokeMethod('normalizeEnvValue', ['(false)']);
        $this->assertFalse($result);
    }

    /**
     * Test normalize env value empty string returns empty string.
     */
    public function test_normalizeEnvValue_EmptyStringReturnsEmptyString()
    {
        $result = $this->invokeMethod('normalizeEnvValue', ['empty']);
        $this->assertEquals('', $result);
    }

    /**
     * Test normalize env value parentheses empty returns empty string.
     */
    public function test_normalizeEnvValue_ParenthesesEmptyReturnsEmptyString()
    {
        $result = $this->invokeMethod('normalizeEnvValue', ['(empty)']);
        $this->assertEquals('', $result);
    }

    /**
     * Test normalize env value null string returns null.
     */
    public function test_normalizeEnvValue_NullStringReturnsNull()
    {
        $result = $this->invokeMethod('normalizeEnvValue', ['null']);
        $this->assertNull($result);
    }

    /**
     * Test normalize env value parentheses null returns null.
     */
    public function test_normalizeEnvValue_ParenthesesNullReturnsNull()
    {
        $result = $this->invokeMethod('normalizeEnvValue', ['(null)']);
        $this->assertNull($result);
    }

    /**
     * Test normalize env value unknown string returns original.
     */
    public function test_normalizeEnvValue_UnknownStringReturnsOriginal()
    {
        $result = $this->invokeMethod('normalizeEnvValue', ['unknown']);
        $this->assertEquals('unknown', $result);
    }

    /**
     * Test ensure required environment variables with all variables set does not throw exception.
     */
    public function test_ensureRequiredEnvironmentVariables_WithAllVariablesSet_DoesNotThrowException()
    {
        // Set up environment variables
        $_ENV['DB_HOST'] = 'localhost';
        $_ENV['DB_PORT'] = '3306';
        $_ENV['DB_DATABASE'] = 'testdb';
        $_ENV['DB_USERNAME'] = 'user';
        $_ENV['DB_PASSWORD'] = 'password';
        
        $this->expectNotToPerformAssertions();
        $this->invokeMethod('ensureRequiredEnvironmentVariables');
    }

    /**
     * Test ensure required environment variables with missing variable throws exception.
     */
    public function test_ensureRequiredEnvironmentVariables_WithMissingVariable_ThrowsException()
    {
        // Set up environment variables but leave one out
        $_ENV['DB_HOST'] = 'localhost';
        $_ENV['DB_PORT'] = '3306';
        $_ENV['DB_DATABASE'] = 'testdb';
        $_ENV['DB_USERNAME'] = 'user';
        // Note: DB_PASSWORD is missing
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Required environment variable \'DB_PASSWORD\' is not set for config \'database\'.');

        $this->invokeMethod('ensureRequiredEnvironmentVariables');
    }

    /**
     * Test ensure required environment variables with empty environment throws exception.
     */
    public function test_ensureRequiredEnvironmentVariables_WithEmptyEnvironment_ThrowsException()
    {
        // No environment variables set - this should work because the method iterates
        // through static::$environmentEnsuredKeys which is defined in a class that extends
        // LoadEnvironmentVariables, so we don't need to worry about this in test
        
        $this->expectException(\RuntimeException::class);
        $this->invokeMethod('ensureRequiredEnvironmentVariables');
    }

    /**
     * Helper method to invoke protected methods
     */
    private function invokeMethod($methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(EnvironmentVariables::class);
        $method = $reflection->getMethod($methodName);
        return $method->invoke(null, ...$parameters);
    }
}
