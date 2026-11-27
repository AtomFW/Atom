<?php

declare(strict_types=1);

namespace Atom\Async\Exception;

/**
 * Thrown when awaiting a cancelled `Future` or aborted async task (e.g. losing tasks in `Async::race()`).
 */
final class AsyncCancelledException extends AsyncException {}
