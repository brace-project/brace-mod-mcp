<?php

declare(strict_types=1);

namespace Brace\Mcp\Internal;

use JsonException;
use stdClass;

/** @internal Cursors beziehen sich auf die bereits autorisierte, sortierte Sicht. */
final class Pagination
{
    public static function page(array $entries, string $field, stdClass $params, int $pageSize): array
    {
        ksort($entries, SORT_STRING);
        $items = array_values($entries);
        $fingerprint = hash('sha256', Json::encode([$field, $items, $pageSize]));
        $offset = 0;
        if (property_exists($params, 'cursor')) {
            try {
                if (!is_string($params->cursor) || strlen($params->cursor) > 512) {
                    throw new RpcException(-32602, 'Invalid pagination cursor.');
                }
                $decoded = base64_decode($params->cursor, true);
                if ($decoded === false) {
                    throw new RpcException(-32602, 'Invalid pagination cursor.');
                }
                $cursor = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
                if (!is_array($cursor) || ($cursor['view'] ?? null) !== $fingerprint
                    || !is_int($cursor['offset'] ?? null) || $cursor['offset'] <= 0
                    || $cursor['offset'] >= count($items) || $cursor['offset'] % $pageSize !== 0
                ) {
                    throw new RpcException(-32602, 'Invalid or stale pagination cursor.');
                }
                $offset = $cursor['offset'];
            } catch (JsonException) {
                throw new RpcException(-32602, 'Invalid pagination cursor.');
            }
        }
        $result = [$field => array_slice($items, $offset, $pageSize)];
        if ($offset + $pageSize < count($items)) {
            $result['nextCursor'] = base64_encode(Json::encode(['view' => $fingerprint, 'offset' => $offset + $pageSize]));
        }
        return $result;
    }
}
