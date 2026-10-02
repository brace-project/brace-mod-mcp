<?php

declare(strict_types=1);

namespace Brace\Mcp\Internal;

/** @internal Gemeinsame Registry-Repräsentation; unabhängig von Attributen und Providern. */
final readonly class Definition
{
    public function __construct(
        public string $kind,
        public string $key,
        public array $metadata,
        public Invocation $invocation,
        public ?SchemaDocument $output = null,
        public ?UriTemplate $template = null,
    ) {
    }
}
