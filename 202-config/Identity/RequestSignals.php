<?php

declare(strict_types=1);

namespace Prosper202\Identity;

/**
 * The identity signals a tracking request carries, read strictly.
 *
 * Browser ids are 128-bit random values the tracker minted itself, so a
 * value that is not exactly 32 lowercase hex characters did not come from
 * us and is ignored (not "fixed"). A customer id is a signal only when its
 * cust_sig verifies against the account's linking key; an unsigned cust
 * keeps its LTV role and links nothing.
 */
final class RequestSignals
{
    public const VISITOR_COOKIE = 'p202vid';
    public const LANDING_PAGE_PARAM = 'p202lpid';
    public const CONSENT_PARAM = 'p202_consent';
    /** Browsers cap cookie lifetime at 400 days. */
    public const COOKIE_LIFETIME = 400 * 86400;

    private function __construct()
    {
    }

    /**
     * Whether this request may carry browser signals at all. One switch
     * suppresses every one of them: `p202_consent=0` on the URL, or a
     * campaign that has identity capture turned off. The journey is then one
     * touch.
     *
     * @param array<string, mixed> $get
     */
    public static function consentGiven(array $get, bool $campaignAllows = true): bool
    {
        if (!$campaignAllows) {
            return false;
        }
        $value = $get[self::CONSENT_PARAM] ?? null;

        return !(is_string($value) && trim($value) === '0');
    }

    /**
     * A campaign's identity_signals setting as a tracker query returns it.
     * No campaign (a tracker or rotator rule that points at a bare URL)
     * leaves capture on; a campaign turns it off with 0. Anything else
     * stored there is not a setting this code wrote, and is read as off:
     * an unreadable privacy switch must not resolve to capturing
     * (CLAUDE.md #11).
     */
    public static function campaignAllows(mixed $stored): bool
    {
        if ($stored === null) {
            return true;
        }
        $value = is_int($stored) ? (string) $stored : $stored;

        return $value === '1';
    }

    /** @param array<string, mixed> $cookies */
    public static function visitorCookie(array $cookies): ?string
    {
        return self::browserId($cookies[self::VISITOR_COOKIE] ?? null);
    }

    /** @param array<string, mixed> $get */
    public static function landingPageId(array $get): ?string
    {
        return self::browserId($get[self::LANDING_PAGE_PARAM] ?? null);
    }

    public static function mintVisitorId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Set the tracking-domain visitor cookie: HttpOnly (no page script needs
     * it), SameSite=Lax (sent on the top-level navigations redirects are),
     * Secure when the request came over HTTPS.
     */
    public static function setVisitorCookie(string $visitorId, bool $secure): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::VISITOR_COOKIE, $visitorId, [
            'expires' => time() + self::COOKIE_LIFETIME,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * The customer signal, when the request carries a customer id AND the
     * operator's signature of it.
     *
     * @param array<string, mixed> $get
     */
    public static function signedCustomer(array $get, string $linkKeyHex): ?IdentitySignal
    {
        $id = null;
        foreach (['cust', 'customer_ref'] as $key) {
            if (isset($get[$key]) && is_string($get[$key]) && trim($get[$key]) !== '') {
                $id = $get[$key];
                break;
            }
        }
        $sig = $get['cust_sig'] ?? null;
        if ($id === null || !is_string($sig)) {
            return null;
        }
        $type = null;
        foreach (['cust_type', 'customer_ref_type'] as $key) {
            if (isset($get[$key]) && is_string($get[$key])) {
                $type = $get[$key];
                break;
            }
        }
        $canonical = CustomerId::canonical($id, $type);

        return $canonical !== null && CustomerId::verify($linkKeyHex, $canonical, $sig)
            ? new IdentitySignal(SignalType::CUSTOMER, $canonical)
            : null;
    }

    public static function requestIsHttps(array $server): bool
    {
        $https = $server['HTTPS'] ?? '';

        return (is_string($https) && $https !== '' && strtolower($https) !== 'off')
            || (($server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    private static function browserId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{32}$/D', $value) === 1 ? $value : null;
    }
}
