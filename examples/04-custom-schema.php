<?php

declare(strict_types=1);

use Brace\Mcp\McpRegistry;
use Brace\Mcp\McpServer;
use Phore\Schema\Generator\JsonSchema\JsonSchema;

require __DIR__ . '/../vendor/autoload.php';

// Vorhandene JSON-Schema-Daten können direkt in ein Phore-JsonSchema-Objekt fließen.
$schema = new JsonSchema([
    'type' => 'object',
    'properties' => [
        'name' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 100],
        'language' => ['type' => 'string', 'enum' => ['de', 'en']],
    ],
    'required' => ['name'],
    'additionalProperties' => false,
]);

$registry = new McpRegistry();
$registry->tool(
    name: 'draft_greeting',
    callback: static fn (array $arguments): array => [
        'text' => (($arguments['language'] ?? 'de') === 'de' ? 'Hallo ' : 'Hello ') . $arguments['name'] . '!',
    ],
    description: 'Bereitet einen Begrüßungstext vor; versendet keine Nachricht.',
    inputSchema: $schema,
    outputSchema: new JsonSchema([
        'type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text'],
    ]),
    rawArguments: true,
);

// rawArguments übergibt das ganze validierte Objekt, nicht das Phore-Schema selbst.
// JSON-Schema-defaults verändern die Nutzdaten nicht; Fallbacks gehören in den Callback.
$server = new McpServer($registry);
echo $server->handle('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"draft_greeting","arguments":{"name":"Matthias"}}}')->toJson() . "\n";
echo $server->handle('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"draft_greeting","arguments":{"name":"Al"}}}')->toJson() . "\n";
// Erster Aufruf: structuredContent = {"text":"Hallo Matthias!"}.
// Zweiter Aufruf: isError=true; der Callback wird wegen minLength nicht ausgeführt.
