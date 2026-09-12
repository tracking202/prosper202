<?php

declare(strict_types=1);

/**
 * SKAdNetwork install-validation postback endpoint.
 *
 * Devices POST signed SKAdNetwork postbacks here when an advertised iOS app
 * names this Prosper202 install in its Info.plist:
 *
 *   NSAdvertisingAttributionReportEndpoint = https://your-domain.com
 *
 * (Apple appends /.well-known/skadnetwork/report-attribution/ itself.) The
 * same URL works as an ad network's registered postback endpoint. This is a
 * physical directory index on purpose: it needs no rewrite rules, and the
 * shipped Apache/nginx configs already keep /.well-known/ servable while
 * denying other dotfiles.
 *
 * The HTTP plumbing shared by every postback endpoint is written once, not
 * per endpoint: the headers, the unconfigured-install 503, the autoloader
 * and the database-free request-shape checks in ../../postback-prelude.php;
 * the probe, rate limit, body read and error envelopes in
 * Api\V3\Attribution\PostbackEndpoint; parsing, validation and signature
 * verification in Api\V3\Attribution\SkadnetworkProtocol; storage in
 * Api\V3\Attribution\PostbackReceiver. This file only picks the protocol.
 */

define('P202_POSTBACK_ENTRY', __FILE__);
require dirname(__DIR__, 2) . '/postback-prelude.php';

\Api\V3\Attribution\PostbackEndpoint::serve(
    new \Api\V3\Attribution\SkadnetworkProtocol(new \Api\V3\Attribution\PostbackVerifier())
);
