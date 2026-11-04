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
 *   mode: a wrong one is refused, never read as absent. The token is
 *   checked against the URL token, a signature against the signing secret.
 * - Mode off (every account already there at the 1.9.80 upgrade, until its
 *   owner changes it): a request presenting nothing passes.
 * - Mode token: the token or a signature is needed.
 * - Mode signed: a signature is needed; the token alone is refused. A pixel
 *   is fetched by a browser and cannot sign, so every pixel is refused.
 * - A row that cannot be read throws: the caller answers 500, never a pass.
 *   Only what the request presents is opened: a request presenting nothing
 *   is decided by the mode alone.
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

        if ($presented->presentsNothing()) {
            // The mode decides alone: nothing sealed is opened, so mode off
            // keeps working while a credential cannot be read.
            return self::decide(PostbackAuthSettings::modeOnly($this->store->mode($userId)), $presented, $endpoint, $now);
        }
        $needed = [];
        if ($presented->token !== null) {
            $needed[] = PostbackCredential::TOKEN;
        }
        if ($presented->isSigned()) {
            $needed[] = PostbackCredential::SIGNING_SECRET;
        }

        return self::decide($this->store->settings($userId, $needed), $presented, $endpoint, $now);
    }

    /** check()'s decision for the account's settings as read. */
    public static function decide(PostbackAuthSettings $settings, PresentedCredentials $presented, string $endpoint, int $now): ?PostbackAuthRefusal
    {
        if ($presented->problem !== null) {
            return new PostbackAuthRefusal('credentials_malformed', $presented->problem);
        }
        $pixel = in_array($endpoint, ['px', 'gpx', 'upx'], true);
        $signingOnly = 'This account accepts only signed postbacks: p202_ts and p202_sig, keyed with its signing secret'
            . ' (Account › API Integrations › Postback authentication), not p202_token alone.'
            . ($pixel ? ' A pixel is loaded by the visitor\'s browser and cannot sign, so ' . $endpoint . '.php is refused'
                . ' while the account accepts only signed postbacks.' : '');
        if ($presented->presentsNothing()) {
            return match ($settings->mode) {
                PostbackAuthMode::OFF => null,
                PostbackAuthMode::TOKEN => new PostbackAuthRefusal('token_required', 'This account accepts only authenticated'
                    . ' postbacks and pixels: add p202_token (the URLs in Setup › Postback / Pixel carry it), or sign the'
                    . ' request with p202_ts and p202_sig.'),
                PostbackAuthMode::SIGNED => new PostbackAuthRefusal('signature_required', $signingOnly),
            };
        }

        if ($presented->token !== null) {
            $tokens = $settings->validTokens($now);
            if ($tokens === []) {
                return new PostbackAuthRefusal('token_wrong', 'The request carries p202_token, and this account has no'
                    . ' postback token yet: copy the URL from Setup › Postback / Pixel, which makes one.');
            }
            if (!self::oneOf($presented->token, $tokens)) {
                return new PostbackAuthRefusal('token_wrong', 'p202_token is not this account\'s postback token; copy the URL'
                    . ' again from Setup › Postback / Pixel (the token may have been rotated).');
            }
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
            foreach ($settings->validSecrets($now) as $secret) {
                $signed = PostbackSignature::matches($endpoint, $presented->pairs, (string) $presented->signature, $secret) || $signed;
            }
            if (!$signed) {
                return new PostbackAuthRefusal('signature_wrong', 'p202_sig does not sign this request for ' . $endpoint
                    . '.php with this account\'s signing secret (the secret, not the URL token; see "Signed postbacks" in'
                    . ' the postback documentation).');
            }
        } elseif ($settings->mode === PostbackAuthMode::SIGNED) {
            return new PostbackAuthRefusal('signature_required', $signingOnly);
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
