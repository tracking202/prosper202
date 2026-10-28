<?php

declare(strict_types=1);

namespace Prosper202\Conversion;

use Prosper202\Click\ClickId;
use Prosper202\Database\Connection;
use Prosper202\Identity\RequestSignals;

/**
 * The same-IP fallback: when a pixel's request names no click (no subid, no
 * cookie), the pixel's account's latest click from the visitor's address in
 * the last 30 days, stored as MatchMethod::SAME_IP. A guess: behind iCloud
 * Private Relay or a carrier's NAT many visitors share one address, and the
 * latest of them takes the sale.
 *
 * Three things decide whether a pixel guesses (p202SameIpFallbackClick()):
 * - whose pixel it is: the account of the campaign it names (pixelAccount(),
 *   ofCampaign()). gpx.php and upx.php searched the install's first
 *   account's clicks for every account's pixel (CLAUDE.md #27);
 * - that account's setting (enabledFor()): off for an account made from
 *   1.9.79 on, on for every account there before, which is how they
 *   converted until then;
 * - whether the visitor may be recognised at all (p202MayRecogniseVisitor()),
 *   the decision that gives or withholds the p202vid cookie: a visitor the
 *   privacy setting holds back is not matched by address either.
 */
final class SameIpFallback
{
    public const COLUMN = 'user_pref_same_ip_fallback';

    /** How far back the guess looks: 30 days. */
    public const LOOKBACK = 2592000;

    private function __construct()
    {
    }

    /**
     * Whether $userId has the fallback on. No preferences row is the
     * column's default, off. A stored value that is not 0 or 1, or a read
     * that fails, is off too, logged: a guess nobody can say was allowed is
     * not made. While the 1.9.79 upgrade has not added the column (the
     * pixels do not wait for it), on: every account then is one the upgrade
     * turns on.
     */
    public static function enabledFor(Connection $conn, int $userId): bool
    {
        try {
            $stmt = $conn->prepareRead('SELECT ' . self::COLUMN . ' FROM 202_users_pref WHERE user_id = ?');
            $conn->bind($stmt, 'i', [$userId]);
            $row = $conn->fetchOne($stmt);
        } catch (\Throwable $e) {
            if (self::columnMissing($e)) {
                error_log('p202 same-IP fallback: the 1.9.79 upgrade has not added ' . self::COLUMN . ' yet, so user '
                    . $userId . ' keeps the fallback every account had before it. Run the upgrade.');

                return true;
            }
            error_log('p202 same-IP fallback: user ' . $userId . '\'s ' . self::COLUMN . ' could not be read ('
                . $e->getMessage() . '); no click is guessed from the address');

            return false;
        }
        if ($row === null) {
            return false;
        }
        $value = $row[self::COLUMN] ?? null;
        $text = is_int($value) ? (string) $value : $value;
        if ($text === '1') {
            return true;
        }
        if ($text !== '0') {
            error_log('p202 same-IP fallback: user ' . $userId . '\'s ' . self::COLUMN . ' is ' . var_export($value, true)
                . ', not 0 or 1; no click is guessed from the address');
        }

        return false;
    }

    /**
     * Whose pixel a gpx.php or upx.php request is: the account of the
     * campaign its cid names, or, with no cid, the install's one account
     * with campaigns (a deleted account or campaign does not count). Null
     * when nothing says: a cid that is not a campaign id or names no
     * campaign, more than one account with campaigns and no cid (the simple
     * pixel names none, the advanced one does), or a read that fails. Each
     * is logged by $endpoint, as the conversion is then not made.
     *
     * @return array{user_id: int, campaign_allows: bool}|null
     */
    public static function pixelAccount(Connection $conn, mixed $cid, string $endpoint): ?array
    {
        $named = $cid !== null && $cid !== '';
        $campaignId = $named ? ClickId::parse($cid) : null;
        if ($named && $campaignId === null) {
            error_log($endpoint . ': cid ' . var_export($cid, true) . ' is not a campaign id; no click is guessed from'
                . ' the address');

            return null;
        }
        try {
            if ($campaignId !== null) {
                $stmt = $conn->prepareRead(
                    'SELECT user_id, identity_signals FROM 202_aff_campaigns WHERE aff_campaign_id = ?'
                );
                $conn->bind($stmt, 'i', [$campaignId]);
                $campaign = $conn->fetchOne($stmt);
                if ($campaign === null) {
                    error_log($endpoint . ': cid ' . $campaignId . ' names no campaign; no click is guessed from the'
                        . ' address');

                    return null;
                }

                return self::ofCampaign($campaign, $endpoint);
            }
            $stmt = $conn->prepareRead(
                'SELECT DISTINCT ac.user_id FROM 202_aff_campaigns AS ac'
                . ' INNER JOIN 202_users AS u ON u.user_id = ac.user_id'
                . ' WHERE ac.aff_campaign_deleted = 0 AND u.user_deleted = 0 LIMIT 2'
            );
            $owners = $conn->fetchAll($stmt);
        } catch (\Throwable $e) {
            error_log($endpoint . ': whose pixel this is could not be read (' . $e->getMessage() . '); no click is'
                . ' guessed from the address');

            return null;
        }
        if (count($owners) !== 1) {
            if ($owners !== []) {
                error_log($endpoint . ': nothing named a click and the pixel names no campaign (cid), while more than'
                    . ' one account has campaigns; no click is guessed from the address. The advanced pixel names'
                    . ' its campaign.');
            }

            return null;
        }

        return self::ofCampaign(['user_id' => $owners[0]['user_id'], 'identity_signals' => null], $endpoint);
    }

    /**
     * The account and identity capture of a campaign row a pixel names
     * (px.php's acip, gpx.php's and upx.php's cid): its user_id, and its
     * identity_signals as the click path reads it. A row read without the
     * setting is a campaign whose setting is unknown: capture off
     * (CLAUDE.md #11). identity_signals null is no campaign: on.
     *
     * @param array<string, mixed> $campaign
     * @return array{user_id: int, campaign_allows: bool}|null
     */
    public static function ofCampaign(array $campaign, string $endpoint): ?array
    {
        $owner = $campaign['user_id'] ?? null;
        $userId = is_int($owner) ? $owner : (is_string($owner) && ctype_digit($owner) ? (int) $owner : 0);
        if ($userId <= 0) {
            error_log($endpoint . ': the campaign names no account (' . var_export($owner, true) . '); no click is'
                . ' guessed from the address');

            return null;
        }

        return [
            'user_id' => $userId,
            'campaign_allows' => array_key_exists('identity_signals', $campaign)
                && RequestSignals::campaignAllows($campaign['identity_signals']),
        ];
    }

    /** Whether $e, or one it wraps, is the server's 1054 for this column. */
    private static function columnMissing(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (Connection::isMysqlError($current, 1054, '') && str_contains($current->getMessage(), self::COLUMN)) {
                return true;
            }
        }

        return false;
    }
}
