<?php

declare(strict_types=1);

namespace Brace\Mcp;

use Brace\Mcp\Internal\Json;
use InvalidArgumentException;

/** Gerenderte Prompt-Nachrichten; dieser Server ruft dafür kein LLM auf. */
final readonly class PromptResult
{
    /**
     * Erstellt einen Prompt aus user-/assistant-Nachrichten mit MCP-Content.
     *
     * @param list<array{role: string, content: array<string, mixed>}> $messages Nachrichten.
     * @example new PromptResult([['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Prüfe den Code.']]]);
     * @throws InvalidArgumentException Bei ungültigen Rollen oder Content-Blöcken.
     * @see self::text()
     */
    public function __construct(public array $messages, public ?string $description = null)
    {
        if (!array_is_list($messages)) {
            throw new InvalidArgumentException('Prompt messages must be a list.');
        }
        foreach ($messages as $message) {
            if (!in_array($message['role'] ?? null, ['user', 'assistant'], true) || !is_array($message['content'] ?? null)) {
                throw new InvalidArgumentException('Invalid prompt message.');
            }
            Json::content($message['content']);
        }
    }

    /**
     * Erstellt einen Prompt mit einer einzelnen user-Nachricht.
     * @example return PromptResult::text('Fasse die folgenden Kundendaten zusammen: ...');
     * @see self::__construct()
     */
    public static function text(string $text, ?string $description = null): self
    {
        return new self([['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]]], $description);
    }

    /**
     * Liefert Prompt-Messages und die optionale Beschreibung ohne JSON-RPC-Envelope.
     * @return array<string, mixed>
     * @example $messages = PromptResult::text('Hallo')->toArray()['messages'];
     * @see McpServer::handle()
     */
    public function toArray(): array
    {
        $result = ['messages' => $this->messages];
        if ($this->description !== null) {
            $result['description'] = $this->description;
        }
        return $result;
    }
}
