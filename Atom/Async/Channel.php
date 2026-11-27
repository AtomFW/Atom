<?php

declare(strict_types=1);

namespace Atom\Async;

use Atom\Async\Exception\AsyncException;
use Atom\Async\Exception\AsyncTimeoutException;
use SplQueue;
use Time\Duration;

/**
 * Bounded, backpressure-aware asynchronous message channel compatible with both
 * Swoole / OpenSwoole Coroutines (`Swoole\Coroutine\Channel`) and PHP 8.6 `\Fiber` scheduling.
 *
 * Producers suspend cooperatively when the buffer is full; consumers suspend cooperatively when empty.
 */
final class Channel
{
    private int $capacity;
    private bool $closed = false;

    /** @var SplQueue<mixed> */
    private SplQueue $buffer;

    /** @var SplQueue<Future<mixed>> */
    private SplQueue $waitingReceivers;

    /** @var SplQueue<array{item: mixed, future: Future<bool>}> */
    private SplQueue $waitingSenders;

    private ?object $swooleChannel = null;
    private ?FiberScheduler $scheduler;

    public function __construct(int $capacity = 64, ?FiberScheduler $scheduler = null, bool $preferSwoole = true)
    {
        $this->capacity = \clamp($capacity, 1, 100_000);
        $this->buffer = new SplQueue();
        $this->waitingReceivers = new SplQueue();
        $this->waitingSenders = new SplQueue();
        $this->scheduler = $scheduler;

        if ($preferSwoole && Async::isInSwooleCoroutine()) {
            $chanClass = Async::resolveSwooleChannelClass();
            if ($chanClass !== null) {
                $this->swooleChannel = new $chanClass($this->capacity);
            }
        }
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function length(): int
    {
        if ($this->swooleChannel !== null && \method_exists($this->swooleChannel, 'length')) {
            return (int) $this->swooleChannel->length();
        }

        return $this->buffer->count();
    }

    public function isEmpty(): bool
    {
        return $this->length() === 0;
    }

    public function isFull(): bool
    {
        return $this->length() >= $this->capacity;
    }

    /**
     * Pushes a value into the channel, suspending the current Fiber / Coroutine if at capacity.
     */
    public function push(mixed $value, ?Duration $timeout = null): bool
    {
        if ($this->closed) {
            throw new AsyncException('Cannot push into a closed Channel.');
        }

        if ($this->swooleChannel !== null && \method_exists($this->swooleChannel, 'push')) {
            $sec = $timeout !== null ? ($timeout->seconds + ($timeout->nanoseconds / 1_000_000_000)) : -1.0;
            $ok = (bool) $this->swooleChannel->push($value, $sec);
            if (!$ok && $timeout !== null) {
                throw new AsyncTimeoutException('Channel push timed out.');
            }
            return $ok;
        }

        // Direct handoff to a waiting consumer
        while (!$this->waitingReceivers->isEmpty()) {
            $receiverFuture = $this->waitingReceivers->dequeue();
            if ($receiverFuture->isPending()) {
                $receiverFuture->resolve($value);
                return true;
            }
        }

        // Buffer has room
        if ($this->buffer->count() < $this->capacity) {
            $this->buffer->enqueue($value);
            return true;
        }

        // Channel is full -> suspend fiber until a consumer pops an item
        if ($this->scheduler === null) {
            throw new AsyncException('Channel buffer is full and no active FiberScheduler is attached.');
        }

        /** @var Future<bool> $sendFuture */
        $sendFuture = $this->scheduler->createAwaitableFuture();
        $this->waitingSenders->enqueue(['item' => $value, 'future' => $sendFuture]);

        return (bool) $sendFuture->await($timeout);
    }

    /**
     * Non-blocking attempt to push a value immediately; returns `false` if full or closed.
     */
    public function tryPush(mixed $value): bool
    {
        if ($this->closed) {
            return false;
        }

        while (!$this->waitingReceivers->isEmpty()) {
            $receiverFuture = $this->waitingReceivers->dequeue();
            if ($receiverFuture->isPending()) {
                $receiverFuture->resolve($value);
                return true;
            }
        }

        if ($this->buffer->count() < $this->capacity) {
            $this->buffer->enqueue($value);
            return true;
        }

        return false;
    }

    /**
     * Pops the next value from the channel, suspending the current Fiber / Coroutine if empty.
     * Returns `null` when the channel has been closed and drained.
     */
    public function pop(?Duration $timeout = null): mixed
    {
        if ($this->swooleChannel !== null && \method_exists($this->swooleChannel, 'pop')) {
            $sec = $timeout !== null ? ($timeout->seconds + ($timeout->nanoseconds / 1_000_000_000)) : -1.0;
            $val = $this->swooleChannel->pop($sec);
            if ($val === false && !$this->closed && $timeout !== null) {
                throw new AsyncTimeoutException('Channel pop timed out.');
            }
            return $val === false ? null : $val;
        }

        if (!$this->buffer->isEmpty()) {
            $item = $this->buffer->dequeue();
            $this->promoteWaitingSender();
            return $item;
        }

        while (!$this->waitingSenders->isEmpty()) {
            $sender = $this->waitingSenders->dequeue();
            if ($sender['future']->isPending()) {
                $sender['future']->resolve(true);
                return $sender['item'];
            }
        }

        if ($this->closed) {
            return null;
        }

        if ($this->scheduler === null) {
            return null;
        }

        /** @var Future<mixed> $recvFuture */
        $recvFuture = $this->scheduler->createAwaitableFuture();
        $this->waitingReceivers->enqueue($recvFuture);

        return $recvFuture->await($timeout);
    }

    /**
     * Non-blocking attempt to pop a value immediately; returns `$default` if empty.
     */
    public function tryPop(mixed $default = null): mixed
    {
        if (!$this->buffer->isEmpty()) {
            $item = $this->buffer->dequeue();
            $this->promoteWaitingSender();
            return $item;
        }

        return $default;
    }

    /**
     * Closes the channel, waking all waiting consumers (`null`) and rejecting blocked senders.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        if ($this->swooleChannel !== null && \method_exists($this->swooleChannel, 'close')) {
            $this->swooleChannel->close();
        }

        while (!$this->waitingReceivers->isEmpty()) {
            $recv = $this->waitingReceivers->dequeue();
            if ($recv->isPending()) {
                $recv->resolve(null);
            }
        }

        while (!$this->waitingSenders->isEmpty()) {
            $sender = $this->waitingSenders->dequeue();
            if ($sender['future']->isPending()) {
                $sender['future']->reject(new AsyncException('Channel was closed while waiting to push.'));
            }
        }
    }

    private function promoteWaitingSender(): void
    {
        while (!$this->waitingSenders->isEmpty() && $this->buffer->count() < $this->capacity) {
            $sender = $this->waitingSenders->dequeue();
            if ($sender['future']->isPending()) {
                $this->buffer->enqueue($sender['item']);
                $sender['future']->resolve(true);
                return;
            }
        }
    }
}
