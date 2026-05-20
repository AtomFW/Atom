<?php

declare(strict_types=1);

namespace Atom\DateTime;

use Carbon\Carbon;
use Carbon\Traits\Date;
use Carbon\CarbonTimeZone;
use Carbon\Month;
use Carbon\Translator;
use Carbon\WeekDay;
use DateTimeInterface;
use IntlDateFormatter;

/**
 * DateTime class that extends Carbon
 *
 * This class is a wrapper around the Carbon date/time library.
 * It provides the same functionality as Carbon, but with additional
 * methods and properties that are specific to the Atom framework.
 *
 * @package Atom\DateTime
 * @author  Basem Aly <basem@a13technology.com>
 * @license MIT
 */
final class DateTime extends Carbon
{
    /**
     * Whether the global configuration has been set.
     *
     * This variable is used to track whether the global configuration
     * has been set or not. It is set to true when the global configuration
     * is set and false otherwise.
     *
     * @var bool
     */
    private static bool $isSetConfig = false;
    /**
     * Whether the global define for the Atom framework has been set.
     *
     * This variable is used to track whether the global define for the Atom
     * framework has been set or not. It is set to true when the global define
     * is set and false otherwise.
     *
     * @var bool
     */
    private static bool $isSetDefine = false;
    /**
     * Whether the global macro has been set.
     *
     * This variable is used to track whether the global macro has been set
     * or not. It is set to true when the global macro is set and false
     * otherwise.
     *
     * @var bool
     */
    private static bool $isSetMacro = false;
    
    /**
     * Set the global define for the Atom framework
     *
     * This method sets the global define for the Atom framework.
     * It sets the timezone, datetime, date, time, and locale defines.
     *
     * @param object $config The configuration object
     * @return void
     */
    public static function setGlobalDefine (object $config): void
    {
        if (self::$isSetDefine) {
            return;
        }

        \define('ATOM_TIMEZONE', $config->timezone);
        \define('ATOM_DATETIME', $config->datetime);
        \define('ATOM_DATE', $config->date);
        \define('ATOM_TIME', $config->time);
        \define('ATOM_LOCAE', $config->locale);

        self::$isSetDefine = true;
    }

    /**
     * Set the default locale.
     *
     * This method sets the default locale for the Atom framework.
     * It sets the locale to the value of the ATOM_LOCALE define if it is set,
     * otherwise it sets the locale to the 'en_US' locale.
     *
     * @return void
     */
    public static function setDefaultLocale (): void
    {
        if (self::$isSetConfig) {
            return;
        }

        \date_default_timezone_set("UTC");

        if (\defined("ATOM_LOCAE")) {
            parent::setLocale(ATOM_LOCAE);
        }

        self::$isSetConfig = true;
    }

    /**
     * Set the default timezone.
     *
     * This method sets the default timezone for the DateTime object.
     * It sets the timezone to the value of the ATOM_TIMEZONE define if it is set,
     * otherwise it sets the timezone to the 'UTC' timezone.
     *
     * @return bool True if the timezone is set, false otherwise.
     */
    public function setDefaultTimezone (): bool
    {
        if (\defined("ATOM_TIMEZONE")) {
            $this->timezone(ATOM_TIMEZONE);
            $this->settings ([
                'toStringFormat' => ATOM_DATETIME,
                'dateFormat' => ATOM_DATE,
                'timeFormat' => ATOM_TIME,
                'locale' => ATOM_LOCAE,
                'timezone' => ATOM_TIMEZONE
            ]);
            return true;
        }

        return false;
    }

    /**
     * Set the global macro for the Atom framework
     *
     * This method sets the global macro for the Atom framework. It sets
     * the native, atom, and toAtom macros.
     *
     * @return bool True if the macro is set, false otherwise.
     */
    public static function setMacro(): bool
    {
        if (self::$isSetMacro) {
            return false;
        }

        /*
            DATETIME
        */
        parent::macro("native", function (DateTimeInterface|WeekDay|Month|string|int|float|null $other = null) {
            return $this->parse($other)->locale(ATOM_LOCAE)->timezone(ATOM_TIMEZONE)->format(ATOM_DATETIME);
        });
        
        parent::macro("atom", function (DateTimeInterface|WeekDay|Month|string|int|float|null $other = null) {
            return $this->parse($other)->locale(ATOM_LOCAE)->timezone(ATOM_TIMEZONE)->format(ATOM_DATETIME);
        });
        
        parent::macro("toAtom", function (DateTimeInterface|WeekDay|Month|string|int|float|null $other = null) {
            return $this->parse($other)->locale(ATOM_LOCAE)->timezone(ATOM_TIMEZONE)->format(ATOM_DATETIME);
        });
        
        /*
            DATE
        */
        parent::macro("nativeDate", function (DateTimeInterface|WeekDay|Month|string|int|float|null $other = null) {
            return $this->parse($other)->locale(ATOM_LOCAE)->timezone(ATOM_TIMEZONE)->format(ATOM_DATE);
        });
        
        parent::macro("atomDate", function (DateTimeInterface|WeekDay|Month|string|int|float|null $other = null) {
            return $this->parse($other)->locale(ATOM_LOCAE)->timezone(ATOM_TIMEZONE)->format(ATOM_DATE);
        });
        
        parent::macro("toAtomDate", function (DateTimeInterface|WeekDay|Month|string|int|float|null $other = null) {
            return $this->parse($other)->locale(ATOM_LOCAE)->timezone(ATOM_TIMEZONE)->format(ATOM_DATE);
        });
        
        /*
            TIME
        */
        parent::macro("nativeTime", function (DateTimeInterface|WeekDay|Month|string|int|float|null $other = null) {
            return $this->parse($other)->locale(ATOM_LOCAE)->timezone(ATOM_TIMEZONE)->format(ATOM_TIME);
        });
        
        parent::macro("atomTime", function (DateTimeInterface|WeekDay|Month|string|int|float|null $other = null) {
            return $this->parse($other)->locale(ATOM_LOCAE)->timezone(ATOM_TIMEZONE)->format(ATOM_TIME);
        });
        
        parent::macro("toAtomTime", function (DateTimeInterface|WeekDay|Month|string|int|float|null $other = null) {
            return $this->parse($other)->locale(ATOM_LOCAE)->timezone(ATOM_TIMEZONE)->format(ATOM_TIME);
        });
        

        // sql datetime
        parent::macro("sqlDatetime", function (DateTimeInterface|WeekDay|Month|string|int|float|null $other = null) {
            return $this->parse($other)->format("Y-m-d H:i:s.u");
        });
        
        parent::macro("toSQL", function (DateTimeInterface|WeekDay|Month|string|int|float|null $other = null) {
            return $this->parse($other)->format("Y-m-d H:i:s.u");
        });

        self::$isSetMacro = true;
        return true;
    }

    /**
     * Returns a string representation of the DateTime object.
     *
     * This method returns a string representation of the DateTime object. If
     * the ATOM_DATETIME constant is defined, the method will use that
     * format. Otherwise, it will use the 'Y-m-d H:i:s' format.
     *
     * @return string A string representation of the DateTime object.
     */
    public function __toString(): string
    {
        if (\defined("ATOM_DATETIME")) {
            return $this->format(ATOM_DATETIME);
        }
        return $this->format('Y-m-d H:i:s');
    }
}
