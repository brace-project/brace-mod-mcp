<?php

declare(strict_types=1);

namespace Brace\Mcp\Attributes;

use Attribute;

/** Opt-in einer öffentlichen Methode als MCP-Tool; verändert keine HTTP-Route. */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class McpTool
{
    /**
     * Beschreibt ein Tool. Ohne inputSchema werden PHP-Typen und PHPDoc verwendet.
     * rawArguments übergibt stattdessen das gesamte Argumentobjekt an den Callback.
     *
     * @param array<string, mixed>|null $inputSchema Explizites JSON Schema.
     * @param array<string, mixed>|null $outputSchema Schema für structuredContent.
     * @param array<string, mixed> $annotations MCP-Verhaltenshinweise, keine Rechte.
     * @example #[McpTool(name: 'get_customer', description: 'Liest einen Kunden')]
     * @see \Brace\Mcp\McpRegistry::register()
     */
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
        public ?string $title = null,
        public ?array $inputSchema = null,
        public ?array $outputSchema = null,
        public array $annotations = [],
        public bool $rawArguments = false,
    ) {
    }
}
