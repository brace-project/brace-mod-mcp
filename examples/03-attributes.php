<?php

declare(strict_types=1);

use Brace\Mcp\Attributes\McpPrompt;
use Brace\Mcp\Attributes\McpResource;
use Brace\Mcp\Attributes\McpResourceTemplate;
use Brace\Mcp\Attributes\McpTool;
use Brace\Mcp\McpRegistry;
use Brace\Mcp\McpServer;
use Brace\Mcp\PromptResult;

require __DIR__ . '/../vendor/autoload.php';

final class ExampleCustomerApi
{
    /**
     * Liest die öffentlichen Stammdaten eines Demo-Kunden.
     * @param int $customerId Eindeutige Kundennummer.
     * @return array Demo-Stammdaten; keine produktive Datenbank wird angesprochen.
     * @example $api->getCustomer(4711);
     * @see McpTool
     */
    #[McpTool(name: 'get_customer', annotations: ['readOnlyHint' => true])]
    public function getCustomer(int $customerId): array
    {
        return ['id' => $customerId, 'name' => 'Muster GmbH'];
    }

    /**
     * Rendert eine Prompt-Vorlage, ohne selbst ein Sprachmodell aufzurufen.
     * @param string $customerId Kundennummer als MCP-Prompt-String.
     * @param string $language Gewünschte Ausgabesprache.
     * @example $api->summary('4711', 'de');
     * @see PromptResult::text()
     */
    #[McpPrompt(name: 'customer_summary', title: 'Kunden zusammenfassen')]
    public function summary(string $customerId, string $language = 'de'): PromptResult
    {
        return PromptResult::text("Fasse den Kunden {$customerId} auf {$language} zusammen.");
    }

    /**
     * Liefert eine feste Resource-URI; ihr Inhalt darf sich zwischen Aufrufen ändern.
     * @example $api->configuration();
     * @see McpResource
     */
    #[McpResource(uri: 'config://application', name: 'configuration', mimeType: 'application/json')]
    public function configuration(): array
    {
        return ['language' => 'de', 'currency' => 'EUR'];
    }

    /**
     * Liest eine Resource-Familie mit einem benannten URI-Parameter.
     * @param string $customerId Einmal dekodierter Wert aus customer://{customerId}.
     * @example $api->customerResource('4711');
     * @see McpResourceTemplate
     */
    #[McpResourceTemplate(uriTemplate: 'customer://{customerId}', name: 'customer', mimeType: 'application/json')]
    public function customerResource(string $customerId): array
    {
        return ['id' => $customerId, 'name' => 'Muster GmbH'];
    }
}

// Nur dieses ausdrücklich übergebene Objekt wird gescannt. Andere Methoden bleiben privat für MCP.
$registry = (new McpRegistry())->register(new ExampleCustomerApi());
$server = new McpServer($registry);
echo $server->handle('{"jsonrpc":"2.0","id":1,"method":"prompts/get","params":{"name":"customer_summary","arguments":{"customerId":"4711"}}}')->toJson() . "\n";
echo $server->handle('{"jsonrpc":"2.0","id":2,"method":"resources/read","params":{"uri":"customer://4711"}}')->toJson() . "\n";
// Prompt: eine user-Nachricht mit "Fasse den Kunden 4711 auf de zusammen."
// Resource: contents[0].uri = customer://4711, text enthält die JSON-Kundendaten.
