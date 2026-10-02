<?php

declare(strict_types=1);

namespace Brace\Mcp;

use Brace\Core\BraceApp;
use Brace\Core\BraceModule;
use Phore\Di\Container\Producer\DiValue;

/** Optionaler Brace-DI-Adapter; die Middleware kann auch ohne Brace verwendet werden. */
final readonly class McpModule implements BraceModule
{
    /**
     * Veröffentlicht Registry, Server und Middleware als Brace-Services.
     * Die Pipeline wird absichtlich nicht verändert: Auth vor MCP, Router nach MCP.
     * Ein eigener Server kann Rechteprüfung, Metadaten und Logging mitbringen.
     *
     * @param list<string> $allowedOrigins Explizit erlaubte Browser-Origins.
     * @example $app->addModule(new McpModule($registry));
     * @see self::register()
     */
    public function __construct(
        private McpRegistry $registry,
        private string $path = '/mcp',
        private ?McpServer $server = null,
        private array $allowedOrigins = [],
        private int $maxBodyBytes = 1048576,
    ) {
    }

    /**
     * Definiert mcpRegistry, mcpServer und mcpMiddleware, ohne Callbacks auszuführen.
     *
     * @example $app->addModule(new McpModule($registry)); $app->setPipe([$app->mcpMiddleware]);
     * @see \Brace\Core\BraceModule::register()
     */
    public function register(BraceApp $app): void
    {
        $server = $this->server ?? new McpServer($this->registry);
        $middleware = new McpMiddleware($server, $this->path, $this->allowedOrigins, $this->maxBodyBytes);
        $app->define('mcpRegistry', new DiValue($this->registry));
        $app->define('mcpServer', new DiValue($server));
        $app->define('mcpMiddleware', new DiValue($middleware));
    }
}
