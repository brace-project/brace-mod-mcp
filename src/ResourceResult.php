<?php

declare(strict_types=1);

namespace Brace\Mcp;

use Brace\Mcp\Internal\Json;
use InvalidArgumentException;

/** Inhalte einer oder mehrerer gelesener Ressourcen. */
final readonly class ResourceResult
{
    /**
     * Erstellt ein resources/read-Ergebnis; jeder Eintrag hat URI und genau text oder blob.
     * @param list<array<string, mixed>> $contents Ressourceninhalte.
     * @example new ResourceResult([['uri' => 'config://app', 'text' => 'Hallo']]);
     * @throws InvalidArgumentException Bei ungültigen Ressourceninhalten.
     * @see self::text()
     */
    public function __construct(public array $contents)
    {
        if (!array_is_list($contents)) {
            throw new InvalidArgumentException('Resource contents must be a list.');
        }
        foreach ($contents as $content) {
            Json::resource($content);
        }
    }

    /**
     * Erstellt eine Text-Ressource ohne Netzwerk- oder Dateizugriff.
     * @example return ResourceResult::text('config://app', 'language=de');
     * @see self::__construct()
     */
    public static function text(string $uri, string $text, string $mimeType = 'text/plain'): self
    {
        return new self([['uri' => $uri, 'mimeType' => $mimeType, 'text' => $text]]);
    }

    /**
     * Serialisiert Anwendungsdaten als application/json-Ressource.
     * @example return ResourceResult::json('customer://4711', ['name' => 'Muster GmbH']);
     * @see self::text()
     */
    public static function json(string $uri, mixed $data): self
    {
        return self::text($uri, Json::encode($data), 'application/json');
    }

    /**
     * Kodiert rohe Binärdaten einmalig als base64-Blob; liest selbst keine Datei.
     * @example return ResourceResult::blob('document://invoice', $pdfBytes, 'application/pdf');
     * @see self::__construct()
     */
    public static function blob(string $uri, string $bytes, string $mimeType): self
    {
        return new self([['uri' => $uri, 'mimeType' => $mimeType, 'blob' => base64_encode($bytes)]]);
    }

    /**
     * Liefert die contents-Liste für das MCP-Protokoll.
     * @return array{contents: array}
     * @example $contents = ResourceResult::text('config://app', 'de')->toArray();
     * @see McpServer::handle()
     */
    public function toArray(): array
    {
        return ['contents' => $this->contents];
    }
}
