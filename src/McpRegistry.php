<?php

declare(strict_types=1);

namespace Brace\Mcp;

use Brace\Mcp\Attributes\McpPrompt;
use Brace\Mcp\Attributes\McpResource;
use Brace\Mcp\Attributes\McpResourceTemplate;
use Brace\Mcp\Attributes\McpTool;
use Brace\Mcp\Internal\Definition;
use Brace\Mcp\Internal\Invocation;
use Brace\Mcp\Internal\Json;
use Brace\Mcp\Internal\SchemaDocument;
use Brace\Mcp\Internal\UriTemplate;
use Closure;
use InvalidArgumentException;
use Phore\Schema\Generator\JsonSchema\JsonSchema;
use Phore\Schema\Schema\Type\SchemaType;
use ReflectionClass;

/** Explizite Registrierung; keine automatischen Dateiscans oder veröffentlichten HTTP-Routen. */
final class McpRegistry
{
    /** @var array<string, array<string, Definition>> */
    private array $definitions = ['tools' => [], 'prompts' => [], 'resources' => [], 'templates' => []];
    /** @var array<string, Closure> */
    private array $completions = [];

    /**
     * Registriert ein Tool und ermittelt standardmäßig sein Schema mit Phore Schema.
     * Callbacks erhalten benannte, validierte und hydrierte Parameter; Defaults bleiben erhalten.
     * rawArguments=true übergibt ein ganzes Argumentobjekt und verlangt ein explizites Schema.
     *
     * @param array|JsonSchema|SchemaType|null $inputSchema Expliziter Input-Vertrag.
     * @param array|JsonSchema|SchemaType|null $outputSchema Vertrag für structuredContent.
     * @param array<string, mixed> $annotations MCP-Verhaltenshinweise, keine Autorisierung.
     * @throws InvalidArgumentException Bei Konflikten oder nicht unterstützter Signatur.
     * @example $registry->tool('sum', fn (int $a, int $b): int => $a + $b);
     * @see Attributes\McpTool
     */
    public function tool(
        string $name,
        callable $callback,
        ?string $description = null,
        array|JsonSchema|SchemaType|null $inputSchema = null,
        array|JsonSchema|SchemaType|null $outputSchema = null,
        ?string $title = null,
        array $annotations = [],
        bool $rawArguments = false,
    ): self {
        self::name($name);
        $invocation = new Invocation($callback, $inputSchema, $rawArguments);
        $metadata = self::metadata($name, $description ?? $invocation->function->description, $title);
        $metadata['inputSchema'] = $invocation->input->document;
        $output = $outputSchema === null ? null : new SchemaDocument($outputSchema);
        if ($output !== null) {
            $metadata['outputSchema'] = $output->document;
        }
        if ($annotations !== []) {
            foreach (['readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint'] as $hint) {
                if (array_key_exists($hint, $annotations) && !is_bool($annotations[$hint])) {
                    throw new InvalidArgumentException('Tool annotation ' . $hint . ' must be boolean.');
                }
            }
            $metadata['annotations'] = (object) $annotations;
        }
        return $this->add(new Definition('tools', $name, $metadata, $invocation, $output));
    }

    /**
     * Registriert einen Prompt. Parameter sind Strings; PHP-Defaults machen sie optional.
     * Der Callback liefert PromptResult oder einen String für eine einzelne user-Nachricht.
     *
     * @example $registry->prompt('greeting', fn (string $name): string => "Begrüße {$name}.");
     * @throws InvalidArgumentException Bei Namenskonflikten oder Nicht-String-Parametern.
     * @see PromptResult
     */
    public function prompt(string $name, callable $callback, ?string $description = null, ?string $title = null): self
    {
        self::name($name);
        $invocation = new Invocation($callback);
        $metadata = self::metadata($name, $description ?? $invocation->function->description, $title);
        $metadata['arguments'] = $invocation->stringArguments();
        return $this->add(new Definition('prompts', $name, $metadata, $invocation));
    }

    /**
     * Registriert eine feste Resource-URI. Der Callback erhält höchstens RequestContext.
     * Strings werden Text, andere JSON-Daten werden JSON; ResourceResult erlaubt Binärdaten.
     *
     * @example $registry->resource('config://app', fn (): array => ['language' => 'de'], name: 'configuration');
     * @throws InvalidArgumentException Bei doppelter URI oder fachlichen Callback-Parametern.
     * @see ResourceResult
     */
    public function resource(
        string $uri,
        callable $callback,
        string $name,
        ?string $description = null,
        ?string $mimeType = null,
        ?string $title = null,
    ): self {
        Json::uri($uri);
        $invocation = new Invocation($callback);
        if ($invocation->stringArguments() !== []) {
            throw new InvalidArgumentException('A fixed resource cannot require URI parameters.');
        }
        $metadata = self::metadata($name, $description ?? $invocation->function->description, $title);
        $metadata['uri'] = $uri;
        if ($mimeType !== null) {
            $metadata['mimeType'] = $mimeType;
        }
        return $this->add(new Definition('resources', $uri, $metadata, $invocation));
    }

    /**
     * Registriert eine Familie von Resource-URIs; unterstützt getrennte einfache {variable}.
     * Die benannten String-Parameter müssen genau zu den URI-Variablen passen.
     * Prozentkodierte Werte werden nach dem Match einmal dekodiert, nie als Pfad geöffnet.
     *
     * @example $registry->resourceTemplate('customer://{customerId}', fn (string $customerId): array => ['id' => $customerId], name: 'customer');
     * @throws InvalidArgumentException Bei Konflikten oder nicht unterstütztem URI-Muster.
     * @see Attributes\McpResourceTemplate
     */
    public function resourceTemplate(
        string $uriTemplate,
        callable $callback,
        string $name,
        ?string $description = null,
        ?string $mimeType = null,
        ?string $title = null,
    ): self {
        $template = new UriTemplate($uriTemplate);
        $invocation = new Invocation($callback);
        $parameters = array_column($invocation->stringArguments(), 'name');
        $variables = $template->variables;
        sort($parameters);
        sort($variables);
        if ($parameters !== $variables) {
            throw new InvalidArgumentException('Resource-template variables must match the callback parameters.');
        }
        foreach ($this->definitions['templates'] as $existing) {
            if ($existing->template->shape === $template->shape) {
                throw new InvalidArgumentException('Conflicting resource template: ' . $uriTemplate);
            }
        }
        $metadata = self::metadata($name, $description ?? $invocation->function->description, $title);
        $metadata['uriTemplate'] = $uriTemplate;
        if ($mimeType !== null) {
            $metadata['mimeType'] = $mimeType;
        }
        return $this->add(new Definition('templates', $uriTemplate, $metadata, $invocation, template: $template));
    }

    /**
     * Ergänzt Vorschläge für ein Prompt-Argument oder eine Resource-Template-Variable.
     * Callback: fn(string $value, array $otherArguments, RequestContext $context): array.
     * Er darf die unbenötigten letzten Parameter weglassen; geliefert wird eine String-Liste.
     *
     * @param 'ref/prompt'|'ref/resource' $type MCP-Referenztyp.
     * @example $registry->completion('ref/prompt', 'greeting', 'name', fn (string $value): array => ['Matthias']);
     * @throws InvalidArgumentException Bei unbekannter Definition, Variable oder Doppelung.
     * @see McpServer::handle()
     */
    public function completion(string $type, string $reference, string $argument, callable $callback): self
    {
        $kind = match ($type) {
            'ref/prompt' => 'prompts',
            'ref/resource' => 'templates',
            default => throw new InvalidArgumentException('Invalid completion reference type.'),
        };
        $definition = $this->find($kind, $reference);
        if ($definition === null || !in_array($argument, array_column($definition->invocation->stringArguments(), 'name'), true)) {
            throw new InvalidArgumentException('Completion references an unknown argument.');
        }
        $key = Json::encode([$type, $reference, $argument]);
        if (isset($this->completions[$key])) {
            throw new InvalidArgumentException('Duplicate completion callback.');
        }
        $this->completions[$key] = Closure::fromCallable($callback);
        return $this;
    }

    /**
     * Nimmt mehrere Provider oder bereits konstruierte Objekte mit MCP-Attributen auf.
     * Nur explizit markierte öffentliche Methoden werden sichtbar. HTTP-Attribute bleiben unberührt.
     * Bei einem Fehler werden sämtliche Änderungen dieses register-Aufrufs zurückgenommen.
     *
     * @example $registry->register(new CustomerApi($service), new ReportingProvider());
     * @throws InvalidArgumentException Bei privaten markierten Methoden oder Konflikten.
     * @see McpProviderInterface
     */
    public function register(object ...$providers): self
    {
        $staging = clone $this;
        foreach ($providers as $provider) {
            if ($provider instanceof McpProviderInterface) {
                $provider->register($staging);
                continue;
            }
            foreach ((new ReflectionClass($provider))->getMethods() as $method) {
                foreach ([McpTool::class, McpPrompt::class, McpResource::class, McpResourceTemplate::class] as $attributeClass) {
                    foreach ($method->getAttributes($attributeClass) as $attribute) {
                        if (!$method->isPublic()) {
                            throw new InvalidArgumentException('MCP attributes require a public method: ' . $method->name);
                        }
                        $meta = $attribute->newInstance();
                        $callback = [$provider, $method->name];
                        if ($meta instanceof McpTool) {
                            $staging->tool($meta->name ?? $method->name, $callback, $meta->description, $meta->inputSchema, $meta->outputSchema, $meta->title, $meta->annotations, $meta->rawArguments);
                        } elseif ($meta instanceof McpPrompt) {
                            $staging->prompt($meta->name ?? $method->name, $callback, $meta->description, $meta->title);
                        } elseif ($meta instanceof McpResource) {
                            $staging->resource($meta->uri, $callback, $meta->name, $meta->description, $meta->mimeType, $meta->title);
                        } else {
                            $staging->resourceTemplate($meta->uriTemplate, $callback, $meta->name, $meta->description, $meta->mimeType, $meta->title);
                        }
                    }
                }
            }
        }
        $this->definitions = $staging->definitions;
        $this->completions = $staging->completions;
        return $this;
    }

    /** @internal */
    public function entries(string $kind): array
    {
        return $this->definitions[$kind] ?? [];
    }

    /** @internal */
    public function find(string $kind, string $key): ?Definition
    {
        return $this->definitions[$kind][$key] ?? null;
    }

    /** @internal */
    public function completer(string $type, string $reference, string $argument): ?Closure
    {
        return $this->completions[Json::encode([$type, $reference, $argument])] ?? null;
    }

    /** @internal */
    public function hasCompletions(): bool
    {
        return $this->completions !== [];
    }

    private function add(Definition $definition): self
    {
        if (isset($this->definitions[$definition->kind][$definition->key])) {
            throw new InvalidArgumentException('Duplicate MCP ' . $definition->kind . ': ' . $definition->key);
        }
        $this->definitions[$definition->kind][$definition->key] = $definition;
        return $this;
    }

    private static function name(string $name): void
    {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $name)) {
            throw new InvalidArgumentException('MCP names must contain 1-128 ASCII letters, digits, dots, underscores or hyphens.');
        }
    }

    private static function metadata(string $name, ?string $description, ?string $title): array
    {
        if ($name === '') {
            throw new InvalidArgumentException('MCP definitions require a non-empty name.');
        }
        $metadata = ['name' => $name];
        if ($description !== null && $description !== '') {
            $metadata['description'] = $description;
        }
        if ($title !== null) {
            $metadata['title'] = $title;
        }
        return $metadata;
    }
}
