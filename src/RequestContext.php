<?php

declare(strict_types=1);

namespace Brace\Mcp;

use Psr\Http\Message\ServerRequestInterface;
use stdClass;

/** Request-lokaler Kontext. Weder Client-Metadaten noch Session-IDs sind ein Identitätsnachweis. */
final readonly class RequestContext
{
    /**
     * Erstellt den Kontext für einen Aufruf; normalerweise übernimmt dies McpServer.
     * Ein entsprechend typisierter Callback-Parameter wird injiziert, nicht veröffentlicht.
     *
     * @param stdClass $meta Unverifizierte MCP-Metadaten des Clients.
     * @example $principal = $context->request?->getAttribute('principal');
     * @see McpServer::handle()
     */
    public function __construct(
        public string $protocolVersion,
        public ?ServerRequestInterface $request = null,
        public stdClass $meta = new stdClass(),
    ) {
    }
}
