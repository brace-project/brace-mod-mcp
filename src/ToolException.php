<?php

declare(strict_types=1);

namespace Brace\Mcp;

use RuntimeException;

/**
 * Bewusst veröffentlichbare fachliche Fehlermeldung eines Tool-Callbacks.
 * Wird als ToolResult mit isError=true ausgegeben. Keine Secrets in die Message schreiben.
 * Andere Exceptions werden dem Client ausschließlich als generischer Fehler gemeldet.
 *
 * @example throw new ToolException('Der Kunde wurde nicht gefunden.');
 * @see ToolResult::error()
 */
final class ToolException extends RuntimeException
{
}
