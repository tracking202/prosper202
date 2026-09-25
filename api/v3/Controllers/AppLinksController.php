<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Apps\AppIdentity;
use Api\V3\Apps\StoreLink;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\MysqliStatements;

/**
 * GET /apps/{id}/store-link: the link builder's read (plan §5.6, PR 11).
 *
 * What a campaign advertising this app should send its clicks to, and —
 * with `campaign_id` — whether that campaign already does:
 *
 *  - Android: the Play link with `[[p202_install_token]]` in its referrer,
 *    and the campaign ↔ registration link (`app_registration_id`) that
 *    makes an install of another app on the campaign's clicks
 *    `foreign_click`. `ready` is both: the offer URL names this app and
 *    carries the token, and the campaign is linked to this registration.
 *  - iOS: the App Store link, and the SKAdNetwork / AdAttributionKit setup
 *    the app itself needs — the Info.plist keys, pointed at the origin this
 *    API is served from, and the two receiver paths Apple appends. A
 *    campaign is not linked to an iOS registration (Apple's postback names
 *    the app, not the click), so `ready` is the offer URL naming the app.
 *
 * Nothing is written. To apply it, PUT /campaigns/{id} with the
 * `aff_campaign_url` (and, for Android, `app_registration_id`) this
 * returns under `apply` — `p202 app link <id> --campaign-id N --apply`
 * does exactly that, and so does the link builder on Setup › Mobile Apps.
 *
 * URL rotation: a campaign with `aff_campaign_rotate` on sends each click
 * to the next of its offer URLs in turn — `aff_campaign_url` and every
 * non-empty `aff_campaign_url_2` … `_5` (rotateTrackerUrl() in
 * connect2.php). Every one of them is a URL a click can land on, so the
 * campaign is ready only when every URL in rotation names this app (and,
 * for Android, carries the token): one alternate elsewhere sends that share
 * of clicks away, and an Android install from it arrives without its click.
 * `apply` then turns rotation off (`aff_campaign_rotate: 0`) rather than
 * rewriting the alternates: rotating among copies of one store link does
 * nothing, and the alternates stay stored for an operator who turns
 * rotation back on — which re-reads here as not ready until they are fixed.
 * Alternates stored with rotation off are never served, so they are not
 * read.
 */
final class AppLinksController
{
    use MysqliStatements;

    public const RECEIVER_PATHS = [
        'skadnetwork' => '/.well-known/skadnetwork/report-attribution/',
        'adattributionkit' => '/.well-known/appattribution/report-attribution/',
    ];

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    /** @param array<string, mixed> $params */
    public function storeLink(int $registrationId, array $params): array
    {
        $registration = $this->one(
            'SELECT registration_id, platform, app_key, app_name FROM 202_app_registrations WHERE registration_id = ? AND user_id = ? LIMIT 1',
            'ii',
            [$registrationId, $this->userId]
        );
        if ($registration === null) {
            throw new NotFoundException('App registration not found');
        }
        $platform = (string)$registration['platform'];
        $appKey = (string)$registration['app_key'];
        $link = StoreLink::template($platform, $appKey);

        $data = [
            'registration_id' => (int)$registration['registration_id'],
            'platform' => $platform,
            'app_key' => $appKey,
            'app_name' => (string)$registration['app_name'],
            'store_link' => $link,
        ];
        if ($platform === AppIdentity::ANDROID) {
            $data['android'] = [
                'token' => StoreLink::INSTALL_TOKEN,
                'note' => 'The redirect replaces [[p202_install_token]] with the click\'s signed id; the SDK reads it back from the Play Install Referrer.',
            ];
        } else {
            $data['ios'] = [
                'info_plist' => [
                    'NSAdvertisingAttributionReportEndpoint' => '<the origin this API is served from>',
                    'AttributionCopyEndpoint' => '<the origin this API is served from>',
                    'EligibleForAdAttributionKitReengagementPostbackCopies' => true,
                ],
                'receiver_paths' => self::RECEIVER_PATHS,
                'note' => 'Apple appends the receiver paths to the bare origin in Info.plist. Attribution comes from the ad network\'s signed impression, so the link carries no click and the campaign is not linked to the app.',
            ];
        }

        $campaignId = trim((string)($params['campaign_id'] ?? ''));
        if ($campaignId !== '') {
            if (preg_match('/^[1-9][0-9]{0,9}$/D', $campaignId) !== 1) {
                throw new ValidationException('Invalid campaign_id', ['campaign_id' => 'Must be a campaign id from GET /campaigns']);
            }
            $data['campaign'] = $this->campaignState((int)$campaignId, $platform, $appKey, (int)$registration['registration_id'], $link);
        }

        return ['data' => $data];
    }

    /** @return array<string, mixed> */
    private function campaignState(int $campaignId, string $platform, string $appKey, int $registrationId, string $link): array
    {
        $campaign = $this->one(
            'SELECT aff_campaign_id, aff_campaign_name, aff_campaign_url, app_registration_id,
                    aff_campaign_rotate, aff_campaign_url_2, aff_campaign_url_3, aff_campaign_url_4, aff_campaign_url_5
             FROM 202_aff_campaigns
             WHERE aff_campaign_id = ? AND user_id = ? AND aff_campaign_deleted = 0 LIMIT 1',
            'ii',
            [$campaignId, $this->userId]
        );
        if ($campaign === null) {
            throw new NotFoundException('Campaign not found');
        }
        $url = (string)$campaign['aff_campaign_url'];
        $linkedTo = $campaign['app_registration_id'] === null ? null : (int)$campaign['app_registration_id'];
        $namesApp = StoreLink::namesApp($url, $platform, $appKey);
        $needs = [];
        if (!$namesApp) {
            $needs[] = 'aff_campaign_url: the offer URL is not this app\'s store link';
        }
        $apply = [];
        if ($platform === AppIdentity::ANDROID) {
            $carries = $namesApp && StoreLink::carriesInstallToken($url);
            if ($namesApp && !$carries) {
                $needs[] = 'aff_campaign_url: the store link does not carry [[p202_install_token]] in its referrer, so installs arrive without their click';
            }
            if ($linkedTo !== $registrationId) {
                $needs[] = $linkedTo === null
                    ? 'app_registration_id: the campaign is not linked to this app'
                    : 'app_registration_id: the campaign is linked to another app (registration ' . $linkedTo . ')';
            }
            if (!$carries) {
                $apply['aff_campaign_url'] = $link;
            }
            if ($linkedTo !== $registrationId) {
                $apply['app_registration_id'] = $registrationId;
            }
        } elseif (!$namesApp) {
            $apply['aff_campaign_url'] = $link;
        }

        // The alternates a click can be rotated to (see the class docblock).
        $rotating = (int)($campaign['aff_campaign_rotate'] ?? 0) !== 0;
        $rotation = [];
        if ($rotating) {
            foreach (['aff_campaign_url_2', 'aff_campaign_url_3', 'aff_campaign_url_4', 'aff_campaign_url_5'] as $field) {
                $alternate = (string)($campaign[$field] ?? '');
                if ($alternate === '') {
                    continue; // skipped by the rotation too
                }
                $fits = StoreLink::namesApp($alternate, $platform, $appKey)
                    && ($platform !== AppIdentity::ANDROID || StoreLink::carriesInstallToken($alternate));
                $rotation[] = ['field' => $field, 'url' => $alternate, 'ready' => $fits];
                if (!$fits) {
                    $needs[] = $field . ': URL rotation is on and also sends clicks here, which is not this app\'s store link'
                        . ($platform === AppIdentity::ANDROID ? ' carrying [[p202_install_token]]' : '');
                    $apply['aff_campaign_rotate'] = 0;
                }
            }
        }

        return [
            'aff_campaign_id' => (int)$campaign['aff_campaign_id'],
            'aff_campaign_name' => (string)$campaign['aff_campaign_name'],
            'aff_campaign_url' => $url,
            'app_registration_id' => $linkedTo,
            'aff_campaign_rotate' => $rotating ? 1 : 0,
            // With rotation on: the alternates in rotation and whether each
            // is this app's link; [] with rotation off (none is served).
            'rotation' => $rotation,
            'ready' => $needs === [],
            'needs' => $needs,
            // What PUT /campaigns/{id} needs to make it ready; empty when it is.
            'apply' => (object)$apply,
        ];
    }

    /**
     * @param list<mixed> $binds
     * @return array<string, mixed>|null
     */
    private function one(string $sql, string $types, array $binds): ?array
    {
        $stmt = $this->prepare($sql);
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'Store link lookup failed');
        $row = $this->result($stmt)->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }
}
