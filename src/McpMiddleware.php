<?php

declare(strict_types=1);

namespace Brace\Mcp;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** PSR-15 Streamable HTTP mit JSON-Antworten, ohne SSE oder PHP-Sitzung. */
final class McpMiddleware implements MiddlewareInterface
{
    /**
     * Fängt exakt path vor dem normalen Router ab. Andere Pfade bleiben unverändert.
     * Authentifizierung gehört VOR diese Middleware; der Server prüft optional Fachrechte.
     * Origin fehlt bei Server-Clients üblicherweise. Vorhandene Origins werden nur bei
     * explizitem Allowlisting akzeptiert; Host-/Forwarded-Header werden nicht vertraut.
     *
     * @param list<string> $allowedOrigins Erlaubte Origin-Werte, ohne Wildcards.
     * @param int $maxBodyBytes Maximale dekodierte HTTP-Body-Größe in Bytes.
     * @throws InvalidArgumentException Bei ungültigem Endpoint oder Body-Limit.
     * @example $app->setPipe([$auth, new McpMiddleware($server, path: '/mcp'), $router]);
     * @see McpServer
     */
    public function __construct(
        private readonly McpServer $server,
        private readonly string $path = '/mcp',
        private readonly array $allowedOrigins = [],
        private readonly int $maxBodyBytes = 1048576,
        private readonly ResponseFactoryInterface $responseFactory = new Psr17Factory(),
        private readonly StreamFactoryInterface $streamFactory = new Psr17Factory(),
    ) {
        if (!str_starts_with($path, '/') || str_contains($path, '?') || str_contains($path, '#') || $maxBodyBytes < 1) {
            throw new InvalidArgumentException('Use an absolute endpoint path and a positive body limit.');
        }
        foreach ($allowedOrigins as $origin) {
            if (!is_string($origin) || !preg_match('~^https?://[^/\s]+$~D', $origin)) {
                throw new InvalidArgumentException('Allowed origins must be explicit HTTP(S) origins.');
            }
        }
    }

    /**
     * Bedient einen MCP-POST oder delegiert einen anderen Pfad an den nächsten Handler.
     * Liefert 405 für GET/DELETE, 202 ohne Body für Notifications und JSON für Requests.
     * Kein echo, flush, session_start oder globales Output-Buffering wird ausgeführt.
     *
     * @example $response = $middleware->process($request, $next);
     * @see \Psr\Http\Server\MiddlewareInterface::process()
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== $this->path) {
            return $handler->handle($request);
        }
        if ($request->hasHeader('Origin')
            && (count($request->getHeader('Origin')) !== 1 || !in_array($request->getHeaderLine('Origin'), $this->allowedOrigins, true))
        ) {
            return $this->responseFactory->createResponse(403)->withHeader('Cache-Control', 'no-store');
        }
        if ($request->getMethod() !== 'POST') {
            return $this->responseFactory->createResponse(405)->withHeader('Allow', 'POST');
        }
        $contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));
        if ($contentType !== 'application/json' || ($request->hasHeader('Content-Encoding') && $request->getHeaderLine('Content-Encoding') !== 'identity')) {
            return $this->responseFactory->createResponse(415);
        }
        $accepted = [];
        foreach (explode(',', strtolower($request->getHeaderLine('Accept'))) as $range) {
            $parts = array_map('trim', explode(';', $range));
            $quality = 1.0;
            foreach (array_slice($parts, 1) as $parameter) {
                if (str_starts_with($parameter, 'q=')) {
                    $quality = (float) substr($parameter, 2);
                }
            }
            if ($quality > 0) {
                $accepted[$parts[0]] = true;
            }
        }
        if (!isset($accepted['application/json'], $accepted['text/event-stream'])) {
            return $this->responseFactory->createResponse(406);
        }

        // Auch nicht seekbare Bodies begrenzt lesen; keine großen Strings vorab erzeugen.
        $stream = $request->getBody();
        if ($stream->getSize() !== null && $stream->getSize() > $this->maxBodyBytes) {
            return $this->responseFactory->createResponse(413);
        }
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $payload = '';
        while (!$stream->eof() && strlen($payload) <= $this->maxBodyBytes) {
            $chunk = $stream->read(min(8192, $this->maxBodyBytes + 1 - strlen($payload)));
            if ($chunk === '') {
                break;
            }
            $payload .= $chunk;
        }
        if (strlen($payload) > $this->maxBodyBytes) {
            return $this->responseFactory->createResponse(413);
        }
        $reply = $this->server->handle($payload, $request);
        $response = $this->responseFactory->createResponse($reply->httpStatus)->withHeader('Cache-Control', 'no-store');
        if ($reply->data === null) {
            return $response;
        }
        return $response->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($reply->toJson()));
    }
}
