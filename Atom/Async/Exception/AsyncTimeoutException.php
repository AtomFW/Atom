<?php

declare(strict_types=1);

namespace Atom\Async\Exception;

/**
 * Thrown when an asynchronous fiber, coroutine, stream wait, or `Future` exceeds its `\Time\Duration` deadline.
 */
final class AsyncTimeoutException extends AsyncException {}
