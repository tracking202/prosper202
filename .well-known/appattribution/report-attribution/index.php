<?php

declare(strict_types=1);

/**
 * AdAttributionKit postback-copy endpoint.
 *
 * Devices POST developer copies of winning AdAttributionKit postbacks here
 * when an advertised iOS app names this Prosper202 install in its
 * Info.plist:
 *
 *   AttributionCopyEndpoint = https://your-domain.com
 *
 * (Apple appends /.well-known/appattribution/report-attribution/ itself;
 * re-engagement copies also need the Boolean key
 * EligibleForAdAttributionKitReengagementPostbackCopies.) Like its
 * SKAdNetwork sibling this is a physical directory index on purpose: no
 * rewrite rules, and the shipped Apache/nginx configs keep /.well-known/
 * servable while denying other dotfiles.
 *
 * The HTTP plumbing shared by every postback endpoint is written once, not
 * per endpoint: the headers, the unconfigured-install 503, the autoloader
 * and the database-free request-shape checks in ../../postback-prelude.php;
 * the probe, rate limit, body read and error envelopes in
 * Api\V3\Attribution\PostbackEndpoint; JWS decoding, validation and
 * signature verification in Api\V3\Attribution\AdAttributionKitProtocol;
 * storage in Api\V3\Attribution\PostbackReceiver. This file only picks the
 * protocol.
 */

define('P202_POSTBACK_ENTRY', __FILE__);
require dirname(__DIR__, 2) . '/postback-prelude.php';

\Api\V3\Attribution\PostbackEndpoint::serve(
    new \Api\V3\Attribution\AdAttributionKitProtocol(new \Api\V3\Attribution\JwsVerifier())
);
