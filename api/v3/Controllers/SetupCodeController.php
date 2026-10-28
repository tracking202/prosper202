<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\StatementHelpers;
use Api\V3\Support\TrackingBaseLookup;
use Prosper202\Setup\LandingPageCode;
use Prosper202\Setup\PostbackCode;

/**
 * The code the Setup section hands out, as REST:
 *
 * - GET /landing-pages/{id}/code — Setup › Get LP Code: the loader script
 *   and the ways out to the offer, for a simple page, or for each offer of
 *   an advanced one (`?offers=campaign:12,rotator:3`);
 * - GET /conversions/postback-code — Setup › Postback / Pixel: the simple,
 *   advanced and universal pixels and postback URLs, filled with the amount,
 *   sub id and campaign given.
 *
 * Every snippet is built by the class the pages build theirs with
 * (Prosper202\Setup\LandingPageCode, PostbackCode), on the base the
 * tracker link uses (TrackingBaseLookup). What the pages refuse is refused
 * here, by the pages' sentences where they have one, and what the pages
 * could not be asked for (a malformed offer, a value that would break the
 * snippet, a parameter nobody reads) is refused too rather than ignored
 * (CLAUDE.md #4): every query value is read as sent, before any cast.
 *
 * The routes ask for the Setup pages' role permission,
 * access_to_setup_section, in their group middleware (index.php).
 */
final class SetupCodeController
{
    use StatementHelpers;
    use TrackingBaseLookup;

    /** The most offers one advanced page's code is built for at once. */
    public const MAX_OFFERS = 100;

    private const LANDING_PAGE_TYPES = [0 => 'simple', 1 => 'advanced'];

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    /**
     * GET /landing-pages/{id}/code.
     *
     * @param array<string, mixed> $query the query string, as sent
     * @param array<string, mixed>|null $server the request ($_SERVER)
     * @return array{data: array<string, mixed>}
     */
    public function landingPageCode(int $id, array $query, ?array $server = null, ?int $now = null): array
    {
        self::refuseUnknown($query, ['offers'], 'GET /landing-pages/{id}/code takes offers (an advanced page\'s campaigns and redirectors) and nothing else');
        $now ??= time();

        // A page the API created before public ids were assigned has none,
        // and every snippet carries it: give it one first, as a read of
        // the page through the API does.
        (new LandingPagesController($this->db, $this->userId))->repairMissingPublicIds();
        $page = $this->landingPage($id);

        $type = (int) $page['landing_page_type'];
        if (!isset(self::LANDING_PAGE_TYPES[$type])) {
            throw new ValidationException('This landing page has no code', [
                'landing_page_type' => "Landing page $id has type $type; Get LP Code makes code for a simple page (0) or an advanced one (1). Set its landing_page_type first.",
            ]);
        }
        $base = LandingPageCode::protocolRelative($this->trackingBaseUrl($server));
        $publicId = (string) $page['landing_page_id_public'];
        $url = (string) $page['landing_page_url'];

        $data = [
            'landing_page_id' => (int) $page['landing_page_id'],
            'landing_page_id_public' => (int) $page['landing_page_id_public'],
            'landing_page_type' => self::LANDING_PAGE_TYPES[$type],
            'landing_page_nickname' => (string) $page['landing_page_nickname'],
            'landing_page_url' => $url,
            'base_url' => $base,
            'loader' => LandingPageCode::loader($base, $publicId),
        ];

        if ($type === 0) {
            if (array_key_exists('offers', $query)) {
                throw new ValidationException('A simple landing page has no offers to choose', [
                    'offers' => "Landing page $id is a simple page: it links out to its own campaign. Offers are for an advanced page (landing_page_type 1); drop offers.",
                ]);
            }
            $this->requireLiveCampaign($page);
            $data += [
                'aff_campaign_id' => (int) $page['aff_campaign_id'],
                'outbound_link' => LandingPageCode::simpleOutboundLink($base, $publicId),
                'outbound_php' => LandingPageCode::simpleOutboundPhp($base, $publicId, $url, $now),
                'outbound_javascript' => LandingPageCode::simpleOutboundJavascript($base, $publicId),
            ];
        } else {
            $offers = [];
            foreach ($this->offers($query) as $position => [$offerType, $offerId]) {
                $offers[] = $offerType === 'campaign'
                    ? $this->campaignOffer($position, $offerId, $base, $url, $now)
                    : $this->rotatorOffer($position, $offerId, $base, $url, $now);
            }
            $data['offers'] = $offers;
        }
        $data['segments'] = LandingPageCode::SEGMENTS;

        return ['data' => $data];
    }

    /**
     * GET /conversions/postback-code.
     *
     * @param array<string, mixed> $query the query string, as sent
     * @param array<string, mixed>|null $server the request ($_SERVER)
     * @return array{data: array<string, mixed>}
     */
    public function postbackCode(array $query, ?array $server = null): array
    {
        self::refuseUnknown($query, ['amount', 'subid', 'campaign_id', 'scheme'], 'GET /conversions/postback-code takes amount, subid, campaign_id and scheme');
        $errors = [];
        $values = [];
        foreach (['amount', 'subid'] as $key) {
            $value = $query[$key] ?? '';
            if (!is_string($value)) {
                $errors[$key] = 'Must be a single value';
                continue;
            }
            $problem = PostbackCode::problem($value);
            if ($problem !== null) {
                $errors[$key] = $problem;
                continue;
            }
            $values[$key] = $value;
        }

        $scheme = $query['scheme'] ?? null;
        if ($scheme !== null && !in_array($scheme, ['http', 'https'], true)) {
            $errors['scheme'] = 'Must be http or https (omit it to use the scheme the tracking domain is reached on)';
        }

        $campaignId = null;
        $rawCampaign = $query['campaign_id'] ?? null;
        if ($rawCampaign !== null) {
            $campaignId = is_string($rawCampaign) ? self::positiveId($rawCampaign) : null;
            if ($campaignId === null) {
                $errors['campaign_id'] = 'Must be a campaign id (a whole number from `p202 campaign list`)';
            } elseif ($this->liveCampaign($campaignId) === null) {
                $errors['campaign_id'] = "Campaign $campaignId is not yours, or it was removed";
            }
        }
        if ($errors !== []) {
            throw new ValidationException('Invalid postback code request', $errors);
        }

        $base = $this->trackingBaseUrl($server);
        if (is_string($scheme)) {
            $base = $scheme . substr($base, (int) strpos($base, '://'));
        }
        $root = $base . 'tracking202/static/';
        $snippets = PostbackCode::snippets($root, $values['amount'], $campaignId === null ? '' : (string) $campaignId, $values['subid']);

        return ['data' => [
            'scheme' => substr($base, 0, (int) strpos($base, '://')),
            'base_url' => $root,
            'amount' => $values['amount'],
            'subid' => $values['subid'],
            'campaign_id' => $campaignId,
        ] + $snippets];
    }

    /**
     * Parameters this endpoint does not read are refused by name: a
     * misspelled `offer=` would otherwise answer as if no offer were asked
     * for.
     *
     * @param array<string, mixed> $query
     * @param list<string> $known
     */
    private static function refuseUnknown(array $query, array $known, string $takes): void
    {
        $unknown = array_diff(array_map('strval', array_keys($query)), $known);
        if ($unknown !== []) {
            $errors = [];
            foreach ($unknown as $key) {
                $errors[$key] = 'Unknown parameter; ' . $takes;
            }
            throw new ValidationException('Unknown parameter(s): ' . implode(', ', $unknown), $errors);
        }
    }

    /** A whole number id as sent ("12", never "12.0", "1e3" or "012"), or null. */
    private static function positiveId(string $raw): ?int
    {
        return preg_match('/^[1-9]\d{0,9}$/D', $raw) === 1 && (int) $raw <= 4294967295 ? (int) $raw : null;
    }

    /**
     * An advanced page's offers, in order: `campaign:<id>` or
     * `rotator:<id>`, comma separated, numbered 1… as the page numbers them.
     * The page refuses a page with no offer chosen; so does this.
     *
     * @param array<string, mixed> $query
     * @return array<int, array{string, int}> position => [type, id]
     */
    private function offers(array $query): array
    {
        $how = 'Give each offer as campaign:<id> (`p202 campaign list`) or rotator:<id> (`p202 rotator list`), comma separated, e.g. offers=campaign:12,rotator:3';
        if (!array_key_exists('offers', $query)) {
            throw new ValidationException('Please select an affiliate campaign or rotator', [
                'offers' => 'An advanced landing page links out to one or more offers. ' . $how,
            ]);
        }
        $raw = $query['offers'];
        if (!is_string($raw) || trim($raw) === '') {
            throw new ValidationException('Please select an affiliate campaign or rotator', ['offers' => $how]);
        }
        $items = explode(',', $raw);
        if (count($items) > self::MAX_OFFERS) {
            throw new ValidationException('Too many offers', ['offers' => 'At most ' . self::MAX_OFFERS . ' offers at once']);
        }
        $offers = [];
        $errors = [];
        foreach ($items as $index => $item) {
            $position = $index + 1;
            if (preg_match('/^(campaign|rotator):([1-9]\d{0,9})$/D', $item, $m) !== 1 || (int) $m[2] > 4294967295) {
                $errors["offers[$position]"] = "Offer $position (" . json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ') is not an offer. ' . $how;
                continue;
            }
            $offers[$position] = [$m[1], (int) $m[2]];
        }
        if ($errors !== []) {
            throw new ValidationException('Invalid offers', $errors);
        }

        return $offers;
    }

    /** @return array<string, mixed> */
    private function campaignOffer(int $position, int $campaignId, string $base, string $landingPageUrl, int $now): array
    {
        $campaign = $this->liveCampaign($campaignId);
        if ($campaign === null) {
            throw new ValidationException('Invalid offers', ["offers[$position]" => "Offer $position: that campaign is not yours, or it was removed."]);
        }
        if (in_array($campaign['aff_campaign_id_public'], [null, 0], true)) {
            // A campaign with no public id (NULL, or 0) cannot be carried by
            // acip=: give it one, the repair every API read of campaigns
            // runs (CampaignsController::repairMissingPublicIds()).
            (new CampaignsController($this->db, $this->userId))->repairMissingPublicIds();
            $campaign = $this->liveCampaign($campaignId);
            if ($campaign === null || in_array($campaign['aff_campaign_id_public'], [null, 0], true)) {
                throw new DatabaseException("Campaign $campaignId has no public id");
            }
        }
        $publicId = (string) $campaign['aff_campaign_id_public'];
        $name = (string) $campaign['aff_campaign_name'];

        return [
            'position' => $position,
            'type' => 'campaign',
            'id' => $campaignId,
            'public_id' => (int) $publicId,
            'name' => $name,
            'outbound_link' => LandingPageCode::campaignOutboundLink($base, $publicId),
            'outbound_php' => LandingPageCode::campaignOutboundPhp($base, $publicId, $name, $landingPageUrl, $now),
        ];
    }

    /** @return array<string, mixed> */
    private function rotatorOffer(int $position, int $rotatorId, string $base, string $landingPageUrl, int $now): array
    {
        $stmt = $this->prepare('SELECT id, public_id, name FROM 202_rotators WHERE id = ? AND user_id = ? LIMIT 1');
        $this->bind($stmt, 'ii', $rotatorId, $this->userId);
        $rotator = $this->fetchOne($stmt, 'Redirector lookup failed');
        if ($rotator === null) {
            throw new ValidationException('Invalid offers', ["offers[$position]" => "Offer $position: that redirector is not yours, or it was removed."]);
        }
        $publicId = (string) $rotator['public_id'];
        $name = (string) $rotator['name'];

        return [
            'position' => $position,
            'type' => 'rotator',
            'id' => $rotatorId,
            'public_id' => (int) $publicId,
            'name' => $name,
            'outbound_link' => LandingPageCode::rotatorOutboundLink($base, $publicId),
            'outbound_php' => LandingPageCode::rotatorOutboundPhp($base, $publicId, $name, $landingPageUrl, $now),
        ];
    }

    /** @return array<string, mixed> */
    private function landingPage(int $id): array
    {
        $stmt = $this->prepare(
            'SELECT landing_page_id, landing_page_id_public, landing_page_type, landing_page_url, landing_page_nickname, aff_campaign_id
             FROM 202_landing_pages
             WHERE landing_page_id = ? AND user_id = ? AND landing_page_deleted = 0
             LIMIT 1'
        );
        $this->bind($stmt, 'ii', $id, $this->userId);
        $page = $this->fetchOne($stmt, 'Landing page lookup failed');
        if ($page === null) {
            throw new NotFoundException("Landing page $id not found");
        }
        if (in_array($page['landing_page_id_public'], [null, 0], true)) {
            // repairMissingPublicIds() ran first, so this is not a page it
            // missed: a snippet with an empty lpip= tracks nothing.
            throw new DatabaseException("Landing page $id has no public id");
        }

        return $page;
    }

    /**
     * A simple page's campaign must be live, as the page's list of simple
     * pages only offers pages whose campaign is (get_simple_landing_code.php).
     *
     * @param array<string, mixed> $page
     */
    private function requireLiveCampaign(array $page): void
    {
        $stmt = $this->prepare('SELECT 1 FROM 202_aff_campaigns WHERE aff_campaign_id = ? AND user_id = ? AND aff_campaign_deleted = 0 LIMIT 1');
        $campaignId = (int) $page['aff_campaign_id'];
        $this->bind($stmt, 'ii', $campaignId, $this->userId);
        if ($this->fetchOne($stmt, 'Campaign lookup failed') === null) {
            throw new ValidationException('This landing page\'s campaign was removed', [
                'aff_campaign_id' => 'Landing page ' . (int) $page['landing_page_id'] . " promotes campaign $campaignId, which is not yours or was removed: Get LP Code does not offer its code. Point it at a live campaign first (`p202 landing-page update " . (int) $page['landing_page_id'] . ' --aff-campaign-id <id>`).',
            ]);
        }
    }

    /**
     * One of the account's live campaigns, in a live category of its own: the
     * ones the pages' campaign lists offer (p202_setup_campaign_options()).
     *
     * @return array<string, mixed>|null
     */
    private function liveCampaign(int $campaignId): ?array
    {
        $stmt = $this->prepare(
            'SELECT ac.aff_campaign_id, ac.aff_campaign_id_public, ac.aff_campaign_name
             FROM 202_aff_campaigns ac
             INNER JOIN 202_aff_networks an ON an.aff_network_id = ac.aff_network_id AND an.user_id = ac.user_id
             WHERE ac.aff_campaign_id = ? AND ac.user_id = ? AND ac.aff_campaign_deleted = 0 AND an.aff_network_deleted = 0
             LIMIT 1'
        );
        $this->bind($stmt, 'ii', $campaignId, $this->userId);

        return $this->fetchOne($stmt, 'Campaign lookup failed');
    }

    /**
     * The statement's one row, or null when there is none. A failed read
     * throws: read as "no row", it would refuse a valid id as not found.
     *
     * @return array<string, mixed>|null
     */
    private function fetchOne(\mysqli_stmt $stmt, string $message): ?array
    {
        $this->execute($stmt, $message);
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new DatabaseException($message);
        }
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row ?? null;
    }
}
