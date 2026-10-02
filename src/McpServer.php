<?php

declare(strict_types=1);

namespace Brace\Mcp;

use Brace\Mcp\Internal\Definition;
use Brace\Mcp\Internal\Json;
use Brace\Mcp\Internal\Pagination;
use Brace\Mcp\Internal\Protocol;
use Brace\Mcp\Internal\RpcException;
use Closure;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;
use Throwable;

/** Sessionloser MCP-Protokollkern; die HTTP-Middleware übernimmt allein den Transport. */
final class McpServer
{
    public const PROTOCOL_VERSION = Protocol::VERSION;
    public const SUPPORTED_VERSIONS = Protocol::VERSIONS;

    private readonly ?Closure $authorize;
    private readonly ?Closure $onError;

    /**
     * Verbindet Registry, Server-Metadaten und optionale anwendungsseitige Rechteprüfung.
     * authorize(kind, identifier, context): bool gilt für Discovery UND Ausführung.
     * kind ist tools, prompts, resources oder templates. Ressourcen werden zusätzlich
     * mit ihrer konkreten URI geprüft; Objekt-/Mandantenrechte bleiben im Anwendungsdienst.
     * Ohne authorize sind alle ausdrücklich registrierten Definitionen zugänglich.
     * Moderne cachefähige Ergebnisse sind privat und unmittelbar veraltet (ttlMs=0).
     *
     * @param callable|null $authorize Request-lokale Rechteprüfung, keine Authentifizierung.
     * @param callable|null $onError Erhält interne Throwable für das Logging der Anwendung.
     * @throws InvalidArgumentException Bei ungültiger Seitengröße oder leeren Server-Metadaten.
     * @example $server = new McpServer($registry, name: 'customer-service', version: '1.0.0');
     * @see McpMiddleware
     */
    public function __construct(
        private readonly McpRegistry $registry,
        private readonly string $name = 'brace/mod-mcp',
        private readonly string $version = '1.0.0',
        private readonly ?string $instructions = null,
        private readonly int $pageSize = 100,
        ?callable $authorize = null,
        ?callable $onError = null,
    ) {
        if ($pageSize < 1 || $pageSize > 1000 || $name === '' || $version === '') {
            throw new InvalidArgumentException('Use non-empty server metadata and a page size between 1 and 1000.');
        }
        $this->authorize = $authorize === null ? null : Closure::fromCallable($authorize);
        $this->onError = $onError === null ? null : Closure::fromCallable($onError);
    }

    /**
     * Bearbeitet eine JSON-RPC-Nachricht, ohne Ausgabe, Netzwerkzugriff oder PHP-Sitzung.
     * $request bringt verifizierte Auth-Attribute und Transport-Header in den Kontext.
     * Notifications führen niemals Request-Callbacks aus und erzeugen keinen Antwortbody.
     * Interne Fehler gelangen nur an onError, nicht als Stacktrace oder Exception-Text zum Client.
     *
     * @example $reply = $server->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list"}');
     * @see ProtocolResponse::toJson()
     */
    public function handle(string $payload, ?ServerRequestInterface $request = null): ProtocolResponse
    {
        $id = null;
        $modern = $request?->getHeaderLine('MCP-Protocol-Version') === self::PROTOCOL_VERSION;
        try {
            try {
                $message = json_decode($payload, false, 128, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RpcException(-32700, 'Parse error.', 400);
            }
            if (!$message instanceof stdClass) {
                throw new RpcException(-32600, 'Expected one JSON-RPC request; batches are not supported.', 400);
            }
            if (isset($message->id) && (is_string($message->id) || is_int($message->id))) {
                $id = $message->id;
            }
            if (($message->jsonrpc ?? null) !== '2.0' || !is_string($message->method ?? null)
                || $message->method === '' || property_exists($message, 'result') || property_exists($message, 'error')
                || (property_exists($message, 'id') && $id === null)
            ) {
                throw new RpcException(-32600, 'Invalid JSON-RPC request.', 400);
            }
            $params = property_exists($message, 'params') ? $message->params : new stdClass();
            if (!$params instanceof stdClass) {
                throw new RpcException(-32602, 'params must be an object.', 400);
            }
            if (!property_exists($message, 'id')) {
                return new ProtocolResponse(null, 202);
            }
            $modern = $modern || isset($params->_meta->{Protocol::META_VERSION}) || $message->method === 'server/discover';
            $context = Protocol::context($message->method, $params, $request);
            $modern = $context->protocolVersion === self::PROTOCOL_VERSION;

            // Erst nach Envelope-, Versions- und Headerprüfung darf die Registry arbeiten.
            $result = $this->dispatch($message->method, $params, $context);
            if ($modern) {
                $result['resultType'] = 'complete';
                $result['_meta'] = (object) [Protocol::META_SERVER => ['name' => $this->name, 'version' => $this->version]];
                if (in_array($message->method, [
                    'server/discover', 'tools/list', 'prompts/list',
                    'resources/list', 'resources/templates/list', 'resources/read',
                ], true)) {
                    // Keine Wiederverwendung zwischen Benutzern oder Zusage unveränderter Daten.
                    $result['cacheScope'] = 'private';
                    $result['ttlMs'] = 0;
                }
            }
            $response = new ProtocolResponse(['jsonrpc' => '2.0', 'id' => $id, 'result' => (object) $result]);
            $response->toJson();
            return $response;
        } catch (RpcException $exception) {
            $error = ['code' => $exception->getCode(), 'message' => $exception->getMessage()];
            if ($exception->details !== null) {
                $error['data'] = $exception->details;
            }
            $status = $exception->httpStatus;
        } catch (Throwable $exception) {
            if ($this->onError !== null) {
                try {
                    ($this->onError)($exception);
                } catch (Throwable) {
                    // Auch ein ausgefallenes Anwendungs-Logging darf keine Interna offenlegen.
                }
            }
            $error = ['code' => -32603, 'message' => 'Internal error.'];
            $status = 200;
        }
        $data = ['jsonrpc' => '2.0', 'error' => $error];
        if ($id !== null || !$modern) {
            $data['id'] = $id;
        }
        return new ProtocolResponse($data, $status);
    }

    private function dispatch(string $method, stdClass $params, RequestContext $context): array
    {
        $modern = $context->protocolVersion === self::PROTOCOL_VERSION;
        if ($method === 'server/discover' && $modern) {
            $result = ['supportedVersions' => self::SUPPORTED_VERSIONS, 'capabilities' => $this->capabilities()];
            if ($this->instructions !== null) {
                $result['instructions'] = $this->instructions;
            }
            return $result;
        }
        if ($method === 'initialize' && !$modern) {
            $result = [
                'protocolVersion' => $context->protocolVersion,
                'capabilities' => $this->capabilities(),
                'serverInfo' => ['name' => $this->name, 'version' => $this->version],
            ];
            if ($this->instructions !== null) {
                $result['instructions'] = $this->instructions;
            }
            return $result;
        }
        if ($method === 'ping') {
            return [];
        }
        $kind = match ($method) {
            'tools/list' => 'tools',
            'prompts/list' => 'prompts',
            'resources/list' => 'resources',
            'resources/templates/list' => 'templates',
            default => null,
        };
        if ($kind !== null) {
            $entries = [];
            foreach ($this->registry->entries($kind) as $key => $definition) {
                if ($this->allowed($kind, $key, $context)) {
                    $entries[$key] = $definition->metadata;
                }
            }
            return Pagination::page($entries, $kind === 'templates' ? 'resourceTemplates' : $kind, $params, $this->pageSize);
        }
        return match ($method) {
            'tools/call' => $this->tool($params, $context),
            'prompts/get' => $this->prompt($params, $context),
            'resources/read' => $this->resource($params, $context),
            'completion/complete' => $this->complete($params, $context),
            default => throw new RpcException(-32601, 'Method not found.', $modern ? 404 : 200),
        };
    }

    private function capabilities(): stdClass
    {
        $capabilities = new stdClass();
        foreach (['tools', 'prompts', 'resources'] as $kind) {
            if ($this->registry->entries($kind) !== [] || ($kind === 'resources' && $this->registry->entries('templates') !== [])) {
                $capabilities->{$kind} = new stdClass();
            }
        }
        if ($this->registry->hasCompletions()) {
            $capabilities->completions = new stdClass();
        }
        return $capabilities;
    }

    private function allowed(string $kind, string $identifier, RequestContext $context): bool
    {
        return $this->authorize === null || ($this->authorize)($kind, $identifier, $context) === true;
    }

    private function definition(string $kind, stdClass $params, RequestContext $context): Definition
    {
        if (!is_string($params->name ?? null)) {
            throw new RpcException(-32602, 'name must be a string.');
        }
        $definition = $this->registry->find($kind, $params->name);
        if ($definition === null || !$this->allowed($kind, $params->name, $context)) {
            throw new RpcException(-32602, 'Unknown or unavailable ' . $kind . ' name.');
        }
        return $definition;
    }

    private function arguments(stdClass $params): stdClass
    {
        $arguments = property_exists($params, 'arguments') ? $params->arguments : new stdClass();
        if (!$arguments instanceof stdClass) {
            throw new RpcException(-32602, 'arguments must be an object.');
        }
        return $arguments;
    }

    private function tool(stdClass $params, RequestContext $context): array
    {
        $definition = $this->definition('tools', $params, $context);
        $arguments = $this->arguments($params);
        $error = $definition->invocation->input->error($arguments);
        if ($error !== null) {
            return ToolResult::error('Invalid tool arguments: ' . $error)->toArray();
        }
        try {
            $value = $definition->invocation->invoke($arguments, $context);
        } catch (ToolException $exception) {
            return ToolResult::error($exception->getMessage())->toArray();
        }

        // Häufige Rückgaben bleiben einfach; spezielle Inhalte nutzen ToolResult.
        if ($value instanceof ToolResult) {
            $result = $value;
        } elseif (is_string($value)) {
            $result = ToolResult::text($value);
        } elseif (is_array($value) || is_object($value)) {
            $data = Json::value($value);
            $result = ToolResult::json($data instanceof stdClass ? $data : ['items' => $data]);
        } else {
            $result = ToolResult::text(Json::encode($value));
        }
        if ($definition->output !== null && !$result->isError) {
            if ($result->structuredContent === null
                || $definition->output->error(Json::value((object) $result->structuredContent)) !== null
            ) {
                throw new \UnexpectedValueException('Tool output does not match its outputSchema: ' . $definition->key);
            }
        }
        return $result->toArray();
    }

    private function prompt(stdClass $params, RequestContext $context): array
    {
        $definition = $this->definition('prompts', $params, $context);
        $arguments = $this->arguments($params);
        if ($definition->invocation->input->error($arguments) !== null) {
            throw new RpcException(-32602, 'Invalid prompt arguments. Prompt arguments must be strings.');
        }
        $value = $definition->invocation->invoke($arguments, $context);
        if (is_string($value)) {
            $value = PromptResult::text($value);
        }
        if (!$value instanceof PromptResult) {
            throw new \UnexpectedValueException('Prompt callbacks must return a string or PromptResult.');
        }
        if ($context->protocolVersion !== self::PROTOCOL_VERSION) {
            foreach ($value->messages as $message) {
                if ($message['content']['type'] === 'resource_link') {
                    throw new \UnexpectedValueException('resource_link prompt content requires MCP 2026-07-28.');
                }
            }
        }
        return $value->toArray();
    }

    private function resource(stdClass $params, RequestContext $context): array
    {
        if (!is_string($params->uri ?? null)) {
            throw new RpcException(-32602, 'uri must be a string.');
        }
        try {
            Json::uri($params->uri);
        } catch (InvalidArgumentException) {
            throw new RpcException(-32602, 'uri must be an absolute resource URI.');
        }
        $missing = new RpcException(
            $context->protocolVersion === self::PROTOCOL_VERSION ? -32602 : -32002,
            'Resource not found or unavailable.',
        );
        if (!$this->allowed('resources', $params->uri, $context)) {
            throw $missing;
        }
        $definition = $this->registry->find('resources', $params->uri);
        $arguments = new stdClass();
        if ($definition === null) {
            // Keine First-Match-Zufälle: mehrdeutige Templates dürfen nichts ausführen.
            $matches = [];
            foreach ($this->registry->entries('templates') as $candidate) {
                $values = $candidate->template->match($params->uri);
                if ($values !== null && $this->allowed('templates', $candidate->key, $context)) {
                    $matches[] = [$candidate, $values];
                }
            }
            if (count($matches) > 1) {
                throw new RpcException(-32602, 'Ambiguous resource URI.');
            }
            if ($matches === []) {
                throw $missing;
            }
            [$definition, $arguments] = $matches[0];
        }
        if ($definition->invocation->input->error($arguments) !== null) {
            throw new RpcException(-32602, 'Invalid resource parameters.');
        }
        $value = $definition->invocation->invoke($arguments, $context);
        if ($value instanceof ResourceResult) {
            return $value->toArray();
        }
        if (is_string($value)) {
            return ResourceResult::text($params->uri, $value, $definition->metadata['mimeType'] ?? 'text/plain')->toArray();
        }
        return ResourceResult::json($params->uri, $value)->toArray();
    }

    private function complete(stdClass $params, RequestContext $context): array
    {
        $ref = $params->ref ?? null;
        $argument = $params->argument ?? null;
        if (!$ref instanceof stdClass || !$argument instanceof stdClass
            || !is_string($argument->name ?? null) || !is_string($argument->value ?? null)
        ) {
            throw new RpcException(-32602, 'completion requires ref and a string argument name/value.');
        }
        $kind = match ($ref->type ?? null) {
            'ref/prompt' => 'prompts',
            'ref/resource' => 'templates',
            default => throw new RpcException(-32602, 'Unknown completion reference type.'),
        };
        $reference = $kind === 'prompts' ? ($ref->name ?? null) : ($ref->uri ?? null);
        if (!is_string($reference) || !$this->allowed($kind, $reference, $context)) {
            throw new RpcException(-32602, 'Unknown or unavailable completion reference.');
        }
        $definition = $this->registry->find($kind, $reference);
        if ($definition === null || !in_array($argument->name, array_column($definition->invocation->stringArguments(), 'name'), true)) {
            throw new RpcException(-32602, 'Unknown completion argument.');
        }
        $other = new stdClass();
        if (property_exists($params, 'context')) {
            if (!$params->context instanceof stdClass) {
                throw new RpcException(-32602, 'completion context must be an object.');
            }
            $other = $this->arguments($params->context);
            foreach ((array) $other as $value) {
                if (!is_string($value)) {
                    throw new RpcException(-32602, 'Completion context arguments must be strings.');
                }
            }
        }
        $callback = $this->registry->completer($ref->type, $reference, $argument->name);
        $values = $callback === null ? [] : $callback($argument->value, (array) $other, $context);
        if (!is_array($values) || !array_is_list($values)) {
            throw new \UnexpectedValueException('Completion callbacks must return a list of strings.');
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \UnexpectedValueException('Completion values must be strings.');
            }
        }
        $values = array_values(array_unique($values));
        return ['completion' => ['values' => array_slice($values, 0, 100), 'total' => count($values), 'hasMore' => count($values) > 100]];
    }
}
