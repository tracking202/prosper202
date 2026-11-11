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
     * @param array<string, string> $headers the request's headers, by lowercase name (x-shopify-topic)
     * @param array{notification: ?string, body: string}|null $heldKeys for a held notification recorded later
     *        (HeldReplay): what its own request keyed a refund by, which the rebuilt request cannot repeat
     */
    public function __construct(
        public readonly string $method,
        public readonly string $contentType,
        public readonly array $fields,
        public readonly string $senderAddress = '',
        public readonly string $peerAddress = '',
        public readonly string $userAgent = '',
        public readonly string $body = '',
        public readonly array $headers = [],
        public readonly ?array $heldKeys = null,
    ) {
    }

    /** A header's value, or null when the request has none. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
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
            self::headersOf($server),
        );
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function headersOf(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (is_string($value) && str_starts_with((string) $key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
            }
        }

        return $headers;
    }
}
