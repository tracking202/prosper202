<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Conversion\FacebookPixelId;
use Prosper202\Database\Connection;
use Prosper202\Setup\FacebookPixelAssistantSettings;

/**
 * The Bot202 Facebook Pixel Assistant's part of a simple landing page's
 * record.php answer (record_simple.php): a call of landing.php's
 * window.p202FbPixelAssistant() with what the page's Meta Pixel events
 * carry, which 202-js/p202-fb-pixel-assistant.js then fires. Paid 1.9.56
 * did this in record_simple.php, bot202_fb_pixel.php and fbpx.js.
 *
 * Nothing is answered unless the visitor may be tracked in full (the
 * privacy setting in force and the page's consent: a third-party pixel
 * sets the visitor cookies), the page's assistant is on, and the click's
 * traffic source (the account's own) has a "Bot202 Facebook Pixel
 * Assistant" pixel holding a Meta Pixel ID. With dynamic EPV on, the
 * outbound click carries the source's earnings per outbound click over
 * the last seven days.
 *
 * The click is already recorded when this runs, so a failed read is logged
 * and answers nothing rather than failing the page's script.
 */
final class FacebookPixelAssistantScript
{
    /** The days dynamic EPV looks back over, as in paid 1.9.56. */
    public const EPV_DAYS = 7;

    private function __construct()
    {
    }

    /**
     * @param \Closure(string, \Closure(): mixed): mixed|null $cache reads the
     *        EPV through a cache: called with a key and the read, answers the
     *        read's value (record_simple.php passes memcache). Null reads it.
     */
    public static function forClick(
        Connection $conn,
        int $userId,
        int $landingPageId,
        int $ppcAccountId,
        int $clickId,
        bool $mayTrack,
        ?\Closure $cache = null,
        ?int $now = null
    ): string {
        if (!$mayTrack || $userId <= 0 || $landingPageId <= 0 || $ppcAccountId <= 0) {
            return '';
        }
        try {
            $settings = self::config($conn, $userId, $landingPageId, $ppcAccountId, $clickId, $cache, $now ?? time());
        } catch (\Throwable $e) {
            error_log('p202 Bot202 Facebook Pixel Assistant: landing page ' . $landingPageId
                . ' could not be read; its Meta Pixel events are not sent for click ' . $clickId . ': ' . $e->getMessage());

            return '';
        }
        if ($settings === null) {
            return '';
        }
        $json = json_encode(
            $settings,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );

        return "\nif (typeof window.p202FbPixelAssistant === 'function') { window.p202FbPixelAssistant(" . $json . "); }\n";
    }

    /**
     * The click path's memcache (getCache()/setCache()) as forClick()'s
     * $cache, for an hour, under this install's namespace (systemHash()).
     *
     * @return \Closure(string, \Closure(): mixed): mixed
     */
    public static function bootstrapCache(): \Closure
    {
        return static function (string $key, \Closure $read): mixed {
            $key = md5(\systemHash() . $key);
            $hit = \getCache($key);
            if (is_string($hit)) {
                return $hit;
            }
            $value = $read();
            \setCache($key, $value, 3600);

            return $value;
        };
    }

    /** @return array<string, string|float>|null */
    private static function config(
        Connection $conn,
        int $userId,
        int $landingPageId,
        int $ppcAccountId,
        int $clickId,
        ?\Closure $cache,
        int $now
    ): ?array {
        // A simple page of the user's, with its campaign when that is the user's.
        $stmt = $conn->prepareRead(
            'SELECT fb.b202_fbpa_dynamic_epv, fb.b202_fbpa_content_name, fb.b202_fbpa_content_type,
                    fb.b202_fbpa_outbound_clicks, ac.aff_campaign_id_public
               FROM 202_bot202_facebook_pixel_assistant AS fb
               JOIN 202_landing_pages AS lp
                 ON lp.landing_page_id = fb.landing_page_id AND lp.user_id = ? AND lp.landing_page_type = 0
               LEFT JOIN 202_aff_campaigns AS ac
                 ON ac.aff_campaign_id = lp.aff_campaign_id AND ac.user_id = lp.user_id
              WHERE fb.landing_page_id = ? AND fb.b202_fbpa_status = 1
              LIMIT 1'
        );
        $conn->bind($stmt, 'ii', [$userId, $landingPageId]);
        $page = $conn->fetchOne($stmt);
        if ($page === null) {
            return null;
        }

        $pixelId = self::pixelId($conn, $userId, $ppcAccountId);
        if ($pixelId === null) {
            return null;
        }

        $config = ['pixelId' => $pixelId, 'eventId' => 'p202-' . $clickId];
        $name = (string) ($page['b202_fbpa_content_name'] ?? '');
        if ($name !== '') {
            $config['contentName'] = $name;
        }
        $type = FacebookPixelAssistantSettings::CONTENT_TYPES[(int) $page['b202_fbpa_content_type']] ?? null;
        if ($type !== null) {
            $config['contentType'] = $type[0];
        }
        $offer = (int) ($page['aff_campaign_id_public'] ?? 0);
        if ($offer > 0) {
            $config['contentId'] = 't202id' . $offer;
        }
        $event = FacebookPixelAssistantSettings::CLICK_EVENTS[(int) $page['b202_fbpa_outbound_clicks']] ?? null;
        if ($event !== null) {
            $config['clickEvent'] = $event[0];
            if ((int) $page['b202_fbpa_dynamic_epv'] === 1) {
                $config += self::dynamicEpv($conn, $userId, $ppcAccountId, $cache, $now);
            }
        }

        return $config;
    }

    /** The source's first pixel of the assistant's type that holds a Pixel ID. */
    private static function pixelId(Connection $conn, int $userId, int $ppcAccountId): ?string
    {
        $stmt = $conn->prepareRead(
            'SELECT p.pixel_id, p.pixel_code
               FROM 202_ppc_account_pixels AS p
               JOIN 202_ppc_accounts AS a ON a.ppc_account_id = p.ppc_account_id AND a.user_id = ?
              WHERE p.ppc_account_id = ? AND p.pixel_type_id = ?
              ORDER BY p.pixel_id'
        );
        $conn->bind($stmt, 'iii', [$userId, $ppcAccountId, FacebookPixelId::PIXEL_TYPE]);
        foreach ($conn->fetchAll($stmt) as $row) {
            $id = FacebookPixelId::fromCode($row['pixel_code'] ?? null);
            if ($id !== null) {
                return $id;
            }
            error_log('p202 Bot202 Facebook Pixel Assistant: pixel ' . (int) ($row['pixel_id'] ?? 0)
                . ' of ppc account ' . $ppcAccountId . ' holds no Meta Pixel ID; skipped');
        }

        return null;
    }

    /**
     * The value and currency an outbound click carries: income over outbound
     * clicks for the source since the start of the hour seven days ago (whole
     * hours, so a cached answer is shared within the hour). None when there
     * is nothing to divide or the account's currency is not a currency code.
     *
     * @return array{value?: float, currency?: string}
     */
    private static function dynamicEpv(Connection $conn, int $userId, int $ppcAccountId, ?\Closure $cache, int $now): array
    {
        $since = (intdiv($now, 3600) - self::EPV_DAYS * 24) * 3600;
        $read = static function () use ($conn, $userId, $ppcAccountId, $since): string {
            $stmt = $conn->prepareRead(
                'SELECT SUM(income) AS income, SUM(click_out) AS click_out
                   FROM 202_dataengine
                  WHERE user_id = ? AND click_time >= ? AND ppc_account_id = ?'
            );
            $conn->bind($stmt, 'iii', [$userId, $since, $ppcAccountId]);
            $row = $conn->fetchOne($stmt) ?? [];
            $out = (float) ($row['click_out'] ?? 0);

            return $out > 0 ? number_format((float) ($row['income'] ?? 0) / $out, 2, '.', '') : '0.00';
        };
        $epv = $cache === null ? $read() : $cache('p202-fbpa-epv-' . $userId . '-' . $ppcAccountId . '-' . $since, $read);
        if (!is_string($epv) || !is_numeric($epv) || (float) $epv <= 0) {
            return [];
        }

        $stmt = $conn->prepareRead('SELECT user_account_currency FROM 202_users_pref WHERE user_id = ?');
        $conn->bind($stmt, 'i', [$userId]);
        $currency = (string) ($conn->fetchOne($stmt)['user_account_currency'] ?? 'USD');
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            error_log('p202 Bot202 Facebook Pixel Assistant: user ' . $userId . '\'s currency is not a currency code; no value sent');

            return [];
        }

        return ['value' => (float) $epv, 'currency' => $currency];
    }
}
