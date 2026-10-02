<?php

declare(strict_types=1);

use Brace\Core\Base\NotFoundMiddleware;
use Brace\Core\BraceApp;
use Brace\Core\EnvironmentType;
use Brace\Mcp\McpModule;
use Brace\Mcp\McpRegistry;
use Brace\Mcp\McpServer;
use Brace\Mcp\PromptResult;
use Brace\Mod\Request\Zend\BraceRequestLaminasModule;

require __DIR__ . '/../vendor/autoload.php';

// Vollständiger Bootstrap mit ausschließlich öffentlichen Demo-Daten.
// In einer Anwendung stammen die Callbacks aus den eigenen Diensten/Controllern.
$registry = new McpRegistry();
$registry->tool('get_customer', static fn (int $customerId): array => [
    'id' => $customerId, 'name' => 'Muster GmbH',
], description: 'Liest einen Demo-Kunden.', annotations: ['readOnlyHint' => true]);
$registry->prompt('customer_summary', static fn (string $customerId): PromptResult => PromptResult::text(
    'Fasse den Demo-Kunden ' . $customerId . ' anhand seiner verfügbaren Daten zusammen.',
));
$registry->resource('config://application', static fn (): array => ['language' => 'de', 'currency' => 'EUR'], name: 'configuration');
$registry->resourceTemplate('customer://{customerId}', static fn (string $customerId): array => [
    'id' => $customerId, 'name' => 'Muster GmbH',
], name: 'customer', mimeType: 'application/json');
$registry->completion('ref/prompt', 'customer_summary', 'customerId', static fn (string $value): array => array_values(
    array_filter(['4711', '4712'], static fn (string $id): bool => str_starts_with($id, $value)),
));

$app = new BraceApp(EnvironmentType::DEVELOPMENT);
$app->addModule(new BraceRequestLaminasModule());
$app->addModule(new McpModule(
    registry: $registry,
    path: '/mcp',
    server: new McpServer($registry, name: 'crm-example', version: '1.0.0'),
));
$app->setPipe([
    // Produktion: bestehende Auth-/Rate-Limit-Middleware VOR mcpMiddleware einsetzen.
    $app->mcpMiddleware,
    // Bestehende RouterEval-/RouterDispatch-Middleware würde HIER folgen (Example 06).
    new NotFoundMiddleware(),
]);

// public/index.php lädt den Bootstrap und ruft $app->run() genau einmal auf.
return $app;
