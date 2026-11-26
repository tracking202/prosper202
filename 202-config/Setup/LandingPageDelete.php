<?php

declare(strict_types=1);

namespace Prosper202\Setup;

use Prosper202\Database\Connection;

/**
 * Setup › Landing Pages' delete. An advanced page is not removed while text
 * ads of type 1 or trackers name it: each would be left pointing at a page
 * that is gone (DELETE /landing-pages/{id} refuses it too). The page is
 * locked, checked and removed in one transaction (LandingPageTrackers::lockPage()).
 */
final class LandingPageDelete
{
    public const NOT_FOUND = 'That landing page was not found on this account.'
        . ' Nothing was removed.';

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * The page's delete, from the id it was sent: the refusal or failure in
     * the page's words, or null once the page is removed.
     */
    public static function fromSetupPage(\mysqli $db, int $userId, mixed $postedId): ?string
    {
        $landingPageId = \Prosper202\Http\PostedId::read($postedId);
        if ($landingPageId === null) {
            return self::NOT_FOUND;
        }
        try {
            return (new self(new Connection($db)))->remove($userId, $landingPageId);
        } catch (\Throwable $e) {
            error_log('landing_pages.php: the landing page could not be removed: ' . $e->getMessage());

            return 'The landing page could not be removed, and nothing was changed. Try again.';
        }
    }

    /**
     * Remove the account's page, or say in the page's words why not.
     *
     * @return string|null the refusal, or null once the page is removed; NOT_FOUND
     *                     when it was not the account's live page
     * @throws \Prosper202\Database\Exceptions\QueryException when a read or the write fails; nothing is changed
     */
    public function remove(int $userId, int $landingPageId): ?string
    {
        return $this->conn->transaction(function () use ($userId, $landingPageId): ?string {
            $trackers = new LandingPageTrackers($this->conn);
            if ($trackers->lockPage($userId, $landingPageId) === 1) {
                $refusal = self::refusal(
                    (new AdvancedPageTextAds($this->conn))->naming($userId, $landingPageId),
                    $trackers->naming($userId, $landingPageId)
                );
                if ($refusal !== null) {
                    return $refusal;
                }
            }
            if (!SetupRemoval::mark($this->conn, 'landing_page', (string) $landingPageId, $userId, time())) {
                return self::NOT_FOUND;
            }

            return null;
        });
    }

    /**
     * @param list<array{text_ad_id: int, text_ad_name: string}> $ads
     * @param list<int> $trackers
     */
    private static function refusal(array $ads, array $trackers): ?string
    {
        if ($ads === [] && $trackers === []) {
            return null;
        }
        $named = [];
        if ($ads !== []) {
            $named[] = count($ads) . ' text ' . (count($ads) === 1 ? 'ad' : 'ads')
                . ' (' . AdvancedPageTextAds::names($ads) . ')';
        }
        if ($trackers !== []) {
            $named[] = count($trackers) . ' ' . (count($trackers) === 1 ? 'tracker' : 'trackers')
                . ' (tracker ' . LandingPageTrackers::names($trackers) . ')';
        }

        return 'This advanced page is still used by ' . implode(' and ', $named)
            . ', so it was not removed: removing it would leave '
            . (count($ads) + count($trackers) === 1 ? 'that link' : 'their links')
            . ' pointing at a page that is gone. '
            . ($ads !== [] ? 'In Text Ads, point each ad at another advanced page or at a campaign. ' : '')
            . ($trackers !== [] ? 'In Get Links, delete each tracker or build it on another page. ' : '')
            . 'Then remove this page.';
    }
}
