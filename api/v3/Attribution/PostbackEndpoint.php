<?php

declare(strict_types=1);

namespace Api\V3\Attribution;

use Api\V3\Bootstrap;
use Api\V3\Support\ServerStateStore;

/**
 * The HTTP plumbing every well-known postback endpoint shares: the GET
 * probe, the method check, database bring-up, the peer-keyed soft rate
 * limit, the bounded body read, and the receiver's response. Each
 * /.well-known/<vendor>/... index.php is a few lines that pick a protocol
 * and call serve(); the trust-boundary decisions are made here once.
 */
final class PostbackEndpoint
{
    /** Requests per window per peer, across every protocol's endpoint. */
    public const RATE_LIMIT_PER_WINDOW = 600;
    public const RATE_LIMIT_WINDOW_SECONDS = 60;

    public static function serve(PostbackProtocol $protocol): never
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if ($method === 'GET' || $method === 'HEAD') {
            // Setup probe: lets an operator confirm the endpoint is reachable
            // at the exact URL the platform will use, before pointing an app
            // at it.
            Bootstrap::jsonResponse([
                'data' => ['status' => 'ready', 'protocol' => $protocol->name()] + $protocol->describe(),
            ]);
            exit;
        }

        if ($method !== 'POST') {
            header('Allow: GET, POST');
            Bootstrap::errorResponse('Method not allowed', 405);
            exit;
        }

        try {
            Bootstrap::init();
            $db = Bootstrap::db();
        } catch (\Throwable) {
            // Non-200: the device retries later, so a DB outage loses nothing.
            Bootstrap::errorResponse('Service unavailable', 503);
            exit;
        }

        // Soft rate limit keyed on the validated TCP peer (never client
        // headers — see ServerStateStore::softIpRateLimit). One bucket for
        // every protocol's endpoint: the limit is about the peer, not the
        // protocol. Behind a TLS-terminating proxy the peer is the proxy, so
        // the ceiling is an aggregate one; devices each send a handful of
        // postbacks over an install's lifetime.
        try {
            $retryAfter = (new ServerStateStore())->softIpRateLimit(
                'attribution-receiver',
                self::RATE_LIMIT_PER_WINDOW,
                self::RATE_LIMIT_WINDOW_SECONDS
            );
        } catch (\Throwable $e) {
            error_log('p202 attribution: rate limiter unavailable, accepting postback: ' . $e->getMessage());
            $retryAfter = null;
        }
        if ($retryAfter !== null) {
            header('Retry-After: ' . $retryAfter);
            Bootstrap::errorResponse('Rate limit exceeded', 429, ['retry_after_seconds' => $retryAfter]);
            exit;
        }

        $rawBody = file_get_contents('php://input', false, null, 0, PostbackReceiver::MAX_BODY_BYTES + 1);
        if ($rawBody === false) {
            Bootstrap::errorResponse('Failed to read request body', 500);
            exit;
        }

        try {
            // The stored remote_ip is display/forensic data, so the validated
            // XFF-aware helper is right for it; the rate-limit key above is
            // not derived from it.
            $receiver = new PostbackReceiver($db, $protocol);
            $result = $receiver->receive($rawBody, \AUTH::client_ip());
        } catch (\Throwable $e) {
            error_log('p202 attribution: postback processing failed: ' . $e->getMessage());
            Bootstrap::errorResponse('Internal server error', 500);
            exit;
        }

        Bootstrap::jsonResponse($result['body'], $result['status']);
        exit;
    }
}
