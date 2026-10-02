<?php

declare(strict_types=1);

namespace Brace\Mcp\Tests;

use Brace\Mcp\McpRegistry;
use Brace\Mcp\McpServer;
use PHPUnit\Framework\TestCase;

final class CachePolicyTest extends TestCase
{
    public function testModernCacheHintsArePrivateAndImmediatelyStale(): void
    {
        $registry = new McpRegistry();
        $registry->tool('health', fn (): string => 'ok');
        $registry->prompt('hello', fn (): string => 'Hallo');
        $registry->resource('config://app', fn (): string => 'de', name: 'config');
        $server = new McpServer($registry);
        $methods = [
            'server/discover' => [],
            'tools/list' => [],
            'prompts/list' => [],
            'resources/list' => [],
            'resources/templates/list' => [],
            'resources/read' => ['uri' => 'config://app'],
        ];
        foreach ($methods as $method => $params) {
            $result = $this->result($server, $method, $params, true);
            self::assertSame('private', $result->cacheScope, $method);
            self::assertSame(0, $result->ttlMs, $method);
            self::assertSame('complete', $result->resultType, $method);
            if ($method !== 'server/discover') {
                $legacy = $this->result($server, $method, $params, false);
                self::assertFalse(property_exists($legacy, 'cacheScope'), $method);
                self::assertFalse(property_exists($legacy, 'ttlMs'), $method);
            }
        }
        foreach (['tools/call' => ['name' => 'health'], 'prompts/get' => ['name' => 'hello'], 'ping' => []] as $method => $params) {
            $result = $this->result($server, $method, $params, true);
            self::assertFalse(property_exists($result, 'cacheScope'), $method);
            self::assertFalse(property_exists($result, 'ttlMs'), $method);
        }
    }

    private function result(McpServer $server, string $method, array $params, bool $modern): \stdClass
    {
        if ($modern) {
            $params['_meta'] = [
                'io.modelcontextprotocol/protocolVersion' => McpServer::PROTOCOL_VERSION,
                'io.modelcontextprotocol/clientCapabilities' => (object) [],
            ];
        }
        $reply = $server->handle(json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params,
        ], JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('error', $reply->data, $reply->toJson());
        return $reply->data['result'];
    }
}
