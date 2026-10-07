<?php

declare(strict_types=1);

namespace Prosper202\Update;

use Prosper202\Click\ClickId;
use Prosper202\Conversion\BatchInterrupted;
use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;

/**
 * The subid operations of the Update section, one implementation behind the
 * pages (tracking202/update/subids.php, delete-subids.php, clear-subids.php)
 * and the API (POST /api/v3/conversions/subids, …/subids/delete,
 * …/subids/reset), so what a subid list marks or clears cannot differ between
 * them (CLAUDE.md #5):
 *
 * - mark(): each subid's click is recorded as converted, through the ledger's
 *   single writer (MysqlConversionRepository::record(), source subid_upload,
 *   once per click), and a converting click is never left filtered;
 * - clear(): each subid's conversions are cleared through the ledger
 *   (clearClicks()) and its click unfiltered;
 * - reset(): every converted click of a category, or of one campaign in it,
 *   is cleared the same way, and the data engine is told which hours to
 *   rebuild.
 *
 * Every item is its own transaction (the ledger opens one per click), so a
 * failure part-way leaves the items before it written: that is reported as
 * BatchInterrupted, saying how many, never as a failure that reads as
 * "nothing happened" (CLAUDE.md #13). Repeating any of these is safe: a
 * marked click is not marked again, and a cleared one clears nothing more.
 *
 * Each list item is accounted for in the result (CLAUDE.md #4): a line that
 * is not a subid, a click that is not the account's, a click already
 * converted, and a repeat of an earlier line each say so.
 */
final class SubidBatch
{
    public const MARKED = 'marked';
    public const WOULD_MARK = 'would_mark';
    public const ALREADY_CONVERTED = 'already_converted';
    public const CLEARED = 'cleared';
    public const WOULD_CLEAR = 'would_clear';
    public const NOT_FOUND = 'not_found';
    public const NOT_A_SUBID = 'not_a_subid';
    public const DUPLICATE_IN_LIST = 'duplicate_in_list';

    /** Clicks read per statement by the previews (an IN list's size). */
    private const PREVIEW_CHUNK = 500;

    private readonly MysqlConversionRepository $conversions;

    public function __construct(private readonly Connection $conn, ?MysqlConversionRepository $conversions = null)
    {
        $this->conversions = $conversions ?? new MysqlConversionRepository($conn);
    }

    /**
     * The lines of a pasted list, trimmed, blanks dropped, whatever line
     * ending the browser sent.
     *
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        $lines = preg_split('/\R/', trim($text));
        if ($lines === false) {
            return [];
        }
        return array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));
    }

    /**
     * Read a list of subids into one entry per non-blank item: its click id,
     * or why it has none. `line` is the item's 1-based position in the list as
     * given, blanks counted, so a caller that sends every line of a file reads
     * its own line numbers back. A blank item is skipped and not reported.
     *
     * Pure. Only a string or an integer is read as a subid; anything else (a
     * boolean, a float, a list) is not one, so it is reported, never cast
     * into some click's id (CLAUDE.md #18).
     *
     * @param list<mixed> $items strings, as typed or read from a file
     * @return list<array{line: int, subid: string, click_id: int|null, status: string|null, first_line?: int}>
     *         status null: the click is to be processed; not_a_subid; or
     *         duplicate_in_list, naming the first line with the same click
     */
    public static function read(array $items): array
    {
        $entries = [];
        $firstLine = [];
        foreach (array_values($items) as $index => $item) {
            $subid = is_string($item) ? trim($item) : (is_int($item) ? (string) $item : null);
            if ($subid === '') {
                continue;
            }
            $entry = ['line' => $index + 1, 'subid' => $subid ?? '', 'click_id' => $subid === null ? null : ClickId::parse($subid), 'status' => null];
            if ($entry['click_id'] === null) {
                $entry['status'] = self::NOT_A_SUBID;
            } elseif (isset($firstLine[$entry['click_id']])) {
                $entry['status'] = self::DUPLICATE_IN_LIST;
                $entry['first_line'] = $firstLine[$entry['click_id']];
            } else {
                $firstLine[$entry['click_id']] = $entry['line'];
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * Mark each subid's click as converted, at its campaign's payout.
     *
     * Through the single canonical writer, which locks the click, records the
     * row and derives the click's value from its rows (and refreshes the
     * click's report row once committed). once_per_click: a click that is
     * already a lead changes nothing.
     *
     * @param list<mixed> $items
     * @return array{marked: int, lines: list<array<string, mixed>>}
     * @throws BatchInterrupted when a click's write fails; the clicks before it stay marked
     */
    public function mark(int $userId, array $items): array
    {
        $entries = self::read($items);
        $marked = 0;
        foreach ($entries as $i => $entry) {
            if ($entry['status'] !== null) {
                continue;
            }
            $clickId = (int) $entry['click_id'];
            try {
                $result = $this->conversions->record(
                    $userId,
                    [
                        'click_id' => $clickId,
                        'source' => ConversionSource::SUBID_UPLOAD->value,
                        'user_agent' => 'subid-upload',
                        'pixel_type' => 0,
                        'once_per_click' => true,
                    ],
                    function (int $lockedClickId) use ($userId): void {
                        // A converting click is never left filtered.
                        foreach (['UPDATE 202_clicks SET click_filtered = 0 WHERE click_id = ? AND user_id = ?',
                            'UPDATE 202_clicks_spy SET click_filtered = 0 WHERE click_id = ? AND user_id = ?'] as $sql) {
                            $stmt = $this->conn->prepareWrite($sql);
                            $this->conn->bind($stmt, 'ii', [$lockedClickId, $userId]);
                            $this->conn->executeUpdate($stmt);
                        }
                    }
                );
            } catch (\Throwable $e) {
                throw new BatchInterrupted('line ' . $entry['line'] . ' (subid ' . $entry['subid'] . ')', $marked, null, $e);
            }

            if (!$result['clickFound']) {
                $entries[$i]['status'] = self::NOT_FOUND;
            } elseif ($result['duplicate']) {
                $entries[$i]['status'] = self::ALREADY_CONVERTED;
            } else {
                $entries[$i]['status'] = self::MARKED;
                $marked++;
            }
        }

        return ['marked' => $marked, 'lines' => $entries];
    }

    /**
     * What mark() would do, writing nothing: each click that is the
     * account's and not yet a lead would be marked.
     *
     * Read from the click's lead flag, the value record() checks. The ledger
     * can still answer already_converted for a click this calls would_mark:
     * one in an accumulate-mode campaign whose plain conversion was deleted,
     * because a deleted conversion keeps its place in the click's ledger.
     *
     * @param list<mixed> $items
     * @return array{would_mark: int, lines: list<array<string, mixed>>}
     */
    public function markPreview(int $userId, array $items): array
    {
        $entries = self::read($items);
        $clicks = $this->ownedClicks($userId, $entries);
        $count = 0;
        foreach ($entries as $i => $entry) {
            if ($entry['status'] !== null) {
                continue;
            }
            $click = $clicks[(int) $entry['click_id']] ?? null;
            if ($click === null) {
                $entries[$i]['status'] = self::NOT_FOUND;
            } elseif ((int) $click['click_lead'] === 1) {
                $entries[$i]['status'] = self::ALREADY_CONVERTED;
            } else {
                $entries[$i]['status'] = self::WOULD_MARK;
                $count++;
            }
        }

        return ['would_mark' => $count, 'lines' => $entries];
    }

    /**
     * Clear each subid's conversions: the reverse of mark().
     *
     * Through the ledger (clearClicks()), so the rows and the click agree:
     * every live row of the click is soft-deleted, its revenue voided, and the
     * click's value recomputed from what is left. The click keeps its visit
     * and is no longer filtered. A subid of another account is left alone and
     * reported not_found.
     *
     * @param list<mixed> $items
     * @return array{cleared: int, lines: list<array<string, mixed>>}
     * @throws BatchInterrupted when a click's clear fails; the clicks before it stay cleared
     */
    public function clear(int $userId, array $items): array
    {
        $entries = self::read($items);
        $cleared = 0;
        foreach ($entries as $i => $entry) {
            if ($entry['status'] !== null) {
                continue;
            }
            $clickId = (int) $entry['click_id'];
            try {
                $found = $this->conversions->clearClicks($userId, [$clickId]);
            } catch (\Throwable $e) {
                throw new BatchInterrupted('line ' . $entry['line'] . ' (subid ' . $entry['subid'] . ')', $cleared, null, $e);
            }
            if ($found === 0) {
                $entries[$i]['status'] = self::NOT_FOUND;
                continue;
            }
            $entries[$i]['status'] = self::CLEARED;
            $cleared++;

            // After the clear has committed: a failure here leaves the
            // conversions cleared and only the filtered flag behind, which
            // the next write to the click corrects, so it is logged and the
            // list goes on (as delete-subids.php always did).
            foreach (['UPDATE 202_clicks SET click_filtered = 0 WHERE click_id = ? AND user_id = ?',
                'UPDATE 202_clicks_spy SET click_filtered = 0 WHERE click_id = ? AND user_id = ?'] as $sql) {
                try {
                    $stmt = $this->conn->prepareWrite($sql);
                    $this->conn->bind($stmt, 'ii', [$clickId, $userId]);
                    $this->conn->executeUpdate($stmt);
                } catch (QueryException $e) {
                    error_log('delete subids: clearing the filtered flag failed for click ' . $clickId . ': ' . $e->getMessage());
                }
            }
            // clearClicks() refreshed the click's report row before the
            // filtered flag changed; refresh it again so the reports read
            // the flag too (best-effort, logged).
            $this->conversions->refreshClickReport($clickId);
        }

        return ['cleared' => $cleared, 'lines' => $entries];
    }

    /**
     * What clear() would do, writing nothing: which clicks are the account's,
     * and how many live conversions each holds (the rows clear() deletes).
     *
     * @param list<mixed> $items
     * @return array{would_clear: int, conversions: int, lines: list<array<string, mixed>>}
     */
    public function clearPreview(int $userId, array $items): array
    {
        $entries = self::read($items);
        $clicks = $this->ownedClicks($userId, $entries);
        $count = 0;
        $conversions = 0;
        foreach ($entries as $i => $entry) {
            if ($entry['status'] !== null) {
                continue;
            }
            $click = $clicks[(int) $entry['click_id']] ?? null;
            if ($click === null) {
                $entries[$i]['status'] = self::NOT_FOUND;
                continue;
            }
            $entries[$i]['status'] = self::WOULD_CLEAR;
            $entries[$i]['conversions'] = (int) $click['live_conversions'];
            $count++;
            $conversions += (int) $click['live_conversions'];
        }

        return ['would_clear' => $count, 'conversions' => $conversions, 'lines' => $entries];
    }

    /**
     * Read what a campaign reset names: a category that is the account's
     * (required), and optionally one of its campaigns. The sentences are the
     * page's; each surface may say them its own way, keyed by field.
     *
     * @param string $rawNetwork the category id as given ('' or '0' = none)
     * @param string $rawCampaign the campaign id as given ('' or '0' = every campaign in the category)
     * @return array{network_id: int, campaign_id: int, network: array<string, mixed>|null, campaign: array<string, mixed>|null, errors: array<string, string>}
     */
    public function resetScope(int $userId, string $rawNetwork, string $rawCampaign): array
    {
        $errors = [];
        $network = null;
        $campaign = null;
        $networkId = 0;
        $campaignId = 0;
        $rawNetwork = trim($rawNetwork);
        $rawCampaign = trim($rawCampaign);

        if ($rawNetwork === '' || $rawNetwork === '0') {
            $errors['aff_network_id'] = 'You have to at least select an affiliate network to clear out.';
        } elseif (!ctype_digit($rawNetwork) || strlen($rawNetwork) > 18
            || ($network = OwnedRow::find($this->conn, '202_aff_networks', 'aff_network_id', (int) $rawNetwork, $userId)) === null) {
            $errors['aff_network_id'] = 'Choose one of your categories from the list.';
        } else {
            $networkId = (int) $rawNetwork;
        }

        if ($rawCampaign !== '' && $rawCampaign !== '0') {
            $campaign = ctype_digit($rawCampaign) && strlen($rawCampaign) <= 18
                ? OwnedRow::find($this->conn, '202_aff_campaigns', 'aff_campaign_id', (int) $rawCampaign, $userId)
                : null;
            if ($campaign === null) {
                $errors['aff_campaign_id'] = 'Choose one of your campaigns from the list.';
            } elseif ($networkId > 0 && (int) $campaign['aff_network_id'] !== $networkId) {
                // Without the page script the campaign list is not narrowed to
                // the category; a campaign from another one is refused rather
                // than cleared under a category it is not in.
                $errors['aff_campaign_id'] = 'That campaign is not in the category you chose.';
            } else {
                $campaignId = (int) $campaign['aff_campaign_id'];
            }
        }

        return ['network_id' => $networkId, 'campaign_id' => $campaignId, 'network' => $network, 'campaign' => $campaign, 'errors' => $errors];
    }

    /**
     * The clicks a reset clears: the converted clicks of the campaign, or of
     * every campaign in the category — a click that is a lead, or holds a
     * live conversion row.
     *
     * @return list<array{click_id: int, click_time: int}>
     */
    public function resetClicks(int $userId, int $networkId, int $campaignId): array
    {
        if ($campaignId > 0) {
            $select = "SELECT c.click_id, c.click_time FROM 202_clicks AS c
                WHERE c.user_id = ? AND c.aff_campaign_id = ?
                AND (c.click_lead = 1 OR EXISTS (SELECT 1 FROM 202_conversion_logs AS cl WHERE cl.click_id = c.click_id AND cl.deleted = 0))";
            $scopeId = $campaignId;
        } else {
            $select = "SELECT c.click_id, c.click_time FROM 202_clicks AS c
                INNER JOIN 202_aff_campaigns AS ac ON ac.aff_campaign_id = c.aff_campaign_id
                WHERE c.user_id = ? AND ac.aff_network_id = ?
                AND (c.click_lead = 1 OR EXISTS (SELECT 1 FROM 202_conversion_logs AS cl WHERE cl.click_id = c.click_id AND cl.deleted = 0))";
            $scopeId = $networkId;
        }
        $stmt = $this->conn->prepareWrite($select);
        $this->conn->bind($stmt, 'ii', [$userId, $scopeId]);

        return array_map(
            static fn (array $row): array => ['click_id' => (int) $row['click_id'], 'click_time' => (int) $row['click_time']],
            $this->conn->fetchAll($stmt)
        );
    }

    /**
     * Clear every conversion of a category, or of one campaign in it, so a
     * report uploaded by mistake can be uploaded again. $networkId and
     * $campaignId are resetScope()'s, with no errors.
     *
     * Each converted click is cleared through the ledger (clearClicks()), one
     * transaction per click; then the data engine is told to rebuild the
     * hours from the earliest cleared click to now. When a click's clear
     * fails, the hours of the clicks already cleared are still marked before
     * the failure is reported.
     *
     * @return int how many clicks were cleared
     * @throws BatchInterrupted when a click's clear fails; the clicks before it stay cleared
     */
    public function reset(int $userId, int $networkId, int $campaignId): int
    {
        $rows = $this->resetClicks($userId, $networkId, $campaignId);
        $cleared = 0;
        $earliest = null;
        foreach ($rows as $row) {
            try {
                $found = $this->conversions->clearClicks($userId, [$row['click_id']]);
            } catch (\Throwable $e) {
                if ($earliest !== null) {
                    try {
                        $this->markDirtyHours($userId, $networkId, $campaignId, $earliest);
                    } catch (\Throwable $dirty) {
                        error_log('reset subids: marking the hours of ' . $cleared . ' cleared clicks failed: ' . $dirty->getMessage());
                    }
                }
                throw new BatchInterrupted('click ' . $row['click_id'], $cleared, null, $e);
            }
            if ($found > 0) {
                $cleared++;
                $earliest = $earliest === null ? $row['click_time'] : min($earliest, $row['click_time']);
            }
        }

        if ($earliest !== null) {
            // The data engine rebuilds the hours these clicks fall in. The
            // window starts at the earliest cleared click; the page used to
            // take whichever click the database returned first, which could
            // leave earlier hours showing the income just cleared.
            $this->markDirtyHours($userId, $networkId, $campaignId, $earliest);
        }

        return $cleared;
    }

    private function markDirtyHours(int $userId, int $networkId, int $campaignId, int $from): void
    {
        $dirty = $this->conn->prepareWrite('INSERT IGNORE INTO 202_dirty_hours SET ppc_account_id = 0, aff_campaign_id = ?, aff_network_id = ?, landing_page_id = 0, user_id = ?, click_time_from = ?, click_time_to = ?');
        $this->conn->bind($dirty, 'iiiii', [$campaignId, $networkId, $userId, $from, time()]);
        $this->conn->executeUpdate($dirty);
    }

    /**
     * The account's clicks among the entries still to be processed, with their
     * lead flag and live conversion count, keyed by click id. Read in chunks.
     *
     * @param list<array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function ownedClicks(int $userId, array $entries): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            if ($entry['status'] === null) {
                $ids[] = (int) $entry['click_id'];
            }
        }
        $clicks = [];
        foreach (array_chunk($ids, self::PREVIEW_CHUNK) as $chunk) {
            $stmt = $this->conn->prepareWrite(
                'SELECT c.click_id, c.click_lead,
                        (SELECT COUNT(*) FROM 202_conversion_logs AS cl WHERE cl.click_id = c.click_id AND cl.deleted = 0) AS live_conversions
                 FROM 202_clicks AS c
                 WHERE c.user_id = ? AND c.click_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')'
            );
            $this->conn->bind($stmt, 'i' . str_repeat('i', count($chunk)), array_merge([$userId], $chunk));
            foreach ($this->conn->fetchAll($stmt) as $row) {
                $clicks[(int) $row['click_id']] = $row;
            }
        }

        return $clicks;
    }
}
