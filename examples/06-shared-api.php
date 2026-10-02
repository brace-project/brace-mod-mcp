<?php

declare(strict_types=1);

use Brace\Core\BraceApp;
use Brace\Core\EnvironmentType;
use Brace\Core\ReturnFormatterInterface;
use Brace\Mcp\Attributes\McpTool;
use Brace\Mcp\McpModule;
use Brace\Mcp\McpRegistry;
use Brace\Mcp\McpServer;
use Brace\Router\Attributes\BraceRoute;
use Brace\Router\RouterDispatchMiddleware;
use Brace\Router\RouterEvalMiddleware;
use Brace\Router\RouterModule;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;

require __DIR__ . '/../vendor/autoload.php';

final class ExampleSharedApi
{
    /**
     * Dieselbe fachliche Funktion ist für Frontend und MCP verfügbar.
     * @return array Öffentliche Anwendungskonfiguration ohne Secrets.
     * @example $api->configuration();
     * @see BraceRoute
     * @see McpTool
     */
    #[BraceRoute('GET@/api/config', name: 'configuration')]
    #[McpTool(name: 'configuration', annotations: ['readOnlyHint' => true])]
    public function configuration(): array
    {
        return ['language' => 'de', 'currency' => 'EUR'];
    }
}

$registry = (new McpRegistry())->register(new ExampleSharedApi());
$server = new McpServer($registry);
$app = new BraceApp(EnvironmentType::DEVELOPMENT);
$app->addModule(new RouterModule());
$app->addModule(new McpModule($registry, server: $server));
$app->router->registerClass('', ExampleSharedApi::class);

// Vorhandene JSON-Formatter können unverändert weiterverwendet werden.
$jsonFormatter = new class implements ReturnFormatterInterface {
    public function canHandle($input): bool
    {
        return is_array($input);
    }

    public function transform($input): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($input, JSON_THROW_ON_ERROR));
    }
};
$app->setPipe([
    // Gemeinsame Auth-Middleware gehört hier VOR die MCP-Middleware.
    $app->mcpMiddleware,
    new RouterEvalMiddleware(),
    new RouterDispatchMiddleware([$jsonFormatter]),
]);

$frontendResponse = $app->handle(new ServerRequest('GET', 'https://example.test/api/config'));
echo (string) $frontendResponse->getBody() . "\n";
echo $server->handle('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"configuration"}}')->toJson() . "\n";
// Frontend: {"language":"de","currency":"EUR"}.
// MCP: dieselben Daten in structuredContent und einem Text-Content-Block.
// Achtung: Route-spezifische Middleware läuft beim MCP-Aufruf NICHT automatisch mit.
// Bei parametrisierten bestehenden Routen bleibt deren HTTP-Binding separat bestehen.
