<?php

declare(strict_types=1);

namespace Brace\Mcp\Tests;

use Brace\Mcp\Attributes\McpPrompt;
use Brace\Mcp\Attributes\McpResource;
use Brace\Mcp\Attributes\McpResourceTemplate;
use Brace\Mcp\Attributes\McpTool;
use Brace\Mcp\McpProviderInterface;
use Brace\Mcp\McpRegistry;
use Brace\Mcp\McpServer;
use Brace\Mcp\PromptResult;
use Brace\Mcp\RequestContext;
use Brace\Mcp\ResourceResult;
use Brace\Mcp\ToolException;
use Brace\Mcp\ToolResult;
use InvalidArgumentException;
use Phore\Schema\Generator\JsonSchema\JsonSchema;
use Phore\Schema\Parser\SchemaParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class McpServerTest extends TestCase
{
    public function testPhoreReflectionExportsAndValidatesOneContract(): void
    {
        $calls = 0;
        $registry = new McpRegistry();
        $registry->tool('get_customer',
            /** @param int $customerId Eindeutige Kundennummer. */
            function (int $customerId, string $language = 'de') use (&$calls): array {
                ++$calls;
                return ['id' => $customerId, 'language' => $language];
            },
        );
        $server = new McpServer($registry);
        $listed = json_decode($server->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list"}')->toJson(), true);
        $schema = $listed['result']['tools'][0]['inputSchema'];
        self::assertSame('integer', $schema['properties']['customerId']['type']);
        self::assertSame('Eindeutige Kundennummer.', $schema['properties']['customerId']['description']);
        self::assertSame(['customerId'], $schema['required']);
        self::assertSame('de', $schema['properties']['language']['default']);
        self::assertFalse($schema['additionalProperties']);

        $reply = $server->handle('{"jsonrpc":"2.0","id":"c1","method":"tools/call","params":{"name":"get_customer","arguments":{"customerId":4711}}}');
        $data = json_decode($reply->toJson(), true);
        self::assertSame('c1', $data['id']);
        self::assertSame(['id' => 4711, 'language' => 'de'], $data['result']['structuredContent']);
        self::assertSame($data['result']['structuredContent'], json_decode($data['result']['content'][0]['text'], true));
        foreach ([['customerId' => '4711'], [], ['customerId' => 1, 'extra' => true], ['customerId' => 1.5]] as $arguments) {
            $reply = $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'get_customer', 'arguments' => (object) $arguments]]));
            self::assertTrue($reply->data['result']->isError);
        }
        self::assertSame(1, $calls);
    }

    public function testNullableRequiredNoArgsAndDefaultsRemainDistinct(): void
    {
        $registry = new McpRegistry();
        $registry->tool('nullable', fn (?string $value): array => ['value' => $value]);
        $registry->tool('empty', fn (): ToolResult => ToolResult::json([]));
        $server = new McpServer($registry);
        $missing = $server->handle('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"nullable"}}');
        self::assertTrue($missing->data['result']->isError);
        $present = $server->handle('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"nullable","arguments":{"value":null}}}');
        self::assertFalse($present->data['result']->isError);
        self::assertNull($present->data['result']->structuredContent->value);
        $listed = json_decode($server->handle('{"jsonrpc":"2.0","id":3,"method":"tools/list"}')->toJson());
        self::assertInstanceOf(\stdClass::class, $listed->result->tools[0]->inputSchema->properties);
        self::assertSame(['value'], $listed->result->tools[1]->inputSchema->required);
        $empty = json_decode($server->handle('{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"empty"}}')->toJson());
        self::assertInstanceOf(\stdClass::class, $empty->result->structuredContent);
    }

    public function testCustomPhoreJsonSchemaValidatesRawArgumentsAndLocalReferences(): void
    {
        $schema = new JsonSchema([
            'type' => 'object',
            'properties' => ['name' => ['$ref' => '#/$defs/name'], 'count' => ['type' => 'integer', 'minimum' => 1]],
            '$defs' => ['name' => ['type' => 'string', 'minLength' => 3]],
            'required' => ['name'],
            'additionalProperties' => false,
        ]);
        $registry = new McpRegistry();
        $registry->tool('custom', fn (array $arguments): array => $arguments, inputSchema: $schema, rawArguments: true);
        $server = new McpServer($registry);
        $ok = $server->handle('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"custom","arguments":{"name":"Muster"}}}');
        self::assertSame('Muster', $ok->data['result']->structuredContent->name);
        $bad = $server->handle('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"custom","arguments":{"name":"ab","count":0}}}');
        self::assertTrue($bad->data['result']->isError);
        $this->expectException(InvalidArgumentException::class);
        $registry->tool('unsafe', fn (array $arguments): array => $arguments, inputSchema: [
            'type' => 'object', 'properties' => ['name' => ['$ref' => 'https://example.test/schema.json']],
        ], rawArguments: true);
    }

    public function testTypedObjectsEnumsAndListsAreHydratedByPhore(): void
    {
        $registry = new McpRegistry();
        $registry->tool('customer', fn (CustomerInput $customer, CustomerStatus $status): array => [
            'name' => $customer->name, 'defaultStatus' => $customer->status->value, 'status' => $status->value,
        ]);
        $registry->tool('numbers',
            /** @param list<int> $values Zu addierende Zahlen. */
            fn (array $values): int => array_sum($values),
        );
        $server = new McpServer($registry);
        $reply = $server->handle('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"customer","arguments":{"customer":{"name":"Muster"},"status":"inactive"}}}');
        self::assertArrayNotHasKey('error', $reply->data, $reply->toJson());
        self::assertSame(['name' => 'Muster', 'defaultStatus' => 'active', 'status' => 'inactive'], (array) $reply->data['result']->structuredContent);
        $reply = $server->handle('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"numbers","arguments":{"values":[1,2,3]}}}');
        self::assertSame('6', $reply->data['result']->content[0]['text']);
        $reply = $server->handle('{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"numbers","arguments":{"values":[1,"2"]}}}');
        self::assertTrue($reply->data['result']->isError);
    }

    public function testAttributesAndProvidersShareTheRegistryWithoutExposingOtherMethods(): void
    {
        $controller = new class {
            #[McpTool(name: 'hello')]
            public function greet(string $name, RequestContext $context): array
            {
                return ['name' => $name, 'protocol' => $context->protocolVersion];
            }

            #[McpPrompt(name: 'summary')]
            public function summary(string $name): PromptResult
            {
                return PromptResult::text('Fasse ' . $name . ' zusammen.');
            }

            #[McpResource(uri: 'config://app', name: 'configuration')]
            public function config(): array
            {
                return ['language' => 'de'];
            }

            #[McpResourceTemplate(uriTemplate: 'customer://{id}', name: 'customer')]
            public function customer(string $id): array
            {
                return ['id' => $id];
            }

            public function hidden(): string
            {
                throw new RuntimeException('Must never be discovered.');
            }
        };
        $provider = new class implements McpProviderInterface {
            public function register(McpRegistry $registry): void
            {
                $registry->tool('health', fn (): string => 'ok');
            }
        };
        $registry = (new McpRegistry())->register($controller, $provider);
        $server = new McpServer($registry);
        $listed = json_decode($server->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list"}')->toJson(), true);
        self::assertSame(['health', 'hello'], array_column($listed['result']['tools'], 'name'));
        self::assertArrayNotHasKey('context', $listed['result']['tools'][1]['inputSchema']['properties']);
        $reply = $server->handle('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"hello","arguments":{"name":"Matthias"}}}');
        self::assertSame('2025-11-25', $reply->data['result']->structuredContent->protocol);
    }

    public function testProviderRegistrationIsAtomicOnDuplicateNames(): void
    {
        $registry = (new McpRegistry())->tool('existing', fn (): string => 'ok');
        $provider = new class implements McpProviderInterface {
            public function register(McpRegistry $registry): void
            {
                $registry->tool('temporary', fn (): string => 'not committed');
                $registry->tool('existing', fn (): string => 'conflict');
            }
        };
        try {
            $registry->register($provider);
            self::fail('Duplicate registration must throw.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('Duplicate', $exception->getMessage());
        }
        $reply = (new McpServer($registry))->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list"}');
        self::assertSame(['existing'], array_column($reply->data['result']->tools, 'name'));
    }

    public function testPrivateAttributesAndUnsupportedCallbackSignaturesFailEarly(): void
    {
        $bad = new class {
            #[McpTool]
            private function secret(): string
            {
                return 'secret';
            }
        };
        $operations = [
            fn () => (new McpRegistry())->register($bad),
            fn () => (new McpRegistry())->tool('variadic', fn (string ...$values): array => $values),
            fn () => (new McpRegistry())->prompt('integer_prompt', fn (int $id): string => (string) $id),
            fn () => (new McpRegistry())->resourceTemplate('customer://{id}', fn (string $other): string => $other, name: 'bad'),
            fn () => (new McpRegistry())->resourceTemplate('customer://{+id}', fn (string $id): string => $id, name: 'unsupported'),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                self::fail('Invalid registration must throw.');
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testPromptsResourcesTemplatesAndCompletion(): void
    {
        $registry = new McpRegistry();
        $registry->prompt('summary', fn (string $name, string $language = 'de'): string => "Fasse {$name} auf {$language} zusammen.");
        $registry->resource('config://app', fn (): array => ['language' => 'de'], name: 'configuration');
        $registry->resource('document://binary', fn (): ResourceResult => ResourceResult::blob('document://binary', "\x00\xFF", 'application/octet-stream'), name: 'binary');
        $registry->resourceTemplate('customer://{id}', fn (string $id): array => ['id' => $id], name: 'customer');
        $registry->completion('ref/prompt', 'summary', 'name', fn (string $value): array => array_values(array_filter(['Muster GmbH', 'Andere GmbH'], fn (string $name): bool => str_starts_with($name, $value))));
        $registry->completion('ref/resource', 'customer://{id}', 'id', fn (): array => ['4711']);
        $server = new McpServer($registry);
        $rpc = static fn (string $method, array $params = []): array => json_decode($server->handle(json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params,
        ]))->toJson(), true);
        $prompts = $rpc('prompts/list')['result']['prompts'];
        self::assertSame([true, false], array_column($prompts[0]['arguments'], 'required'));
        self::assertSame('Fasse Muster auf de zusammen.', $rpc('prompts/get', ['name' => 'summary', 'arguments' => ['name' => 'Muster']])['result']['messages'][0]['content']['text']);
        self::assertSame(-32602, $rpc('prompts/get', ['name' => 'summary', 'arguments' => ['name' => 12]])['error']['code']);
        self::assertCount(2, $rpc('resources/list')['result']['resources']);
        self::assertSame('customer://{id}', $rpc('resources/templates/list')['result']['resourceTemplates'][0]['uriTemplate']);
        self::assertSame(['language' => 'de'], json_decode($rpc('resources/read', ['uri' => 'config://app'])['result']['contents'][0]['text'], true));
        self::assertSame('%2F', json_decode($rpc('resources/read', ['uri' => 'customer://%252F'])['result']['contents'][0]['text'], true)['id']);
        self::assertSame(base64_encode("\x00\xFF"), $rpc('resources/read', ['uri' => 'document://binary'])['result']['contents'][0]['blob']);
        self::assertSame(['Muster GmbH'], $rpc('completion/complete', ['ref' => ['type' => 'ref/prompt', 'name' => 'summary'], 'argument' => ['name' => 'name', 'value' => 'Mus']])['result']['completion']['values']);
        self::assertSame(['4711'], $rpc('completion/complete', ['ref' => ['type' => 'ref/resource', 'uri' => 'customer://{id}'], 'argument' => ['name' => 'id', 'value' => '4']])['result']['completion']['values']);
        self::assertSame(-32002, $rpc('resources/read', ['uri' => 'missing://resource'])['error']['code']);
    }

    public function testTemplateConflictsFailRatherThanPickingAnArbitraryCallback(): void
    {
        $calls = 0;
        $registry = new McpRegistry();
        $registry->resourceTemplate('x://{a}/fixed', function (string $a) use (&$calls): string {
            ++$calls;
            return $a;
        }, name: 'one');
        $registry->resourceTemplate('x://fixed/{b}', function (string $b) use (&$calls): string {
            ++$calls;
            return $b;
        }, name: 'two');
        $server = new McpServer($registry);
        $reply = $server->handle('{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"x://fixed/fixed"}}');
        self::assertSame(-32602, $reply->data['error']['code']);
        self::assertSame(0, $calls);
        $this->expectException(InvalidArgumentException::class);
        $registry->resourceTemplate('x://{other}/fixed', fn (string $other): string => $other, name: 'duplicate');
    }

    public function testStablePaginationAndAccessChecksApplyToCallsAsWellAsLists(): void
    {
        $registry = new McpRegistry();
        foreach (['z', 'b', 'a'] as $name) {
            $registry->tool($name, fn (): string => 'ok');
        }
        $server = new McpServer($registry, pageSize: 1, authorize: fn (string $kind, string $name): bool => $name !== 'z');
        $first = json_decode($server->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list"}')->toJson(), true);
        self::assertSame('a', $first['result']['tools'][0]['name']);
        $cursor = $first['result']['nextCursor'];
        $second = $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => ['cursor' => $cursor]]));
        self::assertSame('b', $second->data['result']->tools[0]['name']);
        self::assertFalse(property_exists($second->data['result'], 'nextCursor'));
        $denied = $server->handle('{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"z"}}');
        self::assertSame(-32602, $denied->data['error']['code']);
        $registry->tool('c', fn (): string => 'changed');
        $stale = $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list', 'params' => ['cursor' => $cursor]]));
        self::assertSame(-32602, $stale->data['error']['code']);
    }

    public function testBusinessErrorsAndInvalidOutputNeverLeakInternalExceptions(): void
    {
        $errors = [];
        $registry = new McpRegistry();
        $registry->tool('business', fn (): never => throw new ToolException('Kunde nicht gefunden.'));
        $registry->tool('broken', fn (): never => throw new RuntimeException('database-password=secret'));
        $registry->tool('wrong_output', fn (): array => ['name' => 42], outputSchema: [
            'type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name'],
        ]);
        $registry->tool('valid_output', fn (): CustomerInput => new CustomerInput('Muster'), outputSchema: (new SchemaParser())->parseClass(CustomerInput::class));
        $server = new McpServer($registry, onError: function (\Throwable $error) use (&$errors): void {
            $errors[] = $error;
        });
        $business = $server->handle('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"business"}}');
        self::assertTrue($business->data['result']->isError);
        self::assertSame('Kunde nicht gefunden.', $business->data['result']->content[0]['text']);
        foreach (['broken', 'wrong_output'] as $name) {
            $reply = $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $name]]));
            self::assertSame(-32603, $reply->data['error']['code']);
            self::assertStringNotContainsString('password', $reply->toJson());
        }
        self::assertCount(2, $errors);
        $valid = $server->handle('{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"valid_output"}}');
        self::assertSame('Muster', $valid->data['result']->structuredContent->name);
    }

    public function testInvalidEnvelopesAndNotificationsDoNotRunTools(): void
    {
        $calls = 0;
        $registry = (new McpRegistry())->tool('write', function () use (&$calls): string {
            ++$calls;
            return 'written';
        });
        $server = new McpServer($registry);
        foreach (['{', '[]', '{}', '{"jsonrpc":"2.0","id":null,"method":"tools/list"}', '{"jsonrpc":"2.0","id":true,"method":"tools/list"}', '{"jsonrpc":"2.0","id":1,"result":{}}'] as $json) {
            self::assertSame(400, $server->handle($json)->httpStatus);
        }
        $notification = $server->handle('{"jsonrpc":"2.0","method":"tools/call","params":{"name":"write"}}');
        self::assertSame(202, $notification->httpStatus);
        self::assertSame('', $notification->toJson());
        self::assertSame(0, $calls);
        self::assertSame(-32601, $server->handle('{"jsonrpc":"2.0","id":0,"method":"unknown"}')->data['error']['code']);
        self::assertSame(0, $server->handle('{"jsonrpc":"2.0","id":0,"method":"ping"}')->data['id']);
    }

    public function testLegacyInitializeAndModernDiscoveryAreIndependent(): void
    {
        $server = new McpServer((new McpRegistry())->tool('ready', fn (): string => 'ok'));
        $initialize = $server->handle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1"}}}');
        self::assertSame('2025-06-18', $initialize->data['result']->protocolVersion);
        self::assertFalse(property_exists($initialize->data['result'], 'resultType'));
        $discovery = $server->handle('{"jsonrpc":"2.0","id":2,"method":"server/discover","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}');
        self::assertSame('complete', $discovery->data['result']->resultType);
        self::assertSame(McpServer::SUPPORTED_VERSIONS, $discovery->data['result']->supportedVersions);
        self::assertFalse(property_exists($discovery->data['result']->capabilities, 'sampling'));
        $missingMeta = $server->handle('{"jsonrpc":"2.0","id":3,"method":"server/discover"}');
        self::assertSame(-32602, $missingMeta->data['error']['code']);
        $unsupported = $server->handle('{"jsonrpc":"2.0","id":4,"method":"server/discover","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2099-01-01","io.modelcontextprotocol/clientCapabilities":{}}}}');
        self::assertSame(-32022, $unsupported->data['error']['code']);
        self::assertSame(McpServer::SUPPORTED_VERSIONS, $unsupported->data['error']['data']['supported']);
    }
}

enum CustomerStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

final class CustomerInput
{
    public function __construct(public string $name, public CustomerStatus $status = CustomerStatus::Active)
    {
    }
}
