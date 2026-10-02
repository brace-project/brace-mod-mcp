<?php

declare(strict_types=1);

use Brace\Mcp\McpProviderInterface;
use Brace\Mcp\McpRegistry;
use Brace\Mcp\McpServer;

require __DIR__ . '/../vendor/autoload.php';

// Ein Modul liefert zusammengehörige Prompts und Resources über einen Provider.
$customers = new class implements McpProviderInterface {
    /**
     * Fügt ausschließlich Demo-Definitionen hinzu; kein Callback wird hier ausgeführt.
     * @example $registry->register($customers);
     * @see McpProviderInterface::register()
     */
    public function register(McpRegistry $registry): void
    {
        $registry->prompt('write_greeting', static fn (string $name): string => "Formuliere eine kurze Begrüßung für {$name}.");
        $registry->resourceTemplate('customer://{customerId}', static fn (string $customerId): array => ['id' => $customerId], name: 'customer');
        $registry->completion('ref/prompt', 'write_greeting', 'name', static fn (string $value): array => array_values(
            array_filter(['Matthias', 'Maria', 'Peter'], static fn (string $name): bool => str_starts_with($name, $value)),
        ));
    }
};
$health = new class implements McpProviderInterface {
    /**
     * Fügt ein separat entwickeltes, nebenwirkungsfreies Tool hinzu.
     * @example $registry->register($health);
     * @see McpProviderInterface::register()
     */
    public function register(McpRegistry $registry): void
    {
        $registry->tool('health', static fn (): string => 'ok');
    }
};
$registry = (new McpRegistry())->register($customers, $health);
$server = new McpServer($registry);
echo $server->handle('{"jsonrpc":"2.0","id":1,"method":"completion/complete","params":{"ref":{"type":"ref/prompt","name":"write_greeting"},"argument":{"name":"name","value":"Ma"}}}')->toJson() . "\n";
// result.completion.values = ["Matthias", "Maria"], total=2, hasMore=false.
// Weitere Provider und Attribut-Objekte können im selben register()-Aufruf stehen.
