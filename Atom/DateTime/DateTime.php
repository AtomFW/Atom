<?php

declare(strict_types=1);

namespace Atom\DateTime;

use Carbon\Carbon;
use Carbon\Month;
use Carbon\WeekDay;
use DateTimeInterface;
use Override;
use Stringable;
use Time\Duration;

/**
 * Atom DateTime wrapper around Carbon with native PHP 8.6 `\Time\Duration` interoperability.
 *
 * @method string native(DateTimeInterface|WeekDay|Month|string|int|float|null $other = null)
 * @method string atom(DateTimeInterface|WeekDay|Month|string|int|float|null $other = null)
 * @method string toAtom(DateTimeInterface|WeekDay|Month|string|int|float|null $other = null)
 * @method string nativeDate(DateTimeInterface|WeekDay|Month|string|int|float|null $other = null)
 * @method string atomDate(DateTimeInterface|WeekDay|Month|string|int|float|null $other = null)
 * @method string toAtomDate(DateTimeInterface|WeekDay|Month|string|int|float|null $other = null)
 * @method string nativeTime(DateTimeInterface|WeekDay|Month|string|int|float|null $other = null)
 * @method string atomTime(DateTimeInterface|WeekDay|Month|string|int|float|null $other = null)
 * @method string toAtomTime(DateTimeInterface|WeekDay|Month|string|int|float|null $other = null)
 * @method string sqlDatetime(DateTimeInterface|WeekDay|Month|string|int|float|null $other = null)
 * @method string toSQL(DateTimeInterface|WeekDay|Month|string|int|float|null $other = null)
 */
final class DateTime extends Carbon implements Stringable
{
    private static bool $isSetConfig = false;
    private static bool $isSetDefine = false;
    private static bool $isSetMacro = false;

    public static function setGlobalDefine(object $config): void
    {
        if (self::$isSetDefine) {
            return;
        }

        if (!\defined('ATOM_TIMEZONE')) {
            \define('ATOM_TIMEZONE', (string) $config->timezone);
        }
        if (!\defined('ATOM_DATETIME')) {
            \define('ATOM_DATETIME', (string) $config->datetime);
        }
        if (!\defined('ATOM_DATE')) {
            \define('ATOM_DATE', (string) $config->date);
        }
        if (!\defined('ATOM_TIME')) {
            \define('ATOM_TIME', (string) $config->time);
        }
        if (!\defined('ATOM_LOCAE')) {
            \define('ATOM_LOCAE', (string) $config->locale);
        }
        if (!\defined('ATOM_LOCALE')) {
            \define('ATOM_LOCALE', (string) $config->locale);
        }

        self::$isSetDefine = true;
    }

    public static function setDefaultLocale(): void
    {
        if (self::$isSetConfig) {
            return;
        }

        \date_default_timezone_set('UTC');

        $locale = \defined('ATOM_LOCALE')
            ? (string) \ATOM_LOCALE
            : (\defined('ATOM_LOCAE') ? (string) \ATOM_LOCAE : '');

        if ($locale !== '') {
            parent::setLocale($locale);
        }

        self::$isSetConfig = true;
    }

    public function setDefaultTimezone(): bool
    {
        if (\defined('ATOM_TIMEZONE')) {
            $locale = \defined('ATOM_LOCALE')
                ? (string) \ATOM_LOCALE
                : (\defined('ATOM_LOCAE') ? (string) \ATOM_LOCAE : 'en');

            $this->timezone(\ATOM_TIMEZONE);
            $this->settings([
                'toStringFormat' => \defined('ATOM_DATETIME') ? \ATOM_DATETIME : 'Y-m-d H:i:s',
                'dateFormat' => \defined('ATOM_DATE') ? \ATOM_DATE : 'Y-m-d',
                'timeFormat' => \defined('ATOM_TIME') ? \ATOM_TIME : 'H:i:s',
                'locale' => $locale,
                'timezone' => \ATOM_TIMEZONE,
            ]);
            return true;
        }

        return false;
    }

    public static function setMacro(): bool
    {
        if (self::$isSetMacro) {
            return false;
        }

        $resolveLocale = static fn(): string => \defined('ATOM_LOCALE')
            ? (string) \ATOM_LOCALE
            : (\defined('ATOM_LOCAE') ? (string) \ATOM_LOCAE : 'en');

        $resolveTimezone = static fn(): string => \defined('ATOM_TIMEZONE') ? (string) \ATOM_TIMEZONE : 'UTC';
        $resolveDateTimeFmt = static fn(): string => \defined('ATOM_DATETIME') ? (string) \ATOM_DATETIME : 'Y-m-d H:i:s';
        $resolveDateFmt = static fn(): string => \defined('ATOM_DATE') ? (string) \ATOM_DATE : 'Y-m-d';
        $resolveTimeFmt = static fn(): string => \defined('ATOM_TIME') ? (string) \ATOM_TIME : 'H:i:s';

        $formatDateTime = function (DateTimeInterface|WeekDay|Month|string|int|float|null $other = null) use ($resolveLocale, $resolveTimezone, $resolveDateTimeFmt): string {
            return self::parse($other)->locale($resolveLocale())->timezone($resolveTimezone())->format($resolveDateTimeFmt());
        };

        $formatDate = function (DateTimeInterface|WeekDay|Month|string|int|float|null $other = null) use ($resolveLocale, $resolveTimezone, $resolveDateFmt): string {
            return self::parse($other)->locale($resolveLocale())->timezone($resolveTimezone())->format($resolveDateFmt());
        };

        $formatTime = function (DateTimeInterface|WeekDay|Month|string|int|float|null $other = null) use ($resolveLocale, $resolveTimezone, $resolveTimeFmt): string {
            return self::parse($other)->locale($resolveLocale())->timezone($resolveTimezone())->format($resolveTimeFmt());
        };

        $formatSql = function (DateTimeInterface|WeekDay|Month|string|int|float|null $other = null): string {
            return self::parse($other)->format('Y-m-d H:i:s.u');
        };

        foreach (['native', 'atom', 'toAtom'] as $macroName) {
            parent::macro($macroName, $formatDateTime);
        }
        foreach (['nativeDate', 'atomDate', 'toAtomDate'] as $macroName) {
            parent::macro($macroName, $formatDate);
        }
        foreach (['nativeTime', 'atomTime', 'toAtomTime'] as $macroName) {
            parent::macro($macroName, $formatTime);
        }
        foreach (['sqlDatetime', 'toSQL'] as $macroName) {
            parent::macro($macroName, $formatSql);
        }

        self::$isSetMacro = true;
        return true;
    }

    /**
     * Computes the exact elapsed PHP 8.6 `\Time\Duration` between `$this` and `$other`.
     */
    public function durationUntil(DateTimeInterface $other): Duration
    {
        $microDiff = ((int) $other->format('U')) * 1_000_000 + (int) $other->format('u')
            - (((int) $this->format('U')) * 1_000_000 + (int) $this->format('u'));

        return Duration::fromMicroseconds($microDiff);
    }

    #[Override]
    public function __toString(): string
    {
        if (\defined('ATOM_DATETIME')) {
            return $this->format((string) \ATOM_DATETIME);
        }

        return $this->format('Y-m-d H:i:s');
    }
}
