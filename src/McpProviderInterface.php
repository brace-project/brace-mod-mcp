<?php

declare(strict_types=1);

namespace Brace\Mcp;

/** Ein Anwendungsmodul kann mehrere zusammengehörige MCP-Definitionen beisteuern. */
interface McpProviderInterface
{
    /**
     * Registriert Definitionen, ohne die eigentlichen Tool-/Resource-Callbacks auszuführen.
     * Mehrere Provider werden von derselben Registry aufgenommen; Konflikte sind Fehler.
     *
     * @example $registry->register(new CustomerProvider($customerService));
     * @see McpRegistry::register()
     */
    public function register(McpRegistry $registry): void;
}
