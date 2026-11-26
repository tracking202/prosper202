<?php

declare(strict_types=1);

namespace Prosper202\Setup;

use Prosper202\Database\Connection;

/**
 * The text ads an advanced landing page's links offer: this account's live
 * ads of text_ad_type 1 that name the page. Get Links offers such an ad only
 * on an advanced page, so making the page simple left each one offered
 * nowhere, with the save answered as a success. Setup › Landing Pages and
 * PUT /landing-pages/{id} ask this before a page goes from advanced to
 * simple, and refuse the change while any remain.
 *
 * The read is made on the primary: it decides a write.
 */
final class AdvancedPageTextAds
{
    /** How many ads a refusal names before it counts the rest. */
    private const NAMED = 5;

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * @return list<array{text_ad_id: int, text_ad_name: string}> by id
     * @throws \Prosper202\Database\Exceptions\QueryException when the read fails
     */
    public function naming(int $userId, int $landingPageId): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT text_ad_id, text_ad_name FROM 202_text_ads
             WHERE user_id = ? AND landing_page_id = ? AND text_ad_type = 1 AND COALESCE(text_ad_deleted, 0) = 0
             ORDER BY text_ad_id'
        );
        $this->conn->bind($stmt, 'ii', [$userId, $landingPageId]);
        $ads = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $ads[] = ['text_ad_id' => (int) $row['text_ad_id'], 'text_ad_name' => (string) $row['text_ad_name']];
        }

        return $ads;
    }

    /**
     * The ads' names for a sentence: the first few, quoted, and a count of
     * the rest. Plain text; the page escapes it.
     *
     * @param non-empty-list<array{text_ad_id: int, text_ad_name: string}> $ads
     */
    public static function names(array $ads): string
    {
        $named = array_map(
            static fn (array $ad): string => '"' . $ad['text_ad_name'] . '"',
            array_slice($ads, 0, self::NAMED)
        );
        $rest = count($ads) - count($named);

        return implode(', ', $named) . ($rest > 0 ? " and $rest more" : '');
    }

    /**
     * The ads' ids, as the API names them: all of them, since a client acts
     * on each.
     *
     * @param non-empty-list<array{text_ad_id: int, text_ad_name: string}> $ads
     */
    public static function ids(array $ads): string
    {
        return implode(', ', array_map(static fn (array $ad): string => (string) $ad['text_ad_id'], $ads));
    }
}
