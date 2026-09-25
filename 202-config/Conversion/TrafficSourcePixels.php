<?php

declare(strict_types=1);

namespace Prosper202\Conversion;

use Prosper202\Database\Connection;

/**
 * Telling a click's traffic source about a conversion: the one sender for
 * every 202_ppc_account_pixels pixel, and the token replacement it (and the
 * tracker's own URLs) use.
 *
 * It was two global functions — replaceTokens() in connect2.php and
 * p202FireTrafficSourcePixels() in static-endpoint-helpers.php — and both
 * still exist and delegate here, so every legacy caller is unchanged. It is
 * a class so that paths which never load connect2.php (the REST API's
 * POST /events, the goal engine's traffic-source notifier) send exactly
 * what gpb.php sends, rather than a second copy of the rules that could
 * drift from it.
 *
 * Pixel types, as the setup page defines them:
 *   1 image, 2 iframe, 3 script — markup for a browser, returned;
 *   4 server-to-server postback — fetched here, one GET per URL;
 *   5 raw code — returned with its tokens replaced.
 */
final class TrafficSourcePixels
{
    public const POSTBACK_USER_AGENT = \Prosper202\Notifications\PostbackSender::USER_AGENT;

    /**
     * Every token replaceTokens() fills, in the order it fills them, as
     * [token key, pattern names]. `referer` also fills [[referrer]], and
     * `transactionid` also fills [[t202txid]]. The goal tokens (plan §5.5)
     * name the goal a notification is for and what it is worth there.
     */
    private const TOKENS = [
        'c1' => ['c1'], 'c2' => ['c2'], 'c3' => ['c3'], 'c4' => ['c4'],
        't202pubid' => ['t202pubid'], 'gclid' => ['gclid'], 'msclkid' => ['msclkid'], 'fbclid' => ['fbclid'],
        'utm_source' => ['utm_source'], 'utm_medium' => ['utm_medium'], 'utm_campaign' => ['utm_campaign'],
        'utm_term' => ['utm_term'], 'utm_content' => ['utm_content'],
        'subid' => ['subid'], 't202kw' => ['t202kw'], 'payout' => ['payout'], 'random' => ['random'],
        'cpc' => ['cpc'], 'cpc2' => ['cpc2'], 'cpa' => ['cpa'], 'timestamp' => ['timestamp'],
        'country' => ['country'], 'country_code' => ['country_code'], 'region' => ['region'], 'city' => ['city'],
        'referer' => ['referer', 'referrer'], 'sourceid' => ['sourceid'],
        'transactionid' => ['transactionid', 't202txid'],
        'p202_goal' => ['p202_goal'], 'p202_goal_id' => ['p202_goal_id'], 'p202_goal_value' => ['p202_goal_value'],
        // The Android install token (plan §5.1): computed by the caller from
        // the raw click id (connect2.php's replaceTokens(), which knows the
        // key); here only placed, like any other token.
        'p202_install_token' => ['p202_install_token'],
    ];

    private function __construct()
    {
    }

    /**
     * Replace `[[token]]` placeholders (case-insensitive) with URL-encoded
     * values. A token that is not set is left in place, unless $fillBlanks,
     * which empties every known placeholder the tokens do not set.
     *
     * @param array<string, scalar|null> $tokens
     */
    public static function replaceTokens(mixed $url, array $tokens = [], int|bool $fillBlanks = 0): string
    {
        $encoded = array_map(self::encode(...), $tokens);
        $url = (string) $url;
        foreach (self::TOKENS as $key => $names) {
            if (!isset($encoded[$key]) && !$fillBlanks) {
                continue;
            }
            $value = (string) ($encoded[$key] ?? '');
            foreach ($names as $name) {
                // preg_quote: token names are fixed here, but a replacement
                // string is taken literally only through a callback — "$1"
                // in a value must not become a back-reference.
                $url = (string) preg_replace_callback('/\[\[' . preg_quote($name, '/') . '\]\]/i', static fn (): string => $value, $url);
            }
        }

        return $url;
    }

    /** rawurlencode() that leaves "@" readable, as the tracker always has. */
    public static function encode(mixed $token): ?string
    {
        if ($token === null) {
            return null;
        }

        return str_replace('%40', '@', rawurlencode((string) $token));
    }

    /**
     * Fire every pixel the traffic source (a 202_ppc_accounts row) has.
     *
     * $tokens are replaceTokens() tokens. The caller fills `transactionid`
     * with the conversion's transaction id, or its dedupe key when the
     * network sent none, so a network receiving several conversions for one
     * click can tell them apart.
     *
     * With $browser false (no browser on the other end: an API call, a
     * server-to-server intake) the markup types are counted in
     * `browser_skipped` and not rendered; only type 4 is sent. With $server
     * false the type-4 postbacks are not sent here but counted in
     * `server_skipped`: the goal engine queues them in the notification
     * outbox, which the worker sends with retries.
     *
     * @param array<string, scalar|null> $tokens
     * @param (callable(string): bool)|null $fetch Performs a type-4 GET and
     *        says whether it succeeded (a 2xx or 3xx status); defaults to
     *        curl with the postback user agent. Injected by tests.
     * @return array{markup: string, types: list<int>, server_calls: int, server_failures: int, browser_skipped: int, server_skipped: int}
     */
    public static function fire(Connection $conn, int $ppcAccountId, array $tokens, ?callable $fetch = null, bool $browser = true, bool $server = true): array
    {
        $out = ['markup' => '', 'types' => [], 'server_calls' => 0, 'server_failures' => 0, 'browser_skipped' => 0, 'server_skipped' => 0];
        if ($ppcAccountId <= 0) {
            return $out;
        }

        $stmt = $conn->prepareRead(
            'SELECT pixel_code, pixel_type_id FROM 202_ppc_account_pixels WHERE ppc_account_id = ? ORDER BY pixel_id'
        );
        $conn->bind($stmt, 'i', [$ppcAccountId]);
        $pixels = $conn->fetchAll($stmt);

        // The one server-to-server sender, shared with the notification
        // outbox's worker (PR 5): it answers whether the network heard us
        // (a status, not a body — an empty 200 and a failure both read as
        // ''), and refuses anything but http(s), redirects included.
        $fetch ??= \Prosper202\Notifications\PostbackSender::fetch(...);

        foreach ($pixels as $pixel) {
            $type = (int) $pixel['pixel_type_id'];
            $code = (string) $pixel['pixel_code'];
            $out['types'][] = $type;

            if ($type === 5) {
                if ($browser) {
                    $out['markup'] .= self::replaceTokens($code, $tokens) . "\n";
                } else {
                    $out['browser_skipped']++;
                }
                continue;
            }

            foreach (explode(' ', $code) as $url) {
                if ($url === '') {
                    continue;
                }
                $url = self::replaceTokens($url, $tokens);
                if ($type !== 4 && !$browser) {
                    $out['browser_skipped']++;
                    continue;
                }
                $attr = htmlspecialchars($url, ENT_QUOTES);
                switch ($type) {
                    case 1:
                        $out['markup'] .= "<img src='{$attr}' height='0' width='0' style='display:none' />\n";
                        break;
                    case 2:
                        $out['markup'] .= "<iframe src='{$attr}' height='0' width='0'></iframe>\n";
                        break;
                    case 3:
                        $out['markup'] .= "<script async src='{$attr}'></script>\n";
                        break;
                    case 4:
                        if (!$server) {
                            // Queued by the caller in the notification
                            // outbox instead (a goal outcome, plan §5.10).
                            $out['server_skipped']++;
                            break;
                        }
                        $out['server_calls']++;
                        if (!$fetch($url)) {
                            $out['server_failures']++;
                            error_log('traffic source postback failed for ppc account ' . $ppcAccountId . ': ' . $url);
                        }
                        break;
                }
            }
        }
        $out['types'] = array_values(array_unique($out['types']));

        return $out;
    }

    /**
     * The click-level tokens gpb.php and upx.php send (subid, the c1-c4
     * values, keyword, click ids, UTM values, CPC, referrer), plus the
     * click's traffic source. Null when the click is not this user's.
     *
     * $inTransaction reads through the write connection: the notification
     * outbox resolves a postback inside the transaction that records the
     * conversion it announces.
     *
     * @return array{ppc_account_id: int, tokens: array<string, scalar>}|null
     */
    public static function clickTokens(Connection $conn, int $userId, int $clickId, bool $inTransaction = false): ?array
    {
        $sql =
            'SELECT c.click_id, c.ppc_account_id, c.click_cpc, c1.c1, c2.c2, c3.c3, c4.c4, kw.keyword, g.gclid,
                    us.utm_source, um.utm_medium, uca.utm_campaign, ut.utm_term, uco.utm_content, su.site_url_address
             FROM 202_clicks AS c
             LEFT JOIN 202_clicks_tracking AS ct ON ct.click_id = c.click_id
             LEFT JOIN 202_clicks_advance AS ca ON ca.click_id = c.click_id
             LEFT JOIN 202_google AS g ON g.click_id = c.click_id
             LEFT JOIN 202_tracking_c1 AS c1 ON c1.c1_id = ct.c1_id
             LEFT JOIN 202_tracking_c2 AS c2 ON c2.c2_id = ct.c2_id
             LEFT JOIN 202_tracking_c3 AS c3 ON c3.c3_id = ct.c3_id
             LEFT JOIN 202_tracking_c4 AS c4 ON c4.c4_id = ct.c4_id
             LEFT JOIN 202_utm_source AS us ON us.utm_source_id = g.utm_source_id
             LEFT JOIN 202_utm_medium AS um ON um.utm_medium_id = g.utm_medium_id
             LEFT JOIN 202_utm_campaign AS uca ON uca.utm_campaign_id = g.utm_campaign_id
             LEFT JOIN 202_utm_term AS ut ON ut.utm_term_id = g.utm_term_id
             LEFT JOIN 202_utm_content AS uco ON uco.utm_content_id = g.utm_content_id
             LEFT JOIN 202_keywords AS kw ON kw.keyword_id = ca.keyword_id
             LEFT JOIN 202_clicks_site AS cs ON cs.click_id = c.click_id
             LEFT JOIN 202_site_urls AS su ON su.site_url_id = cs.click_referer_site_url_id
             WHERE c.click_id = ? AND c.user_id = ?
             LIMIT 1';
        $stmt = $inTransaction ? $conn->prepareWrite($sql) : $conn->prepareRead($sql);
        $conn->bind($stmt, 'ii', [$clickId, $userId]);
        $row = $conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }
        $cpc = (string) ($row['click_cpc'] ?? '0');

        return [
            'ppc_account_id' => (int) ($row['ppc_account_id'] ?? 0),
            'tokens' => [
                'subid' => (string) $clickId,
                't202kw' => (string) ($row['keyword'] ?? ''),
                'c1' => (string) ($row['c1'] ?? ''),
                'c2' => (string) ($row['c2'] ?? ''),
                'c3' => (string) ($row['c3'] ?? ''),
                'c4' => (string) ($row['c4'] ?? ''),
                'gclid' => (string) ($row['gclid'] ?? ''),
                'utm_source' => (string) ($row['utm_source'] ?? ''),
                'utm_medium' => (string) ($row['utm_medium'] ?? ''),
                'utm_campaign' => (string) ($row['utm_campaign'] ?? ''),
                'utm_term' => (string) ($row['utm_term'] ?? ''),
                'utm_content' => (string) ($row['utm_content'] ?? ''),
                'cpc' => round((float) $cpc, 2),
                'cpc2' => $cpc,
                'timestamp' => time(),
                'random' => random_int(1000000, 9999999),
                // gpb.php sends the referrer url-encoded once before
                // replaceTokens() encodes it again; kept identical.
                'referer' => urlencode((string) ($row['site_url_address'] ?? '')),
            ],
        ];
    }
}
