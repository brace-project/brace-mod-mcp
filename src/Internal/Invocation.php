<?php

declare(strict_types=1);

namespace Brace\Mcp\Internal;

use Brace\Mcp\RequestContext;
use Closure;
use InvalidArgumentException;
use Phore\Schema\Generator\JsonSchema\JsonSchema;
use Phore\Schema\Hydrator\Hydrator;
use Phore\Schema\Parser\SchemaParser;
use Phore\Schema\Schema\ClassSchema;
use Phore\Schema\Schema\FunctionSchema;
use Phore\Schema\Schema\PropertySchema;
use Phore\Schema\Schema\Type\ArraySchemaType;
use Phore\Schema\Schema\Type\PrimitiveSchemaType;
use Phore\Schema\Schema\Type\SchemaType;
use stdClass;

/** @internal Bindet ausschließlich registrierte Callables, niemals vom Client gelieferte PHP-Namen. */
final class Invocation
{
    public readonly FunctionSchema $function;
    public readonly SchemaDocument $input;
    private readonly Closure $callback;
    private readonly Hydrator $hydrator;

    public function __construct(
        callable $callback,
        array|JsonSchema|SchemaType|null $inputSchema = null,
        private readonly bool $rawArguments = false,
    ) {
        $this->callback = Closure::fromCallable($callback);
        $this->function = (new SchemaParser())->parseCallable($callback);
        $this->hydrator = new Hydrator();
        $properties = [];
        $required = [];
        foreach ($this->function->parameters as $parameter) {
            if ($parameter->isVariadic || $parameter->isPassedByReference) {
                throw new InvalidArgumentException('MCP callbacks cannot have variadic or by-reference parameters.');
            }
            if ($parameter->nativeType === RequestContext::class) {
                continue;
            }
            $properties[] = new PropertySchema(
                name: $parameter->name,
                type: $parameter->type,
                description: $parameter->description,
                allowsNull: $parameter->allowsNull,
                hasDefaultValue: $parameter->hasDefaultValue,
                defaultValue: $parameter->defaultValue,
            );
            // Ein nullable Funktionsparameter ist ohne Default trotzdem erforderlich.
            if (!$parameter->hasDefaultValue) {
                $required[] = $parameter->name;
            }
        }

        if ($rawArguments) {
            $parameters = $this->function->parameters;
            if ($inputSchema === null
                || count($parameters) < 1 || count($parameters) > 2
                || !in_array($parameters[0]->nativeType, ['array', stdClass::class], true)
                || (isset($parameters[1]) && $parameters[1]->nativeType !== RequestContext::class)
            ) {
                throw new InvalidArgumentException('rawArguments requires an explicit schema and callback(array|stdClass $arguments, optional RequestContext $context).');
            }
        }
        if ($inputSchema === null) {
            $data = (new ClassSchema(className: '', shortName: 'Arguments', properties: $properties))->toJsonSchema()->data();
            $data['required'] = $required;
            unset($data['title']);
            $inputSchema = new JsonSchema($data);
        }
        $this->input = new SchemaDocument($inputSchema);
    }

    /** @return list<array{name: string, description: string, required: bool}> */
    public function stringArguments(): array
    {
        $arguments = [];
        foreach ($this->function->parameters as $parameter) {
            if ($parameter->nativeType === RequestContext::class) {
                continue;
            }
            if ($parameter->nativeType !== 'string') {
                throw new InvalidArgumentException('Prompt and URI-template parameters must use string; use a tool for typed input.');
            }
            $arguments[] = [
                'name' => $parameter->name,
                'description' => $parameter->description,
                'required' => !$parameter->hasDefaultValue,
            ];
        }
        return $arguments;
    }

    public function invoke(stdClass $arguments, RequestContext $context): mixed
    {
        if ($this->rawArguments) {
            $input = $this->function->parameters[0]->nativeType === 'array'
                ? json_decode(Json::encode($arguments), true, 128, JSON_THROW_ON_ERROR)
                : $arguments;
            return count($this->function->parameters) === 2
                ? ($this->callback)($input, $context)
                : ($this->callback)($input);
        }

        // Reihenfolge, Defaults und DTO-/Enum-Hydration bleiben an der Callable-Grenze.
        $values = [];
        foreach ($this->function->parameters as $parameter) {
            if ($parameter->nativeType === RequestContext::class) {
                $values[] = $context;
            } elseif (property_exists($arguments, $parameter->name)) {
                $listType = new ArraySchemaType(
                    new PrimitiveSchemaType(PrimitiveSchemaType::INT),
                    $parameter->type,
                    ArraySchemaType::KIND_LIST,
                );
                $values[] = $this->hydrator->hydrate($listType, [$arguments->{$parameter->name}])[0];
            } elseif ($parameter->hasDefaultValue) {
                $values[] = $parameter->defaultValue;
            } else {
                throw new InvalidArgumentException('Missing callback argument: ' . $parameter->name);
            }
        }
        return ($this->callback)(...$values);
    }
}
