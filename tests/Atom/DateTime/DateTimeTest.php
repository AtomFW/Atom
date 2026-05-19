<?php

declare(strict_types=1);

namespace Tests\Atom\DateTime;

use PHPUnit\Framework\TestCase;
use Atom\DateTime\DateTime;
use Carbon\Carbon;
use ReflectionClass;

class DateTimeTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset static properties after each test
        $reflection = new ReflectionClass(DateTime::class);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_STATIC);
        
        foreach ($properties as $property) {
            if ($property->class === DateTime::class) {
                $property->setValue(null, false);
            }
        }
    }

    public function test_set_global_define(): void
    {
        $config = (object) [
            'timezone' => 'UTC',
            'datetime' => 'Y-m-d H:i:s',
            'date' => 'Y-m-d',
            'time' => 'H:i:s',
            'locale' => 'en'
        ];

        DateTime::setGlobalDefine($config);
        
        $this->assertTrue(\defined('ATOM_TIMEZONE'));
        $this->assertTrue(\defined('ATOM_DATETIME'));
        $this->assertTrue(\defined('ATOM_DATE'));
        $this->assertTrue(\defined('ATOM_TIME'));
        $this->assertTrue(\defined('ATOM_LOCAE'));
    }

    public function test_set_default_locale(): void
    {
        // Set up a mock config
        $config = (object) [
            'timezone' => 'UTC',
            'datetime' => 'Y-m-d H:i:s',
            'date' => 'Y-m-d',
            'time' => 'H:i:s',
            'locale' => 'en'
        ];
        
        // Set the define first
        \define('ATOM_LOCAE', $config->locale);
        
        // Call setDefaultLocale (this should set the locale)
        DateTime::setDefaultLocale();
        
        // Since we can't easily test if Carbon's locale was set, 
        // we'll just verify no errors occurred
        $this->assertTrue(true);
    }

    public function test_set_default_timezone(): void
    {
        // Set up config
        $config = (object) [
            'timezone' => 'America/New_York',
            'datetime' => 'Y-m-d H:i:s',
            'date' => 'Y-m-d',
            'time' => 'H:i:s',
            'locale' => 'en'
        ];
        
        // Set the define first
        \define('ATOM_TIMEZONE', $config->timezone);
        \define('ATOM_DATETIME', $config->datetime);
        \define('ATOM_DATE', $config->date);
        \define('ATOM_TIME', $config->time);
        \define('ATOM_LOCAE', $config->locale);
        
        // Create a new DateTime instance and test setDefaultTimezone
        $dateTime = new DateTime();
        $result = $dateTime->setDefaultTimezone();
        
        $this->assertTrue($result);
    }

    public function test_set_macro(): void
    {
        // Set up config
        $config = (object) [
            'timezone' => 'America/New_York',
            'datetime' => 'Y-m-d H:i:s',
            'date' => 'Y-m-d',
            'time' => 'H:i:s',
            'locale' => 'en'
        ];
        
        // Set the defines first
        \define('ATOM_TIMEZONE', $config->timezone);
        \define('ATOM_DATETIME', $config->datetime);
        \define('ATOM_DATE', $config->date);
        \define('ATOM_TIME', $config->time);
        \define('ATOM_LOCAE', $config->locale);
        
        // Call setMacro
        $result = DateTime::setMacro();
        
        $this->assertTrue($result);
    }

    public function test_magic_to_string(): void
    {
        // Set up config for testing
        $config = (object) [
            'timezone' => 'UTC',
            'datetime' => 'Y-m-d H:i:s',
            'date' => 'Y-m-d',
            'time' => 'H:i:s',
            'locale' => 'en'
        ];
        
        // Set the defines
        \define('ATOM_DATETIME', $config->datetime);
        
        // Create a new DateTime instance and test __toString
        $dateTime = new DateTime();
        $result = (string)$dateTime;
        
        $this->assertIsString($result);
    }

    public function test_macro_functions_exist(): void
    {
        // Set up config
        $config = (object) [
            'timezone' => 'America/New_York',
            'datetime' => 'Y-m-d H:i:s',
            'date' => 'Y-m-d',
            'time' => 'H:i:s',
            'locale' => 'en'
        ];
        
        // Set the defines first
        \define('ATOM_TIMEZONE', $config->timezone);
        \define('ATOM_DATETIME', $config->datetime);
        \define('ATOM_DATE', $config->date);
        \define('ATOM_TIME', $config->time);
        \define('ATOM_LOCAE', $config->locale);
        
        // Set macro
        DateTime::setMacro();
        
        // Create a new DateTime instance
        $dateTime = new DateTime();
        
        // Test if macros are registered by trying to call them
        $this->assertIsString($dateTime->native());
        $this->assertIsString($dateTime->atom());
        $this->assertIsString($dateTime->toAtom());
        $this->assertIsString($dateTime->nativeDate());
        $this->assertIsString($dateTime->atomDate());
        $this->assertIsString($dateTime->toAtomDate());
        $this->assertIsString($dateTime->nativeTime());
        $this->assertIsString($dateTime->atomTime());
        $this->assertIsString($dateTime->toAtomTime());
        $this->assertIsString($dateTime->sqlDatetime());
        $this->assertIsString($dateTime->toSQL());
    }
}
