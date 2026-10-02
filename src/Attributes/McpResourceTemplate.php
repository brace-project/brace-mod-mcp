<?php

declare(strict_types=1);

namespace Brace\Mcp\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class McpResourceTemplate
{
    /**
     * Veröffentlicht einfache RFC-6570-Variablen wie {customerId} als Resource-Template.
     * Variablennamen entsprechen den benannten String-Parametern der Methode.
     *
     * @example #[McpResourceTemplate(uriTemplate: 'customer://{customerId}', name: 'customer')]
     * @see \Brace\Mcp\McpRegistry::resourceTemplate()
     */
    public function __construct(
        public string $uriTemplate,
        public string $name,
        public ?string $description = null,
        public ?string $mimeType = null,
        public ?string $title = null,
    ) {
    }
}
