<?php

declare(strict_types=1);

namespace Brace\Mcp;

use Brace\Mcp\Internal\Json;
use InvalidArgumentException;
use stdClass;

/** Explizites Tool-Ergebnis für Text, strukturierte Daten oder multimodale Inhalte. */
final readonly class ToolResult
{
    /**
     * Erstellt ein MCP-Ergebnis; jeder Content-Block wird strukturell geprüft.
     *
     * @param list<array<string, mixed>> $content MCP-Text-, Medien- oder Resource-Blöcke.
     * @param array<string, mixed>|stdClass|null $structuredContent JSON-Objekt, keine Liste.
     * @example new ToolResult([['type' => 'text', 'text' => 'Fertig']]);
     * @throws InvalidArgumentException Bei ungültigem Content oder einer strukturierten Liste.
     * @see self::json()
     */
    public function __construct(
        public array $content,
        public array|stdClass|null $structuredContent = null,
        public bool $isError = false,
    ) {
        if (!array_is_list($content)) {
            throw new InvalidArgumentException('Tool content must be a list.');
        }
        foreach ($content as $block) {
            Json::content($block);
        }
        if (is_array($structuredContent) && $structuredContent !== [] && array_is_list($structuredContent)) {
            throw new InvalidArgumentException('structuredContent must be an object, not a list.');
        }
    }

    /**
     * Erstellt ein erfolgreiches Text-Ergebnis.
     * @example return ToolResult::text('E-Mail vorbereitet.');
     * @see self::__construct()
     */
    public static function text(string $text): self
    {
        return new self([['type' => 'text', 'text' => $text]]);
    }

    /**
     * Liefert ein JSON-Objekt sowohl strukturiert als auch als kompatiblen Text-Block.
     * Listen können ausdrücklich unter einem Feld wie items abgelegt werden.
     * @param array<string, mixed>|stdClass $data Strukturierte Ergebnisdaten.
     * @example return ToolResult::json(['id' => 4711, 'name' => 'Muster GmbH']);
     * @see self::__construct()
     */
    public static function json(array|stdClass $data): self
    {
        return new self([['type' => 'text', 'text' => Json::encode((object) $data)]], $data);
    }

    /**
     * Meldet einen fachlichen Fehler an das Modell, ohne einen Protokollfehler zu erzeugen.
     * @example return ToolResult::error('Bitte eine gültige Kundennummer angeben.');
     * @see ToolException
     */
    public static function error(string $message): self
    {
        return new self([['type' => 'text', 'text' => $message]], isError: true);
    }

    /**
     * Liefert die MCP-Felder; Protokoll-Envelope und resultType ergänzt der Server.
     * @return array<string, mixed>
     * @example $fields = ToolResult::text('OK')->toArray();
     * @see McpServer::handle()
     */
    public function toArray(): array
    {
        $result = ['content' => $this->content, 'isError' => $this->isError];
        if ($this->structuredContent !== null) {
            $result['structuredContent'] = (object) $this->structuredContent;
        }
        return $result;
    }
}
