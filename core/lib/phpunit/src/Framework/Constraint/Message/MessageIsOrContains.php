<?php declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\Framework\Constraint;

use function sprintf;
use function str_contains;

/**
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for PHPUnit
 */
final class MessageIsOrContains extends Constraint
{
    private string $messageType;
    private string $expectedMessage;

    public function __construct(string $messageType, string $expectedMessage)
    {
        $this->messageType     = $messageType;
        $this->expectedMessage = $expectedMessage;
    }

    public function toString(): string
    {
        if ($this->expectedMessage === '') {
            return $this->messageType . ' message is empty';
        }

        return $this->messageType . ' message contains ';
    }

    protected function matches(mixed $other): bool
    {
        if ($this->expectedMessage === '') {
            return $other === '';
        }

        return str_contains((string) $other, $this->expectedMessage);
    }

    /**
     * Returns the description of the failure.
     *
     * The beginning of failure messages is "Failed asserting that" in most
     * cases. This method should return the second part of that sentence.
     */
    protected function failureDescription(mixed $other): string
    {
        if ($this->expectedMessage === '') {
            return sprintf(
                "%s message is empty but is '%s'",
                $this->messageType,
                $other
            );
        }

        return sprintf(
            "%s message '%s' contains '%s'",
            $this->messageType,
            $other,
            $this->expectedMessage
        );
    }
}
