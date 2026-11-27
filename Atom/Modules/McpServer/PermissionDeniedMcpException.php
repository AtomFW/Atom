<?php

declare(strict_types=1);

namespace Atom\Modules\McpServer;

use RuntimeException;

/**
 * Thrown when an AI agent attempts an MCP operation disabled by `config/mcp.php` permissions.
 */
final class PermissionDeniedMcpException extends RuntimeException {}
