<?php

declare(strict_types=1);

namespace Brace\Mcp\Internal;

use InvalidArgumentException;
use stdClass;

/** @internal Bewusst begrenztes RFC-6570-Profil: getrennte einfache {variable}-Expansionen. */
final readonly class UriTemplate
{
    public array $variables;
    public string $shape;
    private string $pattern;

    public function __construct(public string $template)
    {
        $parts = preg_split('/(\{[A-Za-z_][A-Za-z0-9_]*\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        $pattern = '';
        $variables = [];
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                if (str_contains($part, '{') || str_contains($part, '}')) {
                    throw new InvalidArgumentException('Only simple URI-template variables such as {customerId} are supported.');
                }
                $pattern .= preg_quote($part, '~');
                continue;
            }
            $name = substr($part, 1, -1);
            if (in_array($name, $variables, true) || ($index > 1 && $parts[$index - 1] === '')) {
                throw new InvalidArgumentException('URI-template variables must be unique and separated by a literal.');
            }
            $variables[] = $name;
            $pattern .= '(?P<' . $name . '>(?:[A-Za-z0-9._\~-]|%[0-9A-Fa-f]{2})*)';
        }
        if ($variables === []) {
            throw new InvalidArgumentException('A resource template must contain at least one variable.');
        }
        Json::uri(preg_replace('/\{[^}]+\}/', 'value', $template));
        $this->variables = $variables;
        $this->shape = preg_replace('/\{[^}]+\}/', '{}', $template);
        $this->pattern = '~^' . $pattern . '$~D';
    }

    public function match(string $uri): ?stdClass
    {
        if (preg_match($this->pattern, $uri, $matches) !== 1) {
            return null;
        }
        $arguments = new stdClass();
        foreach ($this->variables as $name) {
            $arguments->{$name} = rawurldecode($matches[$name]);
        }
        return $arguments;
    }
}
