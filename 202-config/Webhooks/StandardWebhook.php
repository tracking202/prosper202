<?php

declare(strict_types=1);

namespace Prosper202\Webhooks;

/**
 * The Standard Webhooks signature (https://www.standardwebhooks.com/),
 * which every webhook Prosper202 sends carries beside its older
 * X-P202-Signature, so a receiver can verify one with an off-the-shelf
 * library (standardwebhooks, svix) instead of code of its own.
 *
 *   webhook-id         the message's id, the same on every retry
 *   webhook-timestamp  the attempt's time, in Unix seconds
 *   webhook-signature  "v1,<base64 HMAC-SHA256 of id.timestamp.body>", one
 *                      per secret the endpoint holds, space-separated
 *
 * The key is the secret's bytes after base64-decoding it when it is a
 * whsec_ secret (what the libraries do with one), and the secret's own bytes
 * otherwise: a secret minted before 1.9.81 is hex or the operator's text,
 * and the libraries take it as whsec_ followed by its base64 (keyFor()'s
 * other half, libraryForm()). The rule is the prefix, never whether the
 * text happens to decode as base64: a hex secret does, to other bytes.
 *
 * Pure: no clock, no randomness but newSecret() and newMessageId().
 */
final class StandardWebhook
{
    public const PREFIX = 'whsec_';

    /** Bytes of key a new secret holds, as the libraries mint them. */
    public const SECRET_BYTES = 32;

    /** How far a timestamp may be from the receiver's clock (the libraries' default). */
    public const TOLERANCE_SECONDS = 300;

    /**
     * The release in which X-P202-Signature (and an export's
     * X-P202-Timestamp) is last sent: two releases beside the Standard
     * Webhooks headers, from 1.9.81. 1.9.83 sends only these.
     */
    public const LEGACY_SIGNATURE_LAST_RELEASE = '1.9.82';

    private function __construct()
    {
    }

    /** A new endpoint secret: whsec_ and the base64 of 32 random bytes. */
    public static function newSecret(): string
    {
        return self::PREFIX . base64_encode(random_bytes(self::SECRET_BYTES));
    }

    /** A new message id: msg_ and 32 hex digits (128 random bits). */
    public static function newMessageId(): string
    {
        return 'msg_' . bin2hex(random_bytes(16));
    }

    /**
     * The HMAC key of a secret. A whsec_ secret whose remainder is not
     * base64 is refused: signing it as text would produce a signature no
     * library given that secret can match, with nothing said.
     *
     * @throws \InvalidArgumentException naming why the secret has no key
     */
    public static function keyFor(string $secret): string
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('An empty secret signs nothing');
        }
        if (!str_starts_with($secret, self::PREFIX)) {
            return $secret;
        }
        $key = base64_decode(substr($secret, strlen(self::PREFIX)), true);
        if ($key === false || $key === '') {
            throw new \InvalidArgumentException('A whsec_ secret is whsec_ followed by base64; this one is not');
        }

        return $key;
    }

    /** Whether keyFor() takes the secret. */
    public static function isUsableSecret(string $secret): bool
    {
        try {
            self::keyFor($secret);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * The secret as a Standard Webhooks library takes it: itself when it is
     * a whsec_ secret, whsec_ and the base64 of its bytes otherwise, so the
     * library's key is the bytes this server signs with.
     */
    public static function libraryForm(string $secret): string
    {
        return str_starts_with($secret, self::PREFIX) ? $secret : self::PREFIX . base64_encode($secret);
    }

    /** One v1 signature: "v1," and the base64 HMAC-SHA256 of "id.timestamp.body". */
    public static function sign(string $secret, string $messageId, int $timestamp, string $body): string
    {
        return 'v1,' . base64_encode(hash_hmac(
            'sha256',
            $messageId . '.' . $timestamp . '.' . $body,
            self::keyFor($secret),
            true
        ));
    }

    /**
     * The webhook-signature header's value: a v1 signature per secret, in
     * the order given (the current secret first), duplicates once.
     *
     * @param non-empty-list<string> $secrets
     */
    public static function signatures(array $secrets, string $messageId, int $timestamp, string $body): string
    {
        $signatures = [];
        foreach ($secrets as $secret) {
            $signatures[] = self::sign($secret, $messageId, $timestamp, $body);
        }

        return implode(' ', array_values(array_unique($signatures)));
    }

    /**
     * The three Standard Webhooks request headers, as "Name: value" lines.
     *
     * @param non-empty-list<string> $secrets
     * @return list<string>
     */
    public static function headers(array $secrets, string $messageId, int $timestamp, string $body): array
    {
        if (preg_match('/^[\x21-\x7E]{1,255}$/D', $messageId) !== 1 || str_contains($messageId, '.')) {
            // The id is part of what is signed, split on "."; and a header value.
            throw new \InvalidArgumentException('A message id is 1 to 255 printable characters with no "." or space');
        }

        return [
            'webhook-id: ' . $messageId,
            'webhook-timestamp: ' . $timestamp,
            'webhook-signature: ' . self::signatures($secrets, $messageId, $timestamp, $body),
        ];
    }

    /**
     * Whether a request verifies under the secret, as a receiver checks one:
     * the timestamp within TOLERANCE_SECONDS of $now, and one of the header's
     * v1 signatures equal to the one the secret makes. For this server's
     * tests and documentation; a receiver uses a library.
     */
    public static function verify(
        string $secret,
        string $messageId,
        string $timestamp,
        string $signatureHeader,
        string $body,
        int $now
    ): bool {
        if (preg_match('/^[0-9]{1,12}$/D', $timestamp) !== 1 || abs($now - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }
        $expected = self::sign($secret, $messageId, (int) $timestamp, $body);
        foreach (explode(' ', $signatureHeader) as $candidate) {
            if (str_starts_with($candidate, 'v1,') && hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An event's versioned type, as the envelope's `type` names it:
     * "revenue.recorded" is "revenue.recorded.v1". A change to what an
     * event's data holds that a receiver could misread takes a new version.
     */
    public static function versionedType(string $event, int $version = 1): string
    {
        return $event . '.v' . $version;
    }
}
