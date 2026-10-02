<?php

declare(strict_types=1);

namespace Brace\Mcp\Internal;

use InvalidArgumentException;
use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Phore\Schema\Generator\JsonSchema\JsonClassSchemaGenerator;
use Phore\Schema\Generator\JsonSchema\JsonSchema;
use Phore\Schema\Schema\Type\SchemaType;
use stdClass;

/** @internal Phore erzeugt das Schema; exakt dieses JSON Schema validiert die Wire-Daten. */
final class SchemaDocument
{
    public readonly stdClass $document;
    private CompliantValidator $validator;

    public function __construct(array|JsonSchema|SchemaType $schema)
    {
        if ($schema instanceof SchemaType) {
            $schema = (new JsonClassSchemaGenerator())->generate($schema);
        }
        $data = $schema instanceof JsonSchema ? $schema->data() : $schema;
        if (($data['type'] ?? null) !== 'object') {
            throw new InvalidArgumentException('An MCP tool schema must have type=object at its root.');
        }
        $this->document = Json::schema($data);
        $this->document->{'$schema'} ??= 'https://json-schema.org/draft/2020-12/schema';
        if ($this->document->{'$schema'} !== 'https://json-schema.org/draft/2020-12/schema') {
            throw new InvalidArgumentException('Only JSON Schema draft 2020-12 is supported.');
        }

        // Keine Schema-gesteuerten Netzwerkzugriffe oder nicht implementierten Header-Verträge.
        $pending = [[$this->document, 0]];
        $nodes = 0;
        while ($pending !== []) {
            [$node, $depth] = array_pop($pending);
            if (++$nodes > 10000 || $depth > 64) {
                throw new InvalidArgumentException('Schema exceeds the supported complexity limit.');
            }
            foreach ((array) $node as $key => $value) {
                if (in_array($key, ['$ref', '$dynamicRef', '$recursiveRef'], true)
                    && (!is_string($value) || !str_starts_with($value, '#'))
                ) {
                    throw new InvalidArgumentException('Only document-local schema references are supported.');
                }
                if ($key === 'x-mcp-header') {
                    throw new InvalidArgumentException('Custom x-mcp-header bindings are not supported by this transport.');
                }
                if (is_array($value) || $value instanceof stdClass) {
                    $pending[] = [$value, $depth + 1];
                }
            }
        }
        $this->validator = new CompliantValidator();
        $this->validator->setMaxErrors(5);
        // Kompiliert das Schema früh; ein ungültiger Datenwert ist hier unerheblich.
        $this->validator->validate(new stdClass(), $this->document);
    }

    public function error(mixed $data): ?string
    {
        $result = $this->validator->validate($data, $this->document);
        return $result->isValid() ? null : Json::encode((new ErrorFormatter())->format($result->error()));
    }
}
