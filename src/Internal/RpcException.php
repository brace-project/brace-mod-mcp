<?php

declare(strict_types=1);

namespace Brace\Mcp\Internal;

use RuntimeException;

/** @internal Protokollfehler mit unabhängigem HTTP-Status. */
final class RpcException extends RuntimeException
{
    public function __construct(
        int $code,
        string $message,
        public readonly int $httpStatus = 200,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message, $code);
    }
}
