<?php

declare(strict_types=1);

namespace Prosper202\Conversion\PostbackAuth;

use Prosper202\Database\Connection;

/**
 * Whether a postback or pixel request may change an account's conversions:
 * pb.php, gpb.php, px.php, gpx.php and upx.php ask it, for the account whose
 * campaign (pb, px) or click (gpb, gpx, upx) the request names, before
 * anything is written.
 *
 * - A request presenting a token or a signature is held to it whatever the
 *   setting: a wrong one is refused, never read as absent.
 * - A request presenting nothing passes only when the account does not
 *   require authentication (every account already there at the 1.9.80
 *   upgrade, until its owner turns it on).
 * - A row that cannot be read throws: the caller answers 500, never a pass.
 *
 * A PurLink SubID's MAC is not authentication here: it says which click and
 * offer the SubID names, not who sent the postback.
 */
final class PostbackAuthGate
{
    /** The schema version that brings 202_postback_auth. */
    public const SINCE = '1.9.80';

    public function __construct(private readonly PostbackAuthStore $store)
    {
    }

    public static function forConnection(Connection $conn): self
    {
        return new self(new PostbackAuthStore($conn));
    }

    public function check(int $userId, string $endpoint, string $queryString, int $now): ?PostbackAuthRefusal
    {
        if ($userId <= 0) {
            return new PostbackAuthRefusal('no_account', 'The request names no account this install can authenticate it for.');
        }
        $presented = PresentedCredentials::fromQueryString($queryString);
        if ($presented->problem !== null) {
            return new PostbackAuthRefusal('credentials_malformed', $presented->problem);
        }

        return self::decide($this->store->settings($userId), $presented, $endpoint, $now);
    }

    /** check()'s decision for the account's settings as read. */
    public static function decide(PostbackAuthSettings $settings, PresentedCredentials $presented, string $endpoint, int $now): ?PostbackAuthRefusal
    {
        if ($presented->problem !== null) {
            return new PostbackAuthRefusal('credentials_malformed', $presented->problem);
        }
        if ($presented->presentsNothing()) {
            return $settings->required
                ? new PostbackAuthRefusal('token_required', 'This account accepts only authenticated postbacks and pixels:'
                    . ' add p202_token (the URLs in Setup › Postback / Pixel carry it), or sign the request with p202_ts and p202_sig.')
                : null;
        }

        $valid = $settings->validTokens($now);
        if ($valid === []) {
            return new PostbackAuthRefusal('token_wrong', 'The request carries a postback token or signature, and this account'
                . ' has no postback token yet: copy the URL from Setup › Postback / Pixel, which makes one.');
        }
        if ($presented->token !== null && !self::oneOf($presented->token, $valid)) {
            return new PostbackAuthRefusal('token_wrong', 'p202_token is not this account\'s postback token; copy the URL again'
                . ' from Setup › Postback / Pixel (the token may have been rotated).');
        }
        if ($presented->isSigned()) {
            $ts = PostbackSignature::timestamp((string) $presented->timestamp);
            if ($ts === null) {
                return new PostbackAuthRefusal('signature_malformed', 'p202_ts must be Unix seconds, digits only.');
            }
            if (abs($now - $ts) > PostbackSignature::WINDOW) {
                return new PostbackAuthRefusal('signature_expired', 'p202_ts is more than ' . PostbackSignature::WINDOW
                    . ' seconds from this server\'s clock (' . $now . '); sign each request when it is sent.');
            }
            $signed = false;
            foreach ($valid as $token) {
                $signed = PostbackSignature::matches($endpoint, $presented->pairs, (string) $presented->signature, $token) || $signed;
            }
            if (!$signed) {
                return new PostbackAuthRefusal('signature_wrong', 'p202_sig does not sign this request for ' . $endpoint
                    . '.php with this account\'s postback token (see "Signed postbacks" in the postback documentation).');
            }
        }

        return null;
    }

    /** @param list<string> $valid */
    private static function oneOf(string $presented, array $valid): bool
    {
        $match = false;
        foreach ($valid as $token) {
            $match = hash_equals($token, $presented) || $match;
        }

        return $match;
    }
}
