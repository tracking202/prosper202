<?php

declare(strict_types=1);

namespace Prosper202\Identity;

use Prosper202\Click\LatestVisit;
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
 * retried on a lock error or a concurrent link's race to create the same
 * signal (IdentityRaceException), and otherwise logged with the click id,
 * and the click stays a one-touch journey.
 */
final class ClickIdentity
{
    private const CUSTOMER_KEYS = ['cust', 'customer_ref', 'cust_type', 'customer_ref_type', 'cust_sig'];
    /** A race or a deadlock costs one attempt; a third racer can cost a second. */
    private const ATTEMPTS = 3;

    /**
     * @param list<IdentitySignal> $signals
     * @param array<string, string> $customerParams
     */
    private function __construct(
        public readonly array $signals,
        public readonly array $customerParams,
        public readonly ?string $cookieValue,
        /** The request refused consent (p202_consent=0) or its campaign's capture is off. */
        public readonly bool $withheld = false,
        /**
         * The visitor refused (p202_consent other than 1), whatever the
         * campaign says: what ConsentRefusal stores. A campaign's capture
         * switched off withholds the visitor's data, not the conversion.
         */
        public readonly bool $refused = false,
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
            return new self([], [], null, true, !RequestSignals::consentGiven($get));
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

    /** Whether the request brought a browser signal: the visitor cookie or the landing page id. */
    public function bringsBrowserSignal(): bool
    {
        foreach ($this->signals as $signal) {
            if ($signal->type === SignalType::VISITOR_COOKIE || $signal->type === SignalType::LANDING_PAGE) {
                return true;
            }
        }

        return false;
    }

    /**
     * A later request of a click that is already stored (a landing page's,
     * for the click its tracking template made, whose visit is the one at
     * $visitTime) and that withheld consent takes back the browser marks the
     * click's earlier requests left (IdentityGraph::withdrawBrowserSignals()),
     * and, when the visitor refused, holds that for the click
     * (ConsentRefusal). The caller has shown the click is the account's.
     *
     * Unlike attach(), a failure is the caller's: a link that is not made
     * identifies no one, but a refusal not stored would send the address of
     * a visitor who refused (CLAUDE.md #11). The refusal is stored first
     * and on its own, so it stands when the marks, held by another request,
     * cannot be taken back. Each is tried again on a lock error, then
     * thrown.
     */
    public function withdrawIfWithheld(Connection $conn, int $clickId, int $visitTime): void
    {
        if (!$this->withheld || $clickId <= 0) {
            return;
        }
        if ($this->refused) {
            self::onALockErrorAgain(
                static fn () => ConsentRefusal::record($conn, $clickId, $visitTime)
            );
        }
        self::onALockErrorAgain(static fn () => IdentityGraph::withdrawBrowserSignals($conn, $clickId));
    }

    /** @param \Closure(): void $write one statement that may be run again */
    private static function onALockErrorAgain(\Closure $write): void
    {
        for ($attempt = 1; true; $attempt++) {
            try {
                $write();

                return;
            } catch (\Throwable $e) {
                if ($attempt < self::ATTEMPTS && Connection::isRetryableLockError($e)) {
                    continue;
                }
                throw $e;
            }
        }
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
                if ($attempt < self::ATTEMPTS
                    && ($e instanceof IdentityRaceException || Connection::isRetryableLockError($e))) {
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
     * owner, time and campaign setting come from its latest visit, the one
     * the conversion credits — the setting only from a campaign of the
     * click's own account: a tracker could name another account's campaign
     * before the API checked linked ids (229df10), and that account's
     * setting does not govern this one's clicks (such a click reads as
     * having no campaign, which links).
     */
    public function attachToStoredClick(Connection $conn, int $clickId): ?int
    {
        if ($this->isEmpty() || $clickId <= 0) {
            return null;
        }
        try {
            $stmt = $conn->prepareRead(
                'SELECT c.user_id, c.click_time, ac.identity_signals
                 FROM ' . LatestVisit::of('?') . ' AS c
                 LEFT JOIN 202_aff_campaigns AS ac ON ac.aff_campaign_id = c.aff_campaign_id AND ac.user_id = c.user_id'
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
