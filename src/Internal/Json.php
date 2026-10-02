<?php

declare(strict_types=1);

namespace Brace\Mcp\Internal;

use InvalidArgumentException;
use stdClass;

/** @internal Gemeinsame JSON- und Content-Grenze; keine Änderung von PHP-Output-Buffering. */
final class Json
{
    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function value(mixed $value): mixed
    {
        return json_decode(self::encode($value), false, 128, JSON_THROW_ON_ERROR);
    }

    public static function uri(string $uri): void
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:[^\s]*$/D', $uri)) {
            throw new InvalidArgumentException('Expected an absolute resource URI.');
        }
    }

    public static function content(array $content): void
    {
        $type = $content['type'] ?? null;
        if ($type === 'text' && is_string($content['text'] ?? null)) {
            return;
        }
        if (in_array($type, ['image', 'audio'], true)
            && is_string($content['data'] ?? null)
            && is_string($content['mimeType'] ?? null)
            && base64_decode($content['data'], true) !== false
        ) {
            return;
        }
        if ($type === 'resource_link' && is_string($content['uri'] ?? null) && is_string($content['name'] ?? null)) {
            self::uri($content['uri']);
            return;
        }
        if ($type === 'resource' && is_array($content['resource'] ?? null)) {
            self::resource($content['resource']);
            return;
        }
        throw new InvalidArgumentException('Invalid MCP content block.');
    }

    public static function resource(array $content): void
    {
        if (!is_string($content['uri'] ?? null)) {
            throw new InvalidArgumentException('Resource contents require a URI.');
        }
        self::uri($content['uri']);
        $text = array_key_exists('text', $content);
        $blob = array_key_exists('blob', $content);
        if ($text === $blob
            || ($text && !is_string($content['text']))
            || ($blob && (!is_string($content['blob']) || base64_decode($content['blob'], true) === false))
            || (isset($content['mimeType']) && !is_string($content['mimeType']))
        ) {
            throw new InvalidArgumentException('Resource contents require either text or a base64 blob.');
        }
    }

    /** JSON-Schema-Objekte von Datenwerten wie default/enum unterscheiden. */
    public static function schema(array|stdClass|bool $schema): stdClass|bool
    {
        if (is_bool($schema)) {
            return $schema;
        }
        $result = (object) (array) $schema;
        foreach ((array) $result as $key => $value) {
            if (in_array($key, ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'], true)) {
                $map = new stdClass();
                foreach ((array) $value as $name => $subschema) {
                    $map->{(string) $name} = self::schema($subschema);
                }
                $result->{$key} = $map;
            } elseif (in_array($key, ['items', 'additionalProperties', 'unevaluatedProperties', 'unevaluatedItems', 'contains', 'not', 'if', 'then', 'else', 'propertyNames', 'contentSchema'], true)) {
                $result->{$key} = self::schema($value);
            } elseif (in_array($key, ['allOf', 'anyOf', 'oneOf', 'prefixItems'], true)) {
                $result->{$key} = array_map(self::schema(...), $value);
            }
        }
        return $result;
    }
}
