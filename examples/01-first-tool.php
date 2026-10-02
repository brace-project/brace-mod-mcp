<?php

declare(strict_types=1);

use Brace\Mcp\McpRegistry;
use Brace\Mcp\McpServer;

require __DIR__ . '/../vendor/autoload.php';

// Registrierung: PHP-Typen und PHPDoc werden von Phore Schema ausgewertet.
$registry = new McpRegistry();
$registry->tool(
    name: 'get_customer',
    callback: /** @param int $customerId Eindeutige Kundennummer. */
        static fn (int $customerId): array => ['id' => $customerId, 'name' => 'Muster GmbH'],
    description: 'Liest einen Kunden anhand seiner Kundennummer.',
    annotations: ['readOnlyHint' => true],
);
$server = new McpServer($registry, name: 'crm', version: '1.0.0');

// Ein MCP-Client führt Discovery und Aufruf normalerweise selbst aus.
// Hier wird der Protokollkern ohne HTTP angesprochen (Legacy-Profil 2025-11-25).
echo $server->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list"}')->toJson() . "\n";
echo $server->handle('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"get_customer","arguments":{"customerId":4711}}}')->toJson() . "\n";

// tools/list: customerId hat type=integer und ist required.
// tools/call: result.structuredContent = {"id":4711,"name":"Muster GmbH"}.
// Dieselben Daten stehen zusätzlich als JSON-Text in result.content[0].text.
