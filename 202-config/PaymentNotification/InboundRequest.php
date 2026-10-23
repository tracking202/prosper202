<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

use Prosper202\Http\VisitorIp;

/**
 * A provider's request to a notification endpoint, as the adapter reads it.
 */
final class InboundRequest
{
    /**
     * @param array<array-key, mixed> $fields the form body as PHP parsed it ($_POST)
     * @param string $senderAddress the address the conversion row keeps: the
     *        provider's server, never a visitor's (VisitorIp)
     * @param string $peerAddress REMOTE_ADDR, which the request cannot choose: the one the log names
     * @param string $body the raw body, for a provider that does not post a form (ClickBank's JSON)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $contentType,
        public readonly array $fields,
        public readonly string $senderAddress = '',
        public readonly string $peerAddress = '',
        public readonly string $userAgent = '',
        public readonly string $body = '',
    ) {
    }

    /**
     * @param array<string, mixed> $server $_SERVER
     * @param array<array-key, mixed> $post $_POST
     * @param string $body php://input, for a provider that needs it
     */
    public static function fromGlobals(array $server, array $post, string $body = ''): self
    {
        $text = static fn (mixed $v): string => is_string($v) ? $v : '';

        return new self(
            strtoupper($text($server['REQUEST_METHOD'] ?? '')),
            $text($server['CONTENT_TYPE'] ?? ($server['HTTP_CONTENT_TYPE'] ?? '')),
            $post,
            VisitorIp::fromServer($server),
            $text($server['REMOTE_ADDR'] ?? ''),
            $text($server['HTTP_USER_AGENT'] ?? ''),
            $body,
        );
    }
}
