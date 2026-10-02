<?php

declare(strict_types=1);

namespace Brace\Mcp\Internal;

use Brace\Mcp\RequestContext;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

/** @internal Versionsgrenze zwischen per-request MCP und dem älteren Handshake. */
final class Protocol
{
    public const VERSION = '2026-07-28';
    public const LEGACY = ['2025-11-25', '2025-06-18'];
    public const VERSIONS = [self::VERSION, ...self::LEGACY];
    public const META_VERSION = 'io.modelcontextprotocol/protocolVersion';
    public const META_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
    public const META_CLIENT = 'io.modelcontextprotocol/clientInfo';
    public const META_SERVER = 'io.modelcontextprotocol/serverInfo';

    public static function context(string $method, stdClass $params, ?ServerRequestInterface $request): RequestContext
    {
        $meta = property_exists($params, '_meta') ? $params->_meta : new stdClass();
        if (!$meta instanceof stdClass) {
            throw new RpcException(-32602, '_meta must be an object.', 400);
        }
        $header = $request?->getHeaderLine('MCP-Protocol-Version') ?? '';
        $bodyVersion = $meta->{self::META_VERSION} ?? null;

        // initialize bleibt ein Legacy-Handshake; spätere HTTP-Aufrufe tragen ihre Version.
        if ($method === 'initialize' && $bodyVersion === null && $header !== self::VERSION) {
            if (!is_string($params->protocolVersion ?? null)
                || !($params->capabilities ?? null) instanceof stdClass
                || !($params->clientInfo ?? null) instanceof stdClass
                || !is_string($params->clientInfo->name ?? null)
                || !is_string($params->clientInfo->version ?? null)
            ) {
                throw new RpcException(-32602, 'initialize requires protocolVersion, capabilities and clientInfo.', 400);
            }
            $version = in_array($params->protocolVersion, self::LEGACY, true)
                ? $params->protocolVersion : self::LEGACY[0];
            return new RequestContext($version, $request, $meta);
        }

        if ($bodyVersion !== null || $header === self::VERSION || $method === 'server/discover') {
            if (!is_string($bodyVersion) || !($meta->{self::META_CAPABILITIES} ?? null) instanceof stdClass) {
                throw new RpcException(-32602, 'Required per-request MCP metadata is missing or invalid.', 400);
            }
            if ($bodyVersion !== self::VERSION) {
                throw new RpcException(-32022, 'Unsupported protocol version.', 400, [
                    'supported' => self::VERSIONS, 'requested' => $bodyVersion,
                ]);
            }
            if (property_exists($meta, self::META_CLIENT)) {
                $client = $meta->{self::META_CLIENT};
                if (!$client instanceof stdClass || !is_string($client->name ?? null) || !is_string($client->version ?? null)) {
                    throw new RpcException(-32602, 'Invalid clientInfo metadata.', 400);
                }
            }
            if ($request !== null) {
                if (count($request->getHeader('MCP-Protocol-Version')) !== 1 || $header !== $bodyVersion
                    || count($request->getHeader('Mcp-Method')) !== 1 || $request->getHeaderLine('Mcp-Method') !== $method
                ) {
                    throw new RpcException(-32020, 'Missing or mismatched MCP request headers.', 400);
                }
                $field = match ($method) {
                    'tools/call', 'prompts/get' => 'name',
                    'resources/read' => 'uri',
                    default => null,
                };
                if ($field !== null) {
                    if (!is_string($params->{$field} ?? null)) {
                        throw new RpcException(-32602, 'Missing string parameter: ' . $field, 400);
                    }
                    $value = $request->getHeaderLine('Mcp-Name');
                    if (count($request->getHeader('Mcp-Name')) !== 1) {
                        throw new RpcException(-32020, 'Missing or malformed Mcp-Name header.', 400);
                    }
                    if (str_starts_with($value, '=?base64?') && str_ends_with($value, '?=')) {
                        $encoded = substr($value, 9, -2);
                        $value = base64_decode($encoded, true);
                        if ($value === false || base64_encode($value) !== $encoded) {
                            throw new RpcException(-32020, 'Invalid Mcp-Name base64 encoding.', 400);
                        }
                    } elseif (!preg_match('/^[\x09\x20-\x7E]*$/D', $value) || trim($value, " \t") !== $value) {
                        throw new RpcException(-32020, 'Unsafe Mcp-Name header value.', 400);
                    }
                    if ($value !== $params->{$field}) {
                        throw new RpcException(-32020, 'Mcp-Name does not match the request body.', 400);
                    }
                }
            }
            return new RequestContext(self::VERSION, $request, $meta);
        }

        // In-process-Aufrufe dürfen den dokumentierten Legacy-Standard benutzen.
        $version = $header === '' && $request === null ? self::LEGACY[0] : $header;
        if (!in_array($version, self::LEGACY, true)) {
            if ($version === '') {
                throw new RpcException(-32020, 'MCP-Protocol-Version is required after initialization.', 400);
            }
            throw new RpcException(-32022, 'Unsupported protocol version.', 400, [
                'supported' => self::VERSIONS, 'requested' => $version,
            ]);
        }
        return new RequestContext($version, $request, $meta);
    }
}
