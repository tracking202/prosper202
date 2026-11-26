<?php

declare(strict_types=1);

namespace Prosper202\Setup;

use Prosper202\Database\Connection;

/**
 * What still uses an advanced landing page that a save would make simple:
 * its text ads of type 1, which Get Links offers only on an advanced page,
 * and its trackers, whose links were built for an advanced page. The change
 * left the ads offered nowhere and the trackers pointing at a page that no
 * longer takes advanced traffic, with the save answered as a success.
 * Setup › Landing Pages and PUT /landing-pages/{id} refuse it while any
 * remain.
 *
 * The page is locked first and the uses are read under the lock, in the
 * caller's transaction, which then writes the page or writes nothing: a
 * second write of the page cannot land between the check and the save
 * (LandingPageTrackers::lockPage()).
 */
final class AdvancedPageMadeSimple
{
    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * Lock the page and read what uses it.
     *
     * @return array{text_ads: list<array{text_ad_id: int, text_ad_name: string}>, trackers: list<int>}|null
     *     null when the page is not the account's live advanced page, so nothing
     *     holds it; otherwise its uses, both lists empty when none remain
     * @throws \LogicException outside a transaction, where the lock would end before the save
     * @throws \Prosper202\Database\Exceptions\QueryException when a read fails
     */
    public function uses(int $userId, int $landingPageId): ?array
    {
        $trackers = new LandingPageTrackers($this->conn);
        if ($trackers->lockPage($userId, $landingPageId) !== 1) {
            return null;
        }

        return [
            'text_ads' => (new AdvancedPageTextAds($this->conn))->naming($userId, $landingPageId),
            'trackers' => $trackers->naming($userId, $landingPageId),
        ];
    }

    /**
     * Setup › Landing Pages' refusal, in plain text (the page escapes it), or
     * null when nothing holds the page.
     *
     * @param array{text_ads: list<array{text_ad_id: int, text_ad_name: string}>, trackers: list<int>}|null $uses
     */
    public static function setupPageRefusal(?array $uses): ?string
    {
        $ads = $uses['text_ads'] ?? [];
        $trackers = $uses['trackers'] ?? [];
        if ($ads === [] && $trackers === []) {
            return null;
        }
        $said = [];
        $steps = [];
        if ($ads !== []) {
            $one = count($ads) === 1;
            $said[] = 'This advanced page\'s links offer ' . count($ads) . ' text ' . ($one ? 'ad' : 'ads')
                . ' (' . AdvancedPageTextAds::names($ads) . '), and a simple page offers none, so '
                . ($one ? 'it' : 'they') . ' would be offered nowhere.';
            $steps[] = 'in Text Ads, point ' . ($one ? 'the ad' : 'each ad')
                . ' at another advanced page or at a campaign';
        }
        if ($trackers !== []) {
            $one = count($trackers) === 1;
            $said[] = ($ads !== [] ? 'It is also used by ' : 'This advanced page is used by ')
                . count($trackers) . ' ' . ($one ? 'tracker' : 'trackers')
                . ' (tracker ' . LandingPageTrackers::names($trackers) . '), built for an advanced page, so making it'
                . ' simple would leave ' . ($one ? 'its link' : 'their links')
                . ' pointing at a page that no longer takes advanced traffic.';
            $steps[] = 'in Get Links, delete ' . ($one ? 'the tracker' : 'each tracker')
                . ' or build it on another page';
        }

        return implode(' ', $said) . ' ' . ucfirst(implode(', and ', $steps)) . ', then make this page simple.';
    }
}
