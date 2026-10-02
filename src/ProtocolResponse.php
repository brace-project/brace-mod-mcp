<?php

declare(strict_types=1);

namespace Brace\Mcp;

use JsonException;

/** Transportneutrales Ergebnis; data=null bedeutet eine Notification ohne Antwort. */
final readonly class ProtocolResponse
{
    /**
     * Trägt eine JSON-RPC-Antwort und den Status für den HTTP-Adapter.
     *
     * @param array<string, mixed>|null $data JSON-RPC-Envelope oder null.
     * @example $reply = $server->handle($json); echo $reply->toJson();
     * @see McpServer::handle()
     */
    public function __construct(public ?array $data, public int $httpStatus = 200)
    {
    }

    /**
     * Serialisiert genau eine Antwort; Notifications ergeben einen leeren Body.
     *
     * @throws JsonException Bei nicht serialisierbaren Anwendungsdaten.
     * @example $json = $reply->toJson();
     * @see McpMiddleware::process()
     */
    public function toJson(): string
    {
        return $this->data === null ? '' : json_encode(
            $this->data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
