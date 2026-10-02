<?php

declare(strict_types=1);

namespace Brace\Mcp\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class McpPrompt
{
    /**
     * Markiert einen Prompt-Callback; Argumente und Beschreibungen kommen aus PHPDoc.
     * MCP-Prompt-Argumente sind Strings, nicht beliebige Tool-Eingaben.
     *
     * @example #[McpPrompt(name: 'customer_summary')]
     * @see \Brace\Mcp\McpRegistry::prompt()
     */
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
        public ?string $title = null,
    ) {
    }
}
