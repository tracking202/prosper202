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
 *
 * The checks that need no database live in preflight() so the entry points'
 * shared prelude can run them before it loads 202-config.php, which opens
 * two MySQL connections at file scope. serve() calls preflight() itself, so
 * the checks hold for any caller that skips the prelude.
 */
final class PostbackEndpoint
{
    /** Requests per window per peer, across every protocol's endpoint. */
    public const RATE_LIMIT_PER_WINDOW = 600;
    public const RATE_LIMIT_WINDOW_SECONDS = 60;

    /**
     * Refuse what can be refused without a database: a method this endpoint
     * does not serve (405) and a body that declares itself over the cap
     * (413). Answers and exits, or returns the validated request method so
     * the caller knows whether configuration is needed at all — only POST
     * reaches the database, the probe is rendered from class constants.
     *
     * Nothing here reads state, so calling it twice is the same as calling
     * it once (the prelude does, then serve() does).
     *
     * @return string One of GET, HEAD or POST.
     */
    public static function preflight(): string
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'POST') {
            header('Allow: GET, POST');
            Bootstrap::errorResponse('Method not allowed', 405);
            exit;
        }

        // A body that announces itself as oversized is refused before it is
        // read and before anything connects. Advisory only, in the refusing
        // direction: a chunked request declares no length and the header can
        // lie, so what actually arrived is measured in PostbackReceiver,
        // which stays the authority for the 413.
        $declaredLength = $_SERVER['CONTENT_LENGTH'] ?? null;
        if (
            $method === 'POST'
            && is_string($declaredLength)
            && ctype_digit($declaredLength)
            && (int)$declaredLength > PostbackReceiver::MAX_BODY_BYTES
        ) {
            Bootstrap::errorResponse('Request body too large', 413);
            exit;
        }

        return $method;
    }

    public static function serve(PostbackProtocol $protocol): never
    {
        $method = self::preflight();

        if ($method === 'GET' || $method === 'HEAD') {
            // Setup probe: lets an operator confirm the endpoint is reachable
            // at the exact URL the platform will use, before pointing an app
            // at it. Answered from class constants alone — it must stay that
            // way, because the prelude does not load configuration for it.
            Bootstrap::jsonResponse([
                'data' => ['status' => 'ready', 'protocol' => $protocol->name()] + $protocol->describe(),
            ]);
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
