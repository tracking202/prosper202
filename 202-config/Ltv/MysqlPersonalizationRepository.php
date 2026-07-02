<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use Prosper202\Database\Connection;
use RuntimeException;

/**
 * Landing-page personalization tokens.
 *
 * Security model (mint-at-redirect, seal-at-first-use, replay-after):
 *  - Tokens are 256-bit CSPRNG values — bearer capabilities that name
 *    nothing; only sha256(token) is stored, so neither the DB nor logs can
 *    be used to forge a live cookie.
 *  - Minted ONLY for visitors who resolve to a known customer through an
 *    explicit signal (cust param alias, configured c-param alias, or a
 *    click already stamped with a customer). Never from IP guessing.
 *  - First redemption must happen within the first-use window; the response
 *    payload is then SEALED into `snapshot`. Every later redemption returns
 *    the snapshot verbatim until replay_until — reloads keep working, but a
 *    leaked token can never reveal anything the visitor didn't already see.
 *  - Payload fields come exclusively from the account's allowlist pref
 *    (202_users_pref.user_ltv_personalization_fields): CRM names from
 *    ALLOWED_CRM_FIELDS plus `cf:<field_key>` custom-field entries.
 *    Email/phone/address/revenue/refs are never eligible.
 */
final class MysqlPersonalizationRepository
{
    /** CRM columns eligible for the allowlist pref. Deliberately tiny. */
    public const ALLOWED_CRM_FIELDS = ['first_name', 'last_name', 'company', 'city', 'country'];

    /** Seconds a fresh token may be redeemed for live data. */
    public const FIRST_USE_WINDOW = 3600; // 60 minutes

    /** Seconds a sealed snapshot keeps replaying (UX: reloads never break). */
    public const REPLAY_WINDOW = 2592000; // 30 days

    public function __construct(private Connection $conn)
    {
    }

    /**
     * The account's personalization allowlist, parsed from the pref.
     * Empty array = feature disabled.
     *
     * @return list<string> entries like 'first_name' or 'cf:loyalty_tier'
     */
    public function allowedFields(int $userId): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT user_ltv_personalization_fields FROM 202_users_pref WHERE user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $row = $this->conn->fetchOne($stmt);
        $raw = trim((string) ($row['user_ltv_personalization_fields'] ?? ''));
        if ($raw === '') {
            return [];
        }

        $fields = [];
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (in_array($entry, self::ALLOWED_CRM_FIELDS, true)
                || (str_starts_with($entry, 'cf:') && strlen($entry) > 3)
                || $entry === 'rec:next_offer') {
                $fields[] = $entry;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * Resolve the visiting customer at redirect time, READ-ONLY (minting must
     * never create identity — that is ingest's job):
     *  1. explicit ref from the tracking URL (cust/customer_ref) -> alias lookup
     *  2. account's configured c-param value from the URL -> alias lookup
     *  3. a click id from the request cookies -> 202_clicks_tracking stamp
     * Returns null when no explicit signal resolves. IP-based guessing is
     * deliberately not an option here (wrong-person PII risk).
     *
     * @param array<string, mixed> $get Typically $_GET.
     * @param int $cookieClickId Click id from the request's subid cookie (0 = none).
     * @param bool $allowClickFallback When false, only the explicit URL
     *        signals (cust/c-param) resolve — used to avoid re-minting on
     *        every pageview when the page already holds a token.
     */
    public function resolveVisitorCustomer(int $userId, array $get, int $cookieClickId, bool $allowClickFallback = true): ?int
    {
        $ref = '';
        foreach (['cust', 'customer_ref'] as $key) {
            if (isset($get[$key]) && is_scalar($get[$key]) && trim((string) $get[$key]) !== '') {
                $ref = trim((string) $get[$key]);
                break;
            }
        }
        if ($ref !== '') {
            $found = $this->lookupAliasAnyType($userId, $ref);
            if ($found !== null) {
                return $found;
            }
        }

        $cparam = $this->customerCParamPref($userId);
        if ($cparam >= 1 && $cparam <= 4) {
            $key = 'c' . $cparam;
            if (isset($get[$key]) && is_scalar($get[$key]) && trim((string) $get[$key]) !== '') {
                $found = $this->lookupAliasAnyType($userId, trim((string) $get[$key]));
                if ($found !== null) {
                    return $found;
                }
            }
        }

        if ($allowClickFallback && $cookieClickId > 0) {
            $stmt = $this->conn->prepareRead(
                'SELECT ct.customer_id FROM 202_clicks_tracking ct
                 JOIN 202_customers c ON c.customer_id = ct.customer_id AND c.user_id = ?
                 WHERE ct.click_id = ? LIMIT 1'
            );
            $this->conn->bind($stmt, 'ii', [$userId, $cookieClickId]);
            $row = $this->conn->fetchOne($stmt);
            if ($row !== null && (int) $row['customer_id'] > 0) {
                return (int) $row['customer_id'];
            }
        }

        return null;
    }

    /**
     * Mint a token for a resolved customer. Returns the raw token for the
     * cookie; only its hash is stored.
     */
    public function mint(int $userId, int $customerId, int $clickId, int $now): string
    {
        $rawToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $rawToken, true);

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_personalization_tokens
                (token_hash, user_id, customer_id, click_id, created_at, first_use_deadline, replay_until, redeemed_at, snapshot)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL)'
        );
        $this->conn->bind($stmt, 'siiiiii', [
            $tokenHash,
            $userId,
            $customerId,
            $clickId > 0 ? $clickId : null,
            $now,
            $now + self::FIRST_USE_WINDOW,
            $now + self::REPLAY_WINDOW,
        ]);
        $this->conn->execute($stmt);
        $stmt->close();

        return $rawToken;
    }

    /**
     * Redeem a token. Returns the personalization payload, or [] for
     * unknown/expired/disabled — indistinguishable to the caller (no oracle).
     *
     *  - First redemption (within first_use_deadline): builds the payload
     *    from the account allowlist, atomically seals it as the snapshot.
     *  - Later redemptions (until replay_until): the sealed snapshot,
     *    verbatim — never fresh data.
     *
     * @return array<string, string>
     */
    public function redeem(string $rawToken, int $now): array
    {
        $rawToken = trim($rawToken);
        // 32 random bytes base64url-encode to 43 chars; reject anything else
        // before touching the database.
        if (strlen($rawToken) < 40 || strlen($rawToken) > 64 || preg_match('/^[A-Za-z0-9_\-]+$/', $rawToken) !== 1) {
            return [];
        }
        $tokenHash = hash('sha256', $rawToken, true);

        $stmt = $this->conn->prepareWrite(
            'SELECT p13n_id, user_id, customer_id, first_use_deadline, replay_until, redeemed_at, snapshot
             FROM 202_personalization_tokens WHERE token_hash = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 's', [$tokenHash]);
        $token = $this->conn->fetchOne($stmt);
        if ($token === null || $now > (int) $token['replay_until']) {
            return [];
        }

        // Sealed: replay the snapshot verbatim. This is the UX guarantee —
        // reloads and repeat pageviews keep personalizing — and the privacy
        // guarantee: nothing new can ever come out of this token.
        if ($token['redeemed_at'] !== null) {
            $snapshot = json_decode((string) ($token['snapshot'] ?? ''), true);
            return is_array($snapshot) ? $snapshot : [];
        }

        if ($now > (int) $token['first_use_deadline']) {
            return [];
        }

        $payload = $this->buildPayload((int) $token['user_id'], (int) $token['customer_id']);
        $encoded = json_encode($payload);
        if ($encoded === false) {
            error_log('p13n: failed to encode personalization payload for token ' . (int) $token['p13n_id']);
            return [];
        }

        // Atomic seal: exactly one request wins the race; everyone else
        // (including this request, if it lost) replays the stored snapshot.
        $seal = $this->conn->prepareWrite(
            'UPDATE 202_personalization_tokens SET redeemed_at = ?, snapshot = ?
             WHERE p13n_id = ? AND redeemed_at IS NULL'
        );
        $this->conn->bind($seal, 'isi', [$now, $encoded, (int) $token['p13n_id']]);
        $sealed = $this->conn->executeUpdate($seal);

        if ($sealed === 0) {
            // Lost a concurrent race — return the winner's snapshot so both
            // requests render identical content.
            $again = $this->conn->prepareWrite(
                'SELECT snapshot FROM 202_personalization_tokens WHERE p13n_id = ? LIMIT 1'
            );
            $this->conn->bind($again, 'i', [(int) $token['p13n_id']]);
            $row = $this->conn->fetchOne($again);
            $snapshot = json_decode((string) ($row['snapshot'] ?? ''), true);
            return is_array($snapshot) ? $snapshot : [];
        }

        return $payload;
    }

    /**
     * Delete rows past their replay window (ltv_maintenance sweep).
     */
    public function purgeExpired(int $now): int
    {
        $stmt = $this->conn->prepareWrite(
            'DELETE FROM 202_personalization_tokens WHERE replay_until < ?'
        );
        $this->conn->bind($stmt, 'i', [$now]);

        return $this->conn->executeUpdate($stmt);
    }

    /**
     * Remove all tokens (and their sealed snapshots) for a customer —
     * called from the GDPR-shaped erasure path.
     */
    public function eraseCustomerTokens(int $userId, int $customerId): void
    {
        $stmt = $this->conn->prepareWrite(
            'DELETE FROM 202_personalization_tokens WHERE customer_id = ? AND user_id = ?'
        );
        $this->conn->bind($stmt, 'ii', [$customerId, $userId]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * Build the payload strictly from the account allowlist. Unknown or
     * disallowed entries contribute nothing; an empty allowlist yields [].
     *
     * @return array<string, string>
     */
    private function buildPayload(int $userId, int $customerId): array
    {
        $fields = $this->allowedFields($userId);
        if ($fields === []) {
            return [];
        }

        $payload = [];

        $crmWanted = array_values(array_intersect($fields, self::ALLOWED_CRM_FIELDS));
        if ($crmWanted !== []) {
            // Column names come from the ALLOWED_CRM_FIELDS constant, never
            // from user input.
            $stmt = $this->conn->prepareRead(
                'SELECT ' . implode(', ', $crmWanted) . ' FROM 202_customers
                 WHERE customer_id = ? AND user_id = ? AND merged_into_customer_id IS NULL LIMIT 1'
            );
            $this->conn->bind($stmt, 'ii', [$customerId, $userId]);
            $row = $this->conn->fetchOne($stmt);
            if ($row !== null) {
                foreach ($crmWanted as $column) {
                    $value = trim((string) ($row[$column] ?? ''));
                    if ($value !== '') {
                        $payload[$column] = $value;
                    }
                }
            }
        }

        // Next-offer recommendation (opt-in via 'rec:next_offer'): the
        // suggestion is computed at seal time and frozen into the snapshot
        // like every other field — replays keep showing the same offer.
        if (in_array('rec:next_offer', $fields, true)) {
            $recommendation = (new MysqlRecommendationRepository($this->conn))->nextOffer($userId, $customerId);
            if ($recommendation !== null && $recommendation['name'] !== '') {
                $payload['next_offer_name'] = $recommendation['name'];
                if ($recommendation['url'] !== '') {
                    $payload['next_offer_url'] = $recommendation['url'];
                }
            }
        }

        foreach ($fields as $entry) {
            if (!str_starts_with($entry, 'cf:')) {
                continue;
            }
            $fieldKey = substr($entry, 3);
            $stmt = $this->conn->prepareRead(
                'SELECT f.field_type, v.value_text, v.value_number, v.value_date
                 FROM 202_customer_fields f
                 JOIN 202_customer_field_values v ON v.field_id = f.field_id AND v.customer_id = ?
                 WHERE f.user_id = ? AND f.field_key = ? LIMIT 1'
            );
            $this->conn->bind($stmt, 'iis', [$customerId, $userId, $fieldKey]);
            $row = $this->conn->fetchOne($stmt);
            if ($row === null) {
                continue;
            }
            $value = match ((string) $row['field_type']) {
                'number' => $row['value_number'] !== null ? rtrim(rtrim((string) $row['value_number'], '0'), '.') : '',
                'boolean' => $row['value_number'] !== null ? (((float) $row['value_number']) > 0 ? 'yes' : 'no') : '',
                'date' => $row['value_date'] !== null ? date('Y-m-d', (int) $row['value_date']) : '',
                default => trim((string) ($row['value_text'] ?? '')),
            };
            if ($value !== '') {
                $payload[$fieldKey] = $value;
            }
        }

        return $payload;
    }

    private function lookupAliasAnyType(int $userId, string $ref): ?int
    {
        if ($ref === '' || strlen($ref) > 255) {
            return null;
        }
        $hash = hash('sha256', $ref, true);
        $stmt = $this->conn->prepareRead(
            'SELECT customer_id FROM 202_customer_aliases
             WHERE user_id = ? AND alias_hash = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'is', [$userId, $hash]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }

        return $this->followMergePointer((int) $row['customer_id']);
    }

    private function customerCParamPref(int $userId): int
    {
        $stmt = $this->conn->prepareRead(
            'SELECT user_ltv_customer_cparam FROM 202_users_pref WHERE user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $row = $this->conn->fetchOne($stmt);

        return $row !== null ? (int) ($row['user_ltv_customer_cparam'] ?? 0) : 0;
    }

    private function followMergePointer(int $customerId): int
    {
        for ($hop = 0; $hop < 5; $hop++) {
            $stmt = $this->conn->prepareRead(
                'SELECT merged_into_customer_id FROM 202_customers WHERE customer_id = ? LIMIT 1'
            );
            $this->conn->bind($stmt, 'i', [$customerId]);
            $row = $this->conn->fetchOne($stmt);
            if ($row === null || $row['merged_into_customer_id'] === null) {
                return $customerId;
            }
            $customerId = (int) $row['merged_into_customer_id'];
        }

        return $customerId;
    }
}
