<?php

namespace Atom\Log;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

// Mock global functions if necessary, but for file operations, we'll use a temporary directory.
// For syslog, we'll rely on the internal logic and not try to mock the actual syslog call
// unless a global function mocking