<?php

declare(strict_types=1);

namespace Api\V3\Apps\Apple;

use Api\V3\Apps\PublicIntake;
use Api\V3\Bootstrap;

/**
 * The Apple /.well-known/ postback endpoints: PublicIntake's steps in the
 * order a postback needs them, and the probe. Each
 * /.well-known/<vendor>/.../index.php is the shared prelude plus one
 * serve() line naming its protocol.
 */
final class PostbackIntake
{
    /** Requests per window per peer, across every protocol's endpoint. */
    public const RATE_LIMIT_PER_WINDOW = 600;
    public const RATE_LIMIT_WINDOW_SECONDS = 60;

    /**
     * The rate-limit bucket every protocol's endpoint shares: the limit is
     * about the peer, not the protocol. Devices each send a handful of
     * postbacks over an install's lifetime.
     */
    public const RATE_LIMIT_BUCKET = 'app-postbacks';

    /** The methods the endpoints answer; HEAD rides along with GET. */
    public const METHODS = ['GET', 'POST'];

    public static function serve(PostbackProtocol $protocol): never
    {
        $method = PublicIntake::preflight(PostbackReceiver::MAX_BODY_BYTES, self::METHODS);

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

        $db = PublicIntake::database();
        PublicIntake::rateLimit(self::RATE_LIMIT_BUCKET, self::RATE_LIMIT_PER_WINDOW, self::RATE_LIMIT_WINDOW_SECONDS);
        $rawBody = PublicIntake::readBody(PostbackReceiver::MAX_BODY_BYTES);

        try {
            // The stored remote_ip is display/forensic data, so the validated
            // XFF-aware helper is right for it; the rate-limit key above is
            // not derived from it.
            $receiver = new PostbackReceiver($db, $protocol);
            $result = $receiver->receive($rawBody, \AUTH::client_ip());
        } catch (\Throwable $e) {
            error_log('p202 apps: postback processing failed: ' . $e->getMessage());
            Bootstrap::errorResponse('Internal server error', 500);
            exit;
        }

        Bootstrap::jsonResponse($result['body'], $result['status']);
        exit;
    }
}
