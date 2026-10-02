<?php

declare(strict_types=1);

namespace Brace\Mcp\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class McpResource
{
    /**
     * Veröffentlicht eine lesbare Ressource mit fester URI; der Inhalt darf wechseln.
     *
     * @example #[McpResource(uri: 'config://application', name: 'configuration')]
     * @see \Brace\Mcp\McpRegistry::resource()
     */
    public function __construct(
        public string $uri,
        public string $name,
        public ?string $description = null,
        public ?string $mimeType = null,
        public ?string $title = null,
    ) {
    }
}
