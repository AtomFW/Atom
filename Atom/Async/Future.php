<?php

declare(strict_types=1);

namespace Atom\Async;

use Atom\Async\Exception\AsyncCancelledException;
use Closure;
use Throwable;
use Time\Duration;

/**
 * Represents the eventual result of an asynchronous `\Fiber` or Swoole coroutine computation.
 *
 * Supports cooperative awaiting (`await()`), cancellation (`cancel()`), and promise-style
 * chaining (`map()` / `then()`, `catch()`, `finally()`).
 *
 * @template TValue
 */
final class Future
{
    private const int STATE_PENDING = 0;
    private const int STATE_FULFILLED = 1;
    private const int STATE_REJECTED = 2;
    private const int STATE_CANCELLED = 3;

    private int $state = self::STATE_PENDING;
    private mixed $result = null;
    private ?Throwable $error = null;

    /** @var list<Closure(self<mixed>): void> */
    private array $listeners = [];

    /** @var (Closure(?Duration): mixed)|null */
    private ?Closure $awaiter = null;

    /** @var (Closure(): void)|null */
    private ?Closure $canceller = null;

    /**
     * @param (Closure(?Duration): mixed)|null $awaiter
     * @param (Closure(): mixed)|null $canceller
     */
    public function __construct(?Closure $awaiter = null, ?Closure $canceller = null)
    {
        $this->awaiter = $awaiter;
        $this->canceller = $canceller;
    }

    /**
     * Attaches cooperative awaiter/canceller callbacks without requiring closure variable references.
     *
     * @param (Closure(?Duration): mixed)|null $awaiter
     * @param (Closure(): mixed)|null $canceller
     * @return self<TValue>
     */
    public function bindCallbacks(?Closure $awaiter, ?Closure $canceller = null): self
    {
        if ($this->state === self::STATE_PENDING) {
            $this->awaiter = $awaiter;
            $this->canceller = $canceller;
        }

        return $this;
    }

    /**
     * Creates an already-fulfilled `Future`.
     *
     * @template U
     * @param U $value
     * @return self<U>
     */
    public static function resolved(mixed $value): self
    {
        /** @var self<U> $f */
        $f = new self();
        $f->resolve($value);
        return $f;
    }

    /**
     * Creates an already-rejected `Future`.
     *
     * @return self<never>
     */
    public static function rejected(Throwable $reason): self
    {
        /** @var self<never> $f */
        $f = new self();
        $f->reject($reason);
        return $f;
    }

    public function isPending(): bool
    {
        return $this->state === self::STATE_PENDING;
    }

    public function isSettled(): bool
    {
        return $this->state !== self::STATE_PENDING;
    }

    public function isFulfilled(): bool
    {
        return $this->state === self::STATE_FULFILLED;
    }

    public function isRejected(): bool
    {
        return $this->state === self::STATE_REJECTED;
    }

    public function isCancelled(): bool
    {
        return $this->state === self::STATE_CANCELLED;
    }

    /**
     * Settles the `Future` with a value and notifies all waiting continuations/fibers.
     *
     * @param TValue $value
     */
    public function resolve(mixed $value): void
    {
        if ($this->state !== self::STATE_PENDING) {
            return;
        }

        $this->state = self::STATE_FULFILLED;
        $this->result = $value;
        $this->notifyListeners();
    }

    /**
     * Rejects the `Future` with a `Throwable` and wakes any waiting continuations/fibers.
     */
    public function reject(Throwable $error): void
    {
        if ($this->state !== self::STATE_PENDING) {
            return;
        }

        $this->state = self::STATE_REJECTED;
        $this->error = $error;
        $this->notifyListeners();
    }

    /**
     * Cancels a pending `Future` and triggers its underlying fiber/timer cleanup callback.
     */
    public function cancel(string $reason = 'Future was cancelled.'): bool
    {
        if ($this->state !== self::STATE_PENDING) {
            return false;
        }

        $this->state = self::STATE_CANCELLED;
        $this->error = new AsyncCancelledException($reason);

        if ($this->canceller !== null) {
            ($this->canceller)();
            $this->canceller = null;
        }

        $this->notifyListeners();
        return true;
    }

    /**
     * Registers a settlement callback invoked immediately if already settled, or as soon as settled.
     *
     * @param callable(self<TValue>): void $listener
     */
    public function onSettle(callable $listener): void
    {
        if ($this->state !== self::STATE_PENDING) {
            $listener($this);
            return;
        }

        $this->listeners[] = Closure::fromCallable($listener);
    }

    /**
     * Awaits completion of this `Future`, cooperatively suspending the calling Fiber or Swoole coroutine.
     *
     * @return TValue
     */
    public function await(?Duration $timeout = null): mixed
    {
        if ($this->state === self::STATE_PENDING && $this->awaiter !== null) {
            ($this->awaiter)($timeout);
        }

        if ($this->state === self::STATE_FULFILLED) {
            return $this->result;
        }

        if ($this->error !== null) {
            throw $this->error;
        }

        throw new AsyncCancelledException('Future did not settle.');
    }

    /**
     * Transforms the fulfilled result into a derived `Future` (`Promise::then` equivalent).
     *
     * @template TNext
     * @param callable(TValue): TNext $mapper
     * @return self<TNext>
     */
    public function then(callable $mapper): self
    {
        /** @var self<TNext> $derived */
        $derived = new self();
        $derived->bindCallbacks(
            awaiter: function (?Duration $timeout) use ($derived): mixed {
                $this->await($timeout);
                return $derived->isFulfilled() ? $derived->result : null;
            },
            canceller: fn(): bool => $this->cancel(),
        );

        $this->onSettle(static function (self $settled) use ($derived, $mapper): void {
            if ($settled->state === self::STATE_FULFILLED) {
                try {
                    $derived->resolve($mapper($settled->result));
                } catch (Throwable $e) {
                    $derived->reject($e);
                }
            } elseif ($settled->error !== null) {
                $derived->reject($settled->error);
            }
        });

        return $derived;
    }

    /**
     * Recovers from a rejected `Future` (`Promise::catch` equivalent).
     *
     * @param callable(Throwable): mixed $handler
     * @return self<mixed>
     */
    public function catch(callable $handler): self
    {
        $derived = new self();
        $derived->bindCallbacks(
            awaiter: function (?Duration $timeout) use ($derived): mixed {
                try {
                    $this->await($timeout);
                } catch (Throwable) {
                    // Handled by onSettle listener
                }
                return $derived->isFulfilled() ? $derived->result : null;
            },
            canceller: fn(): bool => $this->cancel(),
        );

        $this->onSettle(static function (self $settled) use ($derived, $handler): void {
            if ($settled->state === self::STATE_FULFILLED) {
                $derived->resolve($settled->result);
            } elseif ($settled->error !== null) {
                try {
                    $derived->resolve($handler($settled->error));
                } catch (Throwable $e) {
                    $derived->reject($e);
                }
            }
        });

        return $derived;
    }

    /**
     * Attaches a cleanup callback executed regardless of fulfillment or rejection.
     *
     * @param callable(): void $callback
     * @return self<TValue>
     */
    public function finally(callable $callback): self
    {
        $this->onSettle(static function () use ($callback): void {
            $callback();
        });

        return $this;
    }

    public function getError(): ?Throwable
    {
        return $this->error;
    }

    private function notifyListeners(): void
    {
        $queued = $this->listeners;
        $this->listeners = [];
        $this->awaiter = null;
        $this->canceller = null;

        foreach ($queued as $cb) {
            $cb($this);
        }
    }
}
