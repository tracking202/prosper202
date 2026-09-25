<?php

declare(strict_types=1);

namespace Prosper202\Identity;

use Prosper202\Database\Connection;

/**
 * What one tracking request says about who is clicking, captured before the
 * redirect and linked into the identity graph after the click is stored.
 *
 * Capture (fromRequest) is pure apart from minting a browser id: it reads
 * the tracking domain's p202vid cookie (minting one when the browser has
 * none), the landing page's p202lpid, and the raw customer-id parameters,
 * whose signature can only be checked against the account's linking key and
 * so is checked at attach time. With consent withheld — `p202_consent=0`, or
 * a campaign whose identity capture is off — it captures nothing and sets no
 * cookie.
 *
 * Attach (attach / attachCustomer) runs in its own transaction, after the
 * click's transaction has committed. Identity is an enrichment: a failure to
 * link must never cost the click or the conversion it rides on, so it is
 * retried once on a lock error and otherwise logged with the click id, and
 * the click stays a one-touch journey.
 */
final class ClickIdentity
{
    private const CUSTOMER_KEYS = ['cust', 'customer_ref', 'cust_type', 'customer_ref_type', 'cust_sig'];
    private const ATTEMPTS = 2;

    /**
     * @param list<IdentitySignal> $signals
     * @param array<string, string> $customerParams
     */
    private function __construct(
        public readonly array $signals,
        public readonly array $customerParams,
        public readonly ?string $cookieValue,
    ) {
    }

    public static function none(): self
    {
        return new self([], [], null);
    }

    /**
     * @param array<string, mixed> $get
     * @param array<string, mixed> $cookies
     * @param bool $mayMint Whether a browser without the cookie gets one. A
     *        landing page's script request to a tracker on another site is
     *        a cross-site subresource: the browser neither sends a Lax
     *        cookie on it nor, usually, keeps one set from it, so minting
     *        there would add a fresh one-click "visitor" on every pageview.
     *        Those requests link by the page's own p202lpid instead.
     */
    public static function fromRequest(array $get, array $cookies, bool $campaignAllows, bool $mayMint = true): self
    {
        if (!RequestSignals::consentGiven($get, $campaignAllows)) {
            return self::none();
        }
        $vid = RequestSignals::visitorCookie($cookies) ?? ($mayMint ? RequestSignals::mintVisitorId() : null);
        $signals = $vid !== null ? [new IdentitySignal(SignalType::VISITOR_COOKIE, $vid)] : [];
        $lpid = RequestSignals::landingPageId($get);
        if ($lpid !== null) {
            $signals[] = new IdentitySignal(SignalType::LANDING_PAGE, $lpid);
        }

        return new self($signals, self::customerParams($get), $vid);
    }

    /**
     * Only the customer id a conversion request carries: a postback or pixel
     * has no browser of ours behind it, so it contributes no cookie.
     *
     * @param array<string, mixed> $get
     */
    public static function customerOnly(array $get): self
    {
        if (!RequestSignals::consentGiven($get)) {
            return self::none();
        }

        return new self([], self::customerParams($get), null);
    }

    /**
     * A customer id from a caller that has already proved it is the
     * operator — an authenticated API request — so it needs no signature.
     */
    public static function trustedCustomer(string $id, ?string $type = null): self
    {
        $canonical = CustomerId::canonical($id, $type);
        if ($canonical === null) {
            return self::none();
        }

        return new self([new IdentitySignal(SignalType::CUSTOMER, $canonical)], [], null);
    }

    public function isEmpty(): bool
    {
        return $this->signals === [] && !isset($this->customerParams['cust_sig']);
    }

    /**
     * Send (or refresh — the lifetime slides with each visit) the visitor
     * cookie. Must run before the response's headers are sent.
     *
     * @param array<string, mixed> $server
     */
    public function sendCookie(array $server): void
    {
        if ($this->cookieValue !== null) {
            RequestSignals::setVisitorCookie($this->cookieValue, RequestSignals::requestIsHttps($server));
        }
    }

    /**
     * Link a stored click. Returns its canonical visitor key, or null when
     * there was nothing to link or linking failed (logged).
     */
    public function attach(Connection $conn, int $userId, int $clickId, int $clickTime): ?int
    {
        if ($this->isEmpty() || $userId <= 0 || $clickId <= 0) {
            return null;
        }
        for ($attempt = 1; ; $attempt++) {
            try {
                return $conn->transaction(function () use ($conn, $userId, $clickId, $clickTime): ?int {
                    $keys = new IdentityKeys($conn);
                    $signals = $this->signals;
                    if (isset($this->customerParams['cust_sig'])) {
                        $customer = RequestSignals::signedCustomer($this->customerParams, $keys->forUser($userId)['link']);
                        if ($customer !== null) {
                            $signals[] = $customer;
                        }
                    }

                    return (new IdentityGraph($conn, $keys))->attachClick($userId, $clickId, $clickTime, $signals);
                });
            } catch (\Throwable $e) {
                if ($attempt < self::ATTEMPTS && Connection::isRetryableLockError($e)) {
                    continue;
                }
                error_log('identity: click ' . $clickId . ' was stored but not linked: ' . $e->getMessage());

                return null;
            }
        }
    }

    /**
     * Link a click that is converting now to the signed customer id the
     * conversion carries: the cross-device join (plan §6.2). The click's
     * owner, time and campaign setting come from the click itself.
     */
    public function attachToStoredClick(Connection $conn, int $clickId): ?int
    {
        if ($this->isEmpty() || $clickId <= 0) {
            return null;
        }
        try {
            $stmt = $conn->prepareRead(
                'SELECT c.user_id, c.click_time, ac.identity_signals
                 FROM 202_clicks AS c
                 LEFT JOIN 202_aff_campaigns AS ac ON ac.aff_campaign_id = c.aff_campaign_id
                 WHERE c.click_id = ? LIMIT 1'
            );
            $conn->bind($stmt, 'i', [$clickId]);
            $row = $conn->fetchOne($stmt);
        } catch (\Throwable $e) {
            error_log('identity: could not read click ' . $clickId . ' to link its customer: ' . $e->getMessage());

            return null;
        }
        // A campaign with identity capture off links nothing, on the
        // conversion as on the click.
        if ($row === null || (isset($row['identity_signals']) && (int) $row['identity_signals'] === 0)) {
            return null;
        }

        return $this->attach($conn, (int) $row['user_id'], $clickId, (int) $row['click_time']);
    }

    /**
     * @param array<string, mixed> $get
     * @return array<string, string>
     */
    private static function customerParams(array $get): array
    {
        $out = [];
        foreach (self::CUSTOMER_KEYS as $key) {
            if (isset($get[$key]) && is_string($get[$key]) && $get[$key] !== '') {
                $out[$key] = $get[$key];
            }
        }

        return $out;
    }
}
