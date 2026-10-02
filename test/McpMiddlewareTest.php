<?php

declare(strict_types=1);

namespace Brace\Mcp\Tests;

use Brace\Core\BraceApp;
use Brace\Core\EnvironmentType;
use Brace\Mcp\McpMiddleware;
use Brace\Mcp\McpModule;
use Brace\Mcp\McpRegistry;
use Brace\Mcp\McpServer;
use Brace\Mcp\RequestContext;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class McpMiddlewareTest extends TestCase
{
    public function testModernHttpHeadersAndResultTypes(): void
    {
        $registry = (new McpRegistry())->tool('hello', fn (string $name): array => ['name' => $name]);
        $registry->resource('config://ä', fn (): string => 'de', name: 'configuration');
        $middleware = new McpMiddleware(new McpServer($registry));
        $next = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('The normal router must not see /mcp.');
            }
        };
        $params = ['name' => 'hello', 'arguments' => ['name' => 'Matthias'], '_meta' => [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => (object) [],
        ]];
        $request = new ServerRequest('POST', 'https://example.test/mcp', [
            'Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'hello',
        ], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $params]));
        $response = $middleware->process($request, $next);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertFalse($response->hasHeader('Mcp-Session-Id'));
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('complete', $data['result']['resultType']);
        self::assertSame('Matthias', $data['result']['structuredContent']['name']);
        foreach ([$request->withoutHeader('Mcp-Method'), $request->withHeader('Mcp-Name', 'wrong'), $request->withHeader('MCP-Protocol-Version', '2025-11-25')] as $badRequest) {
            $reply = $middleware->process($badRequest, $next);
            self::assertSame(400, $reply->getStatusCode());
            self::assertSame(-32020, json_decode((string) $reply->getBody(), true)['error']['code']);
        }
        $params = ['uri' => 'config://ä', '_meta' => $params['_meta']];
        $resource = $request->withHeader('Mcp-Method', 'resources/read')
            ->withHeader('Mcp-Name', '=?base64?' . base64_encode('config://ä') . '?=')
            ->withBody(\Nyholm\Psr7\Stream::create(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => $params])));
        $reply = $middleware->process($resource, $next);
        self::assertSame('de', json_decode((string) $reply->getBody(), true)['result']['contents'][0]['text']);
    }

    public function testTransportRejectionsAndPassThrough(): void
    {
        $middleware = new McpMiddleware(new McpServer(new McpRegistry()), maxBodyBytes: 128);
        $next = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(209, [], $request->getUri()->getPath());
            }
        };
        $base = new ServerRequest('POST', 'https://example.test/mcp', [
            'Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-11-25',
        ], '{"jsonrpc":"2.0","id":1,"method":"ping"}');
        $cases = [
            [$base->withHeader('Origin', 'https://attacker.test'), 403],
            [$base->withMethod('GET'), 405],
            [$base->withMethod('DELETE'), 405],
            [$base->withHeader('Content-Type', 'text/plain'), 415],
            [$base->withHeader('Content-Encoding', 'gzip'), 415],
            [$base->withHeader('Accept', 'application/json'), 406],
            [$base->withHeader('Accept', 'application/json, text/event-stream;q=0'), 406],
            [$base->withBody(\Nyholm\Psr7\Stream::create(str_repeat('x', 129))), 413],
        ];
        foreach ($cases as [$request, $status]) {
            self::assertSame($status, $middleware->process($request, $next)->getStatusCode());
        }
        $other = $base->withUri(new \Nyholm\Psr7\Uri('https://example.test/mcp-other'));
        self::assertSame(209, $middleware->process($other, $next)->getStatusCode());
        self::assertSame('/mcp-other', (string) $middleware->process($other, $next)->getBody());
        $notification = $base->withBody(\Nyholm\Psr7\Stream::create('{"jsonrpc":"2.0","method":"notifications/initialized"}'));
        $reply = $middleware->process($notification, $next);
        self::assertSame(202, $reply->getStatusCode());
        self::assertSame('', (string) $reply->getBody());
        $allowed = new McpMiddleware(new McpServer(new McpRegistry()), allowedOrigins: ['https://frontend.test']);
        self::assertSame(200, $allowed->process($base->withHeader('Origin', 'https://frontend.test'), $next)->getStatusCode());
    }

    public function testVerifiedRequestAttributesReachCallbacksButAreNotSchemaArguments(): void
    {
        $registry = (new McpRegistry())->tool('identity', fn (RequestContext $context): string => $context->request->getAttribute('principal'));
        $server = new McpServer($registry, authorize: fn (string $kind, string $name, RequestContext $context): bool => $context->request?->getAttribute('principal') === 'matthias');
        $middleware = new McpMiddleware($server);
        $next = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(404);
            }
        };
        $request = new ServerRequest('POST', 'https://example.test/mcp', [
            'Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-11-25',
        ], '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"identity"}}');
        $denied = json_decode((string) $middleware->process($request, $next)->getBody(), true);
        self::assertSame(-32602, $denied['error']['code']);
        $allowed = json_decode((string) $middleware->process($request->withAttribute('principal', 'matthias'), $next)->getBody(), true);
        self::assertSame('matthias', $allowed['result']['content'][0]['text']);
    }

    public function testBraceModuleLeavesPipelineOrderExplicit(): void
    {
        $registry = (new McpRegistry())->tool('health', fn (): string => 'ok');
        $app = new BraceApp(EnvironmentType::DEVELOPMENT);
        $app->addModule(new McpModule($registry));
        self::assertSame($registry, $app->mcpRegistry);
        $app->setPipe([$app->mcpMiddleware]);
        $request = new ServerRequest('POST', 'https://example.test/mcp', [
            'Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-11-25',
        ], '{"jsonrpc":"2.0","id":1,"method":"tools/list"}');
        $response = $app->handle($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('health', json_decode((string) $response->getBody(), true)['result']['tools'][0]['name']);
    }
}
