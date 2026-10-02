# brace/mod-mcp

MCP-Server für Brace und PSR-15-Anwendungen: Tools, Prompts, Resources und
Resource-Templates werden einmal registriert und über einen konfigurierbaren
HTTP-Endpoint angeboten. Phore Schema liest PHP-Typen und PHPDoc, erzeugt die
JSON-Schemas und hydriert die Callback-Parameter. Die Middleware läuft vor dem
normalen Router und braucht weder einen zusätzlichen Serverprozess noch eine
PHP-Sitzung.

## Start und Beispiele

Voraussetzungen: PHP 8.3+, Composer und die von `phore/schema` benötigte
YAML-Erweiterung. Im Repository installiert `composer install` auch die
Entwicklungsabhängigkeiten für die Brace-/Laminas-Beispiele.

```sh
composer install
php examples/01-first-tool.php
php -S 127.0.0.1:8099 -t examples/public examples/public/index.php
```

Der HTTP-Demo-Server bietet danach `/mcp` an. Er enthält nur öffentliche
Beispieldaten und absichtlich keine Anmeldung; nicht mit produktiven Daten
öffentlich bereitstellen. Für Apache zeigt der DocumentRoot auf
`examples/public`. Die dortige `.htaccess` benötigt `mod_rewrite` und
`AllowOverride FileInfo`; alternativ leitet die bestehende VirtualHost-
Konfiguration `/mcp` an den eigenen Front Controller weiter.

| Beispiel | Inhalt |
| --- | --- |
| [01: Erstes Tool](examples/01-first-tool.php) | Vollständige Registrierung, Phore-Reflection, Discovery, Aufruf und Ergebnis. |
| [02: HTTP-Bootstrap](examples/02-http-bootstrap.php) | Brace, Request-Modul, MCP-Modul und explizite Middleware-Reihenfolge. |
| [03: Attribute](examples/03-attributes.php) | Tools, Prompts, feste Resources und Templates an einem Controller. |
| [04: Eigenes Schema](examples/04-custom-schema.php) | Phore-`JsonSchema`, gesamtes Argumentobjekt, Constraints und Output-Validierung. |
| [05: Provider und Completion](examples/05-providers-and-completion.php) | Mehrere Module zusammenführen und konkrete Argumentvorschläge liefern. |
| [06: Gemeinsame API](examples/06-shared-api.php) | Dieselbe Methode als echte Brace-Frontend-Route und MCP-Tool. |

Als Consumer wird das Composer-Paket `brace/mod-mcp` eingebunden. Solange es
noch nicht auf Packagist veröffentlicht ist, muss das Repository als
Composer-VCS-Repository hinterlegt werden; anschließend kann die gewünschte
Branch-Version verlangt werden. `brace/mod-router` und
`brace/mod-request-laminas` sind nur Entwicklungsabhängigkeiten der Beispiele,
nicht Voraussetzungen der eigenständigen PSR-15-Middleware.

## Die Anwendungs-API

```php
use Brace\Mcp\McpModule;
use Brace\Mcp\McpRegistry;

$registry = new McpRegistry();
$registry->tool(
    'get_customer',
    fn (int $customerId): array => ['id' => $customerId, 'name' => 'Muster GmbH'],
    description: 'Liest einen Kunden.',
);

// $app ist die bestehende BraceApp mit ihrem Request-/Response-Modul.
$app->addModule(new McpModule($registry, path: '/mcp'));
// $app->mcpMiddleware ausdrücklich NACH Auth und VOR dem Router einordnen.
```

Die Registry bietet `tool()`, `prompt()`, `resource()`, `resourceTemplate()`,
`completion()` und `register()`. Alle Registrierungen sind kombinierbar.
`register()` nimmt ein oder mehrere `McpProviderInterface`-Objekte oder bereits
konstruierte Objekte mit MCP-Attributen auf. Es gibt keinen impliziten
Dateisystemscan und keine automatische Veröffentlichung aller API-Routen.
Ein fehlerhafter `register()`-Aufruf übernimmt keine Teilregistrierungen.

Doppelte Tool-/Prompt-Namen und Resource-URIs sind Konfigurationsfehler. Tool-
und Prompt-Namen verwenden 1–128 ASCII-Buchstaben, Ziffern, Punkt, Bindestrich
oder Unterstrich. Die Namen sind case-sensitive und innerhalb ihrer Kategorie
eindeutig. Tools und Prompts dürfen denselben Namen tragen.

### Tools und Phore Schema

Ohne explizites Schema verwendet die Registry
`SchemaParser::parseCallable()`. PHPDoc liefert Beschreibungen und detaillierte
Listen-/Map-Typen. `int`, `float`, `bool`, Strings, Nullable-/Union-Typen,
unterstützte DTOs und Enums werden über Phore Schema beschrieben und hydriert.
Ein nullable Parameter ohne Default bleibt erforderlich; ein PHP-Default macht
ihn optional. Variadische und per Referenz übergebene Parameter sind bewusst
nicht erlaubt.

`inputSchema` und `outputSchema` akzeptieren ein PHP-Array, ein Phore-
`JsonSchema`-Objekt oder einen passenden Phore-`SchemaType`. Bei normalem
Parameter-Binding müssen die Properties zum Callback passen. Mit
`rawArguments: true` erhält der Callback stattdessen das gesamte validierte
Objekt als `array` oder `stdClass`; ein explizites Input-Schema ist dann Pflicht.
Ein zweiter Parameter darf `RequestContext` sein.

Phore Schema ist für Reflection, Typmodell, Schema-Erzeugung und Hydration
zuständig. Die Validierung genau des veröffentlichten JSON-Schemas übernimmt
`opis/json-schema` im standardkonformen Modus. So gelten auch bei manuell
übergebenen Schemas `required`, `enum`, Constraints und lokale `$ref`-Verweise.
Unterstützt wird JSON Schema 2020-12. Externe Schema-Referenzen und
`x-mcp-header`-Bindings werden früh abgelehnt; Schemas lösen keine
Netzwerkzugriffe aus. JSON-Schema-Defaults verändern keine Eingabedaten.

Rückgabewerte bleiben einfach: Strings werden Text, assoziative Arrays und
Objekte werden `structuredContent` plus kompatibler JSON-Text. Listen werden
unter `items` verpackt; andere Skalare werden als JSON-Text ausgegeben.
`ToolResult` erlaubt mehrere Text-/Medien-/Resource-Content-Blöcke.
`ToolResult::json([])` liefert ausdrücklich ein leeres JSON-Objekt.

Für einheitliche Ergebnisse über die unterstützten Protokollversionen hinweg
verwendet diese Library ausschließlich objektförmige strukturierte Outputs
und objektförmige Output-Schemas. MCP 2026 erlaubt darüber hinaus auch andere
JSON-Output-Typen; diese zusätzliche Variante ist hier nicht implementiert.
Ein vereinbartes Output-Schema wird vor der Auslieferung geprüft.

`ToolException` und `ToolResult::error()` melden bewusst öffentliche fachliche
Fehler als `isError: true`. Ungültige Tool-Eingaben werden ebenfalls als
Tool-Fehler zurückgegeben, ohne den Callback auszuführen. Unbekannte Tools,
ungültige Protokollnachrichten und interne Fehler sind JSON-RPC-Fehler.
Unerwartete Exception-Texte werden nicht an Clients weitergereicht; der
optionale `onError`-Callback des Servers erhält sie für das Anwendungs-Logging.
Die Library startet keine eigene Logging-Engine und kein Output-Buffering.

### Prompts

Prompt-Parameter sind gemäß MCP Strings. Aus den Parametern werden `name`,
`description` und `required` für `prompts/list` erzeugt. PHP-Defaults bleiben
auch bei `prompts/get` wirksam. Der Callback liefert einen String für eine
User-Nachricht oder `PromptResult` mit mehreren `user`-/`assistant`-Nachrichten.
Ein Prompt-Abruf rendert nur die Vorlage; er ruft kein Sprachmodell auf.

### Resources und Templates

Eine feste Resource hat eine vollständige URI, beispielsweise
`config://application`. Der Callback hat keine fachlichen Eingabeparameter;
sein Inhalt darf sich dennoch ändern. Ein Resource-Template beschreibt eine
Familie wie `customer://{customerId}`. Seine benannten String-Parameter müssen
zu den URI-Variablen passen. Beide Varianten werden mit `resources/read`
gelesen; es gibt keine implizite Schreiboperation.

Das unterstützte RFC-6570-Profil umfasst getrennte einfache Variablen wie
`{customerId}`. Operatoren wie `{+path}`, Query-Expansionen, zusammengesetzte
Ausdrücke und direkt aneinanderstehende Variablen sind nicht implementiert und
werden abgelehnt. Werte werden genau einmal percent-dekodiert. Strukturell
identische Templates werden bei der Registrierung abgelehnt; weitere
Mehrdeutigkeiten beim Lesen führen zu einem Fehler statt zu einem beliebigen
Callback. Exakte Resource-URIs haben Vorrang vor Templates.

Strings werden Text-Ressourcen, andere Daten JSON-Ressourcen. `ResourceResult`
ermöglicht mehrere Inhalte oder `blob()` für rohe Binärdaten mit einmaliger
Base64-Kodierung. Die Registry öffnet weder Dateien noch URLs automatisch.
Dateipfad-, Mandanten- und Objektberechtigungen bleiben Aufgabe des Callbacks.

### Completion und Pagination

`completion()` ordnet einem Prompt-Argument oder einer Template-Variablen einen
Callback zu. Er erhält den eingegebenen String, weitere bereits ausgefüllte
Argumente als Array und optional den Request-Kontext. Die Antwort enthält
höchstens 100 eindeutige Vorschläge sowie `total` und `hasMore`.

Alle Listen sind deterministisch sortiert und paginiert. Standardmäßig werden
100 Einträge geliefert; `pageSize` ist konfigurierbar. Cursors beziehen sich auf
die aktuell berechtigte Sicht. Bei Änderungen an dieser Sicht wird ein alter
Cursor abgelehnt, statt Einträge zu überspringen oder Rechte zu umgehen.

## Sicherheit und Einbindung

Die Reihenfolge ist ausdrücklich: Authentifizierung und Rate-Limits,
MCP-Middleware, normaler Brace-Router. Die Middleware verarbeitet nur ihren
exakten Pfad; `/mcp-other` oder andere API-Routen werden unverändert delegiert.
Sie startet keine Sitzung und interpretiert einen Session-Identifier nicht als
Zugangsberechtigung.

Bearer-Tokens gehören in `Authorization`, nicht in Query-Parameter. Prüfung,
OAuth-Discovery/-Issuer, erforderliche Scopes, TLS, Rate-Limits und passende
Proxy-/Request-Timeouts stellt die Anwendung beziehungsweise Infrastruktur.
Dieses Modul ist kein OAuth-Server. Browser-Zugriffe benötigen zusätzlich die
passende CORS-Konfiguration vor MCP. Vorhandene `Origin`-Header werden nur
gegen die expliziten `allowedOrigins` geprüft; standardmäßig ist keiner
erlaubt. Fehlende Origin-Header bei Server-Clients sind zulässig.

`McpServer` nimmt optional `authorize(kind, identifier, RequestContext): bool`
an. Dieselbe Prüfung gilt vor Listen und Aufrufen. Der Request-Kontext enthält
den PSR-7-Request mit den durch vorgeschaltete Auth-Middleware verifizierten
Attributen. Ein als `RequestContext` typisierter Callback-Parameter wird
injiziert und taucht nicht im veröffentlichten Input-Schema auf. Client-Info,
MCP-Metadaten und Tool-Annotations sind keine verifizierten Identitäten oder
Berechtigungen. Ressourcen werden zusätzlich anhand der konkreten URI geprüft.

**Gemeinsame Controller sind keine gemeinsame HTTP-Middleware-Kette:**
`#[BraceRoute]` und `#[McpTool]` können an derselben Methode stehen, aber der
MCP-Aufruf durchläuft nicht die Middleware der HTTP-Route. Gemeinsame Rechte
gehören vor beide Zugänge oder in den fachlichen Dienst. Bestehende
HTTP-Parameterbindungen bleiben ebenfalls Aufgabe des HTTP-Adapters; beliebige
PSR-7-Request-Parameter werden nicht automatisch zu MCP-Argumenten.

## Protokoll und Transportumfang

Implementiert sind MCP `2026-07-28` sowie die Legacy-Profile `2025-11-25` und
`2025-06-18`. Die moderne Revision arbeitet mit Request-Metadaten und
`server/discover`; ältere Clients verwenden `initialize` und
`notifications/initialized`. Legacy-HTTP läuft sessionlos: Es wird keine
`Mcp-Session-Id` erzeugt, und Folge-Requests liefern die ausgehandelte Version
im `MCP-Protocol-Version`-Header. Der In-process-Kern verwendet ohne moderne
Metadaten und ohne HTTP-Request das dokumentierte Legacy-Profil `2025-11-25`.

Der HTTP-Transport ist Streamable HTTP mit JSON-Antworten, nicht das alte
HTTP+SSE-Protokoll. POST erwartet `Content-Type: application/json` und einen
Accept-Header mit `application/json, text/event-stream`. GET und DELETE liefern
405; angenommene Notifications liefern 202 ohne Body. Ein normaler Request darf
nicht als Notification missbraucht werden, um einen Callback auszuführen.
JSON-RPC-Batches, Client-Antwortnachrichten und null-/bool-/Float-IDs werden
abgelehnt. Der Request-Body ist standardmäßig auf 1 MiB begrenzt.

Moderne Requests benötigen `_meta` mit
`io.modelcontextprotocol/protocolVersion` und
`io.modelcontextprotocol/clientCapabilities`. Dazu müssen
`MCP-Protocol-Version`, `Mcp-Method` und bei Tool-/Prompt-/Resource-Aufrufen
`Mcp-Name` zum Body passen. Base64-kodierte Namens-Header werden vor dem
Vergleich dekodiert. Moderne Ergebnisse enthalten `resultType: complete` und
Server-Metadaten. Unbekannte Versionen werden mit der unterstützten Versionsliste
beantwortet; unbekannte moderne RPC-Methoden liefern HTTP 404 plus JSON-RPC-Fehler.

SSE-Streaming, Subscriptions, Sampling, Elicitation, Roots, MRTR und
Change-Notifications sind nicht Bestandteil dieser ersten Implementierung.
Entsprechende Capabilities werden nicht angekündigt. Das ist eine bewusste
Transport-/Funktionsgrenze des Moduls, keine grundsätzliche Apache-Einschränkung.
Registry und Protokollkern bleiben von der Middleware getrennt.

Offizielle Referenzen:
[MCP-Versionierung](https://modelcontextprotocol.io/specification/2026-07-28/basic/versioning),
[Streamable HTTP](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http),
[Tools](https://modelcontextprotocol.io/specification/2026-07-28/server/tools),
[Prompts](https://modelcontextprotocol.io/specification/2026-07-28/server/prompts),
[Resources](https://modelcontextprotocol.io/specification/2026-07-28/server/resources).

## Entwicklung und Prüfungen

```sh
composer validate --strict
composer lint
composer test
composer examples
```

PHPUnit prüft Registry/Reflection, DTO-/Enum-Hydration, Input-/Output-Schemas,
Prompts, Resources, Templates, Completion, Pagination, Rechte, Fehlerfälle und
PSR-15-/Brace-Integration. Der Workflow läuft mit PHP 8.3 und 8.4. Separate
Black-box-Tests starten den echten HTTP-Demo-Endpoint und sprechen ihn mit dem
offiziellen Python-MCP-SDK in Version 1 und 2 an. Sie testen also nicht nur die
eigenen JSON-Erwartungen gegen dieselbe eigene Implementierung.

Diese Prüfungen ersetzen keine Live-Abnahme mit jedem Host. Insbesondere werden
keine OpenAI-Zugangsdaten benutzt und keine ChatGPT-/Responses-API-Aufrufe
behauptet. Welche Tools, Prompts und Resources ein konkreter Host verwendet,
hängt zusätzlich von dessen unterstützten Features ab.
